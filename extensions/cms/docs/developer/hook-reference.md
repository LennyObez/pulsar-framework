# Hook reference

The Pulsar CMS plugin hook system enables plugins to observe and react to CMS events without modifying core code. Hooks are dispatched by the `HookExecutionEngine` and registered via `CmsPluginContext::registerHook()`.

## How hooks work

### Registration

Plugins register hooks in their `boot()` method:

```php
public function boot(CmsPluginContext $context): void
{
    $context->registerHook('hook.point.name', $callback, $priority);
}
```

### Execution

The `HookExecutionEngine` retrieves all registered callbacks for a hook point from the `HookRegistry`, sorts them by priority (ascending), and calls each one in sequence with the provided arguments.

### Priority system

| Priority | Use Case                                      |
| -------- | --------------------------------------------- |
| 1-9      | Early execution (validation, security checks) |
| 10       | Default priority                              |
| 11-50    | Normal execution                              |
| 51-99    | Late execution (logging, analytics, cleanup)  |

Lower values execute first. Callbacks at the same priority execute in registration order.

## Content lifecycle hooks

These hooks correspond to domain events dispatched by the Content module.

### `content.created`

Dispatched after a new content item is created.

```php
$context->registerHook('content.created', function (
    Content $content,
): void {
    // React to new content creation
});
```

### `content.updated`

Dispatched after a content item is updated.

```php
$context->registerHook('content.updated', function (
    Content $content,
): void {
    // React to content updates
});
```

### `content.published`

Dispatched after content transitions to Published status.

```php
$context->registerHook('content.published', function (
    Content $content,
): void {
    // React to content publication
    // e.g., notify subscribers, update external search index
});
```

### `content.archived`

Dispatched after content transitions to Archived status.

```php
$context->registerHook('content.archived', function (
    Content $content,
): void {
    // React to content archival
    // e.g., remove from external search index
});
```

### `content.deleted`

Dispatched after a content item is soft-deleted.

```php
$context->registerHook('content.deleted', function (
    Content $content,
): void {
    // React to content deletion
    // e.g., cleanup related resources
});
```

### `content.restored`

Dispatched after archived content is restored to Draft.

```php
$context->registerHook('content.restored', function (
    Content $content,
): void {
    // React to content restoration
});
```

### `content.slug_changed`

Dispatched when a content item's URL slug is modified.

```php
$context->registerHook('content.slug_changed', function (
    Content $content,
    string $oldSlug,
    string $newSlug,
): void {
    // React to slug changes
    // e.g., create automatic redirects
});
```

### `content.revision_created`

Dispatched after a new content revision is saved.

```php
$context->registerHook('content.revision_created', function (
    Content $content,
    ContentRevision $revision,
): void {
    // React to revision creation
});
```

## CMS lifecycle hooks

### `cms.ready`

Dispatched after the CMS extension has fully booted (post-boot phase). All services are initialized and the CMS is ready to handle requests.

```php
$context->registerHook('cms.ready', function (
    DateTimeImmutable $readyAt,
): void {
    // Perform post-initialization tasks
});
```

## Theme hooks

### `theme.installed`

Dispatched after a theme is installed.

```php
$context->registerHook('theme.installed', function (
    InstalledTheme $theme,
): void {
    // React to theme installation
});
```

### `theme.activated`

Dispatched after a theme is activated.

```php
$context->registerHook('theme.activated', function (
    InstalledTheme $theme,
): void {
    // React to theme activation
    // e.g., precompile theme assets
});
```

### `theme.deleted`

Dispatched after a theme is soft-deleted and its files removed.

```php
$context->registerHook('theme.deleted', function (
    string $themeId,
    string $themeSlug,
): void {
    // React to theme deletion
    // e.g., cleanup cached assets
});
```

## Plugin hooks

### `plugin.installed`

Dispatched after a plugin is installed.

```php
$context->registerHook('plugin.installed', function (
    InstalledCmsPlugin $plugin,
): void {
    // React to plugin installation
});
```

### `plugin.enabled`

Dispatched after a plugin is enabled.

