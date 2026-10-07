<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Admin\NotificationPreferencesController;
use Pulsar\Extension\Forum\Notification\NotificationPreference;
use Pulsar\Extension\Forum\Notification\NotificationPreferenceRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(NotificationPreferencesController::class)]
final class NotificationPreferencesControllerTest extends TestCase
{
    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeRequest(
        string $method = 'GET',
        ?array $parsedBody = null,
    ): ServerRequest {
        $request = new ServerRequest(
            method: $method,
            uri: '/forum/settings/notifications',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }

    #[Test]
    public function indexWithoutAuthThrows(): void
    {
        $controller = new NotificationPreferencesController(
            $this->createStub(NotificationPreferenceRepositoryInterface::class),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/forum/settings/notifications',
            headers: ['Accept' => 'application/json'],
        );

        $this->expectException(ForumException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsEmptyPreferences(): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUser')->willReturn([]);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->index($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function indexReturnsUserPreferences(): void
    {
        $pref = new NotificationPreference(
            userId: 'user-1',
            eventType: 'thread_reply',
            inApp: true,
            email: false,
            emailFrequency: 'immediate',
        );

        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUser')->willReturn([$pref]);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->index($this->makeRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('thread_reply', $body['data'][0]['event_type']);
        self::assertTrue($body['data'][0]['in_app']);
        self::assertFalse($body['data'][0]['email']);
        self::assertSame('immediate', $body['data'][0]['email_frequency']);
    }

    #[Test]
    public function updateWithoutAuthThrows(): void
    {
        $controller = new NotificationPreferencesController(
            $this->createStub(NotificationPreferenceRepositoryInterface::class),
        );

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/forum/settings/notifications',
            headers: ['Accept' => 'application/json'],
        )->withParsedBody(['preferences' => []]);

        $this->expectException(ForumException::class);

        $controller->update($request);
    }

    #[Test]
    public function updateWithNullBodyReturns400(): void
    {
        $controller = new NotificationPreferencesController(
            $this->createStub(NotificationPreferenceRepositoryInterface::class),
        );

        // Simulate a request with no parsed body
        $request = new ServerRequest(
            method: 'PUT',
            uri: '/forum/settings/notifications',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->update($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Invalid request body', $body['error']);
    }

    #[Test]
    public function updateWithMissingPreferencesKeyReturns422(): void
    {
        $controller = new NotificationPreferencesController(
            $this->createStub(NotificationPreferenceRepositoryInterface::class),
        );

        $response = $controller->update($this->makeRequest('PUT', ['some_key' => 'value']));

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Validation failed', $body['error']);
        self::assertArrayHasKey('preferences', $body['details']);
    }

    #[Test]
    public function updateWithNonArrayPreferencesReturns422(): void
    {
        $controller = new NotificationPreferencesController(
            $this->createStub(NotificationPreferenceRepositoryInterface::class),
        );

        $response = $controller->update($this->makeRequest('PUT', ['preferences' => 'invalid']));

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function updateCreatesNewPreference(): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn(null);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                [
                    'event_type' => 'thread_reply',
                    'in_app' => true,
                    'email' => true,
                    'email_frequency' => 'daily',
                ],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('thread_reply', $body['data'][0]['event_type']);
        self::assertTrue($body['data'][0]['in_app']);
        self::assertTrue($body['data'][0]['email']);
        self::assertSame('daily', $body['data'][0]['email_frequency']);
    }

    #[Test]
    public function updateExistingPreference(): void
    {
        $existing = new NotificationPreference(
            userId: 'user-1',
            eventType: 'thread_reply',
            inApp: true,
            email: false,
            emailFrequency: 'immediate',
        );

        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn($existing);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                [
                    'event_type' => 'thread_reply',
                    'in_app' => false,
                    'email' => true,
                    'email_frequency' => 'weekly',
                ],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertFalse($body['data'][0]['in_app']);
        self::assertTrue($body['data'][0]['email']);
        self::assertSame('weekly', $body['data'][0]['email_frequency']);
    }

    #[Test]
    public function updateSkipsEntriesWithEmptyEventType(): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn(null);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                ['event_type' => '', 'in_app' => true],
                ['event_type' => 'thread_reply', 'in_app' => true],
            ],
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('thread_reply', $body['data'][0]['event_type']);
    }

    #[Test]
    public function updateSkipsNonArrayEntries(): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn(null);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                'not_an_array',
                ['event_type' => 'thread_reply', 'in_app' => true],
            ],
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFrequencyProvider(): iterable
    {
        yield 'empty string' => ['', 'immediate'];
        yield 'invalid value' => ['monthly', 'immediate'];
        yield 'numeric' => ['123', 'immediate'];
    }

    #[Test]
    #[DataProvider('invalidFrequencyProvider')]
    public function updateNormalizesInvalidFrequency(string $input, string $expected): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn(null);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                [
                    'event_type' => 'thread_reply',
                    'in_app' => true,
                    'email' => true,
                    'email_frequency' => $input,
                ],
            ],
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($expected, $body['data'][0]['email_frequency']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validFrequencyProvider(): iterable
    {
        yield 'immediate' => ['immediate'];
        yield 'daily' => ['daily'];
        yield 'weekly' => ['weekly'];
    }

    #[Test]
    #[DataProvider('validFrequencyProvider')]
    public function updateAcceptsValidFrequency(string $frequency): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn(null);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                [
                    'event_type' => 'mention',
                    'in_app' => true,
                    'email' => true,
                    'email_frequency' => $frequency,
                ],
            ],
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($frequency, $body['data'][0]['email_frequency']);
    }

    #[Test]
    public function updateDefaultsToInAppTrueAndEmailFalse(): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn(null);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                ['event_type' => 'thread_reply'],
            ],
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data'][0]['in_app']);
        self::assertFalse($body['data'][0]['email']);
        self::assertSame('immediate', $body['data'][0]['email_frequency']);
    }

    #[Test]
    public function updateHandlesMultiplePreferences(): void
    {
        $repo = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByUserAndType')->willReturn(null);

        $controller = new NotificationPreferencesController($repo);

        $response = $controller->update($this->makeRequest('PUT', [
            'preferences' => [
                ['event_type' => 'thread_reply', 'in_app' => true, 'email' => true, 'email_frequency' => 'daily'],
                ['event_type' => 'mention', 'in_app' => false, 'email' => true, 'email_frequency' => 'weekly'],
                ['event_type' => 'badge_awarded', 'in_app' => true, 'email' => false],
            ],
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(3, $body['data']);

        self::assertSame('thread_reply', $body['data'][0]['event_type']);
        self::assertSame('mention', $body['data'][1]['event_type']);
        self::assertSame('badge_awarded', $body['data'][2]['event_type']);
    }
}
