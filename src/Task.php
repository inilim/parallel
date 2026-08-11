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

    function flow(): ?Flow
    {
        return $this->flow->get();
    }

    function done(): bool
    {
        return $this->completed();
    }

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
     * @throws \Throwable
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

    function hasError(): bool
    {
        if (!$this->completed()) {
            return false;
        }

        return isset($this->exception);
    }

    function getError(): ?\Throwable
    {
        return isset($this->exception) ? $this->exception : null;
    }

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

    function waitNative(): self
    {
        if (null === $this->future) {
            return $this;
        }
        $this->extractValue();
        return $this;
    }

    protected function extractValue(): void
    {
        $hasValue = true;
        try {
            $value = $this->future->value();
        } catch (\Throwable $e) {
            $this->exception = $e;
            $value = null;
            $hasValue = false;
            if (null !== $this->handlerError) {
                ($this->handlerError)($e);
            }
        }
        $this->future = null;
        $this->value = $value;
        if ($hasValue && null !== $this->handler) {
            ($this->handler)($value);
        }
    }
}