```php
$context->registerHook('plugin.enabled', function (
    InstalledCmsPlugin $plugin,
): void {
    // React to plugin being enabled
});
```

### `plugin.disabled`

Dispatched after a plugin is disabled.

```php
$context->registerHook('plugin.disabled', function (
    InstalledCmsPlugin $plugin,
): void {
    // React to plugin being disabled
});
```

### `plugin.deleted`

Dispatched after a plugin is soft-deleted.

```php
$context->registerHook('plugin.deleted', function (
    string $pluginId,
    string $pluginSlug,
): void {
    // React to plugin deletion
});
```

## SEO hooks

### `seo.link_health_check_completed`

Dispatched after a link health check scan completes.

```php
$context->registerHook('seo.link_health_check_completed', function (
    int $checkedCount,
    int $brokenCount,
): void {
    // React to link health check results
    // e.g., send notification if broken links found
});
```

## Commerce hooks

### `commerce.order.created`

Dispatched after a new order is created during checkout.

```php
$context->registerHook('commerce.order.created', function (
    Order $order,
): void {
    // React to new order creation
});
```

### `commerce.order.status_changed`

Dispatched when an order's status changes.

```php
$context->registerHook('commerce.order.status_changed', function (
    Order $order,
    OrderStatus $newStatus,
): void {
    // React to order status changes
    // e.g., send notification emails
});
```

### `commerce.payment.received`

Dispatched when payment is confirmed for an order.

```php
$context->registerHook('commerce.payment.received', function (
    Order $order,
    string $paymentIntentId,
): void {
    // React to payment confirmation
});
```

### `commerce.order.cancelled`

Dispatched when an order is cancelled.

```php
$context->registerHook('commerce.order.cancelled', function (
    Order $order,
): void {
    // React to order cancellation
});
```

### `commerce.refund.processed`

Dispatched after a refund is processed.

```php
$context->registerHook('commerce.refund.processed', function (
    Order $order,
    int $amountCents,
    string $reason,
): void {
    // React to refund processing
});
```

## Safety rules

### Hooks must not

1. **Produce output** -- All output is captured and discarded. Use logging instead.
2. **Throw exceptions for flow control** -- Exceptions are caught and counted toward the circuit breaker. Use return values for control flow.
3. **Consume excessive memory** -- Callbacks exceeding 32 MB of memory allocation trigger a failure record.
4. **Access restricted services** -- Only services available through `ScopedContainerProxy` are accessible.
5. **Block indefinitely** -- Long-running operations should be deferred to a queue.

### Hooks should

1. **Be idempotent** -- The same hook may fire multiple times; handle gracefully.
2. **Fail fast** -- Return early if the hook is not relevant to the current context.
3. **Log errors** -- Use `LoggerInterface` from the scoped container for diagnostics.
4. **Keep state minimal** -- Avoid accumulating state across multiple hook invocations.

## Circuit breaker behavior

The `HookExecutionEngine` tracks failures per plugin:

| Metric            | Value                                                    |
| ----------------- | -------------------------------------------------------- |
| Failure threshold | 10 failures                                              |
| Window duration   | 300 seconds (5 minutes)                                  |
| Recovery          | Application restart (circuit breaker state is in-memory) |

When the threshold is reached:

1. All callbacks from the failing plugin are silently skipped
2. A critical log entry is written
3. An audit event (`cms.plugin.circuit_breaker_tripped`) is recorded
4. The plugin remains circuit-broken until the application process restarts

## Inspecting registered hooks

The `HookRegistry` provides introspection methods:

```php
// Get all registered hook points
$points = $hookRegistry->getHookPoints();

// Check if a hook point has any registered callbacks
$hasCallbacks = $hookRegistry->has('content.published');

// Get callbacks for a specific hook point (sorted by priority)
$callbacks = $hookRegistry->getCallbacks('content.published');
// Returns: list<array{callback: Closure, priority: int, pluginSlug: string}>
```

## Related documentation

- [Plugin Development Guide](plugin-development.md) - How to register hooks in a plugin
- [Architecture Overview](architecture.md) - Event dispatch and plugin sandboxing
- [Content Type API](content-type-api.md) - Content lifecycle and custom types
