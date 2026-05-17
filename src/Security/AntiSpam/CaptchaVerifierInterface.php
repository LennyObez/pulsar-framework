<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

/**
 * Verifies an external CAPTCHA service response.
 *
 * Supports hCaptcha and Cloudflare Turnstile (not reCAPTCHA; privacy concern).
 * @api
 */
#[Api(since: '1.0.0')]
interface CaptchaVerifierInterface extends AntiSpamCheckInterface
{
    /**
     * Verify a CAPTCHA response token against the provider's API.
     *
     * @param string $token The CAPTCHA response token from the client
     * @param string $remoteIp The client IP address for verification
     * @return bool True if the token is valid
     */
    public function verify(string $token, string $remoteIp): bool;
}
