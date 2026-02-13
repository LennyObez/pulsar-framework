<?php
/*
PLAIN TEXT VERSION:

Password Reset Requested

Hello {user_name},

A password reset was requested for your account. Click the link below to set a new password:

{reset_url}

This link will expire in {expiry_minutes} minutes.

If you did not request a password reset, please ignore this email. Your account remains secure.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Password Reset</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.btn { display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 4px; margin: 16px 0; }
.notice { background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 12px 16px; margin: 16px 0; font-size: 13px; color: #6d4c00; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Password Reset Requested</h1>
    </div>
    <div class="content">
        <p>Hello <?php echo $this->escape($user_name); ?>,</p>
        <p>A password reset was requested for your account. Click the button below to set a new password:</p>

        <a href="<?php echo $this->escape($reset_url); ?>" class="btn">Reset Password</a>

        <p class="notice">This link will expire in <?php echo $this->escape((string) $expiry_minutes); ?> minutes.</p>

        <p>If you did not request a password reset, please ignore this email. Your account remains secure.</p>
    </div>
    <div class="footer">
        <p>For security, do not forward this email to anyone.</p>
    </div>
</div>
</body>
</html>
