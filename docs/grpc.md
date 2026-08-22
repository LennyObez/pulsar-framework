# gRPC Extension

Pulsar's gRPC extension provides first-class support for building gRPC services with PHP 8.5+. It integrates with Pulsar's interceptor pipeline, security model, and observability stack.

## Prerequisites

### Persistent Runtime

gRPC requires persistent HTTP/2 connections. PHP-FPM cannot maintain these connections, so you must use one of:

- **RoadRunner** (recommended) -- Set `RUNTIME_DRIVER=roadrunner`
- **FrankenPHP** -- Set `RUNTIME_DRIVER=frankenphp`

The gRPC extension refuses to start under PHP-FPM with a clear error message.

### gRPC PECL Extension

Install the gRPC PHP extension:

```bash
pecl install grpc
```

Or use the RoadRunner gRPC plugin, which does not require the PECL extension.

### Protocol Buffers Compiler

Install `protoc` and the PHP plugin:

```bash
# macOS
brew install protobuf grpc

# Ubuntu/Debian
apt-get install -y protobuf-compiler

# The gRPC PHP plugin
pecl install grpc
```

## Quick Start

### 1. Define Your Service

Create a proto file in your extension's `proto/` directory:

```protobuf
// proto/helloworld/v1/greeter.proto
syntax = "proto3";

package helloworld.v1;

option php_namespace = "App\\Proto\\Helloworld\\V1";

service Greeter {
  rpc SayHello (HelloRequest) returns (HelloReply);
}

message HelloRequest {
  string name = 1;
}

message HelloReply {
  string message = 1;
}
```

### 2. Generate PHP Code

```bash
php pulsar grpc:generate
```

This runs `protoc` to generate PHP message classes and client stubs, validates the output, and generates Pulsar service handler base classes.

### 3. Implement Your Handler

```php
<?php

declare(strict_types=1);

namespace App\Grpc\Handler;

use App\Proto\Helloworld\V1\HelloReply;
use App\Proto\Helloworld\V1\HelloRequest;

final class GreeterHandler extends AbstractGreeterHandler
{
    protected function handleSayHello(string $payload): string
    {
        $request = new HelloRequest();
        $request->mergeFromString($payload);

        $reply = new HelloReply();
        $reply->setMessage('Hello, ' . $request->getName() . '!');

        return $reply->serializeToString();
    }
}
```

### 4. Register and Start

Register your handler in the service provider, then start the server:

```bash
php pulsar grpc:serve
```

## Defining Services with Proto Files

Place your `.proto` files in the `proto/` directory (configurable via `codegen.proto_path`). Organize by package and version:

```
proto/
  helloworld/
    v1/
      greeter.proto
  health/
    v1/
      health.proto
```

