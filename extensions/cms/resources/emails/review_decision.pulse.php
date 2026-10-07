<?php
/**
 * Review decision email template.
 *
 * @var string $decision
 * @var string $author_name
 * @var string $title
 * @var string $reviewer_name
 * @var string $comment
 * @var string $content_url
 */

use function htmlspecialchars;
use function ucfirst;

use const ENT_QUOTES;

/*
PLAIN TEXT VERSION:

Your Content Has Been {decision}: {title}

Hello {author_name},

Your content "{title}" has been {decision} by {reviewer_name}.

{comment}

View your content: {content_url}
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review Decision</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header-approved { background: #27ae60; color: #fff; padding: 24px; text-align: center; }
.header-rejected { background: #c0392b; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.decision-box { border-radius: 8px; padding: 16px; margin: 16px 0; }
.decision-approved { background: #f0fff0; border: 1px solid #c3e6c3; }
.decision-rejected { background: #fdf0ef; border: 1px solid #f5c6cb; }
.comment { background: #f9f9f9; border-left: 4px solid #ddd; padding: 12px 16px; margin: 16px 0; font-style: italic; }
.btn { display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 4px; margin: 16px 0; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header-<?php echo htmlspecialchars($decision, ENT_QUOTES, 'UTF-8'); ?>">
        <h1>Content <?php echo htmlspecialchars(ucfirst($decision), ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>
    <div class="content">
        <p>Hello <?php echo htmlspecialchars($author_name, ENT_QUOTES, 'UTF-8'); ?>,</p>

        <div class="decision-box decision-<?php echo htmlspecialchars($decision, ENT_QUOTES, 'UTF-8'); ?>">
            <p>Your content <strong>&ldquo;<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>&rdquo;</strong> has been <strong><?php echo htmlspecialchars($decision, ENT_QUOTES, 'UTF-8'); ?></strong> by <?php echo htmlspecialchars($reviewer_name, ENT_QUOTES, 'UTF-8'); ?>.</p>
        </div>

        <?php if (isset($comment) && $comment !== ''): ?>
        <div class="comment">
            <p><?php echo htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <?php endif; ?>

        <?php if (isset($content_url)): ?>
        <a href="<?php echo htmlspecialchars($content_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn">View Content</a>
        <?php endif; ?>
    </div>
    <div class="footer">
        <p>This is an automated notification from the editorial review system.</p>
    </div>
</div>
</body>
</html>
