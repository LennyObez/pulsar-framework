# Testing

Pulsar uses PHPUnit 13 for unit, integration, and E2E tests, Infection for mutation testing, PHPBench for performance benchmarks, and a multi-stage quality gate pipeline enforced in CI. This guide covers test organization, patterns, advanced testing techniques, benchmarks, and the full quality gate sequence.

## Test suites

Six PHPUnit test suites are defined in `tools/php/phpunit.xml`:

| Suite       | Directory            | Purpose                                                                                        |
| ----------- | -------------------- | ---------------------------------------------------------------------------------------------- |
| Unit        | `tests/Unit/`        | Isolated class-level tests with no external I/O                                                |
| Integration | `tests/Integration/` | Multi-component tests with real config, container, kernel boot                                 |
| E2E         | `tests/E2E/`         | Full request lifecycle from kernel boot to response                                            |
| Fuzz        | `tests/Fuzz/`        | Randomized input testing for parsers, routers, and templates                                   |
| Chaos       | `tests/Chaos/`       | Failure injection testing for database, cache, and load scenarios                              |
| Property    | `tests/Property/`    | Property-based tests verifying invariants like crypto roundtrips and serialization idempotency |

A separate suite, `tests/Benchmark/`, uses PHPBench (not PHPUnit) for performance testing. Mutation testing uses Infection (not PHPUnit) to verify test quality.

### When to use which suite

- **Unit**: The class under test can be exercised with stubs/mocks alone. No filesystem, no database, no kernel boot.
- **Integration**: The behavior depends on multiple components wired together (e.g., kernel boot with extensions, config loading from files, cache warmup cycles).
- **E2E**: The test exercises the complete request lifecycle from `Kernel::handle()` through routing, middleware, controller, and response.
- **Fuzz**: The test sends randomized, malformed, or adversarial input to parsers, routers, or templates to find crashes and edge cases. Tagged with `#[Group('fuzz')]`.
- **Chaos**: The test simulates infrastructure failures (database drops, cache corruption, high load) to verify graceful degradation. Tagged with `#[Group('chaos')]`.
- **Property**: The test verifies mathematical properties that must hold for all inputs, such as encrypt/decrypt roundtrips or serialization idempotency. Tagged with `#[Group('property')]`.
- **Benchmark**: The test measures execution time or memory consumption of a hot path.

## Running tests

### Composer scripts

```bash
composer test                # Run all suites (Unit + Integration + E2E)
composer test:coverage       # Run with coverage (clover XML + HTML report)
composer qa                  # Full quality gate (cs:check + phpstan + psalm + boundary:check + test)
composer fuzz                # Run fuzz tests only
composer chaos               # Run chaos engineering tests only
composer property            # Run property-based tests only
composer mutation            # Run mutation testing with Infection (targets Auth, Security, OAuth2, WebAuthn)
composer bench               # Run PHPBench benchmarks
composer bench:ci            # Run benchmarks in CI mode (no progress bar)
```

### Single test or suite

```bash
# Run a single test class
vendor/bin/phpunit -c tools/php/phpunit.xml --filter PasswordHasherTest

# Run a single test method
vendor/bin/phpunit -c tools/php/phpunit.xml --filter "PasswordHasherTest::hashReturnsNonEmptyStringDifferentFromInput"

# Run only unit tests
vendor/bin/phpunit -c tools/php/phpunit.xml --testsuite Unit

# Run only E2E tests
vendor/bin/phpunit -c tools/php/phpunit.xml --testsuite E2E
```

### Coverage

```bash
# Generate coverage with PCOV (requires ext-pcov)
XDEBUG_MODE=coverage composer test:coverage

# Or directly with the pcov.directory flag
php -d memory_limit=512M -d pcov.directory=. vendor/bin/phpunit -c tools/php/phpunit.xml --coverage-clover coverage/clover.xml --coverage-html coverage/html
```

CI enforces a **70% statement coverage** threshold. The threshold is checked by parsing `coverage/clover.xml` after the test run.

## Test structure

### Directory layout

Tests mirror the source directory structure:

