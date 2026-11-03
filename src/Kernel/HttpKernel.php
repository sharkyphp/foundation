<?php
/**
 * SharkyPHP Foundation Library
 *
 * Http 框架核心
 *
 * @package SharkyPHP\\Foundation
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Foundation\Kernel;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sharky\Event\Events;
use Sharky\Facade\Config;
use Sharky\Facade\Container;
use Sharky\Facade\Context;
use Sharky\Facade\Event;
use Sharky\Facade\Http;
use Sharky\Facade\Route;
use Sharky\Http\Exception\HandlerReturnInvalidException;
use Sharky\Http\Exception\HttpException;
use Sharky\Http\Message\Stream;
use Sharky\Invoker\Invoker;
use Sharky\Pipeline\Pipeline;
use Sharky\Support\Helper;

final class HttpKernel
{
    private Pipeline $pipeline;

    public function __construct(
        private string $directory
    ) {
        // 初始化中间件管道
        $this->pipeline = Container::get(Pipeline::class);
    }

    // 启动 Http 核心
    public static function launch(): self
    {
        // 启动 Http 核心
        $kernel = Container::get(self::class);

        $started = Container::get('foundation.started');

        if (! $started) {
            throw new RuntimeException('Application not booted. Please complete the application bootstrap process first.');
        }

        // 内核启动中
        Event::dispatch(Events::KERNEL_BOOTING, ['kernel' => $kernel]);

        // 加载环境变量
        Helper::loadEnv("{$kernel->directory}/.env.fpm");

        // 加载路由
        Route::loadRoutes("{$kernel->directory}/app/Http/Routes");

        // 内核已启动
        Event::dispatch(Events::KERNEL_BOOTED, ['kernel' => $kernel]);

        return $kernel;
    }

    // 处理 HTTP 请求
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // 开启缓冲区
            ob_start();
            $level = ob_get_level();

            // 请求已接收
            Event::dispatch(Events::REQUEST_RECEIVE, ['request' => $request, 'kernel' => $this]);

            // 这里装载全局中间件
            $pipeline   = clone $this->pipeline;
            $middleware = Config::get('middleware.http', []);
            $pipeline->middleware($middleware);

            // 执行中间件链
            $dispatchRoute = Closure::fromCallable([$this, 'dispatchRoute']);
            $callableChain = $pipeline->makeChain($dispatchRoute);
            $response      = $callableChain($request);

            // 响应已生成
            Event::dispatch(Events::REQUEST_RESPONSE, ['request' => $request, 'response' => $response, 'kernel' => $this]);

            // 清空并关闭缓冲区
            if ($level !== ob_get_level()) {
                throw new RuntimeException(sprintf('Unexpected output detected, cannot continue.'));
            } else {
                ob_end_clean();
            }

            return $response;
        } catch (HttpException $e) {

            // 请求处理异常
            Event::dispatch(Events::REQUEST_ERROR, ['request' => $request, 'exception' => $e, 'kernel' => $this]);

            $response = Http::response()
                ->setStatus($e->getStatusCode());

            $stream = Stream::fromString($response->getReasonPhrase($e->getStatusCode()));
            return $response->setBody($stream);
        }
    }

    public function sendResponse(ResponseInterface $response): void
    {
        // CORS：依据配置白名单回显 Access-Control-Allow-Origin（预检与真实响应均覆盖）
        $origin = $this->resolveCorsOrigin();
        if ($origin !== null) {
            $response->setHeader([
                'Access-Control-Allow-Origin' => $origin,
                'Vary'                        => 'Origin',
            ]);
            if (Config::get('http.response.cors.allow-credentials', false)) {
                $response->setHeader('Access-Control-Allow-Credentials', 'true');
            }
        }

        // 设置状态码
        $code    = $response->getStatusCode();
        $reason  = $response->getReasonPhrase();
        $version = $response->getProtocolVersion();

        header(sprintf('HTTP/%s %d %s', $version, $code, $reason), true, $code);

        // 输出所有headers
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        // 204 / 304 禁止输出body；HEAD 只回头部（帧内容仍按 GET 生成）
        $isHead = Context::getRequest()?->getMethod() === 'HEAD';

        if ($code !== 204 && $code !== 304 && ! $isHead) {
            echo (string) $response->getBody();
        }

        // 响应已发送
        Event::dispatch(Events::REQUEST_RESPONSE_SENT, ['kernel' => $this]);

        // 完成请求
        $this->finish();
    }

    // 处理 Options 请求
    private function handleOptions(array $support): ResponseInterface
    {
        // 组装路由预检
        $headers = Config::get('http.response.cors.allow-headers', []);
        $headers = is_array($headers) ? implode(", ", $headers) : $headers;

        return Http::response()
            ->setHeader([
                'Access-Control-Allow-Methods' => implode(", ", $support),
                'Access-Control-Allow-Headers' => $headers,
            ])
            ->setStatus(204);
    }

    /**
     * 依据配置白名单解析 Access-Control-Allow-Origin 的取值。
     *
     * 白名单配置项：http.response.cors.whitelist（数组），每个元素是一个来源模式，
     * 支持 * 通配（如 `http://127.0.0.1:*`、`https://*.doffish.com`）。
     * - 请求不带 Origin 头：返回 null（不输出跨域响应头）。
     * - Origin 命中白名单任一模式：回显该 Origin（支持凭据场景，不用 *）。
     * - 未命中：返回 null。
     */
    private function resolveCorsOrigin(): ?string
    {
        $request = Context::getRequest();
        if ($request === null) {
            return null;
        }

        $whitelist = Config::get('http.response.cors.whitelist', []);
        if (! is_array($whitelist)) {
            $whitelist = [];
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return null;
        }

        foreach ($whitelist as $pattern) {
            if (is_string($pattern) && $this->matchCorsPattern($origin, $pattern)) {
                return $origin;
            }
        }

        return null;
    }

    // 通配匹配：将来源模式里的 * 当成 .* 做大小写不敏感正则匹配
    private function matchCorsPattern(string $origin, string $pattern): bool
    {
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $origin);
    }

    // 派遣路由执行控制器
    private function dispatchRoute(ServerRequestInterface $request): ResponseInterface
    {
        // 将请求保存到上下文
        Context::setRequest($request);

        // 是否自动接管预检(405处理器：虚拟路由)
        $autoOptions = Config::get('http.response.cors.auto-options', true);
        $onMismatch  = (! $autoOptions) ? null : Closure::fromCallable([$this, 'handleOptions']);

        // 派遣路由
        [$route, $params] = Route::dispatch($request, $onMismatch);

        // 路由已解析
        Event::dispatch(Events::REQUEST_ROUTE, ['request' => $request, 'route' => $route, 'params' => $params, 'kernel' => $this]);

        // 执行路由中间件（洋葱模型）
        $pipeline = clone $this->pipeline;
        $pipeline->middleware($route['middlewares']);

        $callableChain = $pipeline->makeChain(
            function ($request) use ($params, $route) {

                // 路由中间件可能用 withXxx() 派生出新请求（如 withAttribute 挂载身份），
                // 这里把链路末端的请求写回上下文，保证控制器/容器注入拿到的是最终请求
                Context::setRequest($request);

                // 控制器已解析，即将执行
                Event::dispatch(Events::REQUEST_CONTROLLER, ['request' => $request, 'route' => $route, 'kernel' => $this]);

                // 执行路由最终处理器
                return $this->callMethod($route['finalHandler'], $params);
            }
        );

        return $callableChain($request);
    }

    // 完成请求
    private function finish()
    {
        // 置当客户端断开连接时，脚本是否继续执行
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (session_status() == PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // 请求结束
        Event::dispatch(Events::REQUEST_FINISH, ['kernel' => $this]);
    }

    // 收尾函数
    public function terminate()
    {
        Event::dispatch(Events::KERNEL_TERMINATING, ['kernel' => $this]);
    }

    // 调用控制器方法
    private function callMethod($finalHandler, $params): ResponseInterface
    {
        // 获取一个调度器
        $invoker = Container::get(Invoker::class);

        // 执行路由绑定控制器
        $result = $invoker->invoke($finalHandler, $params);

        // 返回是 Response Factory
        if ($result instanceof ResponseFactoryInterface) {
            $result = $result->createResponse();
        }
        // 返回是 null
        elseif (is_null($result)) {

        }

        // 判断返回是否为 ResponseInterface
        if (! $result instanceof ResponseInterface) {
            throw new HandlerReturnInvalidException(
                sprintf('Route return value must implement ResponseInterface, [%s] given.', get_debug_type($result))
            );
        }

        return $result;
    }
}
