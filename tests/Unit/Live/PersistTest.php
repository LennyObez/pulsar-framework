<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Persist;

#[CoversClass(Persist::class)]
final class PersistTest extends TestCase
{
    #[Test]
    public function defaultKeyIsEmpty(): void
    {
        $persist = new Persist();

        self::assertSame('', $persist->key);
    }

    #[Test]
    public function customKey(): void
    {
        $persist = new Persist(key: 'sidebar:collapsed');

        self::assertSame('sidebar:collapsed', $persist->key);
    }
}
