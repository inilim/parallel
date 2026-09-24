<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Exception\TaskNotCompletedException;
use Inilim\Parallel\Task;
use Inilim\Tool\Assert;
use Inilim\Tool\ID;
use parallel\Future;
use parallel\Runtime;

/**
 * 
 */
class Flow implements \Inilim\Parallel\ExecuteInterface, \IteratorAggregate, \Countable
{
    protected Runtime $runtime;
    /**
     * @var array<string,Task>
     */
    protected array $tasks = [];
    protected bool $firstRun = false;
    protected bool $boot = false;
    protected Future $futureBoot;
    protected ?\Closure $handler = null;
    protected ?\Closure $handlerError = null;

    /**
     * @var ?array{0:\Closure,1:int}
     */
    protected ?array $handlerCountTask = null;

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
     * @param ?callable(Flow,int):void $callback
     */
    function setHandlerCountTask(?callable $callback, int $count = 10): self
    {
        if (null !== $callback) {
            Assert::positiveInteger($count);
            $this->handlerCountTask = [\Closure::fromCallable($callback), $count];
        } else {
            $this->handlerCountTask = null;
        }
        return $this;
    }

    /**
     * @param ?callable(mixed,Task):void $callback
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
     * @param ?callable(\Throwable,Task):void $callback
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
        return \array_values($this->tasks);
    }

    /**
     * @trigger handler
     * @trigger handlerError
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
     * @trigger handler
     * @trigger handlerError
     * @return Task[]
     */
    function getCompletedTasksAsArray(): array
    {
        return \iterator_to_array($this->getCompletedTasksAsIterator());
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
    function removeCompletedTasks(): self
    {
        if ([] === $this->tasks) {
            return $this;
        }
        $tasks = &$this->tasks;
        foreach ($tasks as $key => $task) {
            if ($task->completed()) {
                unset($tasks[$key]);
            }
        }
        return $this;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
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

    /**
     * @trigger handler
     * @trigger handlerError
     */
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

    /**
     */
    function boot(\Closure $boot, mixed ...$args): self
    {
        if (true === $this->firstRun || true === $this->boot) {
            throw new \LogicException;
        }

        $this->boot = true;
        $this->futureBoot = [] === $args
            ? $this->runtime->run($boot)
            : $this->runtime->run($boot, $args);
        return $this;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
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

    /**
     * @trigger handler
     * @trigger handlerError
     * @throws TaskNotCompletedException
     */
    function removeByTask(Task $task): self
    {
        if ($task->completed()) {
            unset($this->tasks[$task->key]);
        } else {
            throw new TaskNotCompletedException;
        }
        return $this;
    }

    /**
     * @trigger handlerCountTask
     */
    function execAndGetTask(\Closure $callback, mixed ...$args): Task
    {
        if (false === $this->firstRun) {
            $this->firstRun = true;
        }
        $future = $this->runtime->run(self::$wrapTask, [$callback, $args]);
        $task = new Task(
            $key = ID::uuidv4(),
            $future,
            $this->flow,
            $this->handler,
            $this->handlerError,
        );
        $this->tasks[$key] = $task;

        // handlerCountTask start
        if (null !== $this->handlerCountTask && ($count = \count($this->tasks)) >= $this->handlerCountTask[1]) {
            $this->handlerCountTask[0]($this, $count);
        }
        // handlerCountTask end

        return $task;
    }

    /**
     * @trigger handlerCountTask
     */
    function exec(\Closure $callback, mixed ...$args): self
    {
        $this->execAndGetTask($callback, ...$args);
        return $this;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
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
