<?php

declare(strict_types=1);
extract(['content' => (static function (): string {
    ob_start(); ?>
    <div class="analytics-goals">
        <header class="analytics-header">
            <h2 data-t="analytics.goals.title"><?= __('analytics.goals.title') ?></h2>
            <button id="add-goal-btn" class="btn btn-primary" data-t="analytics.goals.new"><?= __('analytics.goals.new') ?></button>
        </header>

        <div id="goals-list" class="goals-list"></div>

        <dialog id="goal-dialog" class="goal-dialog">
            <form id="goal-form" class="goal-form" method="dialog">
                <h3 id="goal-form-title" data-t="analytics.goals.new"><?= __('analytics.goals.new') ?></h3>
                <input type="hidden" id="goal-id" name="id">
                <div class="form-group">
                    <label for="goal-name" data-t="analytics.goals.goal_name"><?= __('analytics.goals.goal_name') ?></label>
                    <input type="text" id="goal-name" name="name" placeholder="<?= __('analytics.goals.name_placeholder') ?>" data-t-placeholder="analytics.goals.name_placeholder" required>
                </div>
                <div class="form-group">
                    <label for="goal-type" data-t="analytics.goals.type"><?= __('analytics.goals.type') ?></label>
                    <select id="goal-type" name="goal_type">
                        <option value="page_visit" data-t="analytics.goals.page_visit"><?= __('analytics.goals.page_visit') ?></option>
                        <option value="custom_event" data-t="analytics.goals.custom_event"><?= __('analytics.goals.custom_event') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="goal-target" data-t="analytics.goals.target_value"><?= __('analytics.goals.target_value') ?></label>
                    <input type="text" id="goal-target" name="target_value" placeholder="<?= __('analytics.goals.target_placeholder') ?>" data-t-placeholder="analytics.goals.target_placeholder" required>
                    <small data-t="analytics.goals.target_hint"><?= __('analytics.goals.target_hint') ?></small>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="this.closest('dialog').close()" data-t="analytics.goals.cancel"><?= __('analytics.goals.cancel') ?></button>
                    <button type="submit" class="btn btn-primary" data-t="analytics.goals.save"><?= __('analytics.goals.save') ?></button>
                </div>
            </form>
        </dialog>
    </div>
    <?php return ob_get_clean() ?: '';
})()]);
require __DIR__ . '/layout.php';
