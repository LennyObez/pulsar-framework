<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;
use function strtolower;

/**
 * Validates security headers are configured.
 *
 * Checks for X-Content-Type-Options (noSniff) and X-Frame-Options headers
 * in the security headers configuration.
 */
#[Internal]
final readonly class SecurityHeadersReadinessCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'security-headers';

    public function __construct(
        private SecurityHeadersConfig $headersConfig,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates X-Content-Type-Options and X-Frame-Options headers are configured';
    }

    public function check(string $environment): CheckResult
    {
        $headers = $this->headersConfig->headers;

        $hasNoSniff = $this->hasHeader($headers, 'X-Content-Type-Options');
        $hasFrameOptions = $this->hasHeader($headers, 'X-Frame-Options');

        $missing = [];

        if (!$hasNoSniff) {
            $missing[] = 'X-Content-Type-Options';
        }

        if (!$hasFrameOptions) {
            $missing[] = 'X-Frame-Options';
        }

        if ($missing === []) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'Security headers (X-Content-Type-Options, X-Frame-Options) are configured',
            );
        }

        $missingList = implode(', ', $missing);
        $recommendations = [];

        if (!$hasNoSniff) {
            $recommendations[] = 'Add X-Content-Type-Options: nosniff to prevent MIME type sniffing.';
        }

        if (!$hasFrameOptions) {
            $recommendations[] = 'Add X-Frame-Options: DENY or SAMEORIGIN to prevent clickjacking.';
        }

        return match ($environment) {
            'production' => CheckResult::error(
                self::CHECK_NAME,
                sprintf('Missing security headers: %s', $missingList),
                $recommendations,
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                sprintf('Missing security headers: %s', $missingList),
                $recommendations,
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Security headers not enforced in local environment',
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
