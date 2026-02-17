<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Wizard;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Exception\WizardException;
use Pulsar\Security\Session\SessionInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function hash_equals;
use function is_array;
use function is_string;

/**
 * Manages cryptographically random, single-use resume tokens.
 *
 * Resume tokens allow users to continue a wizard later.
 * Single-use consumption uses CAS (Compare-And-Swap) semantics --
 * atomic check + delete prevents race conditions.
 */
#[Api(since: '1.0.0')]
final class ResumeTokenManager
{
    private const string SESSION_PREFIX = '_wizard_resume_';

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly SessionInterface $session,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Issue a resume token for a wizard session.
     *
     * The token is bound to the session and stored server-side.
     */
    public function issue(string $wizardId): string
    {
        $token = bin2hex($this->randomizer->getBytes(32));

        $this->session->set(self::SESSION_PREFIX . $wizardId, [
            'token' => $token,
            'issued_at' => time(),
        ]);

        return $token;
    }

    /**
     * Consume a resume token (single-use with CAS semantics).
     *
     * Atomically checks and deletes the token to prevent replay.
     *
     * @throws WizardException When token is invalid or already consumed
     */
    public function consume(string $wizardId, string $submittedToken): void
    {
        $key = self::SESSION_PREFIX . $wizardId;
        $stored = $this->session->get($key);

        if (!is_array($stored) || !is_string($stored['token'] ?? null)) {
            throw WizardException::invalidResumeToken();
        }

        // CAS: atomically check + delete
        $this->session->remove($key);

        /** @var string $storedToken */
        $storedToken = $stored['token'];

        if (!hash_equals($storedToken, $submittedToken)) {
            throw WizardException::invalidResumeToken();
        }
    }
}
