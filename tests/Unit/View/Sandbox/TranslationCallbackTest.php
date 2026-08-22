<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Sandbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Sandbox\TranslationCallback;

use function assert;
use function is_string;

#[CoversClass(TranslationCallback::class)]
final class TranslationCallbackTest extends TestCase
{
    #[Test]
    public function invokesDelegateWithKeyAndData(): void
    {
        $callback = new TranslationCallback(
            static function (string $key, array $data): string {
                $name = $data['name'] ?? '';
                assert(is_string($name));

                return $key . ':' . $name;
            },
        );

        $result = $callback('greeting', ['name' => 'Dr. Smith']);

        self::assertSame('greeting:Dr. Smith', $result);
    }

    #[Test]
    public function invokeWithoutDataUsesDefault(): void
    {
        $callback = new TranslationCallback(
            static fn(string $key, array $data): string => $key,
        );

        $result = $callback('messages.welcome');

        self::assertSame('messages.welcome', $result);
    }

    #[Test]
    public function acceptsCallableArray(): void
    {
        $translator = new class {
            /** @param array<string, mixed> $data */
            public function translate(string $key, array $data): string
            {
                return "translated:{$key}";
            }
        };

        $callback = new TranslationCallback($translator->translate(...));

        self::assertSame('translated:login.title', $callback('login.title'));
    }
}
