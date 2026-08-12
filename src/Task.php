<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Exception\TaskNotCompletedException;
use Inilim\Parallel\Flow;
use Inilim\Tool\Assert;
use Inilim\Tool\Time;
use parallel\Future;

final class Task
{
    protected mixed $value;
    protected ?Future $future;
    protected \Throwable $exception;

    function __construct(
        public readonly string $key,
        Future $future,
        /**
         * @var \WeakReference<Flow>
         */
        protected \WeakReference $flow,
        protected ?\Closure $handler = null,
        protected ?\Closure $handlerError = null,
    ) {
        $this->future = $future;
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

    function flow(): ?Flow
    {
        return $this->flow->get();
    }

    function doneNative(): bool
    {
        return $this->future ? $this->future->done() : true;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
    function completed(): bool
    {
        if (null === $this->future) {
            return true;
        }

        if ($this->future->done()) {
            $this->extractValue();
            return true;
        }
        return false;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     * @throws \Throwable
     * @throws TaskNotCompletedException
     */
    function value(): mixed
    {
        if (null === $this->future) {
            if (isset($this->exception)) {
                throw $this->exception;
            }
            return $this->value;
        }

        if ($this->future->done()) {
            $this->extractValue();
            if (isset($this->exception)) {
                throw $this->exception;
            }
            return $this->value;
        }

        throw new TaskNotCompletedException;
    }

    /**
     * call after completed task, else always false
     */
    function hasError(): bool
    {
        return isset($this->exception);
    }

    function getError(): ?\Throwable
    {
        return isset($this->exception) ? $this->exception : null;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     * @throws TaskNotCompletedException
     */
    function removeFromFlow(): self
    {
        if (false === $this->completed()) {
            throw new TaskNotCompletedException;
        }
        $this->flow()?->removeByTask($this);
        return $this;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
    function wait(int $ms = 10): self
    {
        Assert::positiveInteger($ms);

        if (null === $this->future) {
            return $this;
        }

        while (!$this->future->done()) {
            Time::sleepMs($ms);
        }

        $this->extractValue();

        return $this;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
    function waitNative(): self
    {
        if (null === $this->future) {
            return $this;
        }
        $this->extractValue();
        return $this;
    }

    /**
     * @trigger handler
     * @trigger handlerError
     */
    protected function extractValue(): void
    {
        $hasValue = true;
        try {
            $value = $this->future->value();
        } catch (\Throwable $e) {
            $this->exception = $e;
            $value = null;
            $hasValue = false;
        }
        $this->future = null;
        $this->value = $value;

        if (false === $hasValue && null !== $this->handlerError) {
            ($this->handlerError)($this->exception, $this);
        } elseif (true === $hasValue && null !== $this->handler) {
            ($this->handler)($value, $this);
        }
    }
}
