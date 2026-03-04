<?php

declare(strict_types=1);

/**
 * Login form.
 *
 * @var string              $page_title
 * @var array<string,string> $errors
 * @var bool                $registered
 * @var string              $email
 * @var string              $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors ??= [];
$email ??= '';
$registered ??= false;
?>

<div class="forum-auth-container">
    <div class="forum-auth-card">
        <h1><?= @t('forum.auth.sign_in') ?></h1>

        <?php if ($registered): ?>
            <div class="forum-alert forum-alert--success" role="status">
                <?= @t('forum.auth.account_created') ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
            <div class="forum-alert forum-alert--error" role="alert">
                <?= $e($errors['form']) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/forum/login" novalidate>
            <input type="hidden" name="_csrf" value="<?= $e($__csrf_token ?? '') ?>">

            <div class="forum-form-group">
                <label for="email" class="forum-label"><?= @t('forum.auth.email') ?></label>
                <input type="email" id="email" name="email" class="forum-input" value="<?= $e($email) ?>" required autocomplete="email" autofocus aria-describedby="<?= isset($errors['email']) ? 'email-error' : '' ?>">
                <?php if (isset($errors['email'])): ?>
                    <p class="forum-form-error" id="email-error" role="alert"><?= $e($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="forum-form-group">
                <label for="password" class="forum-label"><?= @t('forum.auth.password') ?></label>
                <input type="password" id="password" name="password" class="forum-input" required autocomplete="current-password" aria-describedby="<?= isset($errors['password']) ? 'password-error' : '' ?>">
                <?php if (isset($errors['password'])): ?>
                    <p class="forum-form-error" id="password-error" role="alert"><?= $e($errors['password']) ?></p>
                <?php endif; ?>
            </div>

            <button type="submit" class="forum-btn forum-btn--primary" style="width:100%"><?= @t('forum.auth.sign_in') ?></button>
        </form>

        <div class="forum-auth-footer">
            <p><a href="/forum/forgot-password"><?= @t('forum.auth.forgot_password') ?></a></p>
            <p style="margin-top:var(--space-2)"><?= @t('forum.auth.no_account') ?> <a href="/forum/register"><?= @t('forum.auth.create_one') ?></a></p>
        </div>
    </div>
</div>
