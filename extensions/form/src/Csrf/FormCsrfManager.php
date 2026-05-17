<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Csrf;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Config\CsrfFormConfig;
use Pulsar\Extension\Form\Exception\CsrfException;
use Pulsar\Security\Session\SessionInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function hash;
use function hash_equals;
use function is_array;
use function is_int;
use function is_string;
use function time;

/**
 * Form-specific CSRF token manager.
 *
 * Generates tokens bound to: session ID + form ID + intended action + TTL.
 * Each form instance gets its own token, preventing token reuse across
 * different forms or actions.
 * @api
 */
#[Api(since: '1.0.0')]
final class FormCsrfManager
{
    private const string SESSION_PREFIX = '_form_csrf_';

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly CsrfFormConfig $config,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Generate a CSRF token for a specific form.
     *
     * The token is bound to the session, form ID, and intended action.
     */
    public function generate(string $formId, string $action = ''): string
    {
        $secret = bin2hex($this->randomizer->getBytes(32));
        $timestamp = time();

        $this->session->set(self::SESSION_PREFIX . $formId, [
            'secret' => $secret,
            'action' => $action,
            'timestamp' => $timestamp,
        ]);

        return $this->computeToken($secret, $formId, $action);
    }

    /**
     * Validate a submitted CSRF token for a specific form.
     *
     * @throws CsrfException When token is missing, invalid, or expired
     */
    public function validate(string $submittedToken, string $formId, string $action = ''): void
    {
        if ($submittedToken === '') {
            throw CsrfException::tokenMissing();
        }

        $key = self::SESSION_PREFIX . $formId;
        $stored = $this->session->get($key);

        if (!is_array($stored) || !is_string($stored['secret'] ?? null)) {
            throw CsrfException::tokenInvalid();
        }

        /** @var array{secret: string, action: string, timestamp: int} $stored */
        $storedAction = is_string($stored['action'] ?? null) ? $stored['action'] : '';
        $storedTimestamp = is_int($stored['timestamp'] ?? null) ? $stored['timestamp'] : 0;

        // Check TTL
        if ((time() - $storedTimestamp) > $this->config->ttl) {
            $this->session->remove($key);
            throw CsrfException::tokenExpired();
        }

        // Verify token (constant-time comparison)
        $expected = $this->computeToken($stored['secret'], $formId, $storedAction);

        if (!hash_equals($expected, $submittedToken)) {
            throw CsrfException::tokenInvalid();
        }

        // Verify action matches
        if ($storedAction !== $action) {
            throw CsrfException::tokenInvalid();
        }

        // Consume the token (rotate after validation)
        $this->session->remove($key);
    }

    /**
     * Compute the token hash from secret, form ID, and action.
     */
    private function computeToken(string $secret, string $formId, string $action): string
    {
        $sessionId = $this->session->id();

        return hash('sha256', "$sessionId:$formId:$action:$secret");
    }
}
