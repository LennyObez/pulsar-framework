<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Config\GitHubIntegrityConfig;

#[CoversClass(GitHubIntegrityConfig::class)]
final class GitHubIntegrityConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $config = new GitHubIntegrityConfig();

        self::assertFalse($config->enabled);
        self::assertSame('', $config->repository);
        self::assertSame('main', $config->branch);
        self::assertNull($config->token);
        self::assertSame(15, $config->timeoutSeconds);
        self::assertSame(30, $config->rateLimitRequestsPerHour);
        self::assertSame('.modified-backup', $config->modifiedFileSuffix);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = GitHubIntegrityConfig::fromArray([
            'enabled' => true,
            'repository' => 'org/repo',
            'branch' => 'develop',
            'token' => 'ghp_test123',
            'timeout_seconds' => 30,
            'rate_limit_requests_per_hour' => 60,
            'modified_file_suffix' => '.bak',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('org/repo', $config->repository);
        self::assertSame('develop', $config->branch);
        self::assertSame('ghp_test123', $config->token);
        self::assertSame(30, $config->timeoutSeconds);
        self::assertSame(60, $config->rateLimitRequestsPerHour);
        self::assertSame('.bak', $config->modifiedFileSuffix);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
    {
        $config = GitHubIntegrityConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('', $config->repository);
        self::assertSame('main', $config->branch);
        self::assertNull($config->token);
    }

    #[Test]
    public function fromArrayReadsTokenFromEnvWhenNotProvided(): void
    {
        $previousValue = getenv('GITHUB_INTEGRITY_TOKEN');
        putenv('GITHUB_INTEGRITY_TOKEN=env_token_value');

        try {
            $config = GitHubIntegrityConfig::fromArray([
                'enabled' => true,
                'repository' => 'org/repo',
            ]);

            self::assertSame('env_token_value', $config->token);
        } finally {
            if ($previousValue === false) {
                putenv('GITHUB_INTEGRITY_TOKEN');
            } else {
                putenv("GITHUB_INTEGRITY_TOKEN={$previousValue}");
            }
        }
    }

    #[Test]
    public function fromArrayExplicitTokenOverridesEnv(): void
    {
        $previousValue = getenv('GITHUB_INTEGRITY_TOKEN');
        putenv('GITHUB_INTEGRITY_TOKEN=env_token');

        try {
            $config = GitHubIntegrityConfig::fromArray([
                'token' => 'explicit_token',
            ]);

            self::assertSame('explicit_token', $config->token);
        } finally {
            if ($previousValue === false) {
                putenv('GITHUB_INTEGRITY_TOKEN');
            } else {
                putenv("GITHUB_INTEGRITY_TOKEN={$previousValue}");
            }
        }
    }

    #[Test]
    public function fromArrayTokenIsNullWhenNoEnvAndNotProvided(): void
    {
        $previousValue = getenv('GITHUB_INTEGRITY_TOKEN');
        putenv('GITHUB_INTEGRITY_TOKEN');

        try {
            $config = GitHubIntegrityConfig::fromArray([]);

            self::assertNull($config->token);
        } finally {
            if ($previousValue !== false) {
                putenv("GITHUB_INTEGRITY_TOKEN={$previousValue}");
            }
        }
    }
}
