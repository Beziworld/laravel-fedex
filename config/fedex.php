<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Every connection is one project on developer.fedex.com (API key and
    | secret key) together with the nine-digit FedEx account number that
    | pays for shipments. "sandbox" switches to apis-sandbox.fedex.com,
    | which returns predefined (virtualised) responses. Compatible
    | providers and parent-child customers set "grant_type" to
    | csp_credentials or client_pc_credentials with the child key and secret.
    |
    | Use Fedex::connection('name') for another account and
    | Fedex::using([...]) for credentials stored at runtime. Options outside
    | "connections" are shared and can be overridden per connection.
    |
    */

    'default' => env('FEDEX_CONNECTION', 'default'),

    'connections' => [

        'default' => [
            'client_id' => env('FEDEX_CLIENT_ID'),
            'client_secret' => env('FEDEX_CLIENT_SECRET'),
            'account_number' => env('FEDEX_ACCOUNT_NUMBER'),
            'sandbox' => env('FEDEX_SANDBOX', false),
            'grant_type' => env('FEDEX_GRANT_TYPE', 'client_credentials'),
            'child_key' => env('FEDEX_CHILD_KEY'),
            'child_secret' => env('FEDEX_CHILD_SECRET'),

            // Default shipper printed on labels and used for pickups.
            'shipper' => [
                'name' => env('FEDEX_SHIPPER_NAME'),
                'company' => env('FEDEX_SHIPPER_COMPANY'),
                'phone' => env('FEDEX_SHIPPER_PHONE'),
                'email' => env('FEDEX_SHIPPER_EMAIL'),
                'address' => env('FEDEX_SHIPPER_ADDRESS'),
                'city' => env('FEDEX_SHIPPER_CITY'),
                'postal_code' => env('FEDEX_SHIPPER_POSTAL_CODE'),
                'country_code' => env('FEDEX_SHIPPER_COUNTRY', 'PL'),
                'state_code' => env('FEDEX_SHIPPER_STATE'),
                'tax_id' => env('FEDEX_SHIPPER_TAX_ID'),
                'tax_id_type' => env('FEDEX_SHIPPER_TAX_ID_TYPE', 'BUSINESS_UNION'),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Shipments
    |--------------------------------------------------------------------------
    |
    | "units": metric (KG, CM) or imperial (LB, IN). "label_format": PDF,
    | PNG, ZPLII or EPL2; "label_stock" defaults to PAPER_4X6 for images and
    | STOCK_4X6 for thermal printers. "locale" is sent in the x-locale header.
    |
    */

    'units' => env('FEDEX_UNITS', 'metric'),

    'label_format' => env('FEDEX_LABEL_FORMAT', 'PDF'),

    'label_stock' => env('FEDEX_LABEL_STOCK'),

    'locale' => env('FEDEX_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Read-only calls are retried "retries" times on connection errors and
    | HTTP 5xx, waiting "retry_delay" milliseconds; shipments,
    | cancellations and pickups are never retried. Tokens (valid for one
    | hour) are cached in the given store; FedEx blocks an IP for ten
    | minutes when tokens are requested too often, so use a shared store
    | (redis, database) when several processes call the API.
    |
    */

    'timeout' => (int) env('FEDEX_TIMEOUT', 30),

    'retries' => (int) env('FEDEX_RETRIES', 2),

    'retry_delay' => (int) env('FEDEX_RETRY_DELAY', 500),

    'cache' => [
        'store' => env('FEDEX_CACHE_STORE'),
        'prefix' => 'fedex',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking webhook
    |--------------------------------------------------------------------------
    |
    | Shipment visibility webhooks are configured in the FedEx developer
    | portal with an HTTPS URL and a security token (26 to 100 characters).
    | When enabled, POST {path} verifies the fdx-signature header and
    | dispatches Beziworld\Fedex\Events\TrackingWebhookReceived.
    |
    */

    'webhook' => [
        'enabled' => env('FEDEX_WEBHOOK_ENABLED', false),
        'path' => env('FEDEX_WEBHOOK_PATH', 'fedex/webhook'),
        'security_token' => env('FEDEX_WEBHOOK_TOKEN'),
        'connection' => env('FEDEX_WEBHOOK_CONNECTION', 'default'),
        'middleware' => [],
    ],

];
