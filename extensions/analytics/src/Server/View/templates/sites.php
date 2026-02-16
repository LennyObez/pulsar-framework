<?php

declare(strict_types=1);
extract(['content' => (static function (): string {
    ob_start(); ?>
    <div class="analytics-sites">
        <header class="analytics-header">
            <h2 data-t="analytics.sites.title"><?= __('analytics.sites.title') ?></h2>
            <button id="add-site-btn" class="btn btn-primary" data-t="analytics.sites.add"><?= __('analytics.sites.add') ?></button>
        </header>

        <div id="sites-list" class="sites-list"></div>

        <dialog id="site-dialog" class="site-dialog">
            <form id="site-form" class="site-form" method="dialog">
                <h3 id="site-form-title" data-t="analytics.sites.add"><?= __('analytics.sites.add') ?></h3>
                <input type="hidden" id="site-id" name="id">
                <div class="form-group">
                    <label for="site-domain" data-t="analytics.sites.domain"><?= __('analytics.sites.domain') ?></label>
                    <input type="text" id="site-domain" name="domain" placeholder="example.com" required>
                </div>
                <div class="form-group">
                    <label for="site-name" data-t="analytics.sites.name"><?= __('analytics.sites.name') ?></label>
                    <input type="text" id="site-name" name="name" placeholder="<?= __('analytics.sites.name_placeholder') ?>" data-t-placeholder="analytics.sites.name_placeholder" required>
                </div>
                <div class="form-group">
                    <label for="site-timezone" data-t="analytics.sites.timezone"><?= __('analytics.sites.timezone') ?></label>
                    <select id="site-timezone" name="timezone">
                        <option value="UTC">UTC</option>
                        <option value="America/New_York">Eastern Time</option>
                        <option value="America/Chicago">Central Time</option>
                        <option value="America/Denver">Mountain Time</option>
                        <option value="America/Los_Angeles">Pacific Time</option>
                        <option value="Europe/London">London</option>
                        <option value="Europe/Paris">Paris</option>
                        <option value="Europe/Berlin">Berlin</option>
                        <option value="Asia/Tokyo">Tokyo</option>
                        <option value="Asia/Shanghai">Shanghai</option>
                        <option value="Australia/Sydney">Sydney</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="this.closest('dialog').close()" data-t="analytics.sites.cancel"><?= __('analytics.sites.cancel') ?></button>
                    <button type="submit" class="btn btn-primary" data-t="analytics.sites.save"><?= __('analytics.sites.save') ?></button>
                </div>
            </form>
        </dialog>
    </div>
    <?php return ob_get_clean() ?: '';
})()]);
require __DIR__ . '/layout.php';
