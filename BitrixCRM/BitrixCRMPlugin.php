<?php declare(strict_types=1);

namespace Plugin\BitrixCRM;

use App\Domain\AbstractPlugin;
use App\Domain\Entities\Catalog\Order;
use App\Domain\Entities\Form\Data as FromData;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use App\Domain\Service\Catalog\ProductService as CatalogProductService;
use App\Domain\Service\Form\DataService as FormDataService;
use Psr\Container\ContainerInterface;
use Slim\Http\Request;
use Slim\Http\Response;

class BitrixCRMPlugin extends AbstractPlugin
{
    const NAME = 'BitrixCRMPlugin';
    const TITLE = 'Bitrix24 CRM';
    const DESCRIPTION = 'Плагин для интеграции с Bitrix24';
    const AUTHOR = 'Aleksey Ilyin';
    const AUTHOR_SITE = 'https://site.0x12f.com';
    const VERSION = '1.0';

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->setHandledRoute('common:catalog:cart', 'common:form');

        foreach (['C_REST_CLIENT_ID', 'C_REST_CLIENT_SECRET', 'C_REST_WEB_HOOK_URL'] as $param) {
            $name = mb_strtolower(str_replace('_', '', $param));
            $this->addSettingsField([
                'label' => $param,
                'type' => 'text',
                'name' => $name,
            ]);

            if (($value = $this->parameter(self::NAME . '_' . $name, ''))) {
                define($param, $value);
            }
        }

        define('C_REST_LOGS_DIR', VAR_DIR);
    }

    /** {@inheritdoc} */
    public function after(Request $request, Response $response, string $routeName): Response
    {
        if ($request->isPost()) {
            switch ($routeName) {
                case 'common:catalog:cart':
                    $catalogProductService = CatalogProductService::getWithContainer($this->container);
                    $catalogOrderService = CatalogOrderService::getWithContainer($this->container);

                    /** @var Order $order */
                    $order = $catalogOrderService->read([
                        'user' => $request->getAttribute('user', null),
                        'order' => ['date' => 'desc'],
                        'limit' => 1,
                    ])->first();
                    $products = $catalogProductService->read(['uuid' => array_keys($order->getList())]);

                    // создание контакта
                    $contact = crest::call('crm.contact.add', [
                        'fields' => [
                            'TITLE' => 'Лид с сайта',
                            'NAME' => $order->getUser() ? $order->getUser()->getFirstname() : $order->getDelivery()['client'],
                            'LAST_NAME' => $order->getUser() ? $order->getUser()->getLastname() : '',
                            'EMAIL' => [['VALUE' => $order->getUser() ? $order->getUser()->getEmail() : $order->getEmail(), 'VALUE_TYPE' => 'WORK']],
                            'PHONE' => [['VALUE' => $order->getUser() ? $order->getUser()->getPhone() : $order->getPhone(), 'VALUE_TYPE' => 'WORK']],
                        ],
                        'params' => [
                            'REGISTER_SONET_EVENT' => 'Y',
                        ],
                    ]);
                    $id_kontakt = $contact['result'];

                    // создание сделки
                    $deal = crest::call('crm.deal.add', [
                        'fields' => [
                            'TITLE' => 'Заказ с сайта (' . $order->getSerial() . ')',
                            'STAGE_ID' => 'C2:NEW',
                            'SOURCE_ID' => 1,
                            'ASSIGNED_BY_ID' => 1,
                            'CATEGORY_ID' => 2,
                            'CURRENCY_ID' => 'RUB',
                            'OPPORTUNITY' => $products->sum(fn($el) => $el->getPrice() * $order->getList()[$el->getUuid()->toString()]),
                            'OPENED' => 'Y',
                            'COMMENTS' => $products->map(fn($el) => trim($el->getTitle()) . ' (×' . $order->getList()[$el->getUuid()->toString()] . ')')->implode('<br>') . '<hr>' . $order->getComment(),
                        ],
                        'params' => [
                            'REGISTER_SONET_EVENT' => 'Y',
                        ],
                    ]);
                    $id_deal = $deal['result'];

                    // закрепление сделки за контактом
                    crest::call('crm.deal.contact.add', [
                        'id' => $id_deal,
                        'fields' => [
                            'CONTACT_ID' => $id_kontakt,
                        ],
                    ]);

                    break;

                case 'common:form':
                    $formDataService = FormDataService::getWithContainer($this->container);
                    /** @var FromData $data */
                    $data = $formDataService->read([
                        'order' => ['date' => 'desc'],
                        'limit' => 1,
                    ])->first();

                    // создание контакта
                    $contact = crest::call('crm.contact.add', [
                        'fields' => [
                            'TITLE' => 'Лид с сайта',
                            'NAME' => $request->getParam('name', 'Без имени'),
                            'LAST_NAME' => '',
                            'EMAIL' => [['VALUE' => $request->getParam('email', ''), 'VALUE_TYPE' => 'WORK']],
                            'PHONE' => [['VALUE' => $request->getParam('phone', ''), 'VALUE_TYPE' => 'WORK']],
                        ],
                        'params' => [
                            'REGISTER_SONET_EVENT' => 'Y',
                        ],
                    ]);
                    $id_kontakt = $contact['result'];

                    // создание запроса
                    $deal = crest::call('crm.deal.add', [
                        'fields' => [
                            'TITLE' => 'Запрос с сайта',
                            'STAGE_ID' => 'C2:NEW',
                            'SOURCE_ID' => 1,
                            'ASSIGNED_BY_ID' => 1,
                            'CATEGORY_ID' => 2,
                            'CURRENCY_ID' => 'RUB',
                            'OPPORTUNITY' => 0,
                            'OPENED' => 'Y',
                            'COMMENTS' => $request->getParam('message', $request->getParam('question', $data->getMessage())),
                        ],
                        'params' => [
                            'REGISTER_SONET_EVENT' => 'Y',
                        ],
                    ]);
                    $id_question = $deal['result'];

                    // закрепление сделки за контактом
                    crest::call('crm.deal.contact.add', [
                        'id' => $id_question,
                        'fields' => [
                            'CONTACT_ID' => $id_kontakt,
                        ],
                    ]);

                    break;
            }
        }

        return $response;
    }
}