```
tests/
├── Unit/
│   ├── Auth/Password/PasswordHasherTest.php     # Tests src/Auth/Password/PasswordHasher.php
│   ├── Http/Message/UriTest.php                 # Tests src/Http/Message/Uri.php
│   ├── Routing/RouterTest.php                   # Tests src/Routing/Router.php
│   ├── Extension/Forum/...                      # Tests extensions/forum/src/...
│   ├── Api/                                     # API compatibility snapshot tests
│   ├── Boundary/                                # Module boundary enforcement tests
│   └── Integrity/                               # Architecture integrity checks
├── Integration/
│   ├── Core/KernelStudioIntegrationTest.php     # Kernel + Studio extension wiring
│   ├── Cache/                                   # Cache warmup integration
│   └── Database/                                # Migration and transaction integration
├── E2E/
│   ├── FullRequestLifecycleTest.php             # GET/POST/PUT/DELETE lifecycle
│   ├── AuthFlowTest.php                         # Authentication flow
│   ├── SecurityPipelineTest.php                 # Security middleware pipeline
│   ├── ObservabilityPipelineTest.php            # Observability integration
│   └── Extension/Cms/                           # CMS-specific E2E tests
├── Fuzz/
│   ├── HttpRequestFuzzTest.php                  # Random HTTP method, header, URI fuzzing
│   ├── RouteFuzzTest.php                        # Path traversal, encoding, long path fuzzing
│   ├── TemplateFuzzTest.php                     # XSS and template injection fuzzing
│   └── JsonParsingFuzzTest.php                  # Malformed JSON, deep nesting, BOM handling
├── Chaos/
│   ├── DatabaseFailureTest.php                  # Connection drops, deadlocks, pool exhaustion
│   ├── CacheFailureTest.php                     # Stampede, corruption, partial writes
│   └── HighLoadTest.php                         # Memory pressure, rapid request processing
├── Property/
│   ├── CryptoRoundtripTest.php                  # Encrypt/decrypt and HMAC invariants
│   ├── SerializationIdempotencyTest.php         # JSON and URI roundtrip preservation
│   └── RouterSymmetryTest.php                   # URL generation and matching symmetry
└── Benchmark/
    ├── KernelBench.php                          # Kernel dispatch benchmarks
    ├── RouterBench.php                          # Route matching benchmarks
    ├── Support/                                 # Benchmark helpers and stubs
    └── Scenarios/                               # Memory profiling scenarios
```

### Naming conventions

| Element       | Convention                                | Example                                       |
| ------------- | ----------------------------------------- | --------------------------------------------- |
| Test class    | `<ClassName>Test`                         | `PasswordHasherTest`                          |
| Integration   | `<Feature>IntegrationTest`                | `KernelStudioIntegrationTest`                 |
| E2E           | `<Subject>FlowTest` or `<Subject>Test`    | `AuthFlowTest`, `FullRequestLifecycleTest`    |
| Benchmark     | `<Subject>Bench`                          | `RouterBench`, `KernelBench`                  |
| Fuzz test     | `<Subject>FuzzTest`                       | `HttpRequestFuzzTest`, `RouteFuzzTest`        |
| Chaos test    | `<Subject>FailureTest` or `<Subject>Test` | `DatabaseFailureTest`, `HighLoadTest`         |
| Property test | `<Subject>Test`                           | `CryptoRoundtripTest`, `RouterSymmetryTest`   |
| Test method   | `<verb><what><condition>` (camelCase)     | `hashReturnsNonEmptyStringDifferentFromInput` |
| Data provider | `<descriptiveName>Provider`               | `roundTripProvider`                           |

### Required attributes

Every test class must have:

```php
#[CoversClass(TargetClass::class)]      // Links test to production class for coverage
final class TargetClassTest extends TestCase
{
    #[Test]                              // Required on every test method (test_ prefix is not used)
    public function behaviorDescription(): void
    {
        // ...
    }
}
```

For parameterized tests, use `#[DataProvider]`:

```php
/**
 * @return array<string, array{string, string}>
 */
public static function roundTripProvider(): array
{
    return [
        'simple http' => ['http://example.com/', 'http://example.com/'],
        'with path'   => ['http://example.com/foo', 'http://example.com/foo'],
    ];
}

#[Test]
#[DataProvider('roundTripProvider')]
public function parseAndReconstructRoundTrips(string $input, string $expected): void
{
    self::assertSame($expected, (string) Uri::fromString($input));
}
```

## Stubs vs mocks

Use `createStub()` when you only need return values. Use `createMock()` only when you need `expects()` assertions.

```php
// Stub: no behavioral expectations, just return values
$logger = $this->createStub(LoggerInterface::class);
$logger->method('info')->willReturn(null);

// Mock: verifying that a specific method is called
$logger = $this->createMock(LoggerInterface::class);
$logger->expects(self::once())->method('error')->with('Something failed');
```

Using `createMock()` without `expects()` triggers PHPUnit risky-test notices (Pulsar has `failOnRisky="true"`). Always prefer `createStub()` unless you need to verify method invocations.

## Test patterns

### Unit test example

