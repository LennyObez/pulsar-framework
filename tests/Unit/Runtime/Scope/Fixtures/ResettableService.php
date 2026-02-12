<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope\Fixtures;

final class ResettableService
{
    private string $data = '';

    public function reset(): void
    {
        $this->data = '';
    }

    public function getData(): string
    {
        return $this->data;
    }
}
