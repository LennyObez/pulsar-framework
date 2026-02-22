<?php
/*
PLAIN TEXT VERSION:

Welcome to {site_name}

Hello {user_name},

Welcome to {site_name}! Your account has been created successfully.

You can log in at: {login_url}

If you did not create this account, please disregard this email.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.btn { display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 4px; margin: 16px 0; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Welcome to <?php echo $this->escape($site_name); ?></h1>
    </div>
    <div class="content">
        <p>Hello <?php echo $this->escape($user_name); ?>,</p>
        <p>Welcome to <strong><?php echo $this->escape($site_name); ?></strong>! Your account has been created successfully.</p>

        <a href="<?php echo $this->escape($login_url); ?>" class="btn">Log In</a>

        <p>If you did not create this account, please disregard this email.</p>
    </div>
    <div class="footer">
        <p>&copy; <?php echo $this->escape($site_name); ?>. All rights reserved.</p>
    </div>
</div>
</body>
</html>
