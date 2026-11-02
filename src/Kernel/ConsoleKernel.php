<?php
/**
 * SharkyPHP Foundation Library
 *
 * CLI 命令行模式核心
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
use RuntimeException;
use Sharky\Console\Context\Input;
use Sharky\Event\Events;
use Sharky\Invoker\Invoker;
use Sharky\Pipeline\Pipeline;
use Sharky\Facade\Command;
use Sharky\Facade\Config;
use Sharky\Facade\Container;
use Sharky\Facade\Context;
use Sharky\Facade\Event;
use Sharky\Support\Helper;
use Throwable;

class ConsoleKernel
{
    private Pipeline $pipeline;
    private Context $context;

    // 初始化
    public function __construct(
        private string $directory
    ) {
        // 初始化中间件管道
        $this->pipeline = Container::get(Pipeline::class);
    }

    // 启动 Console 核心
    public static function launch(): self
    {
        // 启动 Console 核心
        $kernel = Container::get(self::class);

        $started = Container::get('foundation.started');

        if (! $started) {
            throw new RuntimeException('Application not booted. Please complete the application bootstrap process first.');
        }

        // 内核启动中
        Event::dispatch(Events::KERNEL_BOOTING, ['kernel' => $kernel]);

        // 加载环境变量
        Helper::loadEnv("{$kernel->directory}/.env.cli");

        // 注册子包声明的命令行（框架脚手架命令等）
        $kernel->loadPackageCommands();

        // 加载命令（生产环境优先读缓存，否则声明文件 + 注解扫描）
        Command::loadCommands("{$kernel->directory}/app/Console/Definitions");

        // 内核已启动
        Event::dispatch(Events::KERNEL_BOOTED, ['kernel' => $kernel]);

        return $kernel;
    }

    // 处理 Console 命令
    public function handleConsole(array $argv): void
    {
        $argvInput = new Input($argv);

        try {

            // 命令行已接收
            Event::dispatch(Events::CONSOLE_RECEIVE, ['input' => $argvInput, 'kernel' => $this]);

            // 这里装载全局中间件
            $pipeline   = clone $this->pipeline;
            $middleware = Config::get('middleware.console', []);
            $pipeline->middleware($middleware);

            // 执行中间件链
            $dispatchCommand = Closure::fromCallable([$this, 'dispatchCommand']);
            $callableChain   = $pipeline->makeChain($dispatchCommand);
            $result          = $callableChain($argvInput);

        } catch (Throwable $e) {
            // 命令执行异常
            Event::dispatch(Events::CONSOLE_ERROR, ['input' => $argvInput, 'exception' => $e, 'kernel' => $this]);

            throw $e;
        }
    }

    public function dispatchCommand(Input $argvInput): void
    {
        Context::setInput($argvInput);

        $command = Command::match($argvInput);

        // 命令已匹配 / 即将执行
        Event::dispatch(Events::CONSOLE_MATCH, ['input' => $argvInput, 'command' => $command, 'kernel' => $this]);

        // 执行路由中间件（洋葱模型）
        $pipeline = clone $this->pipeline;
        $pipeline->middleware($command['middlewares']);

        $callableChain = $pipeline->makeChain(
            function ($argvInput) use ($command) {

                // 执行命令行
                Event::dispatch(Events::CONSOLE_START, ['input' => $argvInput, 'command' => $command, 'kernel' => $this]);

                // 执行路由最终处理器
                return $this->callMethod($command['finalHandler'], []);
            }
        );

        $callableChain($argvInput);

        // 命令执行完成
        Event::dispatch(Events::CONSOLE_FINISH, ['input' => $argvInput, 'command' => $command, 'kernel' => $this]);
    }

    // 收尾函数
    public function terminate(): void
    {
        // 内核终止
        Event::dispatch(Events::KERNEL_TERMINATING, ['kernel' => $this]);
    }

    // 调用命令方法
    private function callMethod($finalHandler, $params): void
    {
        // 获取一个调度器
        $invoker = Container::get(Invoker::class);

        // 执行路由绑定控制器
        $result = $invoker->invoke($finalHandler, $params);
    }

    /**
     * 注册子包在 sharky-config.php 中声明的命令行
     *
     * 这些命令来自包配置（如 Skeleton 的 route:compile / command:compile），
     * 在加载命令声明文件之前注册，因此生产环境读缓存时也始终可用。
     */
    private function loadPackageCommands(): void
    {
        try {
            $commands = Container::get('foundation.commands');
        } catch (Throwable) {
            return;
        }

        foreach ((array) $commands as $name => $entry) {
            $handler = $entry['handler'] ?? null;

            if ($handler === null) {continue;}

            Command::register((string) $name, $handler, (string) ($entry['desc'] ?? ''));
        }
    }

}
