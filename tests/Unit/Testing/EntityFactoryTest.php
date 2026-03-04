<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\EntityFactory;

use function is_string;

#[CoversClass(EntityFactory::class)]
final class EntityFactoryTest extends TestCase
{
    #[Test]
    public function create_builds_entity_with_defaults(): void
    {
        $entity = TestUserFactory::new()->create();

        self::assertInstanceOf(TestUser::class, $entity);
        self::assertSame('John Doe', $entity->name);
        self::assertSame('john@example.com', $entity->email);
        self::assertTrue($entity->active);
    }

    #[Test]
    public function with_overrides_specific_attributes(): void
    {
        $entity = TestUserFactory::new()
            ->with(['name' => 'Jane', 'email' => 'jane@example.com'])
            ->create();

        self::assertInstanceOf(TestUser::class, $entity);
        self::assertSame('Jane', $entity->name);
        self::assertSame('jane@example.com', $entity->email);
        self::assertTrue($entity->active);
    }

    #[Test]
    public function state_applies_transformation(): void
    {
        $entity = TestUserFactory::new()
            ->state(fn(array $attrs) => [...$attrs, 'active' => false])
            ->create();

        self::assertInstanceOf(TestUser::class, $entity);
        self::assertFalse($entity->active);
    }

    #[Test]
    public function count_creates_multiple_entities(): void
    {
        $entities = TestUserFactory::new()->count(3)->create();

        self::assertIsArray($entities);
        self::assertCount(3, $entities);

        foreach ($entities as $entity) {
            self::assertInstanceOf(TestUser::class, $entity);
        }
    }

    #[Test]
    public function count_one_returns_single_entity(): void
    {
        $entity = TestUserFactory::new()->count(1)->create();

        self::assertInstanceOf(TestUser::class, $entity);
    }

    #[Test]
    public function make_returns_single_entity(): void
    {
        $entity = TestUserFactory::new()->make();

        self::assertInstanceOf(TestUser::class, $entity);
        self::assertSame('John Doe', $entity->name);
    }

    #[Test]
    public function with_is_immutable(): void
    {
        $factory = TestUserFactory::new();
        $modified = $factory->with(['name' => 'Modified']);

        $original = $factory->create();
        $changed = $modified->create();

        self::assertInstanceOf(TestUser::class, $original);
        self::assertInstanceOf(TestUser::class, $changed);
        self::assertSame('John Doe', $original->name);
        self::assertSame('Modified', $changed->name);
    }

    #[Test]
    public function state_is_immutable(): void
    {
        $factory = TestUserFactory::new();
        $modified = $factory->state(fn(array $a) => [...$a, 'active' => false]);

        $original = $factory->create();
        $changed = $modified->create();

        self::assertInstanceOf(TestUser::class, $original);
        self::assertInstanceOf(TestUser::class, $changed);
        self::assertTrue($original->active);
        self::assertFalse($changed->active);
    }

    #[Test]
    public function count_is_immutable(): void
    {
        $factory = TestUserFactory::new();
        $multi = $factory->count(3);

        $single = $factory->create();
        $multiple = $multi->create();

        self::assertInstanceOf(TestUser::class, $single);
        self::assertIsArray($multiple);
        self::assertCount(3, $multiple);
    }

    #[Test]
    public function resolve_attributes_returns_merged_attributes(): void
    {
        $attrs = TestUserFactory::new()
            ->with(['name' => 'Overridden'])
            ->state(fn(array $a) => [...$a, 'email' => 'state@example.com'])
            ->resolveAttributes();

        self::assertSame('Overridden', $attrs['name']);
        self::assertSame('state@example.com', $attrs['email']);
        self::assertTrue($attrs['active']);
    }

    #[Test]
    public function multiple_states_compose_in_order(): void
    {
        $entity = TestUserFactory::new()
            ->state(fn(array $a) => [...$a, 'name' => 'First'])
            ->state(fn(array $a) => [...$a, 'name' => (is_string($a['name']) ? $a['name'] : '') . ' Second'])
            ->create();

        self::assertInstanceOf(TestUser::class, $entity);
        self::assertSame('First Second', $entity->name);
    }

    #[Test]
    public function with_followed_by_state_composes_correctly(): void
    {
        $entity = TestUserFactory::new()
            ->with(['name' => 'Base'])
            ->state(fn(array $a) => [...$a, 'name' => (is_string($a['name']) ? $a['name'] : '') . ' Modified'])
            ->create();

        self::assertInstanceOf(TestUser::class, $entity);
        self::assertSame('Base Modified', $entity->name);
    }
}

/**
 * Test double: simple user DTO.
 */
final readonly class TestUser
{
    public function __construct(
        public string $name,
        public string $email,
        public bool $active,
    ) {}
}

/**
 * Test double: factory for TestUser.
 *
 * @extends EntityFactory<TestUser>
 */
final class TestUserFactory extends EntityFactory
{
    protected function defaults(): array
    {
        return [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'active' => true,
        ];
    }

    protected function build(array $attributes): object
    {
        return new TestUser(
            name: is_string($attributes['name']) ? $attributes['name'] : '',
            email: is_string($attributes['email']) ? $attributes['email'] : '',
            active: !empty($attributes['active']),
        );
    }
}
