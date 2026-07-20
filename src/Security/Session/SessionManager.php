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
use Pulsar\Runtime\ResettableInterface;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\CookieSessionHandlerInterface;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;
use Pulsar\Security\Session\Validator\SessionMetadataInitializerInterface;
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
final class SessionManager implements SessionInterface, ResettableInterface
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

    /**
     * Whether {@see startWithRequest()} transparently regenerated an anonymous
     * session after an idle-timeout or validator failure this request. Reset at
     * the start of every startWithRequest() call and read by {@see SessionMiddleware}.
     */
    private bool $recoveredFromExpiry = false;

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

    /**
     * Clear every per-request field back to its constructed state.
     *
     * On a persistent worker (RoadRunner, FrankenPHP, PersistentRuntime) the
     * SessionManager is a singleton that outlives the request. Without this reset
     * the previous user's loaded session id, data, and metadata survive into the
     * next request — a full account takeover. Only per-request state is cleared;
     * the readonly collaborators (handler, config, validators, encryption,
     * trusted proxy, randomizer) are request-independent and kept.
     */
    #[Override]
    public function resetRequestState(): void
    {
        $this->started = false;
        $this->sessionId = '';
        $this->idIsNew = false;
        $this->cookieCleared = false;
        $this->recoveredFromExpiry = false;
        $this->data = [];
        $this->metadata = null;
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
     * On an anonymous session that has idled past the timeout or failed a
     * validator, the session is transparently regenerated (fresh id, empty data)
     * and the request continues; only an authenticated session throws, so the
     * caller can force re-authentication (the PCI-DSS 8.2.8 rationale applies to
     * authenticated identity, not to a CSRF-only anonymous cookie). See
     * {@see recoveredFromExpiry()}.
     *
     * @throws SecurityException If an authenticated session fails idle-timeout or a validator.
     */
    public function startWithRequest(ServerRequestInterface $request): void
    {
        $this->recoveredFromExpiry = false;

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

        // FR-10: feed the stateless cookie handler the encrypted payload from its
        // companion request cookie before read(), so the session body persists
        // across requests instead of every request starting empty.
        if ($this->handler instanceof CookieSessionHandlerInterface) {
            /** @var mixed $payloadCookie */
            $payloadCookie = $request->getCookieParams()[$this->payloadCookieName()] ?? null;

            if (is_string($payloadCookie) && $payloadCookie !== '') {
                $this->handler->loadFromCookie($this->sessionId, $payloadCookie);
            }
        }

        $raw = $this->handler->read($this->sessionId);
        $isExistingSession = $raw !== '' && $raw !== false;

        if ($isExistingSession) {
            $decrypted = $this->decryptIfEnabled($raw);
            $this->loadStoredPayload($decrypted);
        }

        // FR-42: an existing session record whose metadata is absent (missing
        // _pulsar_meta, corrupt, or legacy) is untrusted. Adopting it would
        // re-home the session to the current IP/User-Agent with no validation or
        // idle-timeout check, so rotate to a fresh id and discard the loaded
        // state rather than silently trusting it.
        if ($isExistingSession && $this->metadata === null) {
            $this->data = [];
            $this->sessionId = $this->generateId();
            $this->idIsNew = true;
        }

        $ipAddress = $this->resolveClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        if ($this->metadata === null) {
            // New (or rotated) session: stamp baseline metadata and seed each
            // validator's initial state — notably the request fingerprint — so a
            // value exists to validate against on subsequent requests (FR-9).
            $metadata = new SessionMetadata(
                createdAt: time(),
                lastActivity: time(),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $this->metadata = $this->seedValidatorState($metadata, $request);
        } else {
            // PCI-DSS 8.2.8: Enforce idle timeout before updating lastActivity
            $idleTimeout = $this->config->idleTimeout;

            if ($idleTimeout > 0) {
                $idleSeconds = time() - $this->metadata->lastActivity;

                if ($idleSeconds > $idleTimeout) {
                    $this->recoverOrFail($request, SecurityException::sessionIdleExpired($idleSeconds, $idleTimeout));

                    return;
                }
            }

            // FR-28: preserve ALL stored metadata fields (notably the
            // fingerprint) on reload. withLastActivity copies them, unlike the
            // previous partial reconstruction that reset the fingerprint to null
            // and so silently disabled the fingerprint validator.
            $this->metadata = $this->metadata->withLastActivity(time());

            foreach ($this->validators as $validator) {
                if (!$validator->validate($this->metadata, $request)) {
                    $this->recoverOrFail($request, SecurityException::sessionValidationFailed($validator->getName()));

                    return;
                }
            }
        }

        $this->started = true;
    }

    /**
     * Whether the last {@see startWithRequest()} transparently regenerated the
     * session after an anonymous idle-timeout or validator failure. The session
     * middleware reads this to emit an info log line and a `session.expired`
     * request attribute; the request itself continues normally.
     */
    #[NoDiscard]
    public function recoveredFromExpiry(): bool
    {
        return $this->recoveredFromExpiry;
    }

    /**
     * An existing session failed idle-timeout or a validator. Destroy it, then
     * decide by identity: an anonymous session (CSRF-only, no security value in
     * failing the request) is transparently replaced by a fresh session so the
     * request continues, while an authenticated session throws so the caller can
     * force re-authentication (PCI-DSS 8.2.8, which targets authenticated identity).
     *
     * @throws SecurityException When the failed session carried an authenticated identity.
     */
    private function recoverOrFail(ServerRequestInterface $request, SecurityException $failure): void
    {
        $wasAuthenticated = $this->carriesAuthenticatedIdentity();

        $this->destroy();

        if ($wasAuthenticated) {
            throw $failure;
        }

        $this->beginFreshSession($request);
        $this->recoveredFromExpiry = true;
    }

    /**
     * Whether the loaded session carries an authenticated identity, using the same
     * marker key(s) the authentication layer writes into session data
     * ({@see \Pulsar\Config\SessionConfig::$authenticatedMarkerKeys}, defaulting to
     * the framework guard's `_pulsar_identity`). Keying the strict idle/validator
     * path off the authoritative session-data signal — the one SessionGuard,
     * SecurityContext, and AuthManager all read — rather than a parallel field means
     * the PCI-DSS 8.2.8 force-re-authentication gate cannot fail open by desyncing.
     */
    private function carriesAuthenticatedIdentity(): bool
    {
        foreach ($this->config->authenticatedMarkerKeys as $key) {
            if (($this->data[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start a brand-new anonymous session for the current request with a freshly
     * generated id (never reusing the expired cookie's id) and validator state
     * seeded from the request, so the replacement session validates cleanly on the
     * next request instead of failing again. The handler is already open at this
     * point (from {@see startWithRequest()}).
     */
    private function beginFreshSession(ServerRequestInterface $request): void
    {
        $this->data = [];
        $this->metadata = null;
        $this->cookieCleared = false;
        $this->sessionId = $this->generateId();
        $this->idIsNew = true;

        $metadata = new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: $this->resolveClientIp($request),
            userAgent: $request->getHeaderLine('User-Agent'),
        );

        $this->metadata = $this->seedValidatorState($metadata, $request);
        $this->started = true;
    }

    /**
     * Let each validator that seeds metadata stamp its initial state onto a new
     * session's metadata, so a value exists to validate against next request.
     */
    #[NoDiscard]
    private function seedValidatorState(SessionMetadata $metadata, ServerRequestInterface $request): SessionMetadata
    {
        foreach ($this->validators as $validator) {
            if ($validator instanceof SessionMetadataInitializerInterface) {
                $metadata = $validator->initializeMetadata($metadata, $request);
            }
        }

        return $metadata;
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
                $this->config->effectiveCookieName(),
                $this->sessionId,
                $this->config->lifetime > 0 ? $this->config->lifetime : null,
            );
        }

        if ($this->cookieCleared) {
            return $this->buildCookieHeader($this->config->effectiveCookieName(), '', 0);
        }

        return null;
    }

    /**
     * Build the `Set-Cookie` header carrying the encrypted session payload when a
     * stateless cookie handler is in use, or null otherwise.
     *
     * The cookie handler keeps no server-side state: the session body must travel
     * in its own cookie (the id cookie still carries the session id). Without this
     * the cookie handler's read()/write() never see the payload, so every request
     * starts with an empty session. {@see SessionMiddleware} emits this alongside
     * the id cookie via withAddedHeader.
     */
    #[NoDiscard]
    public function pendingPayloadCookieHeader(): ?string
    {
        if (!$this->handler instanceof CookieSessionHandlerInterface) {
            return null;
        }

        if ($this->cookieCleared) {
            return $this->buildCookieHeader($this->payloadCookieName(), '', 0);
        }

        if (!$this->started || $this->sessionId === '') {
            return null;
        }

        $value = $this->handler->getCookieValue($this->sessionId);

        if ($value === null) {
            return null;
        }

        // The encrypted payload is standard base64, whose characters (+, /, =)
        // are all valid cookie-octets per RFC 6265, so it travels unencoded and
        // round-trips identically regardless of how the entry point parsed it.
        return $this->buildCookieHeader(
            $this->payloadCookieName(),
            $value,
            $this->config->lifetime > 0 ? $this->config->lifetime : null,
        );
    }

    /**
     * Name of the companion cookie that carries the encrypted session payload for
     * the stateless cookie handler. Derived from the id cookie name (the `__Host-`
     * prefix, when present, stays valid with the suffix).
     */
    #[NoDiscard]
    private function payloadCookieName(): string
    {
        return $this->config->effectiveCookieName() . '_data';
    }

    /**
     * Build an RFC 6265 `Set-Cookie` header value from the session configuration.
     *
     * @param string $value   Cookie value (the session id, or '' to clear).
     * @param int|null $maxAge Max-Age in seconds; 0 expires immediately, null
     *                         omits the attribute (a browser-session cookie).
     */
    private function buildCookieHeader(string $name, string $value, ?int $maxAge): string
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

        $parts = [$name . '=' . $value];

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
