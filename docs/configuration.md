# Configuration

Pulsar provides a typed configuration system that loads settings from PHP files, environment variables, and optional `.env` files. Configuration is deterministic, explicit, and available to the entire framework before extensions boot.

## Load order

Configuration follows a strict precedence chain:

1. **OS environment variables** - always present, highest priority
2. **`.env` file** - optional, loaded only if a path is provided. File values never override existing OS vars.
3. **Config PHP files** - `config/app.php`, `config/observability.php` - return raw arrays
4. **Runtime overrides** - `ConfigOverrides` applied via `array_replace_recursive` on raw arrays
5. **Typed DTO construction** - `AppConfig::fromArray()`, `ObservabilityConfig::fromArray()` - env vars override array values inside the factory methods

## Environment class

`Pulsar\Config\Environment` loads and merges env vars:

```php
$env = Environment::load('/path/to/.env'); // .env is optional

$env->get('APP_ENV');                   // nullable
$env->get('DB_HOST', 'localhost');       // with default
$env->require('SECRET_KEY');             // throws ConfigException if missing
$env->has('REDIS_URL');                  // boolean
$env->resolveMode();                    // returns EnvironmentMode enum
```

The `.env` parser supports:

- `KEY=VALUE` pairs
- `#` comment lines
- Blank lines
- Single and double quoted values (quotes stripped)
- No interpolation

## EnvironmentMode enum

```php
enum EnvironmentMode: string
{
    case Local = 'local';
    case Staging = 'staging';
    case Production = 'production';
}
```

`isDebugByDefault()` returns `true` only for `Local`.

## Typed config DTOs

### AppConfig

Loaded from `config/app.php`. Fields:

| Property    | Type              | Env Override | Default      |
| ----------- | ----------------- | ------------ | ------------ |
| `name`      | `string`          | `APP_NAME`   | `'Pulsar'`   |
| `mode`      | `EnvironmentMode` | `APP_ENV`    | `Local`      |
| `debug`     | `bool`            | `APP_DEBUG`  | Mode default |
| `timezone`  | `string`          | -            | `'UTC'`      |
| `locale`    | `string`          | -            | `'en'`       |
| `signature` | `AppSignature`    | -            | disabled     |

Debug resolution: `APP_DEBUG` env var > file `debug` key > `EnvironmentMode::isDebugByDefault()`.

### AppSignature

Loaded from the `signature` sub-array of `config/app.php`. An **opt-in, front-end**
"Made with Pulsar" signal rendered as `<meta>` tags in the `<head>` and exposed to
every template as the `$pulsarSignature` view variable.

| Property    | Type     | Config key            | Default |
| ----------- | -------- | --------------------- | ------- |
| `generator` | `bool`   | `signature.generator` | `false` |
| `author`    | `string` | `signature.author`    | `''`    |

```php
// config/app.php
'signature' => [
    'generator' => true,          // <meta name="generator" content="Pulsar">
    'author' => 'Your Name',      // <meta name="author" content="Your Name">
],
```

This is deliberately a front-end signal, **not** an HTTP header. Pulsar never emits
an `X-Powered-By` or versioned `Server` header — advertising the framework or its
version to every client is a fingerprinting leak (OWASP ASVS V14.4.1), and those
headers are stripped at the emitter (`ResponseEmitter`) and in
`SecurityHeadersMiddleware`. The `generator` tag carries **no version**, and the
whole block is disabled by default, so nothing is disclosed unless you opt in.

Render it in a custom layout with `<?php echo $pulsarSignature ?? ''; ?>` (the
framework error layout already does). The framework-rendered pages also show a
"Powered by Pulsar" footer via the `powered_by` translation string.

### ObservabilityConfig

Loaded from `config/observability.php` (logging section). Fields:

| Property                | Type                         | Env Override  | Default   |
| ----------------------- | ---------------------------- | ------------- | --------- |
| `defaultLoggingChannel` | `string`                     | `LOG_CHANNEL` | `'file'`  |
| `loggingLevel`          | `string`                     | `LOG_LEVEL`   | `'info'`  |
| `loggingChannels`       | `list<LoggingChannelConfig>` | -             | From file |

### LoggingChannelConfig

Readonly DTO for each channel entry:

| Property | Type                              |
| -------- | --------------------------------- |
| `name`   | `string`                          |
| `driver` | `string` (`'file'` or `'stream'`) |
| `path`   | `?string`                         |
| `stream` | `?string`                         |

## ConfigRepository

The `ConfigRepository` is a typed store keyed by DTO class name:

```php
$repo->set($appConfig);                           // stores by class name
$repo->get(AppConfig::class);                      // returns AppConfig
$repo->has(ObservabilityConfig::class);             // boolean
```

Accessing a missing config throws `ConfigException`.

## ConfigOverrides

Runtime overrides applied before DTO construction:

```php
$overrides = new ConfigOverrides();
$overrides->add('app', ['debug' => true]);
$overrides->add('observability', ['logging' => ['level' => 'error']]);
```

Overrides merge recursively via `array_replace_recursive`.

## ConfigManager

The Kernel accepts an optional `ConfigManager` in its constructor. When provided, the boot pipeline loads configuration as its first step. When omitted, the Kernel boots without config-file loading and relies on manually registered DTOs or hardcoded defaults. For a fully functional application (database connections, logging channels, security settings), pass a `ConfigManager` to the Kernel.

Orchestrates the full pipeline:

```php
$manager = new ConfigManager(
    configPath: __DIR__ . '/config',
    envFilePath: __DIR__ . '/.env',
    overrides: $overrides,
);
$manager->load();

$appConfig = $manager->repository()->get(AppConfig::class);
$environment = $manager->environment();
```

## Env var precedence table

| Variable      | Overrides                                              | Used By                    |
| ------------- | ------------------------------------------------------ | -------------------------- |
| `APP_NAME`    | `config/app.php` → `name`                              | `AppConfig`                |
| `APP_ENV`     | `config/app.php` → `env`                               | `AppConfig`, `Environment` |
| `APP_DEBUG`   | `config/app.php` → `debug`                             | `AppConfig`                |
| `LOG_LEVEL`   | `config/observability.php` → `logging.level`           | `ObservabilityConfig`      |
| `LOG_CHANNEL` | `config/observability.php` → `logging.default_channel` | `ObservabilityConfig`      |

## Extension config pattern

The `ConfigLoaderInterface` establishes the pattern for extensions to provide their own typed configs:

```php
interface ConfigLoaderInterface
{
    public function configClass(): string;
    public function load(array $data, Environment $environment): object;
}
```

Extensions can implement this interface to integrate with config discovery.
