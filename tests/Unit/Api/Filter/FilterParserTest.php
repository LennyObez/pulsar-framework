<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Filter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Filter\Filter;
use Pulsar\Api\Filter\FilterOperator;
use Pulsar\Api\Filter\FilterParser;
use Pulsar\Api\Filter\FilterRegistry;

#[CoversClass(FilterParser::class)]
final class FilterParserTest extends TestCase
{
    private FilterRegistry $registry;
    private FilterParser $parser;

    protected function setUp(): void
    {
        $this->registry = new FilterRegistry();
        $this->registry->register('users', [
            'name' => Filter::string(),
            'age' => Filter::integer(),
            'score' => Filter::float(),
            'active' => Filter::boolean(),
            'created_at' => Filter::date('created_at'),
            'role' => Filter::enum(TestUserRole::class),
            'internal_status' => Filter::string()->guard('admin'),
        ]);
        $this->parser = new FilterParser($this->registry);
    }

    #[Test]
    public function parsesEqualityOperator(): void
    {
        $expressions = $this->parser->parse('users', ['name' => 'eq:John']);

        self::assertCount(1, $expressions);
        self::assertSame('name', $expressions[0]->field);
        self::assertSame(FilterOperator::Equal, $expressions[0]->operator);
        self::assertSame('John', $expressions[0]->value);
    }

    #[Test]
    public function defaultsToEqualityWhenNoOperatorPrefix(): void
    {
        $expressions = $this->parser->parse('users', ['name' => 'John']);

        self::assertCount(1, $expressions);
        self::assertSame(FilterOperator::Equal, $expressions[0]->operator);
        self::assertSame('John', $expressions[0]->value);
    }

    #[Test]
    public function parsesNotEqualOperator(): void
    {
        $expressions = $this->parser->parse('users', ['name' => 'neq:John']);

        self::assertSame(FilterOperator::NotEqual, $expressions[0]->operator);
    }

    #[Test]
    public function parsesGreaterThanOperator(): void
    {
        $expressions = $this->parser->parse('users', ['age' => 'gt:18']);

        self::assertSame(FilterOperator::GreaterThan, $expressions[0]->operator);
        self::assertSame(18, $expressions[0]->value);
    }

    #[Test]
    public function parsesGreaterThanOrEqualOperator(): void
    {
        $expressions = $this->parser->parse('users', ['age' => 'gte:21']);

        self::assertSame(FilterOperator::GreaterThanOrEqual, $expressions[0]->operator);
        self::assertSame(21, $expressions[0]->value);
    }

    #[Test]
    public function parsesLessThanOperator(): void
    {
        $expressions = $this->parser->parse('users', ['age' => 'lt:65']);

        self::assertSame(FilterOperator::LessThan, $expressions[0]->operator);
        self::assertSame(65, $expressions[0]->value);
    }

    #[Test]
    public function parsesLessThanOrEqualOperator(): void
    {
        $expressions = $this->parser->parse('users', ['age' => 'lte:30']);

        self::assertSame(FilterOperator::LessThanOrEqual, $expressions[0]->operator);
        self::assertSame(30, $expressions[0]->value);
    }

    #[Test]
    public function parsesInOperator(): void
    {
        $expressions = $this->parser->parse('users', ['age' => 'in:18,21,30']);

        self::assertSame(FilterOperator::In, $expressions[0]->operator);
        self::assertSame([18, 21, 30], $expressions[0]->value);
    }

    #[Test]
    public function parsesContainsOperator(): void
    {
        $expressions = $this->parser->parse('users', ['name' => 'contains:john']);

        self::assertSame(FilterOperator::Contains, $expressions[0]->operator);
        self::assertSame('john', $expressions[0]->value);
    }

    #[Test]
    public function parsesStartsWithOperator(): void
    {
        $expressions = $this->parser->parse('users', ['name' => 'starts_with:jo']);

        self::assertSame(FilterOperator::StartsWith, $expressions[0]->operator);
        self::assertSame('jo', $expressions[0]->value);
    }

