<?php

namespace Tiacx\AliyunLog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * 阿里云日志 Handler
 */
class AliyunMonologHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        $contents = [
            'Level' => $record['level'],
            'LevelName' => $record['level_name'],
            'Message' => $record['message'],
            'Context' => $record['formatted'],
        ];

        AliyunLogService::putLog($contents);
    }
}
