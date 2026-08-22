<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Handler;

use Pulsar\Api\Internal;

/**
 * A session handler that keeps no server-side state and instead carries the
 * session payload in a client cookie.
 *
 * Such a handler's read()/write() cannot reach a server store, so the session
 * lifecycle must hand it the request's payload cookie before read() and emit its
 * encrypted value as a Set-Cookie after write(). {@see \Pulsar\Security\Session\SessionManager}
 * detects this interface and wires those two hooks into the request lifecycle.
 */
#[Internal]
interface CookieSessionHandlerInterface extends SessionHandlerInterface
{
    /**
     * Inject the encrypted payload received from the client cookie so the next
     * read() for this session id returns the decrypted body.
     */
    public function loadFromCookie(string $sessionId, string $encryptedCookie): void;

    /**
     * The encrypted payload to send back to the client for this session id, or
     * null when nothing was written this request.
     */
    public function getCookieValue(string $sessionId): ?string;
}
