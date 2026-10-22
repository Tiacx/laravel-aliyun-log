<?php

namespace Tiacx\AliyunLog\Jobs;

use Aliyun_Log_Models_LogItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tiacx\AliyunLog\AliyunLogService;

class PutAccessLogs implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $topic;

    private $store;

    public function __construct($topic = '', $store = null)
    {
        $this->topic = $topic;
        $this->store = $store;
    }

    /**
     * 任务唯一标识
     */
    public function uniqueId(): string
    {
        return 'PutAccessLogs:'.$this->store;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logItems = [];

        while ($log = Redis::lpop(config('aliyunlog.redis_key'))) {
            $contents = json_decode($log, true);
            if (is_array($contents) && isset($contents['_time_'])) {
                $logTime = $contents['_time_'];
                unset($contents['_time_']);

                $logItem = new Aliyun_Log_Models_LogItem;
                $logItem->setTime($logTime);
                $logItem->setContents($contents);
                $logItems[] = $logItem;
            }
        }

        if (! empty($logItems)) {
            if (! AliyunLogService::putLogs($logItems, $this->topic, $this->store)) {
                $contents = array_map(function ($logItem) {
                    return [
                        '_time_' => $logItem->getTime(),
                        ...$logItem->getContents(),
                    ];
                }, $logItems);
                Redis::lpush(config('aliyunlog.redis_key'), json_encode($contents, JSON_UNESCAPED_UNICODE)); // 重新入队
            }
            Cache::lock(config('aliyunlog.redis_key'))->forceRelease(); // 释放锁
        }
    }
}
