<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Task;

/**
 * Старатегия выдачи задач по очереди, без системы ожидания.
 * Реализуйте систему ожидания, иначе будет переполнение стека задач.
 * Реализуйте систему очистки стека выполненных задач
 */
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
