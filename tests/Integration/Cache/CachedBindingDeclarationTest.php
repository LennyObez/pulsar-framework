<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedRouteTable;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\RouteCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Boot\CachedRouteReconstructor;
use Pulsar\Core\Kernel;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function array_key_exists;
use function bin2hex;
use function count;
use function file;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function mkdir;
use function putenv;
use function random_bytes;
use function realpath;
use function scandir;
use function str_contains;
use function str_replace;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const FILE_IGNORE_NEW_LINES;

/**
 * The escape hatch has to exist in the deployment it was written for.
 *
 * `Router::model($p, $class, scope: BindingScope::Root)` is the only channel by
 * which an application declares a deliberately unscoped nested child, and
 * `Router::model($p, $class, scope: BindingScope::Contained, parentRelation: …)`
 * the only one by which it names a relation a path segment cannot spell. Both
 * are called from `routes/web.php`, and `Kernel::boot()` runs the project route
 * files only when the router has no routes — so a deployment that ran
 * `pulsar optimize` skipped every one of those calls.
 *
 * The result was not a bypass; each loss fails closed. It was worse in a
 * quieter way: a fail-closed design whose only escape hatch is unavailable in
 * production is a design an operator switches off. A `Root` declaration became
 * a scoped lookup through a relation that does not exist (404), and a declared
 * relation became the path's (500), in production and not in development.
 *
 * These tests boot a real kernel against a real project directory, warm the
 * cache the way `pulsar optimize` does, boot again, and assert the declaration
 * still decides the scope — with a sentinel proving the route file was skipped,
 * so the declaration can only have come out of the cache.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(FrameworkCache::class)]
#[CoversClass(RouteCache::class)]
#[CoversClass(CachedRouteTable::class)]
#[CoversClass(Router::class)]
final class CachedBindingDeclarationTest extends TestCase
{
    private string $basePath;

    /** @var array<string, string|false> Original env values to restore. */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // realpath() first: the kernel resolves the base path it derives, and a
        // literal short form would not compare equal to it.
        $temp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        $this->basePath = $temp . DIRECTORY_SEPARATOR . 'pulsar_cached_binding_' . bin2hex(random_bytes(8));
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0o750, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0o750, true);
        // FrameworkCache::warm() scans <base>/src to compute allowed classes.
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'src', 0o750, true);

        $this->writeMinimalConfigs();
        $this->writeRouteFile();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $original) {
            if ($original === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $original);
            }
        }
        $this->savedEnv = [];

        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function aRootDeclarationSurvivesTheRouteCache(): void
    {
        $key = $this->prepareEnvironment();

        // Cold boot: the route files run, and with them every Router::model()
        // call. This is the state the declaration has always worked in.
        $cold = new Kernel(configManager: new ConfigManager(configPath: $this->configPath()));
        $cold->boot();

        $coldRouter = $this->routerOf($cold);
        self::assertSame(1, $this->routeFileRuns(), 'the cold boot must read routes/web.php exactly once');
        self::assertNotSame([], $coldRouter->explicitBindings);
        self::assertSame(BindingScope::Root, $this->decidedScope($coldRouter));

        // Warm the cache exactly as `pulsar optimize` does: the router's routes
        // AND the declarations registered alongside them.
        $this->warm($key, $coldRouter);

        // Boot again, from scratch. The route table now comes out of the cache,
        // which is precisely why the route files are not read.
        $warm = new Kernel(configManager: new ConfigManager(configPath: $this->configPath()));
        $warm->boot();

        $profile = $warm->bootProfile();
        self::assertNotNull($profile);
        self::assertTrue($profile->cacheHit, 'the second boot must load the cache, or it proves nothing');
        self::assertSame(
            1,
            $this->routeFileRuns(),
            'the cached boot must NOT re-read routes/web.php — if it did, the declaration '
            . 'could have come from the file rather than from the cache',
        );

        $warmRouter = $this->routerOf($warm);
        self::assertNotSame(
            [],
            $warmRouter->routes,
            'the cached route table must have been applied',
        );

        // The assertion the whole change exists for: the declaration decides the
        // scope on a boot that never executed the call that made it.
        self::assertSame(BindingScope::Root, $this->decidedScope($warmRouter));
    }

    #[Test]
    public function aContainedDeclarationSurvivesTheRouteCache(): void
    {
        // The other declaration, and the one that exercises the revalidation
        // path: a Contained binding carries a relation, and ExplicitBinding's
        // constructor is the sole judge of whether that pairing is coherent.
        // unserialize() does not run constructors, so RouteCache::load() reruns
        // them; a Contained binding that came back without its relation would be
        // caught there rather than resolving through a null relation.
        $key = $this->prepareEnvironment();

        $cold = new Kernel(configManager: new ConfigManager(configPath: $this->configPath()));
        $cold->boot();

        $this->warm($key, $this->routerOf($cold));

        $warm = new Kernel(configManager: new ConfigManager(configPath: $this->configPath()));
        $warm->boot();

        $meta = $this->decide($this->routerOf($warm), '/users/{user}/blog-posts/{post}', 'showPost');

        self::assertArrayHasKey('post', $meta);
        self::assertSame(BindingScope::Contained, $meta['post']->scope);
        self::assertSame(
            'posts',
            $meta['post']->parentRelation,
            'the relation the declaration named, not the `blog-posts` segment no property can be called',
        );
    }

    #[Test]
    public function aRouteCacheWithoutDeclarationsIsWhatTheRouteUsedToGet(): void
    {
        // The control, and the shape of the defect. A router carrying the cached
        // routes and no declarations is exactly what a cached boot produced
        // before the route cache carried them: nothing says what {user} is, so
        // the containment the URL asserts cannot be checked, and the route
        // refuses. Loud, and unavailable-in-production all the same.
        $router = new Router();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $this->decidedScope($router);
    }

    #[Test]
    public function aCacheThisBuildCannotVouchForFallsBackToTheRouteFiles(): void
    {
        // Refusing a cache has to be a fallback, never a failure — otherwise the
        // schema-version and payload-shape guards that reject an older
        // routes.cache.bin would trade one unavailable escape hatch for another.
        // A refused cache must put the boot back where the declarations are made
        // for real: reading routes/web.php.
        $key = $this->prepareEnvironment();

        $cold = new Kernel(configManager: new ConfigManager(configPath: $this->configPath()));
        $cold->boot();
        $this->warm($key, $this->routerOf($cold));

        $manifestPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'manifest.json';
        self::assertFileExists($manifestPath);

        $manifest = file_get_contents($manifestPath);
        self::assertIsString($manifest);
        file_put_contents($manifestPath, str_replace('"app_env"', '"app_environment"', $manifest));

        $warm = new Kernel(configManager: new ConfigManager(configPath: $this->configPath()));
        $warm->boot();

        $profile = $warm->bootProfile();
        self::assertNotNull($profile);
        self::assertFalse($profile->cacheHit, 'a manifest this build cannot vouch for must not be loaded');
        self::assertSame(2, $this->routeFileRuns(), 'refusing the cache must fall back to reading the route files');
        self::assertSame(BindingScope::Root, $this->decidedScope($this->routerOf($warm)));
    }

    /**
     * Warm the framework cache from a booted router, the way `pulsar optimize`
     * does: routes and declarations read off the same router, written as one
     * payload under one signature.
     */
    private function warm(string $key, Router $router): void
    {
        $sourceManager = new ConfigManager(configPath: $this->configPath());
        $sourceManager->load();

        $cache = new FrameworkCache($this->basePath, MasterKey::fromHex($key), new HmacService());
        $cache->warm(
            $sourceManager->repository(),
            $router->routes,
            [],
            'local',
            false,
            CachedRouteReconstructor::forCache($router->explicitBindings),
        );
    }

    /**
     * The scope the binding layer decides for `{setting}` on the nested route.
     *
     * Built the way `ModelBindingWiring::compose()` builds it — from
     * `Router::$explicitBindings` and nothing else — so this asserts the
     * declaration's effect rather than its presence in a list.
     *
     * @throws ModelBindingException When the route path asserts a containment that cannot be checked
     */
    private function decidedScope(Router $router): BindingScope
    {
        return $this->decide($router, '/users/{user}/settings/{setting}', 'showSetting')['setting']->scope;
    }

    /**
     * @return array<string, \Pulsar\Routing\Binding\BindingMeta>
     *
     * @throws ModelBindingException
     */
    private function decide(Router $router, string $path, string $method): array
    {
        $parameters = [];

        foreach (['user', 'setting', 'post'] as $name) {
            if (str_contains($path, '{' . $name . '}')) {
                $parameters[$name] = '1';
            }
        }

        return new BindingResolver($router->explicitBindings)->resolveForRoute(
            new MatchedRoute(
                new Route([Method::GET], $path, [CachedBindingController::class, $method]),
                $parameters,
            ),
            CachedBindingController::class,
            $method,
        );
    }

    private function routerOf(Kernel $kernel): Router
    {
        /** @var Router $router */
        $router = $kernel->container()->get(Router::class);

        return $router;
    }

    /**
     * How many times `routes/web.php` has been executed in this test.
     *
     * The sentinel is what makes "the declaration came from the cache" a fact
     * rather than an inference: a warm boot that re-read the file would produce
     * the same declaration for the wrong reason.
     */
    private function routeFileRuns(): int
    {
        $sentinel = $this->basePath . DIRECTORY_SEPARATOR . 'routes-loaded.log';

        if (!is_file($sentinel)) {
            return 0;
        }

        $lines = file($sentinel, FILE_IGNORE_NEW_LINES);

        return $lines === false ? 0 : count($lines);
    }

    private function prepareEnvironment(): string
    {
        $key = bin2hex(random_bytes(32));

        $this->withEnv('PULSAR_MASTER_KEY', $key);
        $this->withEnv('CACHE_ENCRYPT', null);
        $this->withEnv('PULSAR_BASE_PATH', $this->basePath);
        // Local, not production: this exercises the cache-hit path, not the
        // signed-build-artifact path `pulsar build` writes.
        $this->withEnv('APP_ENV', 'local');

        return $key;
    }

    private function configPath(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * Set (or, with null, unset) a process env var for one test, remembering the
     * original so tearDown restores it — env is process-global.
     */
    private function withEnv(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }

        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }

    private function writeMinimalConfigs(): void
    {
        $configPath = $this->configPath();

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'BindingCacheApp', 'debug' => true, 'url' => 'http://localhost', 'timezone' => 'UTC'];\n",
        );
        file_put_contents($configPath . DIRECTORY_SEPARATOR . 'observability.php', "<?php\nreturn [];\n");
        file_put_contents($configPath . DIRECTORY_SEPARATOR . 'security.php', "<?php\nreturn [];\n");
    }

    /**
     * A project route file that registers two nested routes and declares the two
     * scopes their paths cannot state, then records that it ran.
     */
    private function writeRouteFile(): void
    {
        $controller = CachedBindingController::class;
        $user = CachedBindingUser::class;
        $setting = CachedBindingSetting::class;
        $post = CachedBindingPost::class;
        $sentinel = $this->basePath . DIRECTORY_SEPARATOR . 'routes-loaded.log';

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            use Pulsar\\Http\\Method;
            use Pulsar\\Routing\\Binding\\BindingScope;
            use Pulsar\\Routing\\Route;

            /** @var \\Pulsar\\Routing\\Router \$router */
            \$router->add(new Route([Method::GET], '/users/{user}/settings/{setting}', ['{$controller}', 'showSetting'], 'settings.show'));
            \$router->add(new Route([Method::GET], '/users/{user}/blog-posts/{post}', ['{$controller}', 'showPost'], 'posts.show'));

            // Bind the parent nobody's signature asks for: a Contained child
            // still resolves and still checks the parent, so the parent has to
            // be bound by something, and no handler here wants the object.
            \$router->model('user', '{$user}');

            // A Setting really is global; the path reads as containment and is not.
            \$router->model('setting', '{$setting}', scope: BindingScope::Root);

            // No PHP property can be called `blog-posts`, so name the relation.
            \$router->model('post', '{$post}', scope: BindingScope::Contained, parentRelation: 'posts');

            file_put_contents('{$sentinel}', "loaded\\n", FILE_APPEND);

            PHP;

        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php', $source);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

/**
 * The handler the cached routes name. Its signature names what each parameter
 * is; it has no say in how any of them is scoped.
 *
 * @internal
 */
final class CachedBindingController
{
    public function showSetting(CachedBindingSetting $setting): void {}

    public function showPost(CachedBindingPost $post): void {}
}

/**
 * The parent no handler signature asks for. It is bound anyway, because the URL
 * says the post is one of this user's and the framework has to check it.
 *
 * @internal
 */
final class CachedBindingUser
{
    public function __construct(public string $id = '1') {}
}

/** @internal */
final class CachedBindingSetting
{
    public function __construct(public string $id = '1') {}
}

/** @internal */
final class CachedBindingPost
{
    public function __construct(public string $id = '1') {}
}
