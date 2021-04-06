Bitrix24 CRM для WebSpace Engine
====
######(Плагин)

Плагин для интеграции с Birtix24 CRM.
Отправка заказов и форм в CRM.

#### Установка
Поместить в папку `plugin` и подключить в `index.php` добавив строку:
```php
// clearcache plugin
$plugins->register(new \Plugin\BitrixCRM\BitrixCRMPlugin($container));
```

#### License
Licensed under the MIT license. See [License File](LICENSE.md) for more information.
