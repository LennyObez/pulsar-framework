<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Client\HttpClientException;
use Pulsar\Http\Client\HttpClientInterface;

use function is_array;

/**
 * hCaptcha verification adapter.
 *
 * Verifies CAPTCHA response tokens against the hCaptcha siteverify API.
 * Falls back to failure on network errors to avoid blocking legitimate users.
 */
#[Internal(reason: 'Use CaptchaVerifierInterface')]
final readonly class HCaptchaVerifier implements CaptchaVerifierInterface
{
    private const string VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $siteKey,
        private string $secretKey,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'captcha';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        if ($context->captchaToken === null || $context->captchaToken === '') {
            return AntiSpamCheckResult::fail(
                $this->name(),
                30,
                'CAPTCHA response token is missing',
            );
        }

        $remoteIp = $context->ipHash;
        $verified = $this->verify($context->captchaToken, $remoteIp);

        if (!$verified) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                30,
                'CAPTCHA verification failed',
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }

    #[Override]
    public function verify(string $token, string $remoteIp): bool
    {
        try {
            $response = $this->httpClient->post(self::VERIFY_URL, [
                'form' => [
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                    'sitekey' => $this->siteKey,
                ],
            ]);

            if (!$response->ok()) {
                $this->logger->warning('hCaptcha verification request failed', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            $data = $response->json();

            if (!is_array($data)) {
                return false;
            }

            return ($data['success'] ?? false) === true;
        } catch (HttpClientException $e) {
            $this->logger->error('hCaptcha verification network error', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
