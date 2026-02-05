<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Features\Query\JoinOnBuilder;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

#[CoversClass(JoinOnBuilder::class)]
final class JoinOnBuilderTest extends TestCase
{
    private JoinOnBuilder $builder;
    private BindingCounter $bindings;

    protected function setUp(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);
        $this->bindings = new BindingCounter();
        $this->builder = new JoinOnBuilder($quoter, $this->bindings);
    }

    #[Test]
    public function onComparesQualifiedRefs(): void
    {
        $this->builder->on('t0.id', '=', 't1.user_id');
        $result = $this->builder->compile();

        self::assertSame('`t0`.`id` = `t1`.`user_id`', $result['sql']);
        self::assertSame([], $result['bindings']);
    }

    #[Test]
    public function onRejectsUnqualifiedLeftRef(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->builder->on('id', '=', 't1.user_id');
    }

    #[Test]
    public function onRejectsUnqualifiedRightRef(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->builder->on('t0.id', '=', 'user_id');
    }

    #[Test]
    public function whereBindsValueToQualifiedColumn(): void
    {
        $this->builder->where('t1.active', '=', true);
        $result = $this->builder->compile();

        self::assertSame('`t1`.`active` = :p0', $result['sql']);
        self::assertSame(['p0' => true], $result['bindings']);
    }

    #[Test]
    public function whereRejectsUnqualifiedColumn(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->builder->where('active', '=', true);
    }

    #[Test]
    public function multipleConditionsJoinedWithAnd(): void
    {
        $this->builder
            ->on('t0.id', '=', 't1.user_id')
            ->where('t1.active', '=', true);

        $result = $this->builder->compile();

        self::assertSame('`t0`.`id` = `t1`.`user_id` AND `t1`.`active` = :p0', $result['sql']);
        self::assertSame(['p0' => true], $result['bindings']);
    }

    #[Test]
    public function emptyBuilderCompilesEmptyString(): void
    {
        $result = $this->builder->compile();

        self::assertSame('', $result['sql']);
        self::assertSame([], $result['bindings']);
    }
}
