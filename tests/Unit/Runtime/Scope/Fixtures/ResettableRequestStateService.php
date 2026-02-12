<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope\Fixtures;

final class ResettableRequestStateService
{
    private string $data = '';

    public function resetRequestState(): void
    {
        $this->data = '';
    }

    public function getData(): string
    {
        return $this->data;
    }
}
