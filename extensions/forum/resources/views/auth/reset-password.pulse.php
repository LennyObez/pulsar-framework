<?php

declare(strict_types=1);

/**
 * Reset password: set new password with token.
 *
 * @var string              $page_title
 * @var string              $token
 * @var array<string,string> $errors
 * @var string              $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors ??= [];
$token ??= '';
?>

<div class="forum-auth-container">
    <div class="forum-auth-card">
        <h1><?= @t('forum.reset.set_new_password') ?></h1>

        <?php if (isset($errors['form'])): ?>
            <div class="forum-alert forum-alert--error" role="alert">
                <?= $e($errors['form']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['token'])): ?>
            <div class="forum-alert forum-alert--error" role="alert">
                <?= $e($errors['token']) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/forum/reset-password" novalidate>
            <input type="hidden" name="_csrf" value="<?= $e($__csrf_token ?? '') ?>">
            <input type="hidden" name="token" value="<?= $e($token) ?>">

            <div class="forum-form-group">
                <label for="password" class="forum-label"><?= @t('forum.reset.new_password') ?></label>
                <input type="password" id="password" name="password" class="forum-input" required minlength="8" autocomplete="new-password" autofocus aria-describedby="password-hint <?= isset($errors['password']) ? 'password-error' : '' ?>">
                <span class="forum-form-hint" id="password-hint"><?= @t('forum.auth.password_hint') ?></span>
                <?php if (isset($errors['password'])): ?>
                    <p class="forum-form-error" id="password-error" role="alert"><?= $e($errors['password']) ?></p>
                <?php endif; ?>
            </div>

            <div class="forum-form-group">
                <label for="password_confirm" class="forum-label"><?= @t('forum.reset.confirm_new_password') ?></label>
                <input type="password" id="password_confirm" name="password_confirm" class="forum-input" required autocomplete="new-password" aria-describedby="<?= isset($errors['password_confirm']) ? 'confirm-error' : '' ?>">
                <?php if (isset($errors['password_confirm'])): ?>
                    <p class="forum-form-error" id="confirm-error" role="alert"><?= $e($errors['password_confirm']) ?></p>
                <?php endif; ?>
            </div>

            <button type="submit" class="forum-btn forum-btn--primary" style="width:100%"><?= @t('forum.reset.submit') ?></button>
        </form>

        <div class="forum-auth-footer">
            <p><a href="/forum/login"><?= @t('forum.reset.back_to_sign_in') ?></a></p>
        </div>
    </div>
</div>
