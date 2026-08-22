<?php
/**
 * Review requested email template.
 *
 * @var string $reviewer_name
 * @var string $title
 * @var string $author_name
 * @var string $submitted_at
 * @var string $review_url
 */

use function htmlspecialchars;

use const ENT_QUOTES;

/*
PLAIN TEXT VERSION:

Content Submitted for Review: {title}

Hello {reviewer_name},

A content item has been submitted for your review:

Title: {title}
Submitted by: {author_name}
Submitted at: {submitted_at}

Please review it at: {review_url}
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Content Review Requested</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.review-box { background: #f0f7ff; border: 1px solid #cce0ff; border-radius: 8px; padding: 16px; margin: 16px 0; }
.review-box dt { font-weight: 600; color: #333; }
.review-box dd { margin: 0 0 8px 0; color: #555; }
.btn { display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 4px; margin: 16px 0; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Content Review Requested</h1>
    </div>
    <div class="content">
        <p>Hello <?php echo htmlspecialchars($reviewer_name, ENT_QUOTES, 'UTF-8'); ?>,</p>
        <p>A content item has been submitted for your review:</p>

        <dl class="review-box">
            <dt>Title</dt>
            <dd><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Submitted by</dt>
            <dd><?php echo htmlspecialchars($author_name, ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Submitted at</dt>
            <dd><?php echo htmlspecialchars($submitted_at, ENT_QUOTES, 'UTF-8'); ?></dd>
        </dl>

        <a href="<?php echo htmlspecialchars($review_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn">Review Content</a>
    </div>
    <div class="footer">
        <p>You received this email because you are a reviewer for this content type.</p>
    </div>
</div>
</body>
</html>
