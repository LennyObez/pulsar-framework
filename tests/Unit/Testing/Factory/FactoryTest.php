<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Factory\Factory;
use Pulsar\Testing\Factory\Sequence;

use function sprintf;

#[CoversClass(Factory::class)]
final class FactoryTest extends TestCase
{
    #[Test]
    public function make_returns_default_attributes(): void
    {
        $result = UserFactory::new()->make();

        self::assertSame('John Doe', $result['name']);
        self::assertSame('john@test.com', $result['email']);
        self::assertSame('user', $result['role']);
    }

    #[Test]
    public function state_overrides_attributes(): void
    {
        $result = UserFactory::new()
            ->state(['role' => 'admin'])
            ->make();

        self::assertSame('admin', $result['role']);
        self::assertSame('John Doe', $result['name']);
    }

    #[Test]
    public function overrides_in_make_take_precedence(): void
    {
        $result = UserFactory::new()->make(['name' => 'Jane']);

        self::assertSame('Jane', $result['name']);
    }

    #[Test]
    public function count_creates_multiple_entries(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = UserFactory::new()->count(3)->make();

        self::assertCount(3, $result);
    }

    #[Test]
    public function sequence_generates_unique_values(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = UserFactory::new()
            ->sequence('email', new Sequence(
                static fn(int $i): string => sprintf('user-%d@test.com', $i),
            ))
            ->count(3)
            ->make();

        self::assertSame('user-0@test.com', $result[0]['email']);
        self::assertSame('user-1@test.com', $result[1]['email']);
        self::assertSame('user-2@test.com', $result[2]['email']);
    }

    #[Test]
    public function multiple_states_are_cumulative(): void
    {
        $result = UserFactory::new()
            ->state(['role' => 'admin'])
            ->state(['verified' => true])
            ->make();

        self::assertSame('admin', $result['role']);
        self::assertTrue($result['verified']);
    }

    #[Test]
    public function factory_is_immutable(): void
    {
        $base = UserFactory::new();
        $admin = $base->state(['role' => 'admin']);

        self::assertSame('user', $base->make()['role']);
        self::assertSame('admin', $admin->make()['role']);
    }
}
