# Laravel Aliyun Log

阿里云 SLS 日志扩展包：应用日志推送（Monolog Handler）+ 访问日志中间件。

## 功能

- `AliyunLogService`：静态 API 推送/查询日志（`putLog` / `putLogAsync` / `putLogs` / `getLogs`）
- `AliyunMonologHandler`：Monolog Handler，通过自定义日志驱动 `aliyunlog` 接入 Laravel logging
- `AccessLogsMiddleware`：访问日志中间件，支持 Redis 异步批量推送、敏感路由/请求头过滤
- `PutAccessLogs`：异步批量推送队列任务（Redis 缓冲 + 失败回队）

## 安装

```bash
composer require tiacx/laravel-aliyun-log
```

发布配置文件：

```bash
php artisan vendor:publish --tag=aliyunlog-config
```

## 配置

`.env` 示例：

```dotenv
ALIYUN_LOG_ENABLED=true
ALIYUN_LOG_ACCESS_KEY_ID=xxx
ALIYUN_LOG_ACCESS_KEY_SECRET=xxx
ALIYUN_LOG_ENDPOINT=cn-hangzhou.log.aliyuncs.com
ALIYUN_LOG_PROJECT=your-project
ALIYUN_LOG_STORE_ACCESS=your-access-store
ALIYUN_LOG_STORE_LOGGING=your-logging-store
ALIYUN_LOG_ASYNC_DELAY=10
ALIYUN_LOG_REDIS_KEY=AccessLogs
```

### 访问日志中间件配置（`config/aliyunlog.php` 的 `middleware` 节）

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `secret_routes` | 不记录请求参数的路由（匹配规则同 `ignore_routes`） | `[]` |
| `ignore_routes` | 忽略的路由，不记录访问日志 | `['/']` |
| `sensitive_headers` | 不记录的请求头（不区分大小写） | `authorization`、`x-api-key` |
| `service` | 日志 service 字段，`null` 时取应用名小写 | `null` |

> `ignore_routes` / `secret_routes` 均支持通配：不含 `*` 为精确匹配，尾部 `*` 为前缀匹配（如 `/api/health*`），中间含 `*` 为模糊匹配（如 `/employee/*/avatar`）；`*` 匹配任意字符（含 `/`）。

> 不做 UA 过滤：User-Agent 可被客户端伪造，按 UA 丢日志会让伪造请求绕过审计。

> 内部服务/探针调用产生噪音时，请用 `ignore_routes` 按路由忽略（服务器端判定，不可伪造）。

## 使用

### 1. 应用日志（Monolog）

在 `config/logging.php` 中添加 channel：

```php
'aliyun' => [
    'driver' => 'aliyunlog',
    'level' => env('LOG_LEVEL', 'debug'),
],
```

### 2. 访问日志（中间件）

在 HTTP Kernel 的 `middleware` 中注册：

```php
protected $middleware = [
    \Tiacx\AliyunLog\AccessLogsMiddleware::class,
    // ...
];
```

默认用户识别基于 `auth()->user()`。需要自定义时继承中间件并覆盖 `resolveUser()`：

```php
use Illuminate\Http\Request;
use Tiacx\AliyunLog\AccessLogsMiddleware;

class AccessLogsMiddleWare extends AccessLogsMiddleware
{
    protected function resolveUser(Request $request): array
    {
        // 返回 ['userId' => ..., 'platform' => ...]
    }
}
```

需要自定义日志数据时，覆盖 `resolveData()`：追加/移除字段可调用 `parent::resolveData()` 后调整，完全自定义则直接返回所需数据：

```php
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tiacx\AliyunLog\AccessLogsMiddleware;

class AccessLogsMiddleWare extends AccessLogsMiddleware
{
    protected function resolveData(Request $request, Response $response, float $duration): array
    {
        $data = parent::resolveData($request, $response, $duration);
        $data['userName'] = $request->header('UserName', '');

        return $data;
    }
}
```

### 3. 代码推送日志

```php
use Tiacx\AliyunLog\AliyunLogService;

AliyunLogService::putLog(['key' => 'value']);                    // 同步单条
AliyunLogService::putLogAsync(time(), ['key' => 'value']);       // 异步（Redis 缓冲）
AliyunLogService::putLogs($logItems);                            // 同步批量
(new AliyunLogService())->getLogs($from, $to, $query);           // 查询
```

## 异步推送机制

`putLogAsync` 依赖 Redis：日志先写入 Redis 队列（键名 `aliyunlog.redis_key`），并通过
`PutAccessLogs` 队列任务（`ShouldBeUnique` + 延时）批量推送到 SLS，推送失败自动回队重试。
未配置 Redis 时自动降级为同步推送。
