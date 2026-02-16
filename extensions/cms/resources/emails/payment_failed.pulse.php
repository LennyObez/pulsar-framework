<?php
/**
 * Payment failed email template.
 *
 * @var string $order_number
 * @var string $reason
 * @var string $retry_url
 */

use function htmlspecialchars;

use const ENT_QUOTES;

/*
PLAIN TEXT VERSION:

Payment Failed for Order #{order_number}

Dear Customer,

We were unable to process the payment for your order #{order_number}.

Please update your payment details and try again: {retry_url}

If you continue to experience issues, please contact our support team.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Failed</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #c0392b; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.alert-box { background: #fdf0ef; border: 1px solid #f5c6cb; border-radius: 8px; padding: 16px; margin: 16px 0; }
.btn { display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 4px; margin: 16px 0; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Payment Failed</h1>
    </div>
    <div class="content">
        <p>Dear Customer,</p>

        <div class="alert-box">
            <p>We were unable to process the payment for your order <strong>#<?php echo htmlspecialchars($order_number, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
            <?php if (isset($reason) && $reason !== ''): ?>
            <p>Reason: <?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
        </div>

        <p>Please update your payment details and try again.</p>

        <?php if (isset($retry_url)): ?>
        <a href="<?php echo htmlspecialchars($retry_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn">Retry Payment</a>
        <?php endif; ?>
    </div>
    <div class="footer">
        <p>If you continue to experience issues, please contact our support team.</p>
    </div>
</div>
</body>
</html>
