# Database layer

Pulsar provides a thin, explicit database abstraction layer built on PDO. No query builder, no ORM: raw SQL with named bindings for maximum auditability in regulated domains.

**Named parameters only.** `PdoConnection` uses named placeholders (`:name`) exclusively. Positional placeholders (`?`) are not supported. All bindings arrays must be keyed by parameter name. This restriction simplifies query logging, cache-key construction, and audit output because every binding is self-describing.

## Configuration

Define connections in `config/database.php`:

```php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'pulsar',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [],
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => 'database/pulsar.sqlite',
        ],
    ],
    'migrations' => [
        'table' => 'pulsar_migrations',
        'path' => 'database/migrations',
    ],
];
```

Environment variables override file values for the active connection:

| Variable        | Overrides  |
| --------------- | ---------- |
| `DB_CONNECTION` | `default`  |
| `DB_HOST`       | `host`     |
| `DB_PORT`       | `port`     |
| `DB_DATABASE`   | `database` |
| `DB_USERNAME`   | `username` |
| `DB_PASSWORD`   | `password` |

## Supported drivers

| Driver     | Enum Value | Default Port |
| ---------- | ---------- | ------------ |
| MySQL      | `mysql`    | 3306         |
| PostgreSQL | `pgsql`    | 5432         |
| SQLite     | `sqlite`   | -            |

## Basic usage

### Querying

`query()` returns a `Pulsar\Database\Result` object (not a raw array). The `Result` holds a list of `Pulsar\Database\Row` objects with typed accessors.

```php
$result = $connection->query(
    'SELECT * FROM users WHERE status = :status',
    ['status' => 'active'],
);

// Result API
$result->first();        // ?Row, first row or null
$result->firstOrFail();  // Row, first row or throws DatabaseException
$result->toArray();      // list<Row>, same as $result->rows
$result->map(fn(Row $r) => ...); // list<T>, transform each row
$result->pluck('email'); // list<mixed>, single column values
$result->count();        // int, via $result->rowCount
$result->isEmpty();      // bool, true when no rows returned

// Row API
$row = $result->first();
$row->get('name');                   // mixed, raw value (throws if column missing)
$row->getOrDefault('name', 'anon'); // mixed, with fallback
$row->getInt('id');                  // int, type-safe cast
$row->getString('email');            // string
$row->getBool('active');             // bool
$row->getFloat('score');             // float
$row->getNullableInt('parent_id');   // ?int
$row->getNullableString('bio');      // ?string
$row->getBinary('avatar');           // string, normalizes PostgreSQL bytea streams
$row->has('column');                 // bool
$row->columns();                     // list<string>, column names
$row->toArray();                     // array<string, mixed>
```

Full example:

```php
$result = $connection->query(
    'SELECT * FROM users WHERE status = :status',
    ['status' => 'active'],
);

// Get the first row
$user = $result->first();
$name = $user?->getString('name');

// Get first or throw
$user = $result->firstOrFail();

// Pluck a single column
$emails = $result->pluck('email');

// Map over rows
$dtos = $result->map(fn(Row $row) => new UserDto(
    id: $row->getInt('id'),
    name: $row->getString('name'),
));
```

### Writing

```php
// INSERT/UPDATE/DELETE - returns affected row count
$affected = $connection->execute(
    'INSERT INTO users (name, email) VALUES (:name, :email)',
    ['name' => 'Alice', 'email' => 'alice@example.com'],
);

$lastId = $connection->lastInsertId();
```

### Prepared statements

```php
$stmt = $connection->prepare(
    'INSERT INTO logs (level, message) VALUES (:level, :message)',
);

foreach ($entries as $entry) {
    $stmt->executeAffecting([
        'level' => $entry->level,
        'message' => $entry->message,
    ]);
}
```

## Typed row accessors

`Row` provides type-safe accessors that cast database values:

