<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use JsonException;
use NoDiscard;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;
use Pulsar\Security\Session\Validator\SessionValidatorInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function array_key_exists;
use function bin2hex;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function strcasecmp;
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

    /**
     * Whether the session id was generated or rotated this request and so must
     * be (re-)sent to the client. An id received from the client's cookie does
     * not need re-emitting, so this stays false in that case.
     */
    private bool $idIsNew = false;

    /**
     * Whether an active session was destroyed this request, so an expiring
     * cookie must be sent to make the client drop the now-dead session id.
     */
    private bool $cookieCleared = false;

    /** @var array<string, mixed> */
    private array $data = [];

    public private(set) ?SessionMetadata $metadata = null { get => $this->metadata; }

    private ?SessionEncryption $encryption;

    private readonly ?TrustedProxy $trustedProxy;

    private readonly Randomizer $randomizer;

    /**
     * @param list<SessionValidatorInterface> $validators
     * @param TrustedProxy|null $trustedProxy Resolves the real client IP behind
     *        trusted reverse proxies. When null, the raw REMOTE_ADDR is used.
     *        Must be the SAME instance the session validators use, so the IP
     *        captured here matches the IP they later compare against.
     */
    public function __construct(
        private readonly SessionHandlerInterface $handler,
        private readonly SessionConfig $config,
        private readonly array $validators = [],
        ?SessionEncryption $encryption = null,
        ?TrustedProxy $trustedProxy = null,
    ) {
        $this->encryption = $encryption;
        $this->trustedProxy = $trustedProxy;
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
            $this->idIsNew = true;
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
            $this->idIsNew = true;
        }

        $this->handler->open($this->config->savePath, $this->config->effectiveCookieName());

        $raw = $this->handler->read($this->sessionId);
        $isExistingSession = $raw !== '' && $raw !== false;

        if ($isExistingSession) {
            $decrypted = $this->decryptIfEnabled($raw);
            $this->loadStoredPayload($decrypted);
        }

        $ipAddress = $this->resolveClientIp($request);
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
    #[NoDiscard]
    public function getString(string $key, string $default = ''): string
    {
        $this->ensureStarted();
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    #[Override]
    #[NoDiscard]
    public function getNullableString(string $key): ?string
    {
        $this->ensureStarted();
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    #[Override]
    #[NoDiscard]
    public function getInt(string $key, int $default = 0): int
    {
        $this->ensureStarted();
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    #[Override]
    #[NoDiscard]
    public function getBool(string $key, bool $default = false): bool
    {
        $this->ensureStarted();
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return is_bool($value) ? $value : $default;
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
        $this->idIsNew = true;

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
            $this->cookieCleared = true;
        }

        $this->data = [];
        $this->metadata = null;
        $this->sessionId = '';
        $this->started = false;
        $this->idIsNew = false;
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
     * Build the `Set-Cookie` header value the session lifecycle must emit on the
     * outgoing response this request, or null when nothing changed.
     *
     * Returns a cookie carrying the session id when the id was generated or
     * rotated this request (a returning id from the client is not re-emitted),
     * an expiring cookie when the session was destroyed, or null otherwise. The
     * {@see SessionMiddleware} adds this to the response via withAddedHeader so
     * it never clobbers other Set-Cookie headers.
     */
    #[NoDiscard]
    public function pendingSetCookieHeader(): ?string
    {
        if ($this->started && $this->sessionId !== '' && $this->idIsNew) {
            // A lifetime of 0 means a session cookie (no Max-Age, expires when the
            // browser closes); a positive lifetime sets an explicit Max-Age.
            return $this->buildCookieHeader(
                $this->sessionId,
                $this->config->lifetime > 0 ? $this->config->lifetime : null,
            );
        }

        if ($this->cookieCleared) {
            return $this->buildCookieHeader('', 0);
        }

        return null;
    }

    /**
     * Build an RFC 6265 `Set-Cookie` header value from the session configuration.
     *
     * @param string $value   Cookie value (the session id, or '' to clear).
     * @param int|null $maxAge Max-Age in seconds; 0 expires immediately, null
     *                         omits the attribute (a browser-session cookie).
     */
    private function buildCookieHeader(string $value, ?int $maxAge): string
    {
        $hostPrefixed = $this->config->cookieHostPrefix;
        $sameSite = $this->config->cookieSameSite;

        // `__Host-` cookies and `SameSite=None` both require the Secure flag
        // (browsers reject the cookie otherwise) — enforce it regardless of the
        // configured cookieSecure value so a misconfiguration cannot silently
        // produce a cookie the client drops.
        $secure = $this->config->cookieSecure
            || $hostPrefixed
            || strcasecmp($sameSite, 'None') === 0;

        $parts = [$this->config->effectiveCookieName() . '=' . $value];

        // `__Host-` mandates Path=/ and no Domain.
        $parts[] = 'Path=' . ($hostPrefixed || $this->config->cookiePath === '' ? '/' : $this->config->cookiePath);

        if (!$hostPrefixed && $this->config->cookieDomain !== '') {
            $parts[] = 'Domain=' . $this->config->cookieDomain;
        }

        if ($maxAge !== null) {
            $parts[] = 'Max-Age=' . $maxAge;
        }

        if ($secure) {
            $parts[] = 'Secure';
        }

        if ($this->config->cookieHttpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($sameSite !== '') {
            $parts[] = 'SameSite=' . $sameSite;
        }

        return implode('; ', $parts);
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

    /**
     * Resolve the client IP to record in session metadata. Behind a configured
     * trusted proxy this is the real client IP (so subsequent validation
     * compares like for like); otherwise the raw REMOTE_ADDR.
     */
    private function resolveClientIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        /** @var mixed $rawIp */
        $rawIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($rawIp) ? $rawIp : '';
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
