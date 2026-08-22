<?php

declare(strict_types=1);

/**
 * Registration form.
 *
 * @var string              $page_title
 * @var array<string,string> $errors
 * @var string              $display_name
 * @var string              $email
 * @var string              $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors ??= [];
$displayName = $display_name ?? '';
$email ??= '';
?>

<div class="forum-auth-container">
    <div class="forum-auth-card">
        <h1><?= @t('forum.auth.create_account') ?></h1>

        <?php if (isset($errors['form'])): ?>
            <div class="forum-alert forum-alert--error" role="alert">
                <?= $e($errors['form']) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/forum/register" novalidate>
            <input type="hidden" name="_csrf" value="<?= $e($__csrf_token ?? '') ?>">

            <div class="forum-form-group">
                <label for="display_name" class="forum-label"><?= @t('forum.auth.display_name') ?></label>
                <input type="text" id="display_name" name="display_name" class="forum-input" value="<?= $e($displayName) ?>" required minlength="2" maxlength="100" autocomplete="username" autofocus aria-describedby="<?= isset($errors['display_name']) ? 'name-error' : '' ?>">
                <?php if (isset($errors['display_name'])): ?>
                    <p class="forum-form-error" id="name-error" role="alert"><?= $e($errors['display_name']) ?></p>
                <?php endif; ?>
            </div>

            <div class="forum-form-group">
                <label for="email" class="forum-label"><?= @t('forum.auth.email') ?></label>
                <input type="email" id="email" name="email" class="forum-input" value="<?= $e($email) ?>" required autocomplete="email" aria-describedby="<?= isset($errors['email']) ? 'email-error' : '' ?>">
                <?php if (isset($errors['email'])): ?>
                    <p class="forum-form-error" id="email-error" role="alert"><?= $e($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="forum-form-group">
                <label for="password" class="forum-label"><?= @t('forum.auth.password') ?></label>
                <input type="password" id="password" name="password" class="forum-input" required minlength="8" autocomplete="new-password" aria-describedby="password-hint <?= isset($errors['password']) ? 'password-error' : '' ?>">
                <span class="forum-form-hint" id="password-hint"><?= @t('forum.auth.password_hint') ?></span>
                <?php if (isset($errors['password'])): ?>
                    <p class="forum-form-error" id="password-error" role="alert"><?= $e($errors['password']) ?></p>
                <?php endif; ?>
            </div>

            <div class="forum-form-group">
                <label for="password_confirm" class="forum-label"><?= @t('forum.auth.confirm_password') ?></label>
                <input type="password" id="password_confirm" name="password_confirm" class="forum-input" required autocomplete="new-password" aria-describedby="<?= isset($errors['password_confirm']) ? 'confirm-error' : '' ?>">
                <?php if (isset($errors['password_confirm'])): ?>
                    <p class="forum-form-error" id="confirm-error" role="alert"><?= $e($errors['password_confirm']) ?></p>
                <?php endif; ?>
            </div>

            <button type="submit" class="forum-btn forum-btn--primary" style="width:100%"><?= @t('forum.auth.create_account') ?></button>
        </form>

        <div class="forum-auth-footer">
            <p><?= @t('forum.auth.already_have_account') ?> <a href="/forum/login"><?= @t('forum.auth.sign_in_link') ?></a></p>
        </div>
    </div>
</div>
