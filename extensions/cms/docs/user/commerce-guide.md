# Commerce Guide

This guide covers the Pulsar CMS commerce subsystem, including product management, variants, promotions, order processing, refunds, invoices, tax configuration, and digital product delivery.

## Overview

The CMS commerce subsystem provides a complete e-commerce solution integrated with CMS content. Products can be linked to CMS content pages for rich product descriptions, and the checkout flow integrates with payment gateways via webhooks.

## Enabling Commerce

Commerce is disabled by default. Enable it by adding a `commerce` section to `config/cms.php`:

```php
'commerce' => [
    'currency' => 'EUR',
    'tax_required' => false,
    'invoice_renderer' => 'html',
    'download_token_expiry_days' => 30,
    'max_downloads' => 5,
    'taxRates' => [],
],
```

When the `commerce` key is present (non-null), the commerce subsystem is activated and all commerce routes are registered.

## Products

### Creating a Product

<!-- Screenshot: Product creation form -->

1. Navigate to **Admin > CMS > Products** (`/admin/cms/products`).
2. Click **Create New Product** (`/admin/cms/products/create`).
3. Fill in the product fields:

| Field          | Required | Description                                            |
| -------------- | -------- | ------------------------------------------------------ |
| SKU            | Yes      | Stock keeping unit (unique identifier)                 |
| Price          | Yes      | Price in minor currency units (e.g., cents)            |
| Currency       | Yes      | ISO 4217 currency code (default from config)           |
| Status         | Auto     | Starts as `Draft`                                      |
| Tax Category   | No       | Tax category for tax calculation                       |
| Stock Quantity | No       | Available inventory count (default: 0)                 |
| Digital        | No       | Whether this is a digital product                      |
| Content ID     | No       | Link to a CMS content page for the product description |

4. Click **Save**.

### Via API

```
POST /admin/cms/products
Content-Type: application/json

{
    "sku": "WIDGET-001",
    "price_amount": 2999,
    "price_currency": "EUR",
    "tax_category": "standard",
    "stock_quantity": 100,
    "digital": false
}
```

### Product Statuses

| Status         | Description                                           |
| -------------- | ----------------------------------------------------- |
| `Draft`        | Product is being prepared, not available for purchase |
| `Active`       | Product is live and available for purchase            |
| `Discontinued` | Product is no longer available                        |

### Editing Products

1. Navigate to **Admin > CMS > Products > {id} > Edit** (`/admin/cms/products/{id}/edit`).
2. Modify the desired fields.
3. Click **Save**.

### Product Translations

Products support per-locale translations for name, description, and other display fields:

```json
{
  "translations": {
    "en": { "name": "Widget Pro", "description": "Our best widget" },
    "fr": { "name": "Widget Pro", "description": "Notre meilleur widget" }
  }
}
```

## Product Variants

Products can have multiple variants (e.g., size, color combinations).

### Creating Variants

Each variant has:

| Field          | Description                                           |
| -------------- | ----------------------------------------------------- |
| SKU Suffix     | Appended to the parent SKU (e.g., `WIDGET-001-RED-L`) |
| Attributes     | Key-value pairs (e.g., `color: red`, `size: large`)   |
| Price Override | Optional price different from the parent product      |
| Stock Quantity | Variant-specific inventory count                      |

### Product Attributes

Attributes describe variant properties:

```json
{
  "attributes": [
    { "name": "color", "value": "Red" },
    { "name": "size", "value": "Large" }
  ]
}
```

## Promotions and Coupons

### Creating a Promotion

1. Navigate to **Admin > CMS > Promotions** (`/admin/cms/promotions`).
2. Click **Create New Promotion** (`/admin/cms/promotions/create`).
3. Configure the promotion:

| Field                | Description                                                |
| -------------------- | ---------------------------------------------------------- |
| Name                 | Human-readable promotion name                              |
| Type                 | `percentage`, `fixed_amount`, or `free_shipping`           |
| Value                | Discount value (percentage or fixed amount in minor units) |
| Code                 | Optional coupon code for customer entry                    |
| Start Date           | When the promotion becomes active                          |
| End Date             | When the promotion expires                                 |
| Minimum Order Amount | Minimum cart total to qualify                              |
| Maximum Uses         | Total redemption limit                                     |
| Per-Customer Limit   | Maximum uses per customer                                  |

4. Click **Save**.

### Promotion Types

