<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;

use function fnmatch;
use function realpath;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;

use const DIRECTORY_SEPARATOR;
use const FNM_PATHNAME;

/**
 * Enforces environment-level, path-level, and concurrency constraints for MCP operations.
 */
#[Internal]
final class McpAccessGate implements McpAccessGateInterface
{
    private int $activeActions = 0;

    /**
     * F32.4 / F26.3 family: track whether we've already logged the
     * environment-gate pass for this gate's lifetime. Without
     * this flag the audit warning would fire on every tool call,
     * which both spams the log and obscures the actual pattern
     * we want to surface (a single confirmation per process boot
     * that the env-var gate was opened).
     */
    private bool $environmentPassLogged = false;

    public function __construct(
        private readonly McpSecurityConfig $securityConfig,
        private readonly EnvironmentMode $mode,
        private readonly Environment $environment,
        private readonly string $projectRoot,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function assertEnvironmentAllowed(): void
    {
        match ($this->mode) {
            EnvironmentMode::Staging => $this->assertStagingConfirmed(),
            EnvironmentMode::Production => $this->assertProductionConfirmed(),
            EnvironmentMode::Local => null,
        };
    }

    public function assertPathAllowed(string $path): void
    {
        if (str_contains($path, '..')) {
            throw McpSecurityException::pathNotAllowed($path);
        }

        $normalizedRoot = $this->normalizePath($this->projectRoot);
        $absolute = $this->buildAbsolutePath($path, $normalizedRoot);

        if (file_exists($absolute)) {
            $real = realpath($absolute);

            if ($real === false) {
                throw McpSecurityException::pathNotAllowed($path);
            }

            if (!str_starts_with($real, $normalizedRoot)) {
                throw McpSecurityException::pathNotAllowed($path);
            }

            $checkPath = $real;
        } else {
            $checkPath = $absolute;
        }

        $this->assertPathMatchesAllowlist($checkPath, $normalizedRoot, $path);
    }

    public function assertConcurrencyAllowed(): void
    {
        if ($this->activeActions >= $this->securityConfig->maxConcurrentActions) {
            throw McpSecurityException::concurrencyLimited();
        }

        $this->activeActions++;
    }

    public function releaseConcurrencySlot(): void
    {
        if ($this->activeActions > 0) {
            $this->activeActions--;
        }
    }

    private function assertStagingConfirmed(): void
    {
        $confirm = $this->environment->get('MCP_STAGING_CONFIRM');

        if ($confirm !== '1' && $confirm !== 'true') {
            throw McpSecurityException::environmentBlocked($this->mode->value);
        }

        $this->logEnvironmentPass('staging', 'MCP_STAGING_CONFIRM');
    }

    private function assertProductionConfirmed(): void
    {
        $confirm = $this->environment->get('MCP_PRODUCTION_CONFIRM');

        if ($confirm !== '1' && $confirm !== 'true') {
            throw McpSecurityException::environmentBlocked($this->mode->value);
        }

        $this->logEnvironmentPass('production', 'MCP_PRODUCTION_CONFIRM');
    }

    /**
     * F32.4 / F32.M2: environment-variable-only gates are
     * vulnerable to the F11.1 family of env injections. We
     * cannot prevent that at this layer (the operator owns the
     * env), but we MUST emit a high-priority log entry so the
     * pass shows up in centralised audit. Together with a
     * second-factor recommendation in the docblock — operators
     * SHOULD also pin the confirmation value via an
     * out-of-band signed config file — this turns the gate
     * from a silent flip into a recorded event the SOC can
     * review.
     *
     * We log only once per gate lifetime to avoid drowning the
     * signal: the first request of the boot opens the gate,
     * subsequent ones are uninteresting.
     */
    private function logEnvironmentPass(string $environment, string $envVar): void
    {
        if ($this->environmentPassLogged) {
            return;
        }
        $this->environmentPassLogged = true;

        $this->logger->warning(
            'MCP environment gate opened via env var',
            [
                'environment' => $environment,
                'env_var' => $envVar,
                'recommendation' => 'Pair the env var with an out-of-band confirmation '
                    . 'channel (signed config file, audit log, manual maintainer ack) '
                    . 'so an attacker who controls the env cannot unilaterally enable '
                    . 'MCP for the deployment.',
            ],
        );
    }

    private function buildAbsolutePath(string $path, string $normalizedRoot): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);

        return $normalizedRoot . DIRECTORY_SEPARATOR . $normalized;
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    private function assertPathMatchesAllowlist(string $absolute, string $normalizedRoot, string $originalPath): void
    {
        $relative = $absolute;

        if (str_starts_with($absolute, $normalizedRoot . DIRECTORY_SEPARATOR)) {
            $relative = substr($absolute, strlen($normalizedRoot) + 1);
        }

        foreach ($this->securityConfig->pathAllowlist as $pattern) {
            if (fnmatch($pattern, $relative, FNM_PATHNAME)) {
                return;
            }

            if (fnmatch($pattern, $absolute, FNM_PATHNAME)) {
                return;
            }
        }

        throw McpSecurityException::pathNotAllowed($originalPath);
    }
}
