# Logging

Pulsar provides a PSR-3 compliant structured logging system. Log output is JSON lines format, suitable for log aggregation systems.

## PSR-3 compliance

`Pulsar\Observability\Log\Logger` implements `Psr\Log\LoggerInterface`:

```php
$logger->emergency('System failure', ['component' => 'db']);
$logger->alert('Action required');
$logger->critical('Critical failure');
$logger->error('Operation failed', ['exception' => $e]);
$logger->warning('Deprecated feature used');
$logger->notice('User logged in');
$logger->info('Request processed');
$logger->debug('Query executed', ['sql' => $query]);
```

## LogLevel enum

Backed string enum mapping PSR-3 levels with numeric severity:

| Level     | Severity | Value         |
| --------- | -------- | ------------- |
| Emergency | 0        | `'emergency'` |
| Alert     | 1        | `'alert'`     |
| Critical  | 2        | `'critical'`  |
| Error     | 3        | `'error'`     |
| Warning   | 4        | `'warning'`   |
| Notice    | 5        | `'notice'`    |
| Info      | 6        | `'info'`      |
| Debug     | 7        | `'debug'`     |

Lower severity number = more severe. Level filtering via `meetsThreshold()`: a log entry is written only if its severity is at least as severe as the configured threshold.

## Structured JSON format

Each log entry is a single JSON line:

```json
{
  "timestamp": "2026-02-03T12:00:00.000000+00:00",
  "level": "error",
  "channel": "app",
  "message": "Something failed",
  "context": {
    "exception": {
      "class": "RuntimeException",
      "message": "DB connection lost",
      "code": 0,
      "file": "/app/src/Service.php",
      "line": 42,
      "trace": "#0 ..."
    }
  }
}
```

Fields:

- `timestamp` - ISO 8601 with microseconds, always UTC
- `level` - PSR-3 level string
- `channel` - logger channel name
- `message` - interpolated message
- `context` - optional, present only when non-empty

## Sinks

Sinks are output destinations implementing `LogSinkInterface`:

### FileSink

Appends JSON lines to a file. Creates the directory if missing (mode `0o750`, files `0o640` — logs routinely carry incidental PII/PHI). Uses `LOCK_EX` for concurrent safety.

```php
$sink = new FileSink('/var/logs/app.log');
```

`FileSink` **appends only — it never rotates, truncates, or compresses**. Left unmanaged, the target file grows without bound and eventually exhausts the volume. Rotation is an operating-system concern by design: it lets the platform's own tooling own retention, compression, and post-rotate signalling rather than baking a second-rate rotator into the framework's hot path. Use `logrotate` (or your platform equivalent — `newsyslog`, journald, a sidecar) in production.

A ready-to-use sample ships at [`resources/deploy/logrotate/pulsar`](../resources/deploy/logrotate/pulsar): copy it to `/etc/logrotate.d/pulsar`, adjust the path glob and `su` owner to match your deployment, and it rotates daily with 14 compressed generations. Because `FileSink` reopens the path on every write (it holds no long-lived handle), plain `copytruncate` is unnecessary — a rotated-away file is recreated on the next log line.

### StreamSink

Writes JSON lines to a PHP stream.

```php
$sink = new StreamSink('php://stderr');
```

## Configuration

Logging is configured via `config/observability.php`:

```php
'logging' => [
    'default_channel' => 'file',
    'level' => 'info',
    'channels' => [
        'file' => ['driver' => 'file', 'path' => 'var/logs/pulsar.log'],
        'stderr' => ['driver' => 'stream', 'stream' => 'php://stderr'],
    ],
],
```

Env var overrides:

- `LOG_LEVEL` overrides `logging.level`
- `LOG_CHANNEL` overrides `logging.default_channel`

## Logger::fromConfig factory

Build a configured logger from `ObservabilityConfig`:

```php
$logger = Logger::fromConfig($observabilityConfig);
```

This creates sinks for all configured channels and sets the threshold from `loggingLevel`.

## Context serialization

### PSR-3 placeholder interpolation

```php
$logger->info('User {name} logged in from {ip}', [
    'name' => 'Alice',
    'ip' => '192.168.1.1',
]);
// Message: "User Alice logged in from 192.168.1.1"
```

### Exception serialization

`Throwable` instances in context are serialized to structured arrays:

```php
$logger->error('Operation failed', ['exception' => $e]);
// Context serialized as:
// {"exception": {"class": "RuntimeException", "message": "...", "code": 0, "file": "...", "line": 42, "trace": "..."}}
```

## Sink failure behavior

Sink write failures are silently swallowed. Logging must never crash a request. If a sink throws, the exception is caught and ignored, and remaining sinks still receive the entry.

## Container registration

When a `ConfigManager` is provided to the Kernel, the logger is registered in the container as both:

- `Psr\Log\LoggerInterface`
- `Pulsar\Observability\Log\Logger`
