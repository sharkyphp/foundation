<?php


declare (strict_types = 1);

use Sharky\Event\EventDispatcher;
use Sharky\Event\EventManager;
use Sharky\Event\ListenerProvider;
use Sharky\Foundation\ContainerProxy;
use Sharky\Facade\Config;
use Sharky\Facade\Context;

// 监听器提供者 + PSR-14 调度器 + 管理器（三者共享同一提供者实例）
$containerProxy  = new ContainerProxy($container);
$eventProvider   = new ListenerProvider($containerProxy);
$eventDispatcher = new EventDispatcher($eventProvider);

return [
    'bindings' => [

        // 配置管理器
        \Sharky\Foundation\ConfigManager::class              => [
            'shared' => true,
        ],

        // Redis管理器
        // Cache / Session 的 Redis 驱动复用同一实例
        \Sharky\Redis\Redis::class                           => [
            'concrete' => static function () {
                return new \Sharky\Redis\Redis(Config::get('redis.default', []));
            },
            'shared'   => true,
        ],

        // 数据库管理器
        \Sharky\Database\Database::class                     => [
            'concrete' => static function () {
                return new \Sharky\Database\Database(Config::get('database.default', []));
            },
            'shared'   => true,
        ],

        // 缓存管理器
        \Sharky\Cache\Cache::class                           => [
            'concrete' => static function () use ($container) {
                return new \Sharky\Cache\Cache(
                    Config::get('config.cache', []),
                    $container->get(\Sharky\Redis\Redis::class)
                );
            },
            'shared'   => true,
        ],

        // 会话管理器
        \Sharky\Session\Session::class                       => [
            'concrete' => static function () use ($container) {
                return new \Sharky\Session\Session(
                    Config::get('config.session', []),
                    $container->get(\Sharky\Redis\Redis::class)
                );
            },
            'shared'   => true,
        ],

        // HttpKernel
        \Sharky\Foundation\Kernel\HttpKernel::class                       => [
            'concrete' => static function () use ($directory) {
                return new \Sharky\Foundation\Kernel\HttpKernel($directory);
            },
            'shared'   => true,
        ],

        // ConsoleKernel
        \Sharky\Foundation\Kernel\ConsoleKernel::class                 => [
            'concrete' => static function () use ($directory) {
                return new \Sharky\Foundation\Kernel\ConsoleKernel($directory);
            },
            'shared'   => true,
        ],

        // 当前上下文请求
        \Psr\Http\Message\ServerRequestInterface::class      => [
            'concrete' => static fn() => Context::getRequest(),
            'shared'   => false,
        ],

        // 当前上下文命令行输入
        \Sharky\Console\Context\Input::class                   => [
            'concrete' => static fn() => Context::getInput(),
            'shared'   => false,
        ],

        // 只读容器代理
        \Psr\Container\ContainerInterface::class             => [
            'concrete' => static fn() => new ContainerProxy($container),
            'shared'   => true,
        ],

        \Psr\EventDispatcher\EventDispatcherInterface::class => [
            'concrete' => static fn() => $eventDispatcher,
            'shared'   => true,
        ],

        \Sharky\Event\ListenerProvider::class                => [
            'concrete' => static fn() => $eventProvider,
            'shared'   => true,
        ],

        \Sharky\Event\EventManager::class                    => [
            'concrete' => static fn() => new EventManager($eventDispatcher, $eventProvider),
            'shared'   => true,
        ],

    ],
];
