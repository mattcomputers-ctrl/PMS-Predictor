<?php

return [
    'app' => [
        'name'     => 'Pantone Predictor',
        'debug'    => false,
        'timezone' => 'America/New_York',
        'url'      => 'http://localhost',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'pantone_predictor',
        'user'     => 'pantone_user',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'name'     => 'pantone_session',
        'lifetime' => 7200,
    ],

    'paths' => [
        'data' => __DIR__ . '/../data',
    ],
];
