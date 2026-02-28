<?php
/*
PLAIN TEXT VERSION:

New Form Submission

A new form submission was received.

{field_list}

Submission ID: {submission_id}
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Form Submission</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f4f4f4; }
.container { max-width: 600px; margin: 0 auto; background: #fff; }
.header { background: #1a1a1a; color: #fff; padding: 24px; text-align: center; }
.content { padding: 24px; }
.field-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
.field-table th, .field-table td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
.field-table th { background: #f5f5f5; font-weight: 600; color: #333; }
.field-table td { color: #555; }
.footer { padding: 16px 24px; background: #f9f9f9; font-size: 12px; color: #666; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>New Form Submission</h1>
    </div>
    <div class="content">
        <p>A new form submission was received on <?php echo $this->escape($submitted_at ?? ''); ?>.</p>

        <table class="field-table">
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($fields ?? []) as $fieldName => $fieldValue): ?>
                    <tr>
                        <td><?php echo $this->escape((string) $fieldName); ?></td>
                        <td><?php echo $this->escape(is_string($fieldValue) ? $fieldValue : (string) $fieldValue); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="footer">
        <p>Submission ID: <?php echo $this->escape($submission_id ?? ''); ?></p>
    </div>
</div>
</body>
</html>
