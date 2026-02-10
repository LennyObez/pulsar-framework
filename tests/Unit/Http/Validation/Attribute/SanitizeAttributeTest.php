<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Attribute\Sanitize;

#[CoversClass(Sanitize::class)]
final class SanitizeAttributeTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $sanitize = new Sanitize(
            filter: 'Pulsar\\Http\\Validation\\Filter\\Trim',
            parameters: ['chars' => ' '],
        );

        self::assertSame('Pulsar\\Http\\Validation\\Filter\\Trim', $sanitize->filter);
        self::assertSame(['chars' => ' '], $sanitize->parameters);
    }

    #[Test]
    public function defaultsToEmptyParameters(): void
    {
        $sanitize = new Sanitize(filter: 'Pulsar\\Http\\Validation\\Filter\\Trim');

        self::assertSame([], $sanitize->parameters);
    }
}
