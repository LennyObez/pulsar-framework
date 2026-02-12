<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Sort;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Sort\SortDefinition;
use Pulsar\Api\Sort\SortDirection;
use Pulsar\Api\Sort\SortParser;
use Pulsar\Api\Sort\SortRegistry;

#[CoversClass(SortParser::class)]
final class SortParserTest extends TestCase
{
    private SortRegistry $registry;
    private SortParser $parser;

    protected function setUp(): void
    {
        $this->registry = new SortRegistry();
        $this->registry->register('users', [
            'name' => new SortDefinition(column: 'name'),
            'created_at' => new SortDefinition(column: 'created_at'),
            'salary' => new SortDefinition(column: 'salary', guard: 'hr'),
        ]);
        $this->parser = new SortParser($this->registry);
    }

    #[Test]
    public function parsesAscendingField(): void
    {
        $expressions = $this->parser->parse('users', 'name');

        self::assertCount(1, $expressions);
        self::assertSame('name', $expressions[0]->column);
        self::assertSame(SortDirection::Ascending, $expressions[0]->direction);
    }

    #[Test]
    public function parsesDescendingFieldWithPrefix(): void
    {
        $expressions = $this->parser->parse('users', '-created_at');

        self::assertCount(1, $expressions);
        self::assertSame('created_at', $expressions[0]->column);
        self::assertSame(SortDirection::Descending, $expressions[0]->direction);
    }

    #[Test]
    public function parsesMultipleFields(): void
    {
        $expressions = $this->parser->parse('users', 'name,-created_at');

        self::assertCount(2, $expressions);
        self::assertSame('name', $expressions[0]->column);
        self::assertSame(SortDirection::Ascending, $expressions[0]->direction);
        self::assertSame('created_at', $expressions[1]->column);
        self::assertSame(SortDirection::Descending, $expressions[1]->direction);
    }

    #[Test]
    public function emptyStringReturnsEmptyArray(): void
    {
        self::assertSame([], $this->parser->parse('users', ''));
    }

    #[Test]
    public function whiteSpaceOnlyReturnsEmpty(): void
    {
        self::assertSame([], $this->parser->parse('users', '   '));
    }

    #[Test]
    public function unknownFieldThrows400(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Sort field "email" is not registered/');

        $_ = $this->parser->parse('users', 'email');
    }

    #[Test]
    public function unknownResourceThrows400(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unknown resource type "posts"/');

        $_ = $this->parser->parse('posts', 'title');
    }

    #[Test]
    public function guardedSortRequiresRole(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageMatches('/requires role "hr"/');

        $_ = $this->parser->parse('users', 'salary', userRoles: ['user']);
    }

    #[Test]
    public function guardedSortPassesWithCorrectRole(): void
    {
        $expressions = $this->parser->parse('users', 'salary', userRoles: ['hr']);

        self::assertCount(1, $expressions);
        self::assertSame('salary', $expressions[0]->column);
    }

    #[Test]
    public function trimsWhitespaceAroundFields(): void
    {
        $expressions = $this->parser->parse('users', ' name , -created_at ');

        self::assertCount(2, $expressions);
        self::assertSame('name', $expressions[0]->column);
        self::assertSame('created_at', $expressions[1]->column);
    }

    #[Test]
    public function skipsEmptyPartsFromDoubleCommas(): void
    {
        $expressions = $this->parser->parse('users', 'name,,created_at');

        self::assertCount(2, $expressions);
    }
}
