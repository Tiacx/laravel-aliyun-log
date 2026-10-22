<?php

namespace Tiacx\AliyunLog;

use Illuminate\Support\ServiceProvider;
use Monolog\Formatter\JsonFormatter;
use Monolog\Logger;

class AliyunLogServiceProvider extends ServiceProvider
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aliyunlog.php', 'aliyunlog');

        // 注册自定义日志驱动，宿主项目在 logging.php 中使用 'driver' => 'aliyunlog'
        if ($this->app->bound('log')) {
            $this->app->make('log')->extend('aliyunlog', function ($app, $config) {
                $level = is_int($config['level'] ?? null) ? $config['level'] : Logger::toMonologLevel($config['level'] ?? 'debug');
                $handler = new AliyunMonologHandler($level);
                $handler->setFormatter(new JsonFormatter);

                return new Logger(
                    $config['name'] ?? 'aliyunlog',
                    [$handler]
                );
            });
        }
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/aliyunlog.php' => config_path('aliyunlog.php'),
        ], 'aliyunlog-config');
    }
}
