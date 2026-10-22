<?php

namespace Tiacx\AliyunLog;

use Aliyun_Log_Client;
use Aliyun_Log_Exception;
use Aliyun_Log_Models_GetLogsRequest;
use Aliyun_Log_Models_LogItem;
use Aliyun_Log_Models_PutLogsRequest;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;
use Tiacx\AliyunLog\Jobs\PutAccessLogs;

/**
 * 阿里云日志 Service
 */
class AliyunLogService
{
    protected static ?Aliyun_Log_Client $client = null;

    /**
     * 日志客户端
     *
     * @throws Aliyun_Log_Exception
     */
    protected static function getClient(): Aliyun_Log_Client
    {
        if (is_null(self::$client)) {
            self::$client = new Aliyun_Log_Client(
                config('aliyunlog.endpoint'),
                config('aliyunlog.access_key_id'),
                config('aliyunlog.access_key_secret')
            );
            // 注：Aliyun_Log_Client 会把时区改成 Asia/Shanghai
            date_default_timezone_set(config('app.timezone')); // 恢复默认时区
        }

        return self::$client;
    }

    /**
     * 推送单条日志
     */
    public static function putLog(array $contents, string $topic = '', $store = null): void
    {
        $logItem = new Aliyun_Log_Models_LogItem;
        $logItem->setTime(now('Asia/Shanghai')->getTimestamp());
        $logItem->setContents($contents);
        self::putLogs([$logItem], $topic, $store);
    }

    /**
     * 推送单条日志（异步）
     */
    public static function putLogAsync(int $requestTime, array $contents, string $topic = '', $store = null): void
    {
        if (! config('aliyunlog.enabled')) {
            return;
        }

        // 如果没有配置 Redis，则走同步
        if (config('cache.default') != 'redis') {
            self::putLog($contents, $topic, $store);

            return;
        }

        // 记录原始时间
        $contents['_time_'] = $requestTime;
        Redis::rpush(config('aliyunlog.redis_key'), json_encode($contents, JSON_UNESCAPED_UNICODE));
        $delay = config('aliyunlog.async_delay', 10);

        // 日志已入Redis队列，没必要一直派发事件
        $lock = Cache::lock(config('aliyunlog.redis_key'), $delay); // 加锁

        if ($lock->get()) {
            PutAccessLogs::dispatch($topic, $store)
                ->delay(now()->addSeconds($delay)); // 延时处理（微堆处理）
        }
    }

    /**
     * 推送多条日志
     *
     * @param  null  $store
     */
    public static function putLogs(array $logItems, string $topic = '', $store = null): bool
    {
        if (! config('aliyunlog.enabled')) {
            return true;
        }

        $request = new Aliyun_Log_Models_PutLogsRequest(
            config('aliyunlog.project'),
            $store ?? config('aliyunlog.store_logging'),
            $topic,
            null,
            $logItems
        );

        try {
            self::getClient()->putLogs($request);

            return true;
        } catch (Throwable $e) {
            Log::warning($e->getMessage(), $e->getTrace());

            return false;
        }
    }

    /**
     * 获取日志
     *
     * @param  int  $from  开始时间戳
     * @param  int  $to  结束时间戳
     * @param  string  $query  查询语句
     * @param  null  $store
     */
    public function getLogs(int $from, int $to, string $query, string $topic = '', $store = null): array
    {
        $request = new Aliyun_Log_Models_GetLogsRequest(
            config('aliyunlog.project'),
            $store ?? config('aliyunlog.store_access'),
            $from,
            $to,
            $topic,
            $query
        );

        try {
            $response = self::getClient()->getLogs($request);

            return array_map(function ($log) {
                return $log->getContents();
            }, $response->getLogs());
        } catch (Exception $e) {
            Log::warning($e->getMessage(), $e->getTrace());

            return [];
        }
    }
}
