<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Config\SecurityConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function strtolower;

/**
 * Validates that HSTS (Strict-Transport-Security) is configured.
 *
 * Checks the security headers configuration for the presence of an HSTS header
 * and reports appropriate severity based on the target environment.
 */
#[Internal]
final readonly class HttpsReadinessCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'https-readiness';

    public function __construct(
        private SecurityConfig $securityConfig,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates HSTS (Strict-Transport-Security) header is configured';
    }

    public function check(string $environment): CheckResult
    {
        $headers = $this->securityConfig->headers->headers;
        $hasHsts = $this->hasHeader($headers, 'Strict-Transport-Security');

        if ($hasHsts) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'HSTS header is configured',
            );
        }

        return match ($environment) {
            'production' => CheckResult::error(
                self::CHECK_NAME,
                'Strict-Transport-Security header is not configured',
                [
                    'Add a Strict-Transport-Security header to your security headers configuration.',
                    'Recommended value: "max-age=31536000; includeSubDomains".',
                    'Ensure your TLS certificate is valid before enabling HSTS.',
                ],
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'Strict-Transport-Security header is not configured',
                [
                    'Configure HSTS in staging to validate behavior before production.',
                    'Use a shorter max-age for staging (e.g., "max-age=86400").',
                ],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'HSTS not required in local environment',
            ),
        };
    }

    /**
     * Check if a header exists in the headers map (case-insensitive key match).
     *
     * @param array<string, string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        $lowerName = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower($key) === $lowerName) {
                return true;
            }
        }

        return false;
    }
}
