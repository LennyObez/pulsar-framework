<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Gateway;

use ArrayObject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Gateway\IdentityMap;
use stdClass;

final class IdentityMapTest extends TestCase
{
    private IdentityMap $map;

    protected function setUp(): void
    {
        $this->map = new IdentityMap();
    }

    #[Test]
    public function has_returns_false_for_unknown_entity(): void
    {
        self::assertFalse($this->map->has(stdClass::class, 'id-1'));
    }

    #[Test]
    public function has_returns_true_after_put(): void
    {
        $entity = new stdClass();
        $this->map->put(stdClass::class, 'id-1', $entity);

        self::assertTrue($this->map->has(stdClass::class, 'id-1'));
    }

    #[Test]
    public function get_returns_null_for_unknown(): void
    {
        self::assertNull($this->map->get(stdClass::class, 'id-1'));
    }

    #[Test]
    public function get_returns_same_reference_as_put(): void
    {
        $entity = new stdClass();
        $entity->name = 'Test';
        $this->map->put(stdClass::class, 'id-1', $entity);

        $retrieved = $this->map->get(stdClass::class, 'id-1');

        self::assertSame($entity, $retrieved);
    }

    #[Test]
    public function put_with_integer_id(): void
    {
        $entity = new stdClass();
        $this->map->put(stdClass::class, 42, $entity);

        self::assertTrue($this->map->has(stdClass::class, 42));
        self::assertSame($entity, $this->map->get(stdClass::class, 42));
    }

    #[Test]
    public function remove_deletes_entity(): void
    {
        $entity = new stdClass();
        $this->map->put(stdClass::class, 'id-1', $entity);
        $this->map->remove(stdClass::class, 'id-1');

        self::assertFalse($this->map->has(stdClass::class, 'id-1'));
        self::assertNull($this->map->get(stdClass::class, 'id-1'));
    }

    #[Test]
    public function clear_removes_all_entities(): void
    {
        $this->map->put(stdClass::class, 'id-1', new stdClass());
        $this->map->put(stdClass::class, 'id-2', new stdClass());

        $this->map->clear();

        self::assertSame(0, $this->map->count());
        self::assertFalse($this->map->has(stdClass::class, 'id-1'));
    }

    #[Test]
    public function count_returns_number_of_tracked_entities(): void
    {
        self::assertSame(0, $this->map->count());

        $this->map->put(stdClass::class, 'id-1', new stdClass());
        self::assertSame(1, $this->map->count());

        $this->map->put(stdClass::class, 'id-2', new stdClass());
        self::assertSame(2, $this->map->count());
    }

    #[Test]
    public function all_of_class_returns_entities_of_given_class(): void
    {
        $entity1 = new stdClass();
        $entity2 = new stdClass();

        $this->map->put(stdClass::class, 'id-1', $entity1);
        $this->map->put(stdClass::class, 'id-2', $entity2);

        $result = $this->map->allOfClass(stdClass::class);

        self::assertCount(2, $result);
        self::assertContains($entity1, $result);
        self::assertContains($entity2, $result);
    }

    #[Test]
    public function all_of_class_returns_empty_for_unknown_class(): void
    {
        $this->map->put(stdClass::class, 'id-1', new stdClass());

        $nonExistent = ArrayObject::class;
        $result = $this->map->allOfClass($nonExistent);

        self::assertSame([], $result);
    }

    #[Test]
    public function put_overwrites_existing_entity(): void
    {
        $original = new stdClass();
        $replacement = new stdClass();

        $this->map->put(stdClass::class, 'id-1', $original);
        $this->map->put(stdClass::class, 'id-1', $replacement);

        self::assertSame($replacement, $this->map->get(stdClass::class, 'id-1'));
        self::assertSame(1, $this->map->count());
    }

    #[Test]
    public function different_classes_same_id_are_separate(): void
    {
        $entity1 = new stdClass();

        $this->map->put(stdClass::class, 'id-1', $entity1);

        self::assertFalse($this->map->has(ArrayObject::class, 'id-1'));
    }
}
