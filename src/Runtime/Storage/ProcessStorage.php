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

namespace Sharky\Foundation\Runtime\Storage;
use Sharky\Foundation\Runtime\Context;
use Sharky\Contracts\ContextStoreInterface;

class ProcessStorage implements ContextStoreInterface
{
    private ?Context $ctx = null;

    public function current(): Context
    {
        if (is_null($this->ctx)) {
            $this->ctx = new Context();
        }
        return $this->ctx;
    }

    public function clear(): void
    {
        $this->ctx = null;
    }
}
