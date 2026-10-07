<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Execution\ParsedField;

final class ParsedFieldTest extends TestCase
{
    #[Test]
    public function responseKeyReturnsNameWhenNoAlias(): void
    {
        $field = new ParsedField('content');

        self::assertSame('content', $field->responseKey());
    }

    #[Test]
    public function responseKeyReturnsAliasWhenSet(): void
    {
        $field = new ParsedField('content', alias: 'myContent');

        self::assertSame('myContent', $field->responseKey());
    }

    #[Test]
    public function constructsWithDefaults(): void
    {
        $field = new ParsedField('id');

        self::assertSame('id', $field->name);
        self::assertNull($field->alias);
        self::assertSame([], $field->arguments);
        self::assertSame([], $field->selections);
    }

    #[Test]
    public function constructsWithAllFields(): void
    {
        $sub = new ParsedField('title');
        $field = new ParsedField(
            'content',
            'myContent',
            ['id' => '123'],
            [$sub],
        );

        self::assertSame('content', $field->name);
        self::assertSame('myContent', $field->alias);
        self::assertSame(['id' => '123'], $field->arguments);
        self::assertCount(1, $field->selections);
    }
}
