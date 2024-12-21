<?php
return [
    'adminEmail' => 'no-reply@99ballov.ru',
    'supportEmail' => 'support@99ballov.com',
    'checkEmail' => 'support@99ballov.ru',
    'senderEmail' => 'noreply@99ballov.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'smsSender' => '99ballov',

    'cloudPayment' => [
        'protect' => 'Vs82bvis7bfu', //protect key
        'pk' => '',
        'apiSecret' => '',
    ],
    'organizationData' => [
        'inn' => '1655455610',
    ],

    'baseUrl' => 'https://lk.99ballov.ru',
    'baseAdminUrl' => 'https://cms.99ballov.ru',
    'baseСentrifugoUrl' => 'wss://centrifugo.99ballov.ru/connection/websocket',
    'api_log' => false,
    'dev' => false,
    'robot_id' => 21443459,
    'defaultSupportAdmin' => 23237,//ID пользователя, который будет выводиться по умолчанию как собеседник в поддержке
    'defaultNinetyNinePointsAdmin' => 70,//ID пользователя, который будет выводиться по умолчанию как собеседник в 99ballov
    'sberbank' => [
        'username' => '',
        'password' => '',
        'callback_token' => '',
    ],
    'sberbankInstallment' => [
        'token' => '',
        'callback_token' => '',
    ],
    'admitad' => [
        'campaign_code' => '',
        'postback_key' => '',
    ],
    'edpartners' => [
        'secure' => ''
    ],
    'cityAds' => [
        'secure' => '',
    ],
    'advertise' => [
        'token' => '',
    ],
    'yandexPayMerchant' => '',
    'evotor' => [
        'login' => '',
        'pass' => '',
        'group_code' => '',
    ],
    'googleMacros' => '',
    'sbpClient' => [
        'id' => '',
        'secret' => '',
        'memberId' => '',
        'idQr' => '',
        'sbpMemeberId' => '',
    ],
    'tbankInstallment' => [
        'shopId' => '',
        'showcaseId' => '',
        'apiPassword' => '',
    ],
];
