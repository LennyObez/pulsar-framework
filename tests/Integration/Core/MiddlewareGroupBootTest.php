<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\RateLimitMiddleware;
use Pulsar\Security\Csrf\CsrfMiddleware;

use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_object;
use function is_string;
use function ltrim;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;
use function uniqid;

/**
 * What the `web` and `api` groups contain after a real boot, and nothing else.
 *
 * The claim under test is one the matrix made and the code did not honour: that
 * both default groups carry the rate limiter. They did not. The limiter was added
 * to `MiddlewareAliasConfig::defaultGroups()`, a static array with no framework-side
 * caller, while `SecurityWiring` — the code that actually registers the groups at
 * boot — was left alone. Reading that array would have agreed with the claim; the
 * running application never did.
 *
 * So this boots a Kernel and asks the registry it produced. It also asserts the
 * failure the naive fix would have shipped: `SecurityWiring` binds the limiter only
 * when `rate_limiting.enabled` is true, so naming the class unconditionally in a
 * group costs nothing at boot and returns 500 on the first request to any route in
 * that group.
 */
final class MiddlewareGroupBootTest extends TestCase
{
    private string $tempDir = '';

    protected function tearDown(): void
    {
        $cwd = getcwd();

        if ($cwd === false || $this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }

        $relative = str_starts_with($this->tempDir, $cwd)
            ? ltrim(substr($this->tempDir, strlen($cwd)), '/\\')
            : $this->tempDir;
        $safe = SafePath::resolveUnderCwd($relative);

        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }

        $this->tempDir = '';
    }

    #[Test]
    public function bothDefaultGroupsCarryTheRateLimiterWhenItIsEnabled(): void
    {
        $kernel = $this->boot(rateLimiting: true);
        $registry = $this->registry($kernel);

        self::assertTrue(
            $this->groupCarriesRateLimiter($registry, 'web'),
            'the web group must throttle: browser-facing flows are what automation targets',
        );
        self::assertTrue(
            $this->groupCarriesRateLimiter($registry, 'api'),
            'the api group must throttle',
        );
    }

    #[Test]
    public function neitherGroupNamesTheLimiterWhenItIsDisabled(): void
    {
        $kernel = $this->boot(rateLimiting: false);
        $registry = $this->registry($kernel);

        self::assertFalse($this->groupCarriesRateLimiter($registry, 'web'));
        self::assertFalse($this->groupCarriesRateLimiter($registry, 'api'));
    }

    /**
     * The limiter runs before the work an unauthenticated flood would otherwise
     * force: session lookup and CSRF token comparison. Asserted here rather than on
     * the static table, where the limiter never appears and the comparison was
     * between two absent entries.
     */
    #[Test]
    public function theLimiterPrecedesCsrfInTheWebGroup(): void
    {
        $registry = $this->registry($this->boot(rateLimiting: true));

        $limiter = $this->positionOf($registry, 'web', RateLimitMiddleware::class);
        $csrf = $this->positionOf($registry, 'web', CsrfMiddleware::class);

        self::assertGreaterThanOrEqual(0, $limiter, 'the web group carries no rate limiter');
        self::assertGreaterThanOrEqual(0, $csrf, 'the web group carries no CSRF middleware');
        self::assertLessThan($csrf, $limiter);
    }

    /**
     * @param class-string $class
     *
     * @return int position within the resolved group, or -1 when absent
     */
    private function positionOf(MiddlewareRegistry $registry, string $group, string $class): int
    {
        $position = 0;

        foreach ($registry->resolve($group) as $middleware) {
            if ($middleware === $class || $middleware instanceof $class) {
                return $position;
            }

            ++$position;
        }

        return -1;
    }

    /**
     * The general form of the defect, which is worth more than the specific one:
     * a group may not name a class the container cannot produce. Resolving happens
     * at dispatch, so the cost of getting this wrong is a 500 on a live route
     * rather than a failure at boot where someone would see it.
     */
    #[Test]
    public function everyMiddlewareInEveryDefaultGroupCanActuallyBeResolved(): void
    {
        foreach ([true, false] as $rateLimiting) {
            $kernel = $this->boot($rateLimiting);
            $registry = $this->registry($kernel);

            foreach (['web', 'api'] as $group) {
                foreach ($registry->resolve($group) as $middleware) {
                    if (is_string($middleware)) {
                        self::assertTrue(
                            $kernel->container()->has($middleware),
                            "group '$group' names $middleware, which the container cannot resolve "
                            . '(rate_limiting.enabled=' . ($rateLimiting ? 'true' : 'false') . ')',
                        );
                    }
                }
            }

            $this->tearDown();
        }
    }

    private function groupCarriesRateLimiter(MiddlewareRegistry $registry, string $group): bool
    {
        foreach ($registry->resolve($group) as $middleware) {
            if ($middleware instanceof RateLimitMiddleware) {
                return true;
            }

            if (is_string($middleware) && $middleware === RateLimitMiddleware::class) {
                return true;
            }
        }

        return false;
    }

    private function registry(Kernel $kernel): MiddlewareRegistry
    {
        $registry = $kernel->container()->get(MiddlewareRegistry::class);

        self::assertTrue(is_object($registry) && $registry instanceof MiddlewareRegistry);

        return $registry;
    }

    private function boot(bool $rateLimiting): Kernel
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);

        $this->tempDir = $cwd . '/var/tmp_pulsar_mw_group_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        file_put_contents(
            $this->tempDir . '/app.php',
            "<?php return ['name' => 'TestApp', 'env' => 'local', 'debug' => false, 'timezone' => 'UTC', 'locale' => 'en'];",
        );
        file_put_contents(
            $this->tempDir . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "null", "level" => "debug", '
            . '"channels" => ["null" => ["driver" => "stream", "stream" => "php://memory"]]]];',
        );
        file_put_contents(
            $this->tempDir . '/security.php',
            '<?php return ["session" => [], "csrf" => ["enabled" => false], "headers" => [], '
            . '"rate_limiting" => ["enabled" => ' . ($rateLimiting ? 'true' : 'false') . ']];',
        );
        file_put_contents($this->tempDir . '/cache.php', '<?php return ["enabled" => true];');

        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->boot();

        return $kernel;
    }
}
