<?php


declare (strict_types = 1);

namespace Sharky\Foundation\Runtime;

class TaskRunner
{
    public static function run(callable $task)
    {
        Context::enter();
        try {
            return $task();
        } finally {
            Context::leave();
        }
    }
}
