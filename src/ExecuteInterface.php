<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Task;

interface ExecuteInterface
{
    function execAndGetTask(\Closure $callback, mixed ...$args): Task;
    function exec(\Closure $callback, mixed ...$args): self;
}
