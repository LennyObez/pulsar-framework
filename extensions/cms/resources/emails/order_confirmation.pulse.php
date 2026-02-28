<?php
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
        <p>Thank you for your order! Your order <strong>#<?php echo $this->escape($order_number); ?></strong> has been confirmed and is being processed.</p>

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
                    <td><?php echo $this->escape($item['name']); ?></td>
                    <td><?php echo $this->escape((string) $item['quantity']); ?></td>
                    <td><?php echo $this->escape($item['total']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <dl class="totals">
            <dt>Subtotal:</dt><dd><?php echo $this->escape($subtotal); ?></dd>
            <dt>Tax:</dt><dd><?php echo $this->escape($tax_amount); ?></dd>
            <?php if (($discount_amount ?? '') !== ''): ?>
            <dt>Discount:</dt><dd>-<?php echo $this->escape($discount_amount); ?></dd>
            <?php endif; ?>
            <dt>Total:</dt><dd><strong><?php echo $this->escape($total); ?> <?php echo $this->escape($currency); ?></strong></dd>
        </dl>

        <?php if (isset($invoice_url)): ?>
        <a href="<?php echo $this->escape($invoice_url); ?>" class="btn">View Invoice</a>
        <?php endif; ?>
    </div>
    <div class="footer">
        <p>If you have any questions about your order, please contact our support team.</p>
    </div>
</div>
</body>
</html>