| Method                          | Returns   | Throws on            |
| ------------------------------- | --------- | -------------------- |
| `getInt(column)`                | `int`     | Non-integer value    |
| `getString(column)`             | `string`  | `null` or non-scalar |
| `getBool(column)`               | `bool`    | Non-boolean value    |
| `getFloat(column)`              | `float`   | Non-numeric value    |
| `getNullableInt(column)`        | `?int`    | Non-integer non-null |
| `getNullableString(column)`     | `?string` | Non-scalar non-null  |
| `get(column)`                   | `mixed`   | Missing column       |
| `getOrDefault(column, default)` | `mixed`   | Never                |

Additional methods: `has(column)`, `columns()`, `toArray()`.

## Transactions

### Manual transaction control

```php
$tx = $connection->beginTransaction();

try {
    $connection->execute('UPDATE accounts SET balance = balance - :amount WHERE id = :from', [...]);
    $connection->execute('UPDATE accounts SET balance = balance + :amount WHERE id = :to', [...]);
    $tx->commit();
} catch (Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

### Callback-based transactions

```php
$result = $connection->transaction(function (ConnectionInterface $conn) {
    $conn->execute('INSERT INTO orders (...) VALUES (...)', [...]);
    return $conn->lastInsertId();
});
// Auto-commits on success, auto-rollbacks on exception.
```

### Nested transactions (savepoints)

Pulsar uses savepoints for nested transactions:

```php
$outer = $connection->beginTransaction(); // BEGIN TRANSACTION

$inner = $connection->beginTransaction(); // SAVEPOINT pulsar_sp_1
$connection->execute('...');
$inner->rollback();                       // ROLLBACK TO SAVEPOINT pulsar_sp_1

$outer->commit();                         // COMMIT
```

## Multiple connections

```php
$connectionManager = $container->get(ConnectionManager::class);

$mysql = $connectionManager->connection('mysql');
$sqlite = $connectionManager->connection('sqlite');

// Default connection
$default = $connectionManager->connection();
```

## Lazy initialization

`PdoConnection` creates the PDO instance on first query, not at construction. This avoids unnecessary database connections at boot time.

## Optional integration

Database config is optional. If `config/database.php` does not exist:

- `ConfigManager::load()` skips database config loading.
- `Kernel::createDatabaseServices()` returns early.
- No `ConnectionManager` is registered in the container.
- Migration commands are not registered in the CLI.

No breaking change for applications that don't use a database.

## Schema builder

The core `SchemaBuilder` provides a fluent DDL API for use inside migrations. It compiles `Blueprint` definitions into driver-specific DDL automatically.

```php
use Pulsar\Database\Schema\Blueprint;
use Pulsar\Database\Schema\SchemaBuilder;

$schema = SchemaBuilder::for($connection);

$schema->create('users', function (Blueprint $table) {
    $table->id();
    $table->string('email', 191)->unique();
    $table->string('name');
    $table->boolean('active')->default(true);
    $table->timestamps();
});

$schema->drop('temp_table');
$schema->dropIfExists('temp_table');
```

`Blueprint`'s column methods are `id()`, `string()`, `text()`, `bigText()`, `integer()`, `bigInteger()`, `smallInteger()`, `float()`, `decimal()`, `boolean()`, `date()`, `time()`, `timestamp()`, `timestamps()`, `json()`, `uuid()`, `binary()`, `enum()` and `foreignId()`; the table-level ones are `collation()`, `index()`, `unique()` and `foreign()`. The ORM extension's `TableBuilder` is a separate class with its own reference ([ORM docs](orm.md#schema-builder)); it is not this one, and the two do not track each other method for method.

Two `Blueprint` methods decide things that are easy to get wrong on MySQL, and both are covered below: how wide a text column really is, and whether two identifiers differing only in case are one key or two.

### Text width: `text()` is narrow on MySQL

`text()` compiles to `TEXT` on all three engines, and that single word buys three different ceilings:

| Engine          | `text()`                  | `bigText()`        |
| --------------- | ------------------------- | ------------------ |
| MySQL / MariaDB | `TEXT` — **65,535 bytes** | `LONGTEXT` — 4 GiB |
| PostgreSQL      | `TEXT` — about 1 GiB      | `TEXT` — the same  |
| SQLite          | `TEXT` — about 1 GiB      | `TEXT` — the same  |

The MySQL figure is **bytes, not characters**: a `utf8mb4` column runs out somewhere between 16,383 and 65,535 characters depending on which ones they are. It is not a soft limit in either direction it can fall. With `sql_mode` at MySQL's shipped default an oversized write raises error 1406 and the statement fails; with strict mode switched off the value is truncated and the row is quietly wrong — the worse outcome, because truncated JSON parses as nothing on the next read and the failure surfaces far from the write that caused it.

So the choice is about who decides the width:

- **`text()`** — text a person typed into a field somebody sized: a description, a comment, an operator's note on why a job was retried. 64 KiB is a bound you chose.
- **`bigText()`** — a value whose size the data decides: a serialized job payload, a saga context, an integration event body, a stack trace. The ceiling is one no payload reaches.

```php
use Pulsar\Database\Schema\Blueprint;

