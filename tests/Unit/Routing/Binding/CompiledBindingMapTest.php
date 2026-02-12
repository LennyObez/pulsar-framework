<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\CompiledBindingMap;
use stdClass;

#[CoversClass(CompiledBindingMap::class)]
final class CompiledBindingMapTest extends TestCase
{
    #[Test]
    public function hasReturnsTrueForExistingEntry(): void
    {
        $map = new CompiledBindingMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class),
            ],
        ]);

        self::assertTrue($map->has('users.show', 'user'));
    }

    #[Test]
    public function hasReturnsFalseForMissingRoute(): void
    {
        $map = new CompiledBindingMap([]);

        self::assertFalse($map->has('unknown', 'user'));
    }

    #[Test]
    public function hasReturnsFalseForMissingParameter(): void
    {
        $map = new CompiledBindingMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class),
            ],
        ]);

        self::assertFalse($map->has('users.show', 'post'));
    }

    #[Test]
    public function getReturnsBindingMetaForExistingEntry(): void
    {
        $meta = new BindingMeta(class: stdClass::class, keyName: 'uuid');
        $map = new CompiledBindingMap([
            'users.show' => ['user' => $meta],
        ]);

        self::assertSame($meta, $map->get('users.show', 'user'));
    }

    #[Test]
    public function getReturnsNullForMissingRoute(): void
    {
        $map = new CompiledBindingMap([]);

        self::assertNull($map->get('unknown', 'user'));
    }

    #[Test]
    public function getReturnsNullForMissingParameter(): void
    {
        $map = new CompiledBindingMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class),
            ],
        ]);

        self::assertNull($map->get('users.show', 'missing'));
    }

    #[Test]
    public function getForRouteReturnsAllParametersForRoute(): void
    {
        $userMeta = new BindingMeta(class: stdClass::class);
        $postMeta = new BindingMeta(class: stdClass::class, keyName: 'slug');

        $map = new CompiledBindingMap([
            'posts.show' => [
                'user' => $userMeta,
                'post' => $postMeta,
            ],
        ]);

        $result = $map->getForRoute('posts.show');

        self::assertCount(2, $result);
        self::assertSame($userMeta, $result['user']);
        self::assertSame($postMeta, $result['post']);
    }

    #[Test]
    public function getForRouteReturnsEmptyArrayForMissingRoute(): void
    {
        $map = new CompiledBindingMap([]);

        self::assertSame([], $map->getForRoute('unknown'));
    }

    #[Test]
    public function allReturnsFullMap(): void
    {
        $meta = new BindingMeta(class: stdClass::class);
        $inner = ['users.show' => ['user' => $meta]];

        $map = new CompiledBindingMap($inner);

        self::assertSame($inner, $map->all());
    }

    #[Test]
    public function fromArrayReconstructsFromSerializedData(): void
    {
        $data = [
            'users.show' => [
                'user' => [
                    'class' => stdClass::class,
                    'key_name' => 'uuid',
                    'key_type' => 'string',
                    'scoped' => true,
                    'parent_relation' => 'users',
                    'authz_policy' => 'user.view',
                    'custom_resolver' => 'App\\Resolvers\\UserResolver',
                ],
            ],
        ];

        $map = CompiledBindingMap::fromArray($data);

        self::assertTrue($map->has('users.show', 'user'));

        $meta = $map->get('users.show', 'user');
        self::assertNotNull($meta);
        self::assertSame(stdClass::class, $meta->class);
        self::assertSame('uuid', $meta->keyName);
        self::assertSame('string', $meta->keyType);
        self::assertTrue($meta->scoped);
        self::assertSame('users', $meta->parentRelation);
        self::assertSame('user.view', $meta->authzPolicy);
        self::assertSame('App\\Resolvers\\UserResolver', $meta->customResolver);
    }

    #[Test]
    public function toArraySerializesMap(): void
    {
        $map = new CompiledBindingMap([
            'users.show' => [
                'user' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'uuid',
                    keyType: 'string',
                    scoped: true,
                    parentRelation: 'users',
                    authzPolicy: 'user.view',
                    customResolver: stdClass::class,
                ),
            ],
        ]);

        $data = $map->toArray();

        self::assertArrayHasKey('users.show', $data);
        self::assertArrayHasKey('user', $data['users.show']);
        self::assertSame(stdClass::class, $data['users.show']['user']['class']);
        self::assertSame('uuid', $data['users.show']['user']['key_name']);
        self::assertSame('string', $data['users.show']['user']['key_type']);
        self::assertTrue($data['users.show']['user']['scoped']);
        self::assertSame('users', $data['users.show']['user']['parent_relation']);
        self::assertSame('user.view', $data['users.show']['user']['authz_policy']);
        self::assertSame(stdClass::class, $data['users.show']['user']['custom_resolver']);
    }

    #[Test]
    public function toArrayOmitsDefaultValues(): void
    {
        $map = new CompiledBindingMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class),
            ],
        ]);

        $data = $map->toArray();
        $entry = $data['users.show']['user'];

        self::assertArrayNotHasKey('scoped', $entry);
        self::assertArrayNotHasKey('parent_relation', $entry);
        self::assertArrayNotHasKey('authz_policy', $entry);
        self::assertArrayNotHasKey('custom_resolver', $entry);
    }

    #[Test]
    public function roundTripFromArrayToArray(): void
    {
        $original = [
            'users.show' => [
                'user' => [
                    'class' => stdClass::class,
                    'key_name' => 'id',
                    'key_type' => 'int',
                ],
            ],
            'posts.show' => [
                'post' => [
                    'class' => stdClass::class,
                    'key_name' => 'slug',
                    'key_type' => 'string',
                    'scoped' => true,
                    'parent_relation' => 'posts',
                ],
            ],
        ];

        $map = CompiledBindingMap::fromArray($original);
        $roundTripped = $map->toArray();

        self::assertSame($original['users.show']['user']['class'], $roundTripped['users.show']['user']['class']);
        self::assertSame($original['posts.show']['post']['key_name'], $roundTripped['posts.show']['post']['key_name']);
        self::assertTrue($roundTripped['posts.show']['post']['scoped']);
        self::assertSame('posts', $roundTripped['posts.show']['post']['parent_relation']);
    }
}
