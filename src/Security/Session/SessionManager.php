<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use JsonException;
use NoDiscard;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;
use Pulsar\Security\Session\Validator\SessionValidatorInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function array_key_exists;
use function bin2hex;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Central session orchestrator managing handlers, validators, and lifecycle.
 *
 * Delegates storage to a pluggable handler while managing validation,
 * encryption, metadata tracking, and fixation protection.
 * @api
 */
#[Api(since: '1.0.0')]
final class SessionManager implements SessionInterface
{
    private const string METADATA_KEY = '_pulsar_meta';

    private bool $started = false;

    private string $sessionId = '';

    /** @var array<string, mixed> */
    private array $data = [];

    public private(set) ?SessionMetadata $metadata = null { get => $this->metadata; }

    private ?SessionEncryption $encryption;

    private readonly Randomizer $randomizer;

    /**
     * @param list<SessionValidatorInterface> $validators
     */
    public function __construct(
        private readonly SessionHandlerInterface $handler,
        private readonly SessionConfig $config,
        private readonly array $validators = [],
        ?SessionEncryption $encryption = null,
    ) {
        $this->encryption = $encryption;
        $this->randomizer = new Randomizer(new Secure());
    }

    #[Override]
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if ($this->sessionId === '') {
            $this->sessionId = $this->generateId();
        }

        $this->handler->open($this->config->savePath, $this->config->effectiveCookieName());

        $raw = $this->handler->read($this->sessionId);

        if ($raw !== '' && $raw !== false) {
            $decrypted = $this->decryptIfEnabled($raw);
            $this->loadStoredPayload($decrypted);
        }

        if ($this->metadata === null) {
            $this->metadata = new SessionMetadata(
                createdAt: time(),
                lastActivity: time(),
                ipAddress: '',
                userAgent: '',
            );
        } else {
            $this->metadata = $this->metadata->withLastActivity(time());
        }

