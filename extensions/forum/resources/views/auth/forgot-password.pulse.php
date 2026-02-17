<?php

declare(strict_types=1);

/**
 * Forgot password: request reset link.
 *
 * @var string              $page_title
 * @var array<string,string> $errors
 * @var bool                $sent
 * @var string              $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors ??= [];
$sent ??= false;
?>

<div class="forum-auth-container">
    <div class="forum-auth-card">
        <h1><?= @t('forum.reset.title') ?></h1>

        <?php if ($sent): ?>
            <div class="forum-alert forum-alert--success" role="status">
                <?= @t('forum.reset.sent') ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
            <div class="forum-alert forum-alert--error" role="alert">
                <?= $e($errors['form']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$sent): ?>
            <p style="color:var(--color-text-muted);font-size:0.875rem;margin-bottom:var(--space-6)">
                <?= @t('forum.reset.description') ?>
            </p>

            <form method="post" action="/forum/forgot-password" novalidate>
                <input type="hidden" name="_csrf" value="<?= $e($__csrf_token ?? '') ?>">

                <div class="forum-form-group">
                    <label for="email" class="forum-label"><?= @t('forum.auth.email') ?></label>
                    <input type="email" id="email" name="email" class="forum-input" required autocomplete="email" autofocus aria-describedby="<?= isset($errors['email']) ? 'email-error' : '' ?>">
                    <?php if (isset($errors['email'])): ?>
                        <p class="forum-form-error" id="email-error" role="alert"><?= $e($errors['email']) ?></p>
                    <?php endif; ?>
                </div>

                <button type="submit" class="forum-btn forum-btn--primary" style="width:100%"><?= @t('forum.reset.send_link') ?></button>
            </form>
        <?php endif; ?>

        <div class="forum-auth-footer">
            <p><a href="/forum/login"><?= @t('forum.reset.back_to_sign_in') ?></a></p>
        </div>
    </div>
</div>
