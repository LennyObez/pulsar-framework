# Database Layer

Pulsar provides a thin, explicit database abstraction layer built on PDO. No query builder, no ORM — raw SQL with named bindings for maximum auditability in regulated domains.

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

| Variable | Overrides |
|---|---|
| `DB_CONNECTION` | `default` |
| `DB_HOST` | `host` |
| `DB_PORT` | `port` |
| `DB_DATABASE` | `database` |
| `DB_USERNAME` | `username` |
| `DB_PASSWORD` | `password` |

## Supported Drivers

| Driver | Enum Value | Default Port |
|---|---|---|
| MySQL | `mysql` | 3306 |
| PostgreSQL | `pgsql` | 5432 |
| SQLite | `sqlite` | — |

## Basic Usage

### Querying

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
// INSERT/UPDATE/DELETE — returns affected row count
$affected = $connection->execute(
    'INSERT INTO users (name, email) VALUES (:name, :email)',
    ['name' => 'Alice', 'email' => 'alice@example.com'],
);

$lastId = $connection->lastInsertId();
```

### Prepared Statements

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

## Typed Row Accessors

`Row` provides type-safe accessors that cast database values:

| Method | Returns | Throws on |
|---|---|---|
| `getInt(column)` | `int` | Non-integer value |
| `getString(column)` | `string` | `null` or non-scalar |
| `getBool(column)` | `bool` | Non-boolean value |
| `getFloat(column)` | `float` | Non-numeric value |
| `getNullableInt(column)` | `?int` | Non-integer non-null |
| `getNullableString(column)` | `?string` | Non-scalar non-null |
| `get(column)` | `mixed` | Missing column |
| `getOrDefault(column, default)` | `mixed` | Never |

Additional methods: `has(column)`, `columns()`, `toArray()`.

## Transactions

### Manual Transaction Control

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

### Callback-Based Transactions

```php
$result = $connection->transaction(function (ConnectionInterface $conn) {
    $conn->execute('INSERT INTO orders (...) VALUES (...)', [...]);
    return $conn->lastInsertId();
});
// Auto-commits on success, auto-rollbacks on exception.
```

### Nested Transactions (Savepoints)

Pulsar uses savepoints for nested transactions:

```php
$outer = $connection->beginTransaction(); // BEGIN TRANSACTION

$inner = $connection->beginTransaction(); // SAVEPOINT pulsar_sp_1
$connection->execute('...');
$inner->rollback();                       // ROLLBACK TO SAVEPOINT pulsar_sp_1

$outer->commit();                         // COMMIT
```

## Multiple Connections

```php
$connectionManager = $container->get(ConnectionManager::class);

$mysql = $connectionManager->connection('mysql');
$sqlite = $connectionManager->connection('sqlite');

// Default connection
$default = $connectionManager->connection();
```

## Lazy Initialization

`PdoConnection` creates the PDO instance on first query, not at construction. This avoids unnecessary database connections at boot time.

## Optional Integration

Database config is optional. If `config/database.php` does not exist:

- `ConfigManager::load()` skips database config loading.
- `Kernel::createDatabaseServices()` returns early.
- No `ConnectionManager` is registered in the container.
- Migration commands are not registered in the CLI.

No breaking change for applications that don't use a database.
