# Service Discovery & Config Center

Pulsar provides a built-in service discovery and centralized configuration system for distributed deployments.

## Architecture

The service discovery module has three primary interfaces:

- **`ServiceDiscoveryInterface`** — Read-side: discover and resolve service instances
- **`ServiceRegistryInterface`** — Write-side: register, deregister, heartbeat, and health management
- **`ConfigCenterInterface`** — Centralized key-value configuration with namespacing

## Backends

### Static (Default)

The `StaticServiceDiscovery` backend loads service definitions from configuration files. Suitable for single-instance deployments and development.

```php
// config/service_discovery.php
return [
    'enabled' => true,
    'services' => [
        'payment-gateway' => [
            ['host' => 'payments.internal', 'port' => 8443, 'scheme' => 'https'],
        ],
        'notification-service' => [
            ['host' => 'notifications.internal', 'port' => 8443],
            ['host' => 'notifications-2.internal', 'port' => 8443],
        ],
    ],
];
```

### In-Memory Registry

The `InMemoryServiceRegistry` provides dynamic registration with:

- **TTL-based expiration** — Services auto-expire if heartbeats stop
- **Health status tracking** — Per-instance health status with event dispatch
- **Event integration** — `ServiceRegistered`, `ServiceDeregistered`, `ServiceHealthChanged` events

```php
$registry->register(new ServiceInstance(
    name: 'user-service',
    host: 'users.internal',
    port: 8443,
), ttlSeconds: 30);

// Heartbeat to keep registration alive
$registry->heartbeat('user-service', 'users.internal', 8443);

// Evict expired registrations
$evicted = $registry->evictExpired();
```

## Health Checks

The `HttpHealthCheck` probes service instances via HTTP:

```php
$check = new HttpHealthCheck(healthPath: '/health', timeoutSeconds: 5.0);
$result = $check->check($instance);

match ($result->status) {
    ServiceHealthStatus::Healthy => 'OK',
    ServiceHealthStatus::Degraded => "Degraded: {$result->message}",
    ServiceHealthStatus::Unhealthy => "Down: {$result->message}",
};
```

## Config Center

The `StaticConfigCenter` provides namespaced key-value configuration:

```php
$center = StaticConfigCenter::fromArray([
    'database' => ['host' => 'db.internal', 'port' => '5432'],
    'cache' => ['host' => 'redis.internal', 'port' => '6379'],
]);

$dbHost = $center->get('database', 'host'); // 'db.internal'
```

## Wiring

Enable service discovery in your application configuration:

```php
// config/service_discovery.php
return [
    'enabled' => true,
    'health_path' => '/health',
    'health_timeout' => 5.0,
    'default_ttl' => 60,
    'services' => [
        // Static service definitions
    ],
    'config_center' => [
        // Namespaced configuration
    ],
];
```

The `ServiceDiscoveryWiring` automatically registers all interfaces in the container when enabled.

## Custom Backends

Implement `ServiceDiscoveryInterface` and `ServiceRegistryInterface` for custom backends (Consul, etcd, Kubernetes):

```php
final class ConsulServiceDiscovery implements ServiceDiscoveryInterface, ServiceRegistryInterface
{
    // Implement against Consul HTTP API
}
```

Register your implementation in the container to override the default.

## Events

| Event                  | Dispatched When                                             |
| ---------------------- | ----------------------------------------------------------- |
| `ServiceRegistered`    | A service instance is added to the registry                 |
| `ServiceDeregistered`  | A service instance is removed (manual, bulk, or TTL expiry) |
| `ServiceHealthChanged` | A service instance's health status changes                  |
