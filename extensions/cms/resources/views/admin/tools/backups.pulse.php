@extends('cms::admin.layout')

@section('title', 'Backups')

@section('cms-content')
<div class="cms-content-list">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Backups</h1>
    </header>

    {{-- Create Backup --}}
    @can('cms.tools.backup')
        <section class="cms-card">
            <h2 class="cms-card__title">Create Backup</h2>
            <form method="POST" action="/admin/cms/tools/backups" class="cms-backup-form">
                @csrf

                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Scope</legend>

                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox" name="include_content" value="1" class="cms-form-group__checkbox" checked>
                            Content
                        </label>
                    </div>
                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox" name="include_media" value="1" class="cms-form-group__checkbox">
                            Media
                        </label>
                    </div>
                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox" name="include_taxonomies" value="1" class="cms-form-group__checkbox" checked>
                            Taxonomies
                        </label>
                    </div>
                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox" name="include_menus" value="1" class="cms-form-group__checkbox" checked>
                            Menus
                        </label>
                    </div>
                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox" name="include_settings" value="1" class="cms-form-group__checkbox" checked>
                            Settings
                        </label>
                    </div>
                </fieldset>

                <button type="submit" class="cms-btn cms-btn--primary">Create Backup</button>
            </form>
        </section>
    @endcan

    {{-- Backups List --}}
    <section class="cms-card">
        <h2 class="cms-card__title">Existing Backups</h2>
        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th" scope="col">Date</th>
                    <th class="cms-table__th" scope="col">Scope</th>
                    <th class="cms-table__th" scope="col">Size</th>
                    <th class="cms-table__th" scope="col">Hash</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($backups))
                    <tr>
                        <td colspan="5" class="cms-table__empty">No backups found. Create your first backup to get started.</td>
                    </tr>
                @endif

                @foreach ($backups ?? [] as $backup)
                    <?php /** @var array{created_at: string, created_by: string, scope: array{include_content: bool, include_media: bool, include_taxonomies: bool, include_menus: bool, include_settings: bool}, size: int, id: string, integrity: string} $backup */ ?>
                    <tr class="cms-table__row">
                        <td class="cms-table__td">
                            <time datetime="{{ $backup['created_at'] ?? '' }}">{{ $backup['created_at'] ?? '' }}</time>
                            <br><span class="cms-text--muted">by {{ $backup['created_by'] ?? '' }}</span>
                        </td>
                        <td class="cms-table__td">
                            <?php
                            $__scope = $backup['scope'] ?? [];
                    $__scopeLabels = [];
                    if ($__scope['include_content'] ?? false) {
                        $__scopeLabels[] = 'Content';
                    }
                    if ($__scope['include_media'] ?? false) {
                        $__scopeLabels[] = 'Media';
                    }
                    if ($__scope['include_taxonomies'] ?? false) {
                        $__scopeLabels[] = 'Taxonomies';
                    }
                    if ($__scope['include_menus'] ?? false) {
                        $__scopeLabels[] = 'Menus';
                    }
                    if ($__scope['include_settings'] ?? false) {
                        $__scopeLabels[] = 'Settings';
                    }
                    ?>
                            {{ implode(', ', $__scopeLabels) ?: 'None' }}
                        </td>
                        <td class="cms-table__td">
                            <?php
                    $__size = $backup['size'] ?? 0;
                    if ($__size >= 1048576) {
                        echo htmlspecialchars(number_format($__size / 1048576, 2), ENT_QUOTES, 'UTF-8') . ' MB';
                    } elseif ($__size >= 1024) {
                        echo htmlspecialchars(number_format($__size / 1024, 1), ENT_QUOTES, 'UTF-8') . ' KB';
                    } else {
                        echo htmlspecialchars((string) $__size, ENT_QUOTES, 'UTF-8') . ' B';
                    }
                    ?>
                        </td>
                        <td class="cms-table__td" title="{{ $backup['hash'] ?? '' }}">
                            <code>{{ substr($backup['hash'] ?? '', 0, 12) }}...</code>
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            <div class="cms-action-group" role="group" aria-label="Backup actions">
                                <button type="button"
                                        class="cms-btn cms-btn--sm cms-btn--warning"
                                        data-cms-backup-restore="{{ $backup['id'] }}">
                                    Restore
                                </button>
                                <button type="button"
                                        class="cms-btn cms-btn--sm cms-btn--danger"
                                        data-cms-backup-delete="{{ $backup['id'] }}">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>

