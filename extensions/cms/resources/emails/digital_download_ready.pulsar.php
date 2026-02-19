<?php
/*
PLAIN TEXT VERSION:

Your Downloads Are Ready

Dear Customer,

Your digital purchases from order #{order_number} are ready for download.

Downloads:
{download_links}

Each download link is valid for {expiry_days} days and allows a limited number of downloads.

If you have any issues with your downloads, please contact our support team.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Downloads Are Ready</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.download-list { list-style: none; padding: 0; margin: 16px 0; }
.download-list li { padding: 12px 16px; margin: 8px 0; background: #f0f7ff; border: 1px solid #cce0ff; border-radius: 8px; }
.download-list a { color: #1a73e8; text-decoration: none; font-weight: 600; }
.download-list .meta { font-size: 12px; color: #666; margin-top: 4px; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Your Downloads Are Ready</h1>
    </div>
    <div class="content">
        <p>Dear Customer,</p>
        <p>Your digital purchases from order <strong>#<?php echo $this->escape($order_number); ?></strong> are ready for download.</p>

        <ul class="download-list">
            <?php foreach ($downloads as $download): ?>
            <li>
                <a href="<?php echo $this->escape($download['url']); ?>"><?php echo $this->escape($download['fileName']); ?></a>
                <div class="meta">
                    Downloads remaining: <?php echo $this->escape((string) $download['downloadsRemaining']); ?>
                    | Expires: <?php echo $this->escape($download['expiresAt']); ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>

        <p>Each download link is valid for <?php echo $this->escape((string) $expiry_days); ?> days and allows a limited number of downloads.</p>
    </div>
    <div class="footer">
        <p>If you have any issues with your downloads, please contact our support team.</p>
    </div>
</div>
</body>
</html>
