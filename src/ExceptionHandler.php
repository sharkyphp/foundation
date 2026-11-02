<?php
/**
 * SharkyPHP Foundation Library
 *
 * 全局异常处理类
 *
 * @package SharkyPHP\\Foundation
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Foundation;

use ErrorException;
use Sharky\Event\Events;
use Sharky\Foundation\Application;
use Sharky\Facade\Context;
use Sharky\Facade\Event;
use Throwable;

final class ExceptionHandler
{
    private $debug            = false;
    private const SEVERITYMAP = [
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_STRICT            => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
    ];

    public function __construct(bool $debug)
    {
        // 是否开启调试模式
        $this->debug = $debug;

        // 1. 未捕获异常
        set_exception_handler(function (Throwable $e) {
            $this->fire(Events::EXCEPTION_THROW, ['exception' => $e]);
            $output = $this->format($e);
            $this->fire(Events::EXCEPTION_RENDER, ['exception' => $e, 'output' => $output]);
            $this->render($output);
            return true;
        });

        // 2. 普通错误 Notice/Warning
        set_error_handler(static function (int $errno, string $errstr = '', string $errfile = '', int $errline = 0, array $errcontext = []): bool {
            if (error_reporting() === 0) {
                return false;
            }
            throw new ErrorException($errstr, $errno, $errno, $errfile, $errline);
        });

        // 3. shutdown兜底致命错误
        register_shutdown_function(function () {
            $lastErr = error_get_last();
            if ($lastErr === null) {
                return;
            }

            $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
            if (($lastErr['type'] & $fatalMask) === 0) {
                return;
            }

            $ex = new ErrorException(
                $lastErr['message'],
                $lastErr['type'],
                $lastErr['type'],
                $lastErr['file'] ?? '',
                $lastErr['line'] ?? 0
            );
            $output = $this->format($ex);
            $this->fire(Events::EXCEPTION_THROW, ['exception' => $ex]);
            $this->fire(Events::EXCEPTION_RENDER, ['exception' => $ex, 'output' => $output]);
            $this->render($output);
            return true;
        });
    }

    // 开启关闭 Debug
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    // 安全地派发事件（事件分发异常不影响异常处理流程）
    private function fire(string $name, array $payload): void
    {
        try {
            Event::dispatch($name, $payload);
        } catch (Throwable) {
            // 忽略事件分发过程中的异常
        }
    }

    // 格式化异常信息
    private function format(Throwable $ex): array
    {
        $severityVal = $ex instanceof ErrorException ? $ex->getSeverity() : null;
        if ($severityVal !== null && isset(self::SEVERITYMAP[$severityVal])) {
            $severityText = self::SEVERITYMAP[$severityVal];
        } elseif ($severityVal !== null) {
            $severityText = (string) $severityVal;
        } else {
            $severityText = 'N/A';
        }

        return [
            'type'     => $this->getShortClassName($ex),
            'message'  => $ex->getMessage(),
            'errNo'    => $ex->getCode(),
            'file'     => $ex->getFile() ?: '[unknown file]',
            'line'     => $ex->getLine() ?: 0,
            'stack'    => $ex->getTraceAsString(),
            'severity' => $severityText,
        ];
    }

    // 获取类的短名
    private function getShortClassName(object $obj): string
    {
        $cls = get_class($obj);
        $seg = explode('\\', $cls);
        return end($seg);
    }

    // 输出错误页面
    private function render(array $output)
    {
        if (strtoupper(PHP_SAPI) === 'CLI') {
            $this->render_cli($output);
        } else {
            $this->render_web($output);
        }
    }

    // WEB 模式渲染错误页面
    private function render_web(array $output)
    {
        // 清空缓冲，以免之前的输出破坏错误页面
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $tempFile = $this->debug ? 'debug.html' : 'friendly.html';
        $tempPath = realpath(__DIR__ . "/../views/{$tempFile}");

        $template = $this->loadTemplate($tempPath);

        if ($template) {
            try {
                $request = Context::getRequest();
            } catch (Throwable) {$request = null;}
            $contentType = 'Content-Type: text/html; charset=utf-8';
            $data        = array_merge($output, [
                'project'   => Application::PROJECT,
                'version'   => Application::VERSION,
                'copyright' => sprintf(Application::COPYRIGHT, date('Y')),
                'method'    => $request ? $request->getMethod() : null,
                'path'      => $request ? $request->getUri()->getPath() : null,
            ]);

            $content = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($data) {
                $key   = $matches[1];
                $value = $data[$key] ?? '';
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            }, $template);
        } else {
            $contentType = 'Content-Type: application/json;charset=utf-8';
            if ($this->debug) {
                $content = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                $content = json_encode([
                    'message' => '服务器内部错误，请稍后再试',
                    'code'    => 500,
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        header($contentType);
        http_response_code(500);
        print($content);
        exit(255);
    }

    // CLI 模式错误输出控制台
    private function render_cli(array $output)
    {
        print("\n===============Exception===============\n");
        fprintf(STDERR, "   TYPE: %s\n", $output['type']);
        fprintf(STDERR, "   FILE: %s(%d)\n", $output['file'], $output['line']);
        fprintf(STDERR, "MESSAGE: %s\n", $output['message']);
        fprintf(STDERR, "  STACK:\n%s\n", $output['stack']);
        exit(255);
    }

    // 加载模板文件
    private function loadTemplate(string $path): string | bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }
        $html = file_get_contents($path);
        if ($html === false) {
            return false;
        }
        return $html;
    }
}
