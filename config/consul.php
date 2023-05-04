<?php


return [
    /**
     * Consul URI
     */
    'uri'    => env('CONSUL_URI', '127.0.0.1:8500'),

    /**
     * Consul Token
     */
    'token'  => env('CONSUL_TOKEN', ''),

    /**
     * Consul keys list
     */
    'keys'   => [
        // 'foo', 'bar'
        env('CONSUL_PATH') . '/GENERAL/RABBITMQ',
        env('CONSUL_PATH') . '/LARAVEL_COMMON',
        env('CONSUL_PATH') . '/GENERAL/REDIS',
        env('CONSUL_PATH') . '/SCHOOL_SERVICE',
        env('CONSUL_PATH') . '/GENERAL/MONGODB',
        env('CONSUL_PATH') . '/GENERAL/S3',
        env('CONSUL_PATH') . '/GENERAL/Elasticsearch',
        env('CONSUL_PATH') . '/GENERAL/FIREBASE_FMC',
    ],

    /**
     * Consul scheme
     */
    'scheme' => env('CONSUL_SCHEME', 'http'),

    /**
     * Consul datacenter
     */
    'dc'     => env('CONSUL_DC', 'dc1')
];