{{-- Restore modal (step-up + reason) --}}
<div class="cms-modal cms-modal--restore" data-cms-restore-modal role="dialog" aria-modal="true" aria-labelledby="cms-restore-title" hidden>
    <div class="cms-modal__backdrop" data-cms-modal-close></div>
    <div class="cms-modal__dialog">
        <header class="cms-modal__header">
            <h2 class="cms-modal__title" id="cms-restore-title">Restore Backup</h2>
            <button type="button" class="cms-modal__close" data-cms-modal-close aria-label="Close">&times;</button>
        </header>
        <form method="POST" action="" data-cms-restore-form>
            @csrf
            <div class="cms-modal__body">
                <div class="cms-alert cms-alert--warning" role="alert">
                    <strong>Warning:</strong> Restoring a backup will overwrite current data within the backup scope.
                </div>
                <div class="cms-form-group">
                    <label for="restore-reason" class="cms-form-group__label">Reason (required, min 10 characters)</label>
                    <textarea id="restore-reason"
                              name="reason"
                              class="cms-form-group__textarea"
                              rows="3"
                              minlength="10"
                              required
                              aria-required="true"></textarea>
                </div>
            </div>
            <footer class="cms-modal__footer">
                <button type="button" class="cms-btn cms-btn--outline" data-cms-modal-close>Cancel</button>
                <button type="submit" class="cms-btn cms-btn--warning" data-cms-step-up>Restore</button>
            </footer>
        </form>
    </div>
</div>

@include('cms::admin._partials.confirm-destructive', [
    'actionDescription' => 'delete this backup',
    'formAction' => '',
    'formMethod' => 'DELETE',
    'csrfToken' => $csrfToken ?? '',
])

@include('cms::admin._partials.step-up-prompt')

<script>
(function () {
    // Restore modal
    var restoreModal = document.querySelector('[data-cms-restore-modal]');
    var restoreForm = restoreModal ? restoreModal.querySelector('[data-cms-restore-form]') : null;
    var restoreBtns = document.querySelectorAll('[data-cms-backup-restore]');

    for (var i = 0; i < restoreBtns.length; i++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                var backupId = btn.getAttribute('data-cms-backup-restore');
                if (restoreForm) {
                    restoreForm.action = '/admin/cms/tools/backups/' + backupId + '/restore';
                }
                if (restoreModal) restoreModal.hidden = false;
            });
        })(restoreBtns[i]);
    }

    if (restoreModal) {
        var closeBtns = restoreModal.querySelectorAll('[data-cms-modal-close]');
        for (var j = 0; j < closeBtns.length; j++) {
            closeBtns[j].addEventListener('click', function () {
                restoreModal.hidden = true;
            });
        }
    }

    // Delete via destructive modal
    var deleteBtns = document.querySelectorAll('[data-cms-backup-delete]');
    var destructiveModal = document.querySelector('[data-cms-destructive-modal]');
    var destructiveForm = destructiveModal ? destructiveModal.querySelector('[data-cms-destructive-form]') : null;

    for (var k = 0; k < deleteBtns.length; k++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                var backupId = btn.getAttribute('data-cms-backup-delete');
                if (destructiveForm) {
                    destructiveForm.action = '/admin/cms/tools/backups/' + backupId;
                }
                if (destructiveModal) destructiveModal.hidden = false;
            });
        })(deleteBtns[k]);
    }
})();
</script>
@endsection