```php
<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Password;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    private function createTestHasher(): PasswordHasher
    {
        return new PasswordHasher(
            memoryCost: 256,
            timeCost: 1,
            threads: 1,
            allowWeakParameters: true, // Cheap params for fast tests
        );
    }

    #[Test]
    public function hashReturnsNonEmptyStringDifferentFromInput(): void
    {
        $hasher = $this->createTestHasher();
        $hash = $hasher->hash('my-secret-password');

        self::assertNotEmpty($hash);
        self::assertNotSame('my-secret-password', $hash);
    }

    #[Test]
    public function verifyReturnsFalseForWrongPassword(): void
    {
        $hasher = $this->createTestHasher();
        $hash = $hasher->hash('correct-password');

        self::assertFalse($hasher->verify('wrong-password', $hash));
    }
}
```

Key patterns:

- Factory method (`createTestHasher()`) for shared setup with test-appropriate parameters
- `self::assert*` over `$this->assert*` (static calls for clarity)
- Tests are focused: one behavior per method
- Expensive operations (Argon2id) use weak parameters to keep tests fast

### Integration test example

```php
#[CoversClass(Kernel::class)]
#[CoversClass(StudioExtension::class)]
final class KernelStudioIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        // Clear env vars that could interfere
        putenv('STUDIO_ENABLED');
        putenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        // Always clean environment in tearDown
        putenv('STUDIO_ENABLED');
        putenv('APP_ENV');
        $this->cleanDir($this->tempDir);
    }

    #[Test]
    public function kernelBootsWithStudioAndRegistersServices(): void
    {
        $this->writeConfigFiles();
        $kernel = $this->createKernelWithStudio();
        $kernel->boot();

        self::assertTrue($kernel->container()->has(StudioManager::class));
    }
}
```

Key patterns:

- Temp directory with `uniqid()` for filesystem isolation
- `putenv('VAR_NAME')` (no value) to unset environment variables in both setUp and tearDown
- Config files written programmatically to temp directory
- Kernel boot with real extensions and config loading
- Cleanup in `tearDown()` to prevent test pollution

### E2E test example

```php
#[CoversClass(Kernel::class)]
#[CoversClass(Router::class)]
#[CoversClass(Container::class)]
final class FullRequestLifecycleTest extends TestCase
{
    #[Test]
    public function fullGetRequestLifecycleReturnsExpectedResponse(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('Welcome to Pulsar'));

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Welcome to Pulsar', (string) $response->getBody());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }
}
```

Key patterns:

- No mocks -- real kernel, real router, real container
- Full HTTP lifecycle: kernel boot, route registration, request handling, response verification
- Tests cover status codes, response bodies, and headers

## Test helpers

### ApiAssertionsTrait

Located at `tests/Unit/Api/ApiAssertionsTrait.php`. Provides assertion helpers for API compatibility tests:

```php
use Pulsar\Tests\Unit\Api\ApiAssertionsTrait;

final class SomeApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function classIsMarkedAsPublicApi(): void
    {
        self::assertHasApiAttribute(SomeService::class);
    }

    #[Test]
    public function methodSignatureIsStable(): void
    {
        self::assertMethodSignature(
            SomeService::class,
            'process',
            ['string', 'int'],  // Parameter types
            'bool',             // Return type
        );
    }
}
```

Available assertions:

| Method                         | Purpose                                  |
| ------------------------------ | ---------------------------------------- |
| `assertHasApiAttribute()`      | Verify class has `#[Api]` attribute      |
| `assertHasInternalAttribute()` | Verify class has `#[Internal]` attribute |
| `assertMethodSignature()`      | Verify method parameter and return types |
| `assertEnumCases()`            | Verify enum has expected cases           |

### Benchmark support classes

Located at `tests/Benchmark/Support/`:

| Class                      | Purpose                                           |
| -------------------------- | ------------------------------------------------- |
| `BenchmarkPipelineFactory` | Creates instrumented middleware pipelines         |
| `StubAuthManager`          | In-memory auth manager for benchmark isolation    |
| `StubGuard`                | Guard stub with configurable test identity        |
| `StubTokenResolver`        | Token resolver returning canned results           |
| `NullCache`                | No-op cache implementation                        |
| `InMemoryAuditSink`        | In-memory audit log storage                       |
| `PassThroughMiddleware`    | Middleware that passes through without processing |
| `ManifestValidator`        | Pipeline manifest integrity checker               |
| `MemoryProfileRunner`      | Memory profiling utility for memory budgets       |

### Integrity support classes

Located at `tests/Unit/Integrity/Support/`:

