<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Flow;
use Inilim\Tool\Assert;

class PoolFlow implements \Inilim\Parallel\ExecuteInterface
{
    /**
     * @var Flow[]
     */
    protected array $flows = [];
    protected bool $boot = false;
    protected bool $firstRun = false;

    function __construct(
        protected int $count,
    ) {
        Assert::positiveInteger($count);

        for ($i = 0; $i < $count; $i++) {
            $this->flows[] = new Flow;
        }
    }

    function boot(\Closure $boot): self
    {
        if (true === $this->firstRun) {
            throw new \LogicException;
        }

        foreach ($this->flows as $flow) {
            $flow->boot($boot);
        }

        $this->boot = true;

        return $this;
    }

    function exec(\Closure $callback): self
    {
        if (false === $this->firstRun) {
            $this->firstRun = true;
        }

        return $this;
    }
}
