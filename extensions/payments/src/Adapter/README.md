# Payment Adapter Guide

This directory is the conventional location for vendor-specific payment provider adapters.

## Creating a Vendor Adapter

1. Implement `PaymentProviderInterface` in a new class (e.g., `StripeAdapter`).
2. Map vendor-specific API responses to Pulsar domain DTOs (`PaymentIntent`, `Charge`, `Refund`).
3. Forward the `$idempotencyKey` parameter to the vendor's idempotency mechanism.
4. Register your adapter class-string in `config/payments.php`:

```php
return [
    'provider' => \App\Payments\StripeAdapter::class,
    // ...
];
```

The `PaymentsServiceProvider` will resolve the class from the container, so all constructor dependencies are autowired.

## Guidelines

- Never expose vendor SDK types outside the adapter boundary.
- Map all vendor exceptions to `PaymentProviderException` static factories.
- Include the vendor's request ID in `PaymentProviderException` messages for debugging.
- Use the `$metadata` parameter for vendor-specific options (e.g., statement descriptors) — the gateway excludes metadata from idempotency hashing.
