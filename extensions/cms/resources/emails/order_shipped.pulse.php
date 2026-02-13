<?php
/*
PLAIN TEXT VERSION:

Your Order Has Been Shipped

Dear Customer,

Great news! Your order #{order_number} has been shipped.

Tracking Number: {tracking_number}
Carrier: {carrier}
Estimated Delivery: {estimated_delivery}

Track your package: {tracking_url}

If you have any questions, please contact our support team.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Shipped</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.tracking-box { background: #f0f7ff; border: 1px solid #cce0ff; border-radius: 8px; padding: 16px; margin: 16px 0; }
.tracking-box dt { font-weight: 600; color: #333; }
.tracking-box dd { margin: 0 0 8px 0; color: #555; }
.btn { display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 4px; margin: 16px 0; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Your Order Has Been Shipped</h1>
    </div>
    <div class="content">
        <p>Dear Customer,</p>
        <p>Great news! Your order <strong>#<?php echo $this->escape($order_number); ?></strong> has been shipped.</p>

        <dl class="tracking-box">
            <?php if (isset($tracking_number)): ?>
            <dt>Tracking Number</dt>
            <dd><?php echo $this->escape($tracking_number); ?></dd>
            <?php endif; ?>
            <?php if (isset($carrier)): ?>
            <dt>Carrier</dt>
            <dd><?php echo $this->escape($carrier); ?></dd>
            <?php endif; ?>
            <?php if (isset($estimated_delivery)): ?>
            <dt>Estimated Delivery</dt>
            <dd><?php echo $this->escape($estimated_delivery); ?></dd>
            <?php endif; ?>
        </dl>

        <?php if (isset($tracking_url)): ?>
        <a href="<?php echo $this->escape($tracking_url); ?>" class="btn">Track Your Package</a>
        <?php endif; ?>
    </div>
    <div class="footer">
        <p>If you have any questions about your shipment, please contact our support team.</p>
    </div>
</div>
</body>
</html>
