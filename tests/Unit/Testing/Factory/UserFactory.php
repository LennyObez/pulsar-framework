<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Factory;

use Pulsar\Testing\Factory\Factory;

/**
 * @internal Test-only factory, shared by the Factory and FactoryMap tests.
 */
final class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    protected function definition(): array
    {
        return [
            'name' => 'John Doe',
            'email' => 'john@test.com',
            'role' => 'user',
        ];
    }
}
