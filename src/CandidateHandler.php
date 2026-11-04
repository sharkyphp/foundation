<?php
/**
 * SharkyPHP Foundation Library
 *
 * 绑定候选搜集
 *
 * @package SharkyPHP\\Foundation
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Foundation;

use Sharky\Foundation\Exceptions\FoundationException;

class CandidateHandler
{
    private array $bindings  = [];
    private array $commands  = [];
    private array $listeners = [];

    // 添加候选绑定
    public function append(string $package, array $propertys): self
    {
        $bindings  = $propertys['bindings'] ?? [];
        $commands  = $propertys['commands'] ?? [];
        $listeners = $propertys['listeners'] ?? [];

        foreach ($bindings as $abstract => $concrete) {
            $this->bindings[$abstract][] = ['package' => $package, ...$concrete];
        }

        foreach ($commands as $command => $concrete) {
            $this->commands[$command][] = ['package' => $package, ...$concrete];
        }

        foreach ($listeners as $event => $listener) {
            $this->listeners[$event][] = ['package' => $package, 'listener' => [ ...$listener]];
        }

        return $this;
    }

    // 替换候选绑定
    public function overlay(string $package, array $propertys): self
    {
        $bindings  = $propertys['bindings'] ?? [];
        $commands  = $propertys['commands'] ?? [];
        $listeners = $propertys['listeners'] ?? [];

        foreach ($bindings as $abstract => $concrete) {
            $this->bindings[$abstract] = [['package' => $package, ...$concrete]];
        }

        foreach ($commands as $command => $concrete) {
            $this->commands[$command] = [['package' => $package, ...$concrete]];
        }

        foreach ($listeners as $event => $listener) {
            $this->listeners[$event] = [['package' => $package, ...$listener]];
        }

        return $this;
    }

    // 校验冲突
    public function validate(): self
    {
        // 统一校验冲突实现
        $checkConflict = function (string $name, array $candidateList, string $errorType) {
            if (count($candidateList) !== 1) {
                $pkgs = array_column($candidateList, 'package');
                throw new FoundationException(sprintf(
                    "%s has conflicting %s. Specify the target implementation in your config.\n Packages [%s]",
                    $name,
                    $errorType,
                    implode(',', $pkgs)
                ));
            }
        };

        foreach ($this->bindings as $abstract => $candidate) {
            $checkConflict($abstract, $candidate, 'implementations');
        }

        foreach ($this->commands as $command => $candidate) {
            $checkConflict($command, $candidate, 'commands');
        }

        return $this;
    }

    // 返回全部收集好的候选数据
    public function all(): array
    {
        $extractFirst = fn($value) => $value[0];

        return [
            'bindings'  => array_map($extractFirst, $this->bindings),
            'commands'  => array_map($extractFirst, $this->commands),
            'listeners' => array_map($extractFirst, $this->listeners),
        ];
    }

    // 清空候选列表
    public function clear(): self
    {
        $this->bindings  = [];
        $this->commands  = [];
        $this->listeners = [];
        return $this;
    }
}
