<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use SensitiveParameter;

/**
 * What seals a session payload where it rests, and opens it again.
 *
 * WHY THIS CONTRACT EXISTS, and it is not for a second implementation.
 * {@see SessionEncryption} is `#[Internal]` to this module, and the compliance
 * evidence gatherer said so in its own source: the session cipher could not be
 * MEASURED from outside the Security module, because measuring it means holding
 * one and importing an `#[Internal]` class across a module boundary is exactly
 * what `composer boundary:check` refuses. So the strongest thing a compliance
 * report could say about session payloads at rest was that a class had been
 * constructed — a configuration read, at grade
 * {@see \Pulsar\Compliance\Control\ObservationGrade::Declared} — and the
 * cryptographic evidence standing beside it was `extension_loaded('sodium')`,
 * which ADR-0061 demoted to
 * {@see \Pulsar\Compliance\Control\ObservationGrade::Available} for answering
 * the same on a deployment that encrypts everything and one that encrypts
 * nothing.
 *
 * Published here, the two operations an assessor has to be able to run are
 * reachable without reaching into the module — the same shape
 * {@see \Pulsar\Security\Crypto\TokenizationServiceInterface} already has for the
 * token vault, and for the same reason.
 * {@see \Pulsar\Compliance\Evidence\SessionSealObserver} exercises it and reports
 * what came back.
 *
 * WHAT IS PUBLISHED IS DELIBERATELY THE MINIMUM. Key rotation, key identifiers,
 * the key ring the rotation window reads and the layout of the sealed form are
 * all implementation matters and stay inside {@see SessionEncryption}, which
 * remains `#[Internal]`. Nothing here lets a caller choose a key, name an
 * algorithm or read one.
 *
 * THE CONTEXT PARAMETERS ARE PART OF THE CONTRACT, not decoration. The three of
 * them — session id, handler type, domain — identify the session the payload
 * belongs to, and an implementation must BIND the sealed form to them, so that a
 * payload sealed for one session cannot be opened as another. Combined with
 * authentication of the sealed bytes themselves, that is what stops a payload
 * transplant between sessions or between handlers.
 *
 * Two consequences an implementer must accept, both of them measured:
 *
 *  - {@see decrypt()} MUST throw when the sealed bytes have been modified. An
 *    implementation that returns a value for modified input is unauthenticated
 *    and does not satisfy this contract, however opaque its output looks.
 *  - {@see decrypt()} MUST throw when the context does not match the one the
 *    payload was sealed under. An implementation that returns the payload for
 *    another session id is not binding the context, and the transplant it was
 *    supposed to prevent is available.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface SessionPayloadCipherInterface
{
    /**
     * Seal a session payload for one session context.
     *
     * @param string $data        The serialized session payload
     * @param string $sessionId   The session the payload belongs to
     * @param string $handlerType The storage handler the sealed form is written through
     * @param string $domain      The cookie domain the session is scoped to
     *
     * @return string The sealed form — the bytes the handler will store
     *
     * @throws SecurityException If the payload could not be sealed
     */
    public function encrypt(
        #[SensitiveParameter]
        string $data,
        string $sessionId,
        string $handlerType,
        string $domain,
    ): string;

    /**
     * Open a sealed session payload, refusing anything that was not sealed for
     * exactly this context.
     *
     * @param string $encrypted   The sealed form, as {@see encrypt()} returned it
     * @param string $sessionId   The session the payload is claimed to belong to
     * @param string $handlerType The storage handler it was read back through
     * @param string $domain      The cookie domain the session is scoped to
     *
     * @return string The original payload, byte for byte
     *
     * @throws SecurityException If the sealed form was modified, was sealed under
     *         a different context, or cannot be opened with any key this
     *         deployment holds
     */
    public function decrypt(
        string $encrypted,
        string $sessionId,
        string $handlerType,
        string $domain,
    ): string;
}