| Class                  | Purpose                                             |
| ---------------------- | --------------------------------------------------- |
| `ImportAnalyzer`       | Analyzes PHP import statements for boundary checks  |
| `ModuleMap`            | Maps module structure for architecture verification |
| `VisibilityClassifier` | Classifies visibility (Public, Internal, Private)   |

## Benchmarks

### Configuration

PHPBench is configured in `tools/php/phpbench.json`:

- **Iterations**: 5 (statistical significance)
- **Revolutions**: 1000 (per iteration, for stable timing)
- **Warmup**: 1 iteration (JIT and cache warming)
- **Retry threshold**: 5% (re-run unstable benchmarks)
- **Time unit**: microseconds

### Writing a benchmark

```php
<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Routing\Router;

#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class RouterBench
{
    private Router $router;

    public function setUp(): void
    {
        $this->router = new Router();
        for ($i = 0; $i < 200; $i++) {
            $this->router->get("/route$i", fn() => null);
        }
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchLargeRouterLastRoute(): void
    {
        $this->router->match(Method::GET, '/route199');
    }
}
```

Key patterns:

- Class-level `#[BeforeMethods]` for shared setup
- `#[Subject]` marks each benchmark method
- `#[Assert]` defines the performance budget inline
- Method names use `bench` prefix for discovery
- Setup creates realistic workloads (200 routes, parameterized routes, etc.)

### Performance budgets

Defined in `tools/php/performance-budgets.json`. Tier A budgets are CI-enforced hard gates:

| Budget                          | Threshold | Description                               |
| ------------------------------- | --------- | ----------------------------------------- |
| `container.instance_resolution` | < 100 us  | Direct lookup for pre-registered instance |
| `router.static_10`              | < 5 us    | Match against 10 static routes            |
| `router.static_200`             | < 100 us  | Match against 200 static routes           |
| `router.parameterized`          | < 200 us  | Match with parameter extraction           |
| `middleware.pipeline_5`         | < 10 us   | 5-layer pass-through pipeline             |
| `request.creation`              | < 5 us    | Request object construction               |
| `kernel.dispatch`               | < 500 us  | Full dispatch cycle                       |
| `request.anonymous_json_api`    | < 2 ms    | Full anonymous JSON API request           |
| `request.authenticated_session` | < 3 ms    | Session-authenticated request             |
| `request.with_audit`            | < 4 ms    | Request with full audit trail             |
| `audit.log_write`               | < 500 us  | Single audit entry with HMAC chain        |
| `crypto.encrypt.aead_1024b`     | < 100 us  | AEAD encryption of 1024-byte payload      |

Memory budgets:

| Budget                      | Threshold | Description                      |
| --------------------------- | --------- | -------------------------------- |
| `memory.peak_anonymous`     | < 2 MB    | Anonymous JSON API request       |
| `memory.peak_authenticated` | < 4 MB    | Authenticated request with audit |
| `memory.peak_compliance`    | < 6 MB    | Compliance event request         |

For the full list, see `tools/php/performance-budgets.json`. For the ADR explaining the enforcement model, see `docs/adr/0012-performance-budgets-advisory-ci.md`.

## Quality gate

The full quality gate sequence must pass before any commit. Run all gates with `composer qa`, or individually:

### 1. Code style

```bash
composer cs:fix     # Auto-fix (PER-CS2.0)
composer cs:check   # Dry-run check
```

For Prettier-scoped files (Markdown, YAML, JSON, JS/TS):

```bash
pnpm format:fix     # Auto-fix
pnpm lint           # ESLint
```

### 2. Static analysis

```bash
composer phpstan    # PHPStan level max (config: tools/php/phpstan.neon)
composer psalm      # Psalm level 1 (config: tools/php/psalm.xml)
```

Both must report zero errors. If PHPStan runs out of memory:

```bash
php -d memory_limit=1G vendor/bin/phpstan analyse -c tools/php/phpstan.neon
```

### 3. Boundary enforcement

```bash
composer boundary:check    # Deptrac (structural) + custom script (semantic)
```

Verifies module boundaries: `\Internal\` namespaces are module-private, cross-module imports must go through public API (`#[Api]`).

### 4. Tests

```bash
composer test    # All suites (Unit + Integration + E2E)
```

### 5. Benchmarks (CI only)

```bash
composer bench       # Run locally
composer bench:ci    # CI mode (no progress bar)
```

PHPBench assertions in `#[Assert]` attributes fail the CI pipeline if a performance budget is exceeded.

## CI pipeline

The CI pipeline runs on every pull request and push to `main`:

