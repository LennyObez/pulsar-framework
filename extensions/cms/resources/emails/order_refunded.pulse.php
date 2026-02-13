<?php
/*
PLAIN TEXT VERSION:

Refund Processed for Order #{order_number}

Dear Customer,

A refund has been processed for your order #{order_number}.

Refund Amount: {refund_amount} {currency}
Reason: {reason}

The refund will appear on your statement within 5-10 business days depending on your payment provider.

If you have any questions, please contact our support team.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Refund Processed</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.refund-box { background: #f0fff0; border: 1px solid #c3e6c3; border-radius: 8px; padding: 16px; margin: 16px 0; }
.refund-box dt { font-weight: 600; color: #333; }
.refund-box dd { margin: 0 0 8px 0; color: #555; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Refund Processed</h1>
    </div>
    <div class="content">
        <p>Dear Customer,</p>
        <p>A refund has been processed for your order <strong>#<?php echo $this->escape($order_number); ?></strong>.</p>

        <dl class="refund-box">
            <dt>Refund Amount</dt>
            <dd><strong><?php echo $this->escape($refund_amount); ?> <?php echo $this->escape($currency); ?></strong></dd>
            <?php if (isset($reason) && $reason !== ''): ?>
            <dt>Reason</dt>
            <dd><?php echo $this->escape($reason); ?></dd>
            <?php endif; ?>
        </dl>

        <p>The refund will appear on your statement within 5-10 business days depending on your payment provider.</p>
    </div>
    <div class="footer">
        <p>If you have any questions about this refund, please contact our support team.</p>
    </div>
</div>
</body>
</html>
