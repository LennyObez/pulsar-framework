<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Monitor\PiiMasker;

#[CoversClass(PiiMasker::class)]
final class PiiMaskerTest extends TestCase
{
    #[Test]
    public function masksPiiColumns(): void
    {
        $masker = new PiiMasker(['email', 'ssn']);

        $result = $masker->mask([
            'email' => 'user@example.com',
            'ssn' => '123-45-6789',
            'name' => 'John',
        ]);

        self::assertSame('***MASKED***', $result['email']);
        self::assertSame('***MASKED***', $result['ssn']);
        self::assertSame('John', $result['name']);
    }

    #[Test]
    public function preservesNonPiiColumns(): void
    {
        $masker = new PiiMasker(['email']);

        $result = $masker->mask([
            'id' => 1,
            'status' => 'active',
        ]);

        self::assertSame(1, $result['id']);
        self::assertSame('active', $result['status']);
    }

    #[Test]
    public function handlesColonPrefixedKeys(): void
    {
        $masker = new PiiMasker(['email']);

        $result = $masker->mask([
            ':email' => 'user@example.com',
            ':name' => 'John',
        ]);

        self::assertSame('***MASKED***', $result[':email']);
        self::assertSame('John', $result[':name']);
    }

    #[Test]
    public function emptyPiiListPreservesAll(): void
    {
        $masker = new PiiMasker([]);

        $bindings = ['email' => 'user@example.com', 'name' => 'John'];
        $result = $masker->mask($bindings);

        self::assertSame($bindings, $result);
    }
}
