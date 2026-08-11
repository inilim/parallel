<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Flow;
use Inilim\Parallel\Task;
use Inilim\Tool\Assert;

class PoolFlow implements \Inilim\Parallel\ExecuteInterface, \IteratorAggregate, \Countable
{
    /**
     * @var Flow[]
     */
    protected array $flows = [];
    protected bool $firstRun = false;
    /**
     * @var \Generator<int,Flow>
     */
    protected \Generator $iterator;
    protected ?\Closure $eachCycleCallback = null;

    function __construct(
        protected int $count,
    ) {
        Assert::positiveInteger($count);

        for ($i = 0; $i < $count; $i++) {
            $this->flows[] = new Flow;
        }

        $this->iterator = $this->infiniteIterator();
    }

    /**
     * @param ?callable(PoolFlow):void $callback
     */
    function setEachCycleCallback(?callable $callback): self
    {
        if (null !== $callback) {
            $callback = \Closure::fromCallable($callback);
        }
        $this->eachCycleCallback = $callback;
        return $this;
    }

    /**
     * @param ?callable(mixed) $callback
     */
    function setHandler(?callable $callback): self
    {
        foreach ($this->flows as $flow) {
            $flow->setHandler($callback);
        }
        return $this;
    }

    /**
     * @param ?callable(\Throwable) $callback
     */
    function setHandlerError(?callable $callback): self
    {
        foreach ($this->flows as $flow) {
            $flow->setHandlerError($callback);
        }
        return $this;
    }

    function wait(int $ms = 10): self
    {
        Assert::positiveInteger($ms);
        foreach ($this->flows as $flow) {
            $flow->wait($ms);
        }
        return $this;
    }

    function waitNative(): self
    {
        foreach ($this->flows as $flow) {
            $flow->waitNative();
        }
        return $this;
    }

    /**
     * @return \Generator<int,Flow>
     */
    function getIterator(): \Generator
    {
        foreach ($this->flows as $flow) {
            yield $flow;
        }
    }

    /**
     * @return \Generator<int,Task>
     */
    function getTasksAsIterator(): \Generator
    {
        foreach ($this->flows as $flow) {
            yield from $flow;
        }
    }

    function countTasks(): int
    {
        $count = 0;
        foreach ($this->flows as $flow) {
            $count += \count($flow);
        }
        return $count;
    }

    function count(): int
    {
        return $this->count;
    }

    function removeCompletedTasks(): self
    {
        foreach ($this->flows as $flow) {
            $flow->removeCompletedTasks();
        }
        return $this;
    }

    function boot(\Closure $boot): self
    {
        if (true === $this->firstRun) {
            throw new \LogicException;
        }

        foreach ($this->flows as $flow) {
            $flow->boot($boot);
        }

        return $this;
    }

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

    function exec(\Closure $callback, mixed ...$args): self
    {
        $this->execAndGetTask($callback, ...$args);
        return $this;
    }

    /**
     * @return \Generator<int,Flow>
     */
    protected function infiniteIterator(): \Generator
    {
        while (true) {
            foreach ($this->flows as $idx => $flow) {
                yield $idx => $flow;
            }
        }
    }
}
