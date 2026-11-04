<?php
/**
 * SharkyPHP Foundation Library
 *
 * 框架核心
 *
 * @package SharkyPHP\\Foundation
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Foundation;

use InvalidArgumentException;
use Sharky\Container\Container as RealContainer;
use Sharky\Contracts\ContextStoreInterface;
use Sharky\Event\Events;
use Sharky\Facade\Clock;
use Sharky\Facade\Config;
use Sharky\Facade\Container as ContainerFacade;
use Sharky\Facade\Event;
use Sharky\Foundation\Exceptions\FoundationException;
use Sharky\Foundation\Runtime\Storage\ProcessStorage;
use Sharky\Log\Handler\StreamHandler;
use Sharky\Log\Logger;
use Sharky\Log\LogLevel;
use Sharky\Support\Helper;
use Throwable;

class Application
{
    public const AUTHOR     = 'ByteSharky';
    public const PROJECT    = 'SharkyPHP';
    public const VERSION    = '2.0.0';
    public const COPYRIGHT  = "Copyright \xC2\xA9 2024-%s " . self::AUTHOR;
    public const POWERED_BY = self::PROJECT . "/" . self::VERSION;

    private array $packages     = [];
    private array $consoleScans = [];
    private string $directory   = '';
    private string $storage     = '';
    private RealContainer $container;
    private ExceptionHandler $exHandler;
    private CandidateHandler $candidate;

    public function __construct(string $directory)
    {
        // 关闭错误显示
        // 开启报告所有类型错误
        ini_set('display_errors', 0);
        ini_set('error_reporting', E_ALL);

        // 注册全局异常处理
        $this->exHandler = new ExceptionHandler(debug: true);

        // 注册 header 发送回调
        $xPoweredBy = self::POWERED_BY;
        header_register_callback(static function () use ($xPoweredBy) {
            header_remove("X-Powered-By");
            header("X-Powered-By: {$xPoweredBy}");
        });

        // 设置 APP 目录
        $realPath = Helper::realath($directory);
        if (! $realPath || ! is_dir($realPath)) {
            throw new FoundationException(sprintf('Invalid application root path.'));
        }
        $this->directory = $realPath;

        // 注入项目根目录（Support\Helper::base_path 使用，避免 Support 反向依赖容器门面）
        Helper::setBasePath($realPath);

        // 默认上下文存储
        $this->storage = ProcessStorage::class;

        // 初始化容器/门面/只读代理容器
        $this->container = new RealContainer();
        $containerProxy  = new ContainerProxy($this->container);
        ContainerFacade::setInstance($containerProxy);

        // 记录 project.directory
        $this->container->singleton('project.directory', static function () use ($realPath) {return $realPath;});

        // 默认日志实例：StreamHandler 落 runtime/logs/app.log，
        // 可用 configs/log.php（path / level / channel）覆盖，配置在首次解析时才读取
        $this->container->singleton(Logger::class, static function () {
            $level = (string) Config::get('log.level', LogLevel::DEBUG);
            $path  = (string) Config::get('log.path', Helper::base_path('runtime/logs/app.log'));
            $dir   = dirname($path);

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            return new Logger(
                new StreamHandler($path, $level),
                (string) Config::get('log.channel', 'app'),
                $level
            );
        });
    }

    // 一键启动
    public static function launch(string $directory): self
    {
        return new self($directory);
    }

    // 加载子包 bindings 配置
    public function packages($packages): self
    {
        $this->packages = $packages;
        return $this;
    }

    // 获取已加载的子包
    public function getPackages(): array
    {
        $componentList = [];
        foreach ($this->packages as $pkgName => $pkgMeta) {
            if (! str_starts_with($pkgName, 'sharkyphp/')) {
                continue;
            }
            $componentList[] = $pkgName;
        }

        return $componentList;
    }

    // 启动 APP
    public function bootstrap(): self
    {
        // 加载环境变量
        Helper::loadEnv("{$this->directory}/.env");

        // 加载组件
        $this->candidate = new CandidateHandler();
        $this->loadComponents();
        $this->loadFoundation();
        $this->loadBindings();
        $this->confirm();

        // 加载系统配置
        Config::loadConfigs("{$this->directory}/configs");

        // 异常处理跟随 config.debug（构造阶段配置尚未加载，这里补一次同步，
        // 否则生产环境也会把堆栈/源码渲染到页面上）
        $this->exHandler->setDebug((bool) Config::get('config.debug', false));

        // 设置时区
        $timezoneId = Config::get('config.timezone', 'Asia/Shanghai');
        date_default_timezone_set($timezoneId);

        // 启动时为所有 Model 统一绑定默认数据库连接。
        // 不写死节点名：取 database 配置 hosts 下的第一个节点作为默认连接，
        // 各入口（HTTP / Console）都走这里，无需重复手写注入。
        $database = $this->container->get(\Sharky\Database\Database::class);
        \Sharky\Database\Model::setConnection($database->connect());

        // 标记启动完成
        $this->container->singleton('foundation.started', static fn() => true);

        // 应用启动完成事件（此时所有监听器已注册，可被监听）
        Event::dispatch(Events::APP_START, ['app' => $this]);

        return $this;
    }

    // 设置上下文存储桶
    public function setContext(string $storage): self
    {
        if (is_a($storage, ContextStoreInterface::class, true)) {
            $this->storage = $storage;
        } else {
            throw new InvalidArgumentException(
                sprintf('Context storage must implement %s', ContextStoreInterface::class)
            );
        }
        return $this;
    }

    // 加载组件
    private function loadComponents(): void
    {
        $components = [];
        foreach ($this->packages as $pkgName => $pkgMeta) {
            try {
                $install_path = $pkgMeta['install_path'] . '\\';
                $composer     = Helper::realath("{$install_path}composer.json");

                $composerJson = json_decode(file_get_contents($composer), true);
                $cfgFile      = $composerJson["extra"]["sharky-config"] ?? '';
                if ($cfgFile === '') {continue;}
                $config = (static fn($file) => require $file)(Helper::realath("{$install_path}{$cfgFile}"));

                $config['priority'] = $config['priority'] ?? 100;

                // 收集包内声明的命令注解扫描目录（命令自动暴露）
                $this->collectConsoleScans($config);

                $components[$pkgName] = $config;

            } catch (Throwable $th) {
                throw new FoundationException(sprintf("Package [%s] is corrupted. Please try reinstalling it.", $pkgName));
            }
        }

        foreach ($components as $pkgName => $propertys) {
            $this->candidate->append($pkgName, $propertys);
        }
    }

    /**
     * 收集子包声明的命令注解扫描目录
     *
     * 包的 sharky-config.php 可以用 console.scan 段贡献自己的命令扫描目录，
     * 与项目 configs/console.php 的 commands.scan 合并，实现包内命令自动暴露：
     *   'console' => ['scan' => ['Sharky\\Skeleton\\Toolkits' => __DIR__ . '/src/Toolkits']]
     */
    private function collectConsoleScans(array $config): void
    {
        $scan = $config['console']['scan'] ?? [];

        if (! is_array($scan)) {return;}

        foreach ($scan as $namespace => $directory) {
            $namespace = trim((string) $namespace, '\\');

            if ($namespace === '') {continue;}

            foreach ((array) $directory as $dir) {
                if (is_string($dir) && $dir !== '') {
                    $this->consoleScans[$namespace][] = $dir;
                }
            }
        }
    }

    // 绑定核心服务
    private function loadFoundation()
    {
        $container = $this->container;
        $directory = $this->directory;

        $propertys = require Helper::realath(__DIR__ . '/../sharky-foundation.php');

        // 覆盖子包的绑定
        $this->candidate->overlay('foundation', $propertys);
    }

    // 加载用户绑定表
    private function loadBindings(): void
    {
        $file = Helper::realath("{$this->directory}/configs/service.php");
        if (! is_file($file)) {return;}
        $service = (static fn($file) => require $file)($file);

        // 项目级服务绑定
        $bindings = $service['bindings'] ?? [];
        $this->container->batch($bindings, false);

        // 项目级事件监听器
        $listeners = $service['listeners'] ?? [];
        foreach ($listeners as $eventName => $listener) {
            Event::listen($eventName, $listener);
        }
    }

    // 完成绑定/命令注册/事件注册
    private function confirm(): void
    {
        // 获取全部搜集的绑定
        $propertys = $this->candidate->validate()->all();

        $bindings  = $propertys['bindings'] ?? [];
        $listeners = $propertys['listeners'] ?? [];
        $commands  = $propertys['commands'] ?? [];

        // 绑定预搜集的实现
        $this->container->batch($bindings, false);

        // 暴露子包声明的命令行（ConsoleKernel 启动时注册）
        $this->container->singleton('foundation.commands', static fn() => $commands);

        // 暴露子包声明的命令扫描目录（Console 注解扫描时与之合并）
        $this->container->singleton('foundation.console.scans', fn() => $this->consoleScans);

        // 注册事件监听器（按事件名，支持包级叠加）
        foreach ($listeners as $eventName => $entry) {
            foreach ($this->normalizeListeners($entry) as $listener) {
                if ($listener !== null) {
                    Event::listen($eventName, $listener);
                }
            }
        }

        // 绑定上下文存储
        $this->container->singleton(ContextStoreInterface::class, $this->storage);

    }

    /**
     * 将候选监听器配置规范化为监听器列表
     *
     * 支持以下写法（包级 sharky-config 的 listeners 段）：
     *   'event.name' => \App\Listeners\X::class
     *   'event.name' => ['listener' => \App\Listeners\X::class]
     *   'event.name' => ['listener' => [\App\Listeners\X::class, \App\Listeners\Y::class]]
     */
    private function normalizeListeners(mixed $entry): array
    {

        if (! is_array($entry)) {
            return [$entry];
        }

        if (array_key_exists('listener', $entry)) {
            $listener = $entry['listener'];
            return (is_array($listener) && array_is_list($listener)) ? $listener : [$listener];
        }

        return [];
    }

    public function __debugInfo(): array
    {
        return [
            'project'    => self::PROJECT,
            'version'    => self::VERSION,
            'author'     => self::AUTHOR,
            'copyright'  => sprintf(self::COPYRIGHT, Clock::now()->format('Y')),
            'powered_by' => self::POWERED_BY,
            'directory'  => $this->directory,
            'packages'   => $this->getPackages(),
        ];
    }
}
