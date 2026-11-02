<?php
/**
 * SharkyPHP Foundation Library
 *
 * 容器代理
 * 不允许外部直接操作容器
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

use Psr\Container\ContainerInterface;

class ContainerProxy implements ContainerInterface
{
    public function __construct(
        protected ContainerInterface $innerContainer
    ){}

    public function get(string $id): mixed
    {
        return $this->innerContainer->get($id);
    }

    public function has(string $id): bool
    {
        return $this->innerContainer->has($id);
    }
}