$schema->create('failed_jobs', function (Blueprint $table) {
    $table->string('id', 255);
    $table->bigText('payload');       // LONGTEXT on MySQL, TEXT elsewhere
    $table->bigText('exception');     // a stack trace has no natural ceiling
    $table->text('note')->nullable(); // 64 KiB is plenty for a human note
});
```

`bigText()` costs almost nothing on MySQL, so the choice can be made on evidence rather than on caution: a `LONGTEXT` value carries a 4-byte length prefix where `TEXT` carries 2, and an index on the column must state a prefix length — which is equally true of `TEXT`, so nothing is given up there.

Both methods are thin wrappers over `SchemaColumnType::BigText` and `SchemaColumnType::Text`, which is what you name when you build a `SchemaColumn` value directly instead of going through the `Blueprint`.

This is not a hypothetical trap. The framework's own `saga_states`, `workflow_instances`, `workflow_transitions`, `failed_jobs`, `outbox_events` and `saga_step_results` tables were first written against `text()`, and every serialized column in them silently carried a 64 KiB ceiling on MySQL where the hand-written DDL they replaced had used `LONGTEXT`. `BigText` exists because of that narrowing ([ADR-0043](adr/0043-schema-belongs-to-migrations.md)).

### How a table compares the text it stores

A collation decides two things at once: the order text sorts in, and — the half with teeth — whether two values differing only in case or in accent are the same value. The second is not a display concern. `PRIMARY KEY` and `UNIQUE` are enforced through the column's collation, so under a case-insensitive one `order-42` and `ORDER-42` collide on insert, and a lookup by identifier can return a row nobody asked for.

MySQL is why this has to be stated rather than assumed. Its shipped defaults are case- and accent-insensitive — `utf8mb4_0900_ai_ci` on MySQL 8.0, `utf8mb4_general_ci` and `latin1_swedish_ci` on the versions and forks beside it — so a table created with no collation clause silently gets the insensitive behaviour. PostgreSQL and SQLite default the other way.

`Blueprint::collation()` states the intent once for the whole table:

```php
use Pulsar\Database\Schema\Blueprint;
use Pulsar\Database\Schema\SchemaCollation;

