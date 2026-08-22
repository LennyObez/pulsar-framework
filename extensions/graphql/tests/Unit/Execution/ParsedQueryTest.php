<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Execution\ParsedField;
use Pulsar\Extension\Graphql\Execution\ParsedQuery;

final class ParsedQueryTest extends TestCase
{
    #[Test]
    public function constructsWithFieldsOnly(): void
    {
        $query = new ParsedQuery([new ParsedField('content')]);

        self::assertCount(1, $query->fields);
        self::assertSame([], $query->fragments);
    }

    #[Test]
    public function constructsWithFragments(): void
    {
        $fragments = [
            'ContentFields' => [new ParsedField('id'), new ParsedField('title')],
        ];
        $query = new ParsedQuery([new ParsedField('content')], $fragments);

        self::assertArrayHasKey('ContentFields', $query->fragments);
        self::assertCount(2, $query->fragments['ContentFields']);
    }
}
