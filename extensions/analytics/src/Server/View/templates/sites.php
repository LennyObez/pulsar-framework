<?php

declare(strict_types=1);
extract(['content' => <<<'HTML'
    <div class="analytics-sites">
        <header class="analytics-header">
            <h2>Tracked Sites</h2>
            <button id="add-site-btn" class="btn btn-primary">Add Site</button>
        </header>

        <div id="sites-list" class="sites-list"></div>

        <dialog id="site-dialog" class="site-dialog">
            <form id="site-form" class="site-form" method="dialog">
                <h3 id="site-form-title">Add Site</h3>
                <input type="hidden" id="site-id" name="id">
                <div class="form-group">
                    <label for="site-domain">Domain</label>
                    <input type="text" id="site-domain" name="domain" placeholder="example.com" required>
                </div>
                <div class="form-group">
                    <label for="site-name">Name</label>
                    <input type="text" id="site-name" name="name" placeholder="My Website" required>
                </div>
                <div class="form-group">
                    <label for="site-timezone">Timezone</label>
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
                    <button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </dialog>
    </div>
    HTML]);
require __DIR__ . '/layout.php';