        $this->started = true;
    }

    /**
     * Start the session with request context for metadata and validation.
     *
     * @throws SecurityException If session validation fails
     */
    public function startWithRequest(ServerRequestInterface $request): void
    {
        if ($this->started) {
            return;
        }

        /** @var mixed $cookieValue */
        $cookieValue = $request->getCookieParams()[$this->config->effectiveCookieName()] ?? null;
        $existingId = is_string($cookieValue) ? $cookieValue : '';

        if ($existingId !== '' && preg_match('/^[0-9a-f]{64}$/', $existingId) === 1) {
            $this->sessionId = $existingId;
        } else {
            $this->sessionId = $this->generateId();
        }

        $this->handler->open($this->config->savePath, $this->config->effectiveCookieName());

        $raw = $this->handler->read($this->sessionId);
        $isExistingSession = $raw !== '' && $raw !== false;

        if ($isExistingSession) {
            $decrypted = $this->decryptIfEnabled($raw);
            $this->loadStoredPayload($decrypted);
        }

        /** @var mixed $rawIp */
        $rawIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $ipAddress = is_string($rawIp) ? $rawIp : '';
        $userAgent = $request->getHeaderLine('User-Agent');

        if ($this->metadata === null) {
            $this->metadata = new SessionMetadata(
                createdAt: time(),
                lastActivity: time(),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        } else {
            // PCI-DSS 8.2.8: Enforce idle timeout before updating lastActivity
            $idleTimeout = $this->config->idleTimeout;

            if ($idleTimeout > 0) {
                $idleSeconds = time() - $this->metadata->lastActivity;

                if ($idleSeconds > $idleTimeout) {
                    $this->destroy();
                    throw SecurityException::sessionIdleExpired($idleSeconds, $idleTimeout);
                }
            }

            $this->metadata = new SessionMetadata(
                createdAt: $this->metadata->createdAt,
                lastActivity: time(),
                ipAddress: $this->metadata->ipAddress,
                userAgent: $this->metadata->userAgent,
                userId: $this->metadata->userId,
            );

            foreach ($this->validators as $validator) {
                if (!$validator->validate($this->metadata, $request)) {
                    $this->destroy();
                    throw SecurityException::sessionValidationFailed($validator->getName());
                }
            }
        }

        $this->started = true;
    }

    #[Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $this->data[$key] ?? $default;
    }

    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();

        $this->data[$key] = $value;
    }

    #[Override]
    public function has(string $key): bool
    {
        $this->ensureStarted();

        return array_key_exists($key, $this->data);
    }

    #[Override]
    public function remove(string $key): void
    {
        $this->ensureStarted();

        unset($this->data[$key]);
    }

    #[Override]
    public function id(): string
    {
        return $this->sessionId;
    }

    #[Override]
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->ensureStarted();

        $oldId = $this->sessionId;
        $this->sessionId = $this->generateId();

        if ($deleteOldSession) {
            $this->handler->destroy($oldId);
        }

        $this->save();
    }

    #[Override]
    public function destroy(): void
    {
        if ($this->sessionId !== '') {
            $this->handler->destroy($this->sessionId);
        }

        $this->data = [];
        $this->metadata = null;
        $this->sessionId = '';
        $this->started = false;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function all(): array
    {
        $this->ensureStarted();

        return $this->data;
    }

    /**
     * Associate the session with a user ID.
     */
    public function setUserId(?string $userId): void
    {
        $this->ensureStarted();

        if ($this->metadata !== null) {
            $this->metadata = $this->metadata->withUserId($userId);
        }
    }

    /**
     * Get the underlying handler for capability queries.
     */
    #[NoDiscard]
    public function getHandler(): SessionHandlerInterface
    {
        return $this->handler;
    }

    /**
     * Get the session configuration.
     */
    #[NoDiscard]
    public function getConfig(): SessionConfig
    {
        return $this->config;
    }

    /**
     * Persist the current session data to the handler.
     *
     * Storage format is JSON. Pulsar 1.0.0-rc.12 switched away from PHP
     * serialize() to eliminate the unserialize() attack surface (HIGH-4):
     * if encryption is disabled and an attacker can write to the session
     * storage location (e.g. shared temp dirs in multi-tenant hosting),
     * a serialized payload could trigger nested-array DoS or
     * `__PHP_Incomplete_Class` shenanigans even with `allowed_classes:false`.
     * JSON cannot instantiate classes, has bounded depth, and is the
     * recommended substitute per CWE-502.
     *
     * Session values must therefore be JSON-encodable (scalars, arrays,
     * or `JsonSerializable` instances). Storing raw object instances
     * will throw a `SecurityException` at save time so the application
     * fails loudly instead of silently dropping data.
     *
     * @throws SecurityException If session data is not JSON-encodable.
     */
    public function save(): void
    {
        if (!$this->started || $this->sessionId === '') {
            return;
        }

        $stored = [
            'data' => $this->data,
            self::METADATA_KEY => $this->metadata?->toArray(),
        ];

        try {
            $serialized = json_encode(
                $stored,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw SecurityException::sessionEncodingFailed($e->getMessage());
        }

        $payload = $this->encryptIfEnabled($serialized);

        $this->handler->write($this->sessionId, $payload);
    }

    /**
     * Decode and apply a stored session payload.
     *
     * Silently discards malformed payloads (treats them as a fresh
     * session) — this matches the previous unserialize-based behavior
     * for graceful recovery from corrupted storage. The critical
     * difference vs `unserialize()` is that JSON has zero code-execution
     * attack surface: malformed input cannot construct objects or
     * trigger magic methods.
     */
    private function loadStoredPayload(string $decrypted): void
    {
        if ($decrypted === '') {
            return;
        }

        try {
            $stored = json_decode($decrypted, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (!is_array($stored)) {
            return;
        }

        /** @var array<string, mixed> $sessionData */
        $sessionData = is_array($stored['data'] ?? null) ? $stored['data'] : [];
        $this->data = $sessionData;

        /** @var array<string, mixed>|null $metadataArray */
        $metadataArray = is_array($stored[self::METADATA_KEY] ?? null)
            ? $stored[self::METADATA_KEY]
            : null;

        $this->metadata = $metadataArray !== null
            ? SessionMetadata::fromArray($metadataArray)
            : null;
    }

    /**
     * Close the session (save and close handler).
     */
    public function close(): void
    {
        if ($this->started) {
            $this->save();
            $this->handler->close();
        }
    }

    /**
     * Enforce concurrent session limits for a user.
     *
     * @throws SecurityException If the handler doesn't support concurrency control or limit is exceeded
     */
    public function enforceConcurrencyLimit(string $userId): void
    {
        if (!$this->handler->supportsConcurrencyControl()) {
            return;
        }

        $activeSessions = $this->handler->getActiveSessions($userId);

        if ($activeSessions >= $this->config->maxConcurrentSessions) {
            throw SecurityException::sessionConcurrencyExceeded($this->config->maxConcurrentSessions);
        }
    }

    /**
     * Trigger garbage collection.
     */
    public function gc(): int|false
    {
        return $this->handler->gc($this->config->lifetime);
    }

    private function generateId(): string
    {
        return bin2hex($this->randomizer->getBytes(32));
    }

    private function encryptIfEnabled(string $data): string
    {
        if ($this->encryption === null) {
            return $data;
        }

        return $this->encryption->encrypt(
            $data,
            $this->sessionId,
            $this->config->handler,
            $this->config->cookieDomain,
        );
    }

    private function decryptIfEnabled(string $data): string
    {
        if ($this->encryption === null) {
            return $data;
        }

        return $this->encryption->decrypt(
            $data,
            $this->sessionId,
            $this->config->handler,
            $this->config->cookieDomain,
        );
    }

    /**
     * @throws SecurityException If the session has not been started
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            throw SecurityException::sessionNotStarted();
        }
    }
}