| Type            | Description                              | Example               |
| --------------- | ---------------------------------------- | --------------------- |
| `percentage`    | Percentage off the cart total            | 15% off               |
| `fixed_amount`  | Fixed amount off in minor currency units | 500 cents ($5.00) off |
| `free_shipping` | Removes shipping charges                 | Free shipping         |

### Coupon Codes

Coupons are a specific type of promotion with a customer-entered code:

1. Create a promotion with a `code` field.
2. Customers enter the code at checkout.
3. The `PromotionEngine` validates the coupon:
  - Code must match an active promotion
  - Promotion must be within its date range
  - Usage limits must not be exceeded
  - Minimum order amount must be met
4. The discount is applied to the cart.

### Promotion Validation

The `PromotionValidationResult` provides detailed feedback:

- `valid`: Whether the promotion can be applied
- `reason`: Human-readable explanation if invalid
- `discount`: The calculated discount amount

## Orders

### Order Lifecycle

```
Cart --> Pending Payment --> Confirmed --> Fulfilled
                        |             |
                        v             v
                      Failed       Refunded
                        |
                        v
                     Cancelled
```

### Order Statuses

| Status            | Description                              |
| ----------------- | ---------------------------------------- |
| `Cart`            | Items added but checkout not started     |
| `Pending Payment` | Checkout initiated, awaiting payment     |
| `Confirmed`       | Payment received, order processing       |
| `Fulfilled`       | Order shipped or digital items delivered |
| `Refunded`        | Full or partial refund processed         |
| `Failed`          | Payment failed                           |
| `Cancelled`       | Order cancelled before fulfillment       |

Terminal statuses (no further transitions): `Refunded`, `Cancelled`.

### Viewing Orders

1. Navigate to **Admin > CMS > Orders** (`/admin/cms/orders`).
2. The order list shows all orders with status, total, customer, and date.
3. Click an order to view details (`/admin/cms/orders/{id}`):
  - Order items with quantities and prices
  - Payment status and gateway reference
  - Shipping information
  - Tax breakdown
  - Associated invoice

### Exporting Orders

Export orders to CSV for accounting or analytics:

```
GET /admin/cms/orders/export
```

The `OrderExportService` generates a CSV file with all order fields, suitable for import into accounting software.

## Refunds

### Processing a Refund

1. Open an order at **Admin > CMS > Orders > {id}**.
2. Click **Refund**.
3. Enter the refund amount (full or partial).
4. Provide a reason for the refund.
5. Confirm.

```
POST /admin/cms/orders/{id}/refund
Content-Type: application/json

{
    "amount": 2999,
    "reason": "Customer requested cancellation"
}
```

The refund is processed through the original payment gateway. The `RefundProcessed` event is dispatched.

## Invoices

### Automatic Generation

Invoices are generated automatically when an order is confirmed. The `InvoiceService` creates invoices using the configured renderer.

### Viewing Invoices

1. Navigate to **Admin > CMS > Invoices > {id}** (`/admin/cms/invoices/{id}`).
2. View the invoice details including:
  - Invoice number
  - Order reference
  - Line items with prices and quantities
  - Tax breakdown
  - Total amount
  - Payment status

### Downloading Invoices

```
GET /admin/cms/invoices/{id}/download
```

Invoices are rendered in the configured format (default: HTML). The `HtmlInvoiceRenderer` produces professional invoice documents.

### Invoice Events

The `InvoiceGenerated` event fires when a new invoice is created, enabling integrations with accounting systems.

## Tax Configuration

### Tax Rates

Configure tax rates in `config/cms.php`:

```php
'commerce' => [
    'tax_required' => true,
    'taxRates' => [
        [
            'name' => 'Standard VAT',
            'rate' => 0.20,
            'country' => 'FR',
            'category' => 'standard',
        ],
        [
            'name' => 'Reduced VAT',
            'rate' => 0.055,
            'country' => 'FR',
            'category' => 'reduced',
        ],
    ],
],
```

### Tax Calculation

The `TaxCalculator` computes taxes per order:

1. Each product has a `tax_category` (e.g., `standard`, `reduced`, `exempt`)
2. The calculator matches the product's category to configured tax rates
3. Tax is calculated per line item
4. The result includes individual `TaxLineItem` entries and the total tax

### Tax Result

Each tax calculation produces a `TaxResult` containing:

- Line-item tax breakdown
- Total tax amount
- Applied tax rates

## Digital Products

### Setup

1. Create a product with `digital: true`.
2. Upload digital assets at **Admin > CMS > Digital Assets** (`/admin/cms/digital-assets`).
3. Associate digital assets with the product.

