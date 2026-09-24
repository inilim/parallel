<?php

declare(strict_types=1);

namespace Inilim\Parallel;

use Inilim\Parallel\Task;
use Inilim\Tool\Assert;
use Inilim\Tool\Obj;
use Inilim\Tool\Time;

/**
 * Стратегия выдачи задачи любому свободному потоку с системой ожидания.
 * Реализуйте систему очистки стека выполненных задач
 */
class PoolFlowM2 extends \Inilim\Parallel\BasePoolFlow
{
    protected int $eachCycleCounter = 0;
    /**
     * @var array{0:int,1:int}
     */
    protected array $sleepCycle = [5, 10];

    function setSleepCycle(int $startMs, int $maxMs): self
    {
        Assert::positiveInteger($startMs);
        Assert::positiveInteger($maxMs);
        if ($startMs > $maxMs) {
            throw Obj::sprintfInvalidArgumentException('The value of $startMs cannot be greater than the value of $maxMs.');
        }
        $this->sleepCycle = [$startMs, $maxMs];
        return $this;
    }

    /**
     * @trigger handlerCountTask
     * @trigger eachCycleCallback
     */
    function execAndGetTask(\Closure $callback, mixed ...$args): Task
    {
        [$startMs, $maxMs] = $this->sleepCycle;
        $completed_flow = null;
        $eachCycleCounter = &$this->eachCycleCounter;
        $counterWhile = 1;
        $iter = &$this->iterator;
        $count = $this->count;

        while ($flow = $iter->current()) {
            $iter->next();

            if (!$flow->completed()) {
                if ($counterWhile >= $count) {
                    $counterWhile = 1;
                    Time::sleepMs($startMs);
                    if ($startMs < $maxMs) {
                        $startMs++;
                    }
                } else {
                    $counterWhile++;
                }
                continue;
            }

            $completed_flow = $flow;
            break;
        }

        $task = $completed_flow->execAndGetTask($callback, ...$args);

        if (null !== $this->eachCycleCallback) {
            $eachCycleCounter++;
            if ($eachCycleCounter >= $count) {
                $eachCycleCounter = 0;
                ($this->eachCycleCallback)($this);
            }
        }

        return $task;
    }
}