    #[Test]
    public function unknownOperatorThrows400(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unknown filter operator "nope"/');

        (void) $this->parser->parse('users', ['name' => 'nope:value']);
    }

    #[Test]
    public function unknownFieldThrows400(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Filter field "password" is not registered/');

        (void) $this->parser->parse('users', ['password' => 'eq:secret']);
    }

    #[Test]
    public function unknownResourceThrows400(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unknown resource type "posts"/');

        (void) $this->parser->parse('posts', ['title' => 'eq:Hello']);
    }

    #[Test]
    public function disallowedOperatorForFieldThrows400(): void
    {
        // Boolean filter only allows Equal operator
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Operator "gt" is not allowed/');

        (void) $this->parser->parse('users', ['active' => 'gt:true']);
    }

    #[Test]
    public function guardedFilterRequiresRole(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageMatches('/requires role "admin"/');

        (void) $this->parser->parse('users', ['internal_status' => 'eq:flagged'], userRoles: ['user']);
    }

    #[Test]
    public function guardedFilterPassesWithCorrectRole(): void
    {
        $expressions = $this->parser->parse(
            'users',
            ['internal_status' => 'eq:flagged'],
            userRoles: ['admin'],
        );

        self::assertCount(1, $expressions);
        self::assertSame('flagged', $expressions[0]->value);
    }

    #[Test]
    public function integerTypeValidation(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Invalid value "abc" for filter field "age"/');

        (void) $this->parser->parse('users', ['age' => 'eq:abc']);
    }

    #[Test]
    public function floatRejectsNonNumeric(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Invalid value "xyz"/');

        (void) $this->parser->parse('users', ['score' => 'eq:xyz']);
    }

    #[Test]
    public function floatAcceptsValidNumbers(): void
    {
        $expressions = $this->parser->parse('users', ['score' => 'gte:95.5']);

        self::assertSame(95.5, $expressions[0]->value);
    }

    #[Test]
    public function booleanAcceptsTrueValues(): void
    {
        foreach (['1', 'true', 'yes'] as $truthy) {
            $expressions = $this->parser->parse('users', ['active' => 'eq:' . $truthy]);
            self::assertTrue($expressions[0]->value, "Expected true for input: {$truthy}");
        }
    }

    #[Test]
    public function booleanAcceptsFalseValues(): void
    {
        foreach (['0', 'false', 'no'] as $falsy) {
            $expressions = $this->parser->parse('users', ['active' => 'eq:' . $falsy]);
            self::assertFalse($expressions[0]->value, "Expected false for input: {$falsy}");
        }
    }

    #[Test]
    public function booleanRejectsInvalidValue(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Invalid value "maybe"/');

        (void) $this->parser->parse('users', ['active' => 'eq:maybe']);
    }

    #[Test]
    public function dateAcceptsIso8601Format(): void
    {
        $expressions = $this->parser->parse('users', ['created_at' => 'gte:2024-01-15']);

        self::assertInstanceOf(DateTimeImmutable::class, $expressions[0]->value);
        self::assertSame('2024-01-15', $expressions[0]->value->format('Y-m-d'));
    }

    #[Test]
    public function dateRejectsInvalidFormat(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Invalid value "not-a-date"/');

        (void) $this->parser->parse('users', ['created_at' => 'eq:not-a-date']);
    }

    #[Test]
    public function enumAcceptsValidValue(): void
    {
        $expressions = $this->parser->parse('users', ['role' => 'eq:admin']);

        self::assertSame(TestUserRole::Admin, $expressions[0]->value);
    }

    #[Test]
    public function enumRejectsInvalidValue(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Invalid value "superadmin"/');

        (void) $this->parser->parse('users', ['role' => 'eq:superadmin']);
    }

    #[Test]
    public function multipleFiltersParseCorrectly(): void
    {
        $expressions = $this->parser->parse('users', [
            'name' => 'contains:john',
            'age' => 'gte:18',
            'active' => 'eq:true',
        ]);

        self::assertCount(3, $expressions);
    }
}
