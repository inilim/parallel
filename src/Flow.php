<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Task;
use Inilim\Tool\Assert;
use parallel\Future;
use parallel\Runtime;

/**
 * 
 */
class Flow implements \Inilim\Parallel\ExecuteInterface, \IteratorAggregate, \Countable
{
    protected Runtime $runtime;
    /**
     * @var Task[]
     */
    protected array $tasks = [];
    protected bool $firstRun = false;
    protected Future $futureBoot;
    protected ?\Closure $handler = null;
    protected ?\Closure $handlerError = null;
    /**
     * @var \WeakReference<Flow>
     */
    protected \WeakReference $flow;

    protected static ?\Closure $wrapTask = null;

    function __construct()
    {
        $this->runtime = new Runtime;
        $this->flow = \WeakReference::create($this);
        self::$wrapTask ??= static function (\Closure $task, array $args): mixed {
            return $task(...$args);

            // $fn = static function (mixed &$value): void {
            //     if ($value instanceof \parallel\Runtime\Object\Unavailable) {
            //         $value = new \Inilim\Parallel\BadReturnValue;
            //     }
            // };

            // if (\is_array($value)) {
            //     \array_walk_recursive($value, $fn);
            // } else {
            //     $fn($value);
            // }
        };
    }

    /**
     * @param ?callable(mixed) $callback
     */
    function setHandler(?callable $callback): self
    {
        if (null !== $callback) {
            $callback = \Closure::fromCallable($callback);
        }
        $this->handler = $callback;
        return $this;
    }

    /**
     * @param ?callable(\Throwable) $callback
     */
    function setHandlerError(?callable $callback): self
    {
        if (null !== $callback) {
            $callback = \Closure::fromCallable($callback);
        }
        $this->handlerError = $callback;
        return $this;
    }

    /**
     * @return \Generator<int,Task>
     */
    function getIterator(): \Generator
    {
        foreach ($this->tasks as $task) {
            yield $task;
        }
    }

    function count(): int
    {
        return \count($this->tasks);
    }

    /**
     * @return Task[]
     */
    function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * @return \Generator<int,Task>
     */
    function getCompletedTasksAsIterator(): \Generator
    {
        foreach ($this->tasks as $task) {
            if ($task->completed()) {
                yield $task;
            }
        }
    }

    /**
     * @return Task[]
     */
    function getCompletedTasksAsArray(): array
    {
        return \iterator_to_array($this->getCompletedTasksAsIterator());
    }

    /**
     * Удаляет из списка задач те, у которых результат уже извлечён (Future === null).
     * Совершенно безопасно, т.к. потоки завершены и ресурсы освобождены.
     */
    function removeCompletedTasks(): self
    {
        if ([] === $this->tasks) {
            return $this;
        }
        $tasks = &$this->tasks;
        foreach ($tasks as $idx => $task) {
            if ($task->completed()) {
                unset($tasks[$idx]);
            }
        }
        $tasks = \array_values($tasks);
        return $this;
    }

    function wait(int $ms = 10): self
    {
        Assert::positiveInteger($ms);

        if ([] === $this->tasks) {
            return $this;
        }

        foreach ($this->tasks as $task) {
            $task->wait($ms);
        }

        return $this;
    }

    function waitNative(): self
    {
        if ([] === $this->tasks) {
            return $this;
        }

        foreach ($this->tasks as $task) {
            $task->waitNative();
        }

        return $this;
    }

    function boot(\Closure $boot): self
    {
        if (true === $this->firstRun) {
            throw new \LogicException;
        }

        $this->futureBoot = $this->runtime->run($boot);
        return $this;
    }

    function completed(): bool
    {
        if ([] === $this->tasks) {
            return true;
        }

        foreach ($this->tasks as $task) {
            if (false === $task->completed()) {
                return false;
            }
        }

        return true;
    }

    function execAndGetTask(\Closure $callback, mixed ...$args): Task
    {
        if (false === $this->firstRun) {
            $this->firstRun = true;
        }
        $future = $this->runtime->run(self::$wrapTask, [$callback, $args]);
        $task = new Task(
            $future,
            $this->flow,
            null !== $this->handler ? $this->handler : null,
            null !== $this->handlerError ? $this->handlerError : null,
        );
        $this->tasks[] = $task;
        return $task;
    }

    function exec(\Closure $callback, mixed ...$args): self
    {
        $this->execAndGetTask($callback, ...$args);
        return $this;
    }

    function __destruct()
    {
        if (null !== $this->handler || null !== $this->handlerError) {
            $this->waitNative();
        }
        try {
            // INFO close ожидает выполнение всех задач
            $this->runtime->close();
        } catch (\Throwable) {
        }
    }
}