$schema->create('integration_events', function (Blueprint $table) {
    $table->collation(SchemaCollation::Exact);   // every character column in the table
    $table->string('event_id', 64)->unique();    // ...the key included
    $table->bigText('payload_json');
});
```

| Engine          | What `SchemaCollation::Exact` emits                                             | Why that is the right translation                                                                                                                        |
| --------------- | ------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| MySQL / MariaDB | `COLLATE=utf8mb4_bin` on the table, `COLLATE utf8mb4_bin` on a character column | The server default folds case and accents, so this is where the request changes behaviour                                                                |
| PostgreSQL      | nothing                                                                         | Every collation `initdb` creates is deterministic, so equality falls through to a byte comparison — and there is no table-level `COLLATE` clause to emit |
| SQLite          | nothing                                                                         | The default collating sequence is `BINARY`, which is that same byte comparison; `NOCASE` has to be asked for by name                                     |

Because MySQL derives a table's character set from its collation when only the collation is given, `Exact` brings `utf8mb4` with it — a separate character-set option could only agree or contradict, so the layer does not offer one. It does not offer a storage engine either: InnoDB has been MySQL's default since 5.5 and MariaDB's since 10.2 — since 10.0 counting XtraDB, the InnoDB fork MariaDB shipped before that — a server configured to default elsewhere is a connection-level problem rather than a per-table one, and PostgreSQL and SQLite have no such concept to name.

`ColumnBuilder::collation()` sets it on one column, overriding the table's, for a table that wants a mixed rule:

```php
$table->string('slug', 191)->collation(SchemaCollation::Exact);
```

On an integer, boolean or JSON column the request is a no-op rather than an error. MySQL rejects a `COLLATE` clause on those outright — it is a parse error, not an ignored hint — so a table-wide collation must not be able to compose a statement the engine refuses to read.

**There is no case-insensitive counterpart, and its absence is deliberate.** `Exact` is an intent all three engines can honour: MySQL by naming `utf8mb4_bin`, the other two by saying nothing, because they already do it. A case-insensitive case has no such answer. PostgreSQL has no table-level spelling for it — it needs a non-deterministic ICU collation created per database, or the `citext` extension — and SQLite's `NOCASE` folds ASCII `a`–`z` only, so `Ä` and `ä` stay distinct under it. The enum would be accepting a request it could honour on one engine of three and approximate on the other two, and a promise kept in one deployment out of three is worse than no vocabulary at all. Match case-insensitively in the query instead — `WHERE LOWER(email) = LOWER(:email)`, or an indexed generated column — where the folding is visible at the point it applies.

One thing this is not: the `collation` key in `config/database.php`. A table's comparison rule belongs to the table and is fixed at `CREATE TABLE`. The config key is carried on `ConnectionConfig` and read by nothing that builds a DSN or issues a statement, so setting it to `utf8mb4_bin` there changes no comparison anywhere. Ask for `SchemaCollation::Exact` on the table that needs it.

## Connection pooling

Connection pooling is active **only in persistent runtimes** (Swoole, RoadRunner). Under PHP-FPM, connections are created per-request as usual.

### Configuration

```php
// config/database.php
'pool' => [
    'min_connections' => 2,
    'max_connections' => 10,
    'idle_timeout_seconds' => 60,
    'max_lifetime_seconds' => 3600,
    'health_check_interval_seconds' => 30,
],
```

### Behavior

- Connections are created lazily up to `max_connections`
- Idle connections are pruned after `idle_timeout_seconds`
- Connections exceeding `max_lifetime_seconds` are destroyed and replaced
- Health checks (`SELECT 1`) run on checkout when a connection has been idle longer than `health_check_interval_seconds`
- If the pool is exhausted, a `DatabaseException::poolExhausted()` is thrown
- Under FPM, `NullConnectionPool` creates fresh connections per checkout with no pooling overhead

## Read/write routing

Route SELECT queries to read replicas and writes to the primary.

### Configuration

```php
'read_write' => [
    'enabled' => true,
    'write_host' => 'primary.db.internal',
    'read_hosts' => ['replica-1.db.internal', 'replica-2.db.internal'],
    'sticky_duration' => 'request', // or milliseconds (e.g., 5000)
],
```

### Routing rules

| Query Type                          | Routed To |
| ----------------------------------- | --------- |
| SELECT, SHOW, DESCRIBE, EXPLAIN     | Replica   |
| INSERT, UPDATE, DELETE, DDL         | Primary   |
| Any query inside a transaction      | Primary   |
| Any query after a write (sticky)    | Primary   |
| `->usePrimary()` override           | Primary   |
| `->useReplica()` override (audited) | Replica   |

### Primary stickiness

After any write operation, all subsequent reads are pinned to the primary to prevent stale reads from replicas that haven't caught up.

- **Request-scoped** (default): Pinned until request ends or `resetRouting()` is called
- **Timed**: Pinned for N milliseconds, then resume routing

### Explicit overrides

```php
$manager->usePrimary();   // Next query uses primary
$manager->useReplica();   // Next query uses replica (audited in regulated presets)
```

Both are single-query overrides. In regulated presets, `useReplica()` emits an audit event because it bypasses read-after-write consistency guarantees.

### Load balancing

Multiple read replicas are selected via round-robin rotation.

## Failover

Pulsar detects primary failures and switches to a new endpoint. It does **not** promote replicas - that is the infrastructure's responsibility (RDS Multi-AZ, Patroni, ProxySQL, etc.).

### Configuration

```php
'failover' => [
    'enabled' => true,
    'failure_threshold' => 3,
    'retry_interval_seconds' => 5,
    'strategy' => 'dns', // 'dns', 'callback', or 'config-reload'
    'compliance_events_enabled' => false,
],
```

### Strategies

| Strategy        | Description                                       |
| --------------- | ------------------------------------------------- |
| `dns`           | Re-resolve hostname via DNS lookup                |
| `callback`      | Invoke a cluster manager callback for new target  |
| `config-reload` | Hot-swap primary host from reloaded configuration |

### Circuit breaker

When the primary is unavailable and no failover target is configured:

1. Circuit breaker opens after `failure_threshold` consecutive failures
2. Write operations are rejected
3. Read operations continue from replicas
4. Telemetry events emitted: `PrimaryUnavailable`, `CircuitBreakerOpened`
5. Circuit closes automatically when a health check succeeds

### Compliance events

When `compliance_events_enabled` is true, failover emits a `FailoverEvent` with:

- Reason, source endpoint, target endpoint
- Affected operation count, duration
- Correlation ID and timestamp

## Query cache

Cache query results via PSR-16 with tag-based invalidation.

### Configuration

```php
'query_cache' => [
    'enabled' => true,          // false by default in regulated preset
    'default_ttl_seconds' => 60,
    'sensitive_table_names' => ['audit_logs', 'encryption_keys'],
    'authorization_columns' => ['user_id', 'tenant_id'],
],
```

### Usage

Cache results for a specific duration:

```php
// Via the QueryCache directly
$cache->put($key, $result, ttlSeconds: 60, tags: ['users', 'orders']);
$cached = $cache->get($key);
```

### Cache key determinism

Cache keys are built from a hash of:

- Normalized SQL template
- Sorted and typed bindings
- Tenant ID
- Connection role (read/write)
- Schema version

This ensures no ambient state leaks into cache keys.

### Tag-based invalidation

- Cache entries are tagged by table name
- Write operations on a table invalidate all cached queries for that table
- Tags are extracted from the query builder AST (all referenced tables including JOINs)

### Safety rules

- **Regulated preset**: Query caching disabled by default. Opt-in per query
- **Sensitive tables**: Never cached regardless of `->cache()` calls
- **Authorization-shaped queries**: Queries with `WHERE user_id = ?` or `WHERE tenant_id = ?` are excluded by default

## SQL monitoring

### Safe SQL logging

SQL logging never exposes sensitive data by default:

| What is logged    | Format                           |
| ----------------- | -------------------------------- |
| SQL template      | Normalized with `?` placeholders |
| Bindings          | xxh128 hash (never raw values)   |
| Duration          | Milliseconds                     |
| Row count         | Integer                          |
| Classification    | select/insert/update/delete/ddl  |
| Sensitivity level | From table metadata              |

### Production enforcement

Raw binding logging requires **both**:

1. `log_raw_bindings: true` in config
2. `DB_LOG_RAW_BINDINGS=CONFIRM_UNSAFE` environment variable

Even in debug mode, fields classified as PII are masked with `***MASKED***`.

### Slow query detection

Queries exceeding the configured threshold (default: 1000ms) are logged as warnings with the normalized SQL, duration, and classification.

```php
'monitor' => [
    'slow_query_threshold_ms' => 1000,
    'pii_columns' => ['email', 'ssn', 'phone'],
],
```

### Connection auditing

Connection lifecycle events are logged via PSR-3:

- **Connect**: Connection name and driver (info level)
- **Disconnect**: Connection name (info level)
- **Error**: Connection name and error message (error level)
- **Failover**: Source, target, and reason (warning level)

## Configuration reference

All new configuration sections are optional with sensible defaults. Add them to `config/database.php` as needed:

```php
return [
    'default' => 'sqlite',
    'connections' => [/* ... */],
    'migrations' => [/* ... */],

    // New sections (all optional)
    'pool' => [/* ... */],
    'read_write' => [/* ... */],
    'failover' => [/* ... */],
    'query_cache' => [/* ... */],
    'monitor' => [/* ... */],
];
```
