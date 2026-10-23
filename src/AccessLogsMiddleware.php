<?php

namespace Tiacx\AliyunLog;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 访问日志中间件
 *
 * 路由列表、敏感请求头等行为均通过 aliyunlog.php 配置驱动；
 * 需要自定义用户识别（如多租户平台用户）时，继承本类覆盖 resolveUser()；
 * 需要自定义日志字段时，覆盖 resolveData()。
 * 注：不做 UA 过滤 —— UA 可被伪造，不能作为丢弃日志的依据；内部调用降噪请用 ignore_routes。
 */
class AccessLogsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestStartTime = microtime(true);
        $response = $next($request);

        if ($this->matchesAny($this->ignoreRoutes(), $request->getPathInfo())) {
            return $response;
        }

        try {
            $data = $this->resolveData($request, $response, round(microtime(true) - $requestStartTime, 3));

            AliyunLogService::putLogAsync(intval($requestStartTime), $data, '', config('aliyunlog.store_access'));
        } catch (Exception $e) {
            Log::error($e->getMessage(), $e->getTrace());
        }

        return $response;
    }

    /**
     * 构建访问日志数据，返回写入日志的字段
     * 需要自定义日志内容时继承并覆盖此方法：
     * 追加/移除字段可调用 parent::resolveData() 后调整，完全自定义则直接返回所需数据
     */
    protected function resolveData(Request $request, Response $response, float $duration): array
    {
        $route = $request->getPathInfo();
        $params = $this->matchesAny($this->secretRoutes(), $route) ? [] : $request->all();

        $user = $this->resolveUser($request);

        $headers = $this->getHeaders($request);
        $sensitive = $this->sensitiveHeaders(); // 已转为小写
        $headers = array_filter($headers, function ($name) use ($sensitive) {
            return ! in_array(strtolower($name), $sensitive, true);
        }, ARRAY_FILTER_USE_KEY);

        return [
            'method' => $request->method(),
            'route' => $route,
            'headers' => $headers ? json_encode($headers, JSON_UNESCAPED_UNICODE) : '',
            'params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : '',
            'duration' => $duration,
            'service' => $this->serviceName(),
            'userId' => $user['userId'],
            'platform' => $user['platform'],
        ];
    }

    /**
     * 解析请求用户，返回 ['userId' => ..., 'platform' => ...]
     * 需要接入自有用户体系时继承并覆盖此方法
     */
    protected function resolveUser(Request $request): array
    {
        if (auth()->check() && $user = auth()->user()) {
            return ['userId' => $user->id, 'platform' => 'system'];
        }

        return ['userId' => '', 'platform' => 'system'];
    }

    /**
     * 获取请求头（保留原始大小写风格）
     * CLI 环境（测试）下 $_SERVER 无 HTTP_* 数据，getallheaders()（含 polyfill）返回空，
     * 回退到请求头对象取值
     */
    protected function getHeaders(Request $request): array
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            return getallheaders();
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', (array) $values);
        }

        return $headers;
    }

    /**
     * 这些路由不保存参数
     */
    protected function secretRoutes(): array
    {
        return config('aliyunlog.middleware.secret_routes', []);
    }

    /**
     * 忽略这些路由，不记录访问日志
     */
    protected function ignoreRoutes(): array
    {
        return config('aliyunlog.middleware.ignore_routes', []);
    }

    /**
     * 路由是否命中任一模式：不含 * 为精确匹配；含 * 按通配符匹配，
     * * 代表任意字符（可跨 /），如 '/api/health*'（前缀）、'/employee/123/avatar' 命中 '/employee/*' + '/avatar'（模糊）
     */
    protected function matchesAny(array $patterns, string $route): bool
    {
        foreach ($patterns as $pattern) {
            if (! str_contains($pattern, '*')) {
                if ($pattern === $route) {
                    return true;
                }
                continue;
            }

            if (preg_match('#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#', $route) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 这些请求头不记录（小写比较）
     */
    protected function sensitiveHeaders(): array
    {
        return array_map('strtolower', config('aliyunlog.middleware.sensitive_headers', []));
    }

    /**
     * 日志 service 字段，null 时取应用名小写
     */
    protected function serviceName(): string
    {
        return config('aliyunlog.middleware.service') ?? strtolower(config('app.name'));
    }
}
