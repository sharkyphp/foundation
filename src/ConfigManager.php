<?php
/**
 * SharkyPHP Foundation Library
 *
 * 配置管理器
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

use Sharky\Foundation\Exceptions\ConfigNotFoundException;
use Sharky\Support\ArrayUtils;
use Sharky\Support\Helper;

final class ConfigManager
{
    private $configs = [];
    private $files   = [];

    // 加载配置目录
    public function loadConfigs(string $directory = null): static
    {
        if (! is_dir($directory)) {
            throw new ConfigNotFoundException(sprintf("Config directory '%s' does not exist", $directory));
        }
        $files = scandir($directory);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $rootKey               = pathinfo($file, PATHINFO_FILENAME);
                $this->files[$rootKey] = Helper::realath("{$directory}/{$file}");
            }
        }
        return $this;
    }

    // 加载配置文件
    private function loadFile(string $file): static
    {
        if (! is_readable($file)) {
            throw new ConfigNotFoundException(sprintf("Config file '%s' does not exist", $file));
        }
        $rootKey       = pathinfo($file, PATHINFO_FILENAME);
        $configs       = require $file;
        $this->configs = ArrayUtils::deepMerge($this->configs, [$rootKey => $configs]);
        return $this;
    }

    // 根据点分字符串读取配置值
    public function get(string $path, mixed $default = null): mixed
    {
        $paths = explode('.', $path);
        if (! array_key_exists($paths[0], $this->configs)) {
            if (! array_key_exists($paths[0], $this->files)) {
                return $default;
            }
            $file = $this->files[$paths[0]];
            $this->loadFile($file);
        }
        return ArrayUtils::get($this->configs, $path, $default);
    }

    // 根据点分字符串读取配置值
    public function has(string $path): bool
    {
        $paths = explode('.', $path);
        if (! array_key_exists($paths[0], $this->configs)) {
            if (! array_key_exists($paths[0], $this->files)) {
                return false;
            }
            $file = $this->files[$paths[0]];
            $this->loadFile($file);
        }
        return ArrayUtils::has($this->configs, $path);
    }
}
