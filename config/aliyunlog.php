<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 阿里云日志 SDK 配置
    |--------------------------------------------------------------------------
    */

    // 总开关，关闭后所有日志推送直接跳过
    'enabled' => env('ALIYUN_LOG_ENABLED', false),

    'access_key_id' => env('ALIYUN_LOG_ACCESS_KEY_ID'),
    'access_key_secret' => env('ALIYUN_LOG_ACCESS_KEY_SECRET'),
    'endpoint' => env('ALIYUN_LOG_ENDPOINT'),
    'project' => env('ALIYUN_LOG_PROJECT'),

    // 访问日志 store（中间件使用）
    'store_access' => env('ALIYUN_LOG_STORE_ACCESS'),
    // 应用日志 store（Monolog handler 使用）
    'store_logging' => env('ALIYUN_LOG_STORE_LOGGING'),

    // 异步写日志时，默认延时秒数
    'async_delay' => intval(env('ALIYUN_LOG_ASYNC_DELAY', 10)),

    // 访问日志在 Redis 中的队列键（多项目共享 Redis 时请区分命名）
    'redis_key' => env('ALIYUN_LOG_REDIS_KEY', 'AccessLogs'),

    /*
    |--------------------------------------------------------------------------
    | 访问日志中间件配置
    |--------------------------------------------------------------------------
    */

    'middleware' => [

        // 这些路由（精确匹配）不记录请求参数
        'secret_routes' => [
            '/api/auth/login',
            '/api/admin/auth/login',
        ],

        // 忽略这些路由（前缀匹配），不记录访问日志
        'ignore_routes' => [
        ],

        // 这些路由（精确匹配）不记录访问日志
        'ignored_routes' => [
            '/',
        ],

        // 这些请求头不记录（小写比较）
        'sensitive_headers' => [
            'authorization',
            'x-api-key',
        ],

        // 日志 service 字段，null 时取 strtolower(config('app.name'))
        'service' => null,
    ],

];
