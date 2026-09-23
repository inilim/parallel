<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Task;

class PoolFlow extends \Inilim\Parallel\BasePoolFlow
{
    /**
     * @trigger handlerCountTask
     * @trigger eachCycleCallback
     */
    function execAndGetTask(\Closure $callback, mixed ...$args): Task
    {
        $flow = $this->iterator->current();
        $idx = $this->iterator->key();
        $task = $flow->execAndGetTask($callback, ...$args);
        $this->iterator->next();

        if (($idx + 1) === $this->count && null !== $this->eachCycleCallback) {
            ($this->eachCycleCallback)($this);
        }

        return $task;
    }
}