Follow the [Google API Design Guide](https://cloud.google.com/apis/design) for proto file conventions:

- Use `package` to namespace your services
- Set `php_namespace` to control generated PHP namespaces
- Use semantic versioning in package names (`v1`, `v2`)
- Document services and methods with comments

## Running grpc:generate

The `grpc:generate` command wraps protoc with validation and handler generation:

```bash
# Generate from all proto files
php pulsar grpc:generate

# Generate from a specific proto file
php pulsar grpc:generate proto/helloworld/v1/greeter.proto

# Specify output directory
php pulsar grpc:generate --output=src/Generated

# Validate only (no handler generation)
php pulsar grpc:generate --validate-only
```

The command performs these steps:

1. Invokes `protoc` with the PHP and gRPC PHP plugins
2. Validates the output (checks for naming conflicts, syntax errors)
3. Records the protoc version in `proto/build-metadata.json` for reproducible builds
4. Generates abstract service handler base classes

## Implementing Service Handlers

The generated abstract handler classes implement `ServiceHandlerInterface` and provide:

- `serviceName()` -- returns the fully qualified protobuf service name
- `methods()` -- returns method descriptors for all RPCs
- `invoke()` -- dispatches to the correct handler method

You implement the abstract `handle*()` methods with your business logic. Each method receives and returns serialized protobuf bytes.

## Interceptor Pipeline

gRPC calls pass through a fixed-order interceptor pipeline before reaching the service handler. Interceptors execute in registration order and can:

- Inspect or modify the call context (metadata, attributes)
- Short-circuit the pipeline with an error response
- Add trailing metadata to the response

The interceptor order is fixed at configuration time:

1. **Rate limiting** -- Protects against request floods
2. **Authentication** -- Validates credentials and extracts identity
3. **Authorization** -- Checks permissions for the target method
4. **Validation** -- Validates request payloads
5. **Deadline propagation** -- Enforces request deadlines

Each interceptor receives a `CallContext` and a closure to invoke the next interceptor:

```php
<?php

use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorInterface;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;

final class LoggingInterceptor implements InterceptorInterface
{
    public function handle(CallContext $context, \Closure $next): InterceptorResult
    {
        $start = microtime(true);
        $result = $next($context);
        $elapsed = microtime(true) - $start;

        // Log the call duration
        $this->logger->info('gRPC call', [
            'method' => $context->method->fullName,
            'duration_ms' => $elapsed * 1000,
            'status' => $result->status->name,
        ]);

        return $result;
    }
}
```

## mTLS Setup and Identity Mapping

### Server-Side TLS

Configure TLS in your gRPC config:

```php
'grpc' => [
    'tls' => [
        'enabled' => true,
        'cert_path' => '/path/to/server.crt',
        'key_path' => '/path/to/server.key',
    ],
],
```

### Mutual TLS (mTLS)

For mTLS, also provide a CA certificate and enable mutual verification:

```php
'grpc' => [
    'tls' => [
        'enabled' => true,
        'cert_path' => '/path/to/server.crt',
        'key_path' => '/path/to/server.key',
        'ca_path' => '/path/to/ca.crt',
        'mutual' => true,
    ],
],
```

### Identity Mapping

Map client certificate SANs to application identities:

```php
'grpc' => [
    'identity_map' => [
        'billing.internal.example.com' => [
            'service_name' => 'billing-service',
            'allowed_methods' => ['billing.*'],
        ],
        'spiffe://example.com/payments' => [
            'service_name' => 'payment-service',
            'allowed_methods' => ['payments.*', 'billing.GetInvoice'],
        ],
    ],
],
```

The `MtlsIdentityMapper` resolves client certificates to `ServiceIdentity` objects, which are available in the interceptor pipeline via `CallContext::$peerIdentity`.

## Health Checks

The extension includes the standard `grpc.health.v1.Health` service:

```protobuf
service Health {
  rpc Check(HealthCheckRequest) returns (HealthCheckResponse);
  rpc Watch(HealthCheckRequest) returns (stream HealthCheckResponse);
}
```

Enable health checks in configuration:

```php
'grpc' => [
    'health' => [
        'enabled' => true,
    ],
],
```

The health service is automatically registered and responds to standard gRPC health check probes. Use it with Kubernetes liveness/readiness probes or load balancer health checks.

## Streaming

The extension supports all four gRPC method types:

### Unary (Request-Response)

```protobuf
rpc SayHello(HelloRequest) returns (HelloReply);
```

### Server Streaming

```protobuf
rpc ListFeatures(Rectangle) returns (stream Feature);
```

The handler writes multiple responses to the stream:

```php
public function handleListFeatures(string $payload, StreamInterface $stream): void
{
    foreach ($this->features as $feature) {
        $stream->write($feature->serializeToString());
    }
    $stream->close();
}
```

### Client Streaming

```protobuf
rpc RecordRoute(stream Point) returns (RouteSummary);
```

The handler reads multiple requests from the stream:

```php
public function handleRecordRoute(StreamInterface $stream): string
{
    $points = [];
    while (($data = $stream->read()) !== null) {
        $point = new Point();
        $point->mergeFromString($data);
        $points[] = $point;
    }

    $summary = $this->calculateRoute($points);
    return $summary->serializeToString();
}
```

### Bidirectional Streaming

```protobuf
rpc RouteChat(stream RouteNote) returns (stream RouteNote);
```

Both client and server stream simultaneously.

**Note:** Streaming requires a persistent runtime (RoadRunner or FrankenPHP). The thin gRPC-Web adapter only supports unary calls.

## gRPC-Web

### Production: Envoy Proxy (Recommended)

For production, use Envoy as a gRPC-Web proxy. The extension includes ready-to-use Envoy configurations:

**Basic gRPC-Web translation:**

```bash
envoy -c extensions/grpc/resources/envoy/grpc-web.yaml
```

This provides:

- HTTP/1.1 to gRPC translation via the gRPC-Web filter
- CORS configuration for browser clients
- Rate limiting

**With mTLS:**

```bash
envoy -c extensions/grpc/resources/envoy/mtls.yaml
```

This adds:

- Client certificate verification
- SAN validation
- Forwarded client identity headers

### Development: Thin Adapter

For development convenience, the extension includes a thin `GrpcWebAdapter` that translates gRPC-Web unary calls directly:

```php
$adapter = new GrpcWebAdapter($grpcServer);

$response = $adapter->handle(
    method: 'POST',
    path: '/helloworld.Greeter/SayHello',
    contentType: 'application/grpc-web+proto',
    body: $requestBody,
);
```

**Limitations:**

- Unary calls only (no streaming)
- No HTTP/2 features
- Not suitable for production traffic

## Configuration Reference

```php
'grpc' => [
    // Server binding
    'host' => '0.0.0.0',
    'port' => 50051,
    'max_workers' => 4,
    'max_concurrent_streams' => 100,
    'keep_alive_interval_seconds' => 60,
    'keep_alive_timeout_seconds' => 20,

    // Transport adapter: 'grpc_extension' or 'roadrunner'
    'adapter' => 'grpc_extension',

    // TLS configuration
    'tls' => [
        'enabled' => false,
        'cert_path' => '',
        'key_path' => '',
        'ca_path' => '',
        'mutual' => false,
    ],

    // Interceptor toggles
    'interceptors' => [
        'rate_limiting' => true,
        'authentication' => true,
        'authorization' => true,
        'validation' => true,
        'deadline' => true,
    ],

    // Rate limiting
    'rate_limit' => [
        'enabled' => true,
        'max_requests_per_second' => 1000,
        'burst_size' => 100,
    ],

    // Reflection service
    'reflection' => [
        'enabled' => true,
        'allowed_environments' => ['local', 'development'],
    ],

    // Health check service
    'health' => [
        'enabled' => true,
    ],

    // Code generation
    'codegen' => [
        'proto_path' => 'proto',
        'output_path' => 'src/Generated',
        'protoc_binary' => 'protoc',
        'grpc_php_plugin' => 'grpc_php_plugin',
    ],

    // mTLS identity mapping
    'identity_map' => [
        // SAN => identity config
    ],
],
```

## Troubleshooting

### "The gRPC extension requires a persistent runtime"

PHP-FPM cannot maintain the long-lived HTTP/2 connections required by gRPC. Switch to RoadRunner or FrankenPHP:

```bash
RUNTIME_DRIVER=roadrunner php pulsar grpc:serve
```

### "protoc binary not found"

Ensure `protoc` is installed and in your PATH:

```bash
protoc --version
```

If using a custom location, configure it:

```php
'codegen' => [
    'protoc_binary' => '/usr/local/bin/protoc',
],
```

### "Transport adapter is not available"

The configured transport adapter's underlying extension is not installed:

- For `grpc_extension`: Install the gRPC PECL extension (`pecl install grpc`)
- For `roadrunner`: Ensure RoadRunner is properly configured with the gRPC plugin

### "No services registered"

The gRPC server requires at least one service handler to be registered before starting. Ensure your service provider registers handlers via `ServiceRegistryInterface::register()`.

### gRPC-Web requests failing with CORS errors

When using the Envoy proxy, ensure the CORS configuration in `grpc-web.yaml` includes your application's origin. For custom domains, update the `allow_origin_string_match` section.

### protoc version mismatch warning

The `grpc:generate` command pins the protoc version in `proto/build-metadata.json`. If you see a version mismatch warning, it means a different protoc version is being used. Update the pin by running `grpc:generate` again, or install the expected version.

### Client certificate rejected

For mTLS, verify:

1. The client certificate is signed by the trusted CA
2. The SAN matches an entry in the `identity_map` configuration
3. The certificate has not expired
4. The `ca_path` points to the correct CA certificate file
