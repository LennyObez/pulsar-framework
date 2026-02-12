<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Csrf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\CsrfFormConfig;
use Pulsar\Extension\Form\Csrf\FormCsrfManager;
use Pulsar\Extension\Form\Exception\CsrfException;
use Pulsar\Security\Session\SessionInterface;

use function strlen;

final class FormCsrfManagerTest extends TestCase
{
    #[Test]
    public function generateReturnsNonEmptyToken(): void
    {
        $session = $this->createSessionStub();
        $manager = new FormCsrfManager($session, $this->defaultConfig());

        $token = $manager->generate('login', '/login');

        self::assertNotEmpty($token);
        self::assertSame(64, strlen($token)); // SHA-256 hex
    }

    #[Test]
    public function validateAcceptsValidToken(): void
    {
        $session = $this->createInMemorySession();
        $manager = new FormCsrfManager($session, $this->defaultConfig());

        $token = $manager->generate('login', '/login');
        $manager->validate($token, 'login', '/login');

        // Token consumed — session key removed
        self::assertNull($session->get('_form_csrf_login'));
    }

    #[Test]
    public function validateThrowsOnEmptyToken(): void
    {
        $session = $this->createInMemorySession();
        $manager = new FormCsrfManager($session, $this->defaultConfig());

        $this->expectException(CsrfException::class);
        $this->expectExceptionMessage('missing');
        $manager->validate('', 'login');
    }

    #[Test]
    public function validateThrowsOnMissingSessionData(): void
    {
        $session = $this->createInMemorySession();
        $manager = new FormCsrfManager($session, $this->defaultConfig());

        $this->expectException(CsrfException::class);
        $this->expectExceptionMessage('invalid');
        $manager->validate('fake-token', 'login');
    }

    #[Test]
    public function validateThrowsOnWrongToken(): void
    {
        $session = $this->createInMemorySession();
        $manager = new FormCsrfManager($session, $this->defaultConfig());

        $manager->generate('login', '/login');

        $this->expectException(CsrfException::class);
        $manager->validate('wrong-token-value', 'login', '/login');
    }

    #[Test]
    public function validateThrowsOnActionMismatch(): void
    {
        $session = $this->createInMemorySession();
        $manager = new FormCsrfManager($session, $this->defaultConfig());

        $token = $manager->generate('login', '/login');

        $this->expectException(CsrfException::class);
        $manager->validate($token, 'login', '/different-action');
    }

    #[Test]
    public function validateThrowsWhenTokenExpired(): void
    {
        $session = $this->createInMemorySession();
        $config = new CsrfFormConfig(enabled: true, ttl: 0, fieldName: '_csrf_token');
        $manager = new FormCsrfManager($session, $config);

        $token = $manager->generate('login', '/login');

        // TTL is 0, so any time after generation is expired
        sleep(1);

        $this->expectException(CsrfException::class);
        $this->expectExceptionMessage('expired');
        $manager->validate($token, 'login', '/login');
    }

    private function defaultConfig(): CsrfFormConfig
    {
        return CsrfFormConfig::fromArray([]);
    }

    private function createSessionStub(): SessionInterface
    {
        $stub = $this->createStub(SessionInterface::class);
        $stub->method('id')->willReturn('sess-abc123');

        return $stub;
    }

    private function createInMemorySession(): SessionInterface
    {
        return new class implements SessionInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function start(): void {}
            public function isStarted(): bool
            {
                return true;
            }
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }
            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }
            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }
            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }
            public function id(): string
            {
                return 'test-session-id';
            }
            public function regenerate(bool $deleteOldSession = true): void {}
            public function destroy(): void
            {
                $this->data = [];
            }
            /** @return array<string, mixed> */
            public function all(): array
            {
                return $this->data;
            }
        };
    }
}