### Digital Asset Management

```
POST /admin/cms/digital-assets
Content-Type: multipart/form-data

file: [digital-file.zip]
product_id: "product-uuid"
```

View assets: `GET /admin/cms/digital-assets`
Delete assets: `DELETE /admin/cms/digital-assets/{id}`

### Download Delivery

After purchase, the `DigitalDeliveryService` generates secure download tokens:

1. Customer completes checkout for a digital product.
2. A `DigitalDownload` record is created with a unique token.
3. The `DigitalDownloadReady` event fires.
4. The customer receives a download URL: `/download/{token}`.

### Download Limits

| Setting                      | Default | Description                       |
| ---------------------------- | ------- | --------------------------------- |
| `download_token_expiry_days` | 30      | Days until download token expires |
| `max_downloads`              | 5       | Maximum downloads per purchase    |

After the limit is reached or the token expires, the download link returns a 403 response.

## Checkout Flow

### Public Checkout Routes

The checkout is locale-aware:

| Route                            | Description        |
| -------------------------------- | ------------------ |
| `GET /{locale}/checkout`         | Show checkout page |
| `POST /{locale}/checkout`        | Process checkout   |
| `GET /{locale}/checkout/success` | Confirmation page  |

### Checkout Process

1. Customer navigates to `/{locale}/checkout`.
2. The `CheckoutController` displays the cart summary and payment form.
3. Customer submits payment details.
4. The `CheckoutService` orchestrates:
  - Cart validation (`CartValidationResult`)
  - Tax calculation
  - Promotion/coupon application
  - Payment gateway interaction
5. On success, the order transitions to `Confirmed`.
6. Customer is redirected to the success page.

### Payment Gateway

The `PaymentGateway` interface handles payment processing:

- Payment initiation and confirmation
- Webhook handling for asynchronous payment notifications
- Refund processing

### Payment Webhooks

Payment gateways send asynchronous notifications to:

```
POST /webhooks/cms-payment
```

The `WebhookHandler` processes these notifications, updating order and payment status.

## Commerce Events

| Event                  | When                                |
| ---------------------- | ----------------------------------- |
| `OrderCreated`         | New order is created                |
| `OrderStatusChanged`   | Order status transitions            |
| `PaymentReceived`      | Payment is confirmed                |
| `RefundProcessed`      | Refund is completed                 |
| `InvoiceGenerated`     | Invoice is created                  |
| `DigitalDownloadReady` | Digital download token is generated |

## Permissions

| Permission                  | Role          | Description                  |
| --------------------------- | ------------- | ---------------------------- |
| `cms.products.view`         | Shop Manager+ | View product catalog         |
| `cms.products.create`       | Shop Manager+ | Create new products          |
| `cms.products.edit`         | Shop Manager+ | Edit existing products       |
| `cms.products.delete`       | Shop Manager+ | Delete products              |
| `cms.orders.view`           | Shop Manager+ | View orders                  |
| `cms.orders.manage`         | Shop Manager+ | Manage order status          |
| `cms.orders.refund`         | Shop Manager+ | Process refunds              |
| `cms.orders.export`         | Shop Manager+ | Export order data            |
| `cms.promotions.view`       | Shop Manager+ | View promotions              |
| `cms.promotions.manage`     | Shop Manager+ | Create and manage promotions |
| `cms.invoices.view`         | Shop Manager+ | View invoices                |
| `cms.invoices.download`     | Shop Manager+ | Download invoice documents   |
| `cms.digital_assets.manage` | Shop Manager+ | Manage digital assets        |

## Configuration Reference

| Key                                   | Type   | Default  | Description                          |
| ------------------------------------- | ------ | -------- | ------------------------------------ |
| `commerce.currency`                   | string | `'EUR'`  | Default ISO 4217 currency code       |
| `commerce.tax_required`               | bool   | `false`  | Whether tax calculation is mandatory |
| `commerce.invoice_renderer`           | string | `'html'` | Invoice rendering format             |
| `commerce.download_token_expiry_days` | int    | `30`     | Download token expiry                |
| `commerce.max_downloads`              | int    | `5`      | Max downloads per purchase           |
| `commerce.taxRates`                   | array  | `[]`     | Tax rate configurations              |

## Next Steps

- [Import/Export Guide](import-export-guide.md) - Exporting order data
- [Settings Reference](settings-reference.md) - Commerce configuration details
- [Compliance Guide](../security/compliance-guide.md) - Financial record retention
