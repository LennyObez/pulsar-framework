<?php

declare(strict_types=1);
extract(['content' => <<<'HTML'
    <div class="analytics-goals">
        <header class="analytics-header">
            <h2>Goals</h2>
            <button id="add-goal-btn" class="btn btn-primary">Add Goal</button>
        </header>

        <div id="goals-list" class="goals-list"></div>

        <dialog id="goal-dialog" class="goal-dialog">
            <form id="goal-form" class="goal-form" method="dialog">
                <h3 id="goal-form-title">Add Goal</h3>
                <input type="hidden" id="goal-id" name="id">
                <div class="form-group">
                    <label for="goal-name">Goal Name</label>
                    <input type="text" id="goal-name" name="name" placeholder="e.g., Sign Up" required>
                </div>
                <div class="form-group">
                    <label for="goal-type">Type</label>
                    <select id="goal-type" name="goal_type">
                        <option value="page_visit">Page Visit</option>
                        <option value="custom_event">Custom Event</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="goal-target">Target Value</label>
                    <input type="text" id="goal-target" name="target_value" placeholder="/thank-you or signup" required>
                    <small>For page visits: use a URL path pattern. For events: use the event name.</small>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </dialog>
    </div>
    HTML]);
require __DIR__ . '/layout.php';
