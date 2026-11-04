<?php
/**
 * SharkyPHP Foundation Library
 *
 * 上下文管理器
 *
 * @package SharkyPHP\\Foundation
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Foundation\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use Sharky\Console\Context\Input;
use Sharky\Console\Context\Output;

class Context
{
    private array $values;
    private array $cancelCallbacks = [];
    private bool $cancelled        = false;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->set(ServerRequestInterface::class, $request);
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->get(ServerRequestInterface::class);
    }

    public function setInput(Input $argvInput)
    {
        $this->set(Input::class, $argvInput);
    }

    public function getInput()
    {
        return $this->get(Input::class);
    }

    public function setOutput(Output $output)
    {
        $this->set(Output::class, $output);
    }

    public function getOutput(): ?Output
    {
        return $this->get(Output::class);
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        foreach ($this->cancelCallbacks as $cb) {
            $cb();
        }
        $this->cancelCallbacks = [];
    }

    public function onCancel(callable $cb): void
    {
        if ($this->cancelled) {
            $cb();
            return;
        }
        $this->cancelCallbacks[] = $cb;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