| Job                    | Gates                                    | Blocking |
| ---------------------- | ---------------------------------------- | -------- |
| `php-quality`          | CS-Fixer, PHPStan, Psalm, Deptrac, audit | Yes      |
| `php-tests`            | Unit + Integration + E2E, 70% coverage   | Yes      |
| `php-benchmark-tier-a` | PHPBench assertions, memory budgets      | Yes      |
| `cache-warmup`         | Smoke test for optimize/optimize:clear   | Yes      |
| `adr-check`            | Architecture governance (PR only)        | Yes      |
| `js`                   | ESLint, Prettier, TypeScript, Vitest     | Yes      |

All jobs must pass for a PR to be mergeable.

## PHPUnit configuration

Key settings in `tools/php/phpunit.xml`:

```xml
<phpunit
    executionOrder="depends,defects"   <!-- Run failed tests first, respect @depends -->
    failOnRisky="true"                 <!-- createMock without expects = risky = fail -->
    failOnWarning="true"               <!-- Deprecation warnings are failures -->
    beStrictAboutOutputDuringTests="true"> <!-- No echo/print in tests -->
```

- **Memory limit**: 512 MB (`<ini name="memory_limit" value="512M"/>`)
- **Coverage source**: `src/` and all extension `src/` directories
- **Cache**: `.phpunit.cache/` for faster re-runs

## Advanced testing

### Mutation testing

Mutation testing verifies that your tests actually catch bugs, not just that code runs without errors. Infection makes small changes (mutations) to source code, like flipping `>` to `>=` or changing `true` to `false`, and re-runs the test suite against each mutated version. If a test still passes despite the mutation, it means the test is too weak.

Configuration is in `infection.json5`. It targets the most security-critical modules: Auth, Security, OAuth2, and WebAuthn.

```bash
composer mutation    # Run mutation testing (requires infection/infection)
```

Key metrics:

- **MSI (mutation score indicator)**: percentage of mutations killed by tests. Minimum: 80%.
- **Covered MSI**: percentage of mutations killed in code that has coverage. Minimum: 90%.

Mutations are applied to temporary copies of source files. Your actual code is never modified.

### Fuzz testing

Fuzz tests send randomized, malformed, or adversarial input to parsers and handlers to find crashes, infinite loops, or unhandled edge cases. Tests use `#[Group('fuzz')]` and live in `tests/Fuzz/`.

Current fuzz targets: HTTP request parsing (null bytes, oversized headers, unicode paths), route matching (path traversal, double encoding, extreme lengths), template rendering (XSS vectors, injection patterns), and JSON body parsing (deep nesting, malformed structures, BOM prefixes).

### Chaos engineering

Chaos tests simulate infrastructure failures to verify the framework degrades gracefully. Tests use `#[Group('chaos')]` and live in `tests/Chaos/`.

Current chaos scenarios: database connection drops, deadlock recovery, cache stampede protection, corrupted cache entries, partial write failures, and high-load memory pressure.

### Property-based testing

Property tests verify mathematical invariants that must hold for all inputs, rather than testing specific examples. Tests use `#[Group('property')]` and live in `tests/Property/`.

Current property tests: encrypt/decrypt roundtrip (any plaintext survives the cycle), HMAC sign/verify consistency, serialization idempotency (toArray/fromArray roundtrip), URI parse/reconstruct symmetry, and route generation/matching symmetry.

### Dependency auditing

A scheduled CI workflow (`.github/workflows/dependency-audit.yml`) runs `composer audit` weekly and on every push. It fails on any critical or high severity CVEs in dependencies.

## Best practices

### General

- Write tests first (TDD): define expected behavior before implementation
- One assertion concern per test method (multiple `assert*` calls are fine if they verify the same behavior)
- Use descriptive method names that read as specifications
- Prefer `self::assert*()` over `$this->assert*()`
- Use `#[CoversClass]` on every test class

### Determinism

- Avoid real timeouts; use deterministic clocks or short intervals
- Seed random generators when testing randomized behavior
- Use `uniqid()` or UUID for temp directory names to prevent collisions
- Clean up environment variables in `tearDown()`, not just `setUp()`

### Isolation

- Unit tests must not touch the filesystem, network, or environment variables
- Integration tests create/destroy temp directories and clean environment in `setUp()`/`tearDown()`
- Never depend on test execution order (except explicit `#[Depends]`)

### Performance-sensitive tests

- Use `allowWeakParameters: true` for password hashing in tests (avoids 100ms+ Argon2id per hash)
- Use in-memory drivers (`SyncDriver`, `InMemoryDriver`, `:memory:` SQLite) over real databases
- Keep benchmark setup lightweight; measure only the hot path
