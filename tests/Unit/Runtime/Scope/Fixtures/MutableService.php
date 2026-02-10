<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope\Fixtures;

final class MutableService
{
    public string $state = 'initial';
    private int $counter = 0;

    public function getState(): string
    {
        return $this->state;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }
}
