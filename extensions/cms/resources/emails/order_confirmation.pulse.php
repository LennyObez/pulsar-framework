<?php
/**
 * Order confirmation email template.
 *
 * @var string $order_number
 * @var list<array{name: string, quantity: int, total: string}> $items
 * @var string $subtotal
 * @var string $tax_amount
 * @var string $discount_amount
 * @var string $total
 * @var string $currency
 * @var string $invoice_url
 */

use function htmlspecialchars;

use const ENT_QUOTES;

/*
PLAIN TEXT VERSION:

Your Order #{order_number} Has Been Confirmed

Dear Customer,

Thank you for your order! Your order #{order_number} has been confirmed and is being processed.

Order Summary:
{items_list}

Subtotal: {subtotal}
Tax: {tax_amount}
Discount: {discount_amount}
Total: {total} {currency}

View your invoice: {invoice_url}

If you have any questions, please contact our support team.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Confirmation</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.items-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
.items-table th, .items-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
.items-table th { background: #f9f9f9; font-weight: 600; }
.totals { margin: 16px 0; }
.totals dt { font-weight: 600; display: inline; }
.totals dd { display: inline; margin-left: 4px; margin-right: 16px; }
.btn { display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 4px; margin: 16px 0; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Order Confirmed</h1>
    </div>
    <div class="content">
        <p>Dear Customer,</p>
        <p>Thank you for your order! Your order <strong>#<?php echo htmlspecialchars($order_number, ENT_QUOTES, 'UTF-8'); ?></strong> has been confirmed and is being processed.</p>

        <h2>Order Summary</h2>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $item['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($item['total'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <dl class="totals">
            <dt>Subtotal:</dt><dd><?php echo htmlspecialchars($subtotal, ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Tax:</dt><dd><?php echo htmlspecialchars($tax_amount, ENT_QUOTES, 'UTF-8'); ?></dd>
            <?php if (($discount_amount ?? '') !== ''): ?>
            <dt>Discount:</dt><dd>-<?php echo htmlspecialchars($discount_amount, ENT_QUOTES, 'UTF-8'); ?></dd>
            <?php endif; ?>
            <dt>Total:</dt><dd><strong><?php echo htmlspecialchars($total, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></strong></dd>
        </dl>

        <?php if (isset($invoice_url)): ?>
        <a href="<?php echo htmlspecialchars($invoice_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn">View Invoice</a>
        <?php endif; ?>
    </div>
    <div class="footer">
        <p>If you have any questions about your order, please contact our support team.</p>
    </div>
</div>
</body>
</html>
