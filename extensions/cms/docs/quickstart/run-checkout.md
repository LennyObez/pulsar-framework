# Quickstart: Run a Checkout

**Estimated time: 10 minutes**

This quickstart walks you through enabling the commerce subsystem, creating a product, running a checkout with a test payment, and viewing the resulting order and invoice.

## Prerequisites

- CMS admin access with Shop Manager or Admin role
- Commerce subsystem enabled in configuration
- A payment gateway configured (or use the test simulator)

## Step 1: Enable Commerce

Add the `commerce` section to `config/cms.php`:

```php
'commerce' => [
    'currency' => 'EUR',
    'tax_required' => false,
    'invoice_renderer' => 'html',
    'download_token_expiry_days' => 30,
    'max_downloads' => 5,
],
```

Restart your application to apply the configuration. The CMS registers all commerce routes when the `commerce` key is present.

## Step 2: Create a Product

1. Navigate to **Admin > CMS > Products** (`/admin/cms/products`).
2. Click **Create New Product** (`/admin/cms/products/create`).
3. Fill in the form:
  - **SKU**: `DEMO-WIDGET-001`
  - **Price**: `2999` (29.99 EUR in minor units/cents)
  - **Currency**: `EUR`
  - **Stock Quantity**: `100`
  - **Digital**: No (leave unchecked for a physical product)
4. Click **Save**.

<!-- Screenshot: Product creation form -->

5. Activate the product by changing its status to **Active**.

```
POST /admin/cms/products
Content-Type: application/json

{
    "sku": "DEMO-WIDGET-001",
    "price_amount": 2999,
    "price_currency": "EUR",
    "stock_quantity": 100,
    "digital": false
}
```

## Step 3: Navigate to Checkout

Open a browser and go to the public checkout page:

```
https://your-site.com/en/checkout
```

The checkout page shows:

- Cart summary with the product
- Price breakdown
- Payment form

<!-- Screenshot: Public checkout page with cart summary -->

## Step 4: Process the Checkout

1. Add the product to the cart.
2. Fill in the checkout form:
  - Customer name and email
  - Billing address
  - Payment details (use test card details for your payment gateway simulator)
3. Click **Complete Purchase**.

```
POST /en/checkout
```

The `CheckoutService` processes the order:

1. Cart is validated.
2. Tax is calculated (if configured).
3. Any active promotions are applied.
4. Payment is initiated with the gateway.
5. On success, the order is confirmed.

## Step 5: View the Success Page

After successful payment, you are redirected to:

```
https://your-site.com/en/checkout/success
```

<!-- Screenshot: Checkout success page -->

The page confirms the order with:

- Order reference number
- Items purchased
- Total amount paid
- Expected delivery information

## Step 6: View the Order in Admin

1. Navigate to **Admin > CMS > Orders** (`/admin/cms/orders`).
2. Find your order in the list.
3. Click to view details (`/admin/cms/orders/{id}`).

<!-- Screenshot: Order detail page in admin -->

The order detail shows:

- **Status**: Confirmed
- **Items**: DEMO-WIDGET-001 x 1
- **Subtotal**: 29.99 EUR
- **Tax**: 0.00 EUR (tax not required in this example)
- **Total**: 29.99 EUR
- **Payment**: Confirmed via test gateway
- **Invoice**: Auto-generated

## Step 7: View the Invoice

1. On the order detail page, click the invoice link.
2. Or navigate to **Admin > CMS > Invoices > {id}** (`/admin/cms/invoices/{id}`).

<!-- Screenshot: Invoice view -->

The invoice includes:

- Invoice number
- Order reference
- Line items with quantities and prices
- Tax breakdown
- Total amount
- Payment status

Click **Download** to save the invoice:

```
GET /admin/cms/invoices/{id}/download
```

## Testing a Refund

To test the refund flow:

1. On the order detail page, click **Refund**.
2. Enter the refund amount: `2999` (full refund).
3. Enter a reason: `"Test refund for demonstration purposes"`.
4. Confirm.

```
POST /admin/cms/orders/{id}/refund
Content-Type: application/json

{
    "amount": 2999,
    "reason": "Test refund for demonstration purposes"
}
```

The order status changes to **Refunded**.

## Done

You have completed a full checkout cycle: product creation, checkout, payment, order confirmation, invoice generation, and refund.

## Next Steps

- [Commerce Guide](../user/commerce-guide.md) - Variants, promotions, digital products, and tax configuration
- [Import/Export Guide](../user/import-export-guide.md) - Exporting order data
- [Compliance Guide](../security/compliance-guide.md) - Financial record retention
