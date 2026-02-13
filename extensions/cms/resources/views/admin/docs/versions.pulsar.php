@extends('admin.layout')

@section('title', 'Documentation Versions')

@section('content')
<div class="cms-doc-versions">
    <header class="cms-doc-versions__header">
        <h1 class="cms-doc-versions__title">Documentation Versions</h1>
        <div class="cms-doc-versions__actions">
            <a href="/admin/cms/docs/feedback" class="cms-btn cms-btn--outline">Feedback</a>
            @can('cms.docs.manage')
                <button type="button"
                        class="cms-btn cms-btn--primary"
                        data-cms-open-dialog="add-version-dialog">
                    Add Version
                </button>
            @endcan
        </div>
    </header>

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="slug">Version Slug</th>
                <th class="cms-table__th" scope="col">Label</th>
                <th class="cms-table__th" scope="col">Framework Version</th>
                <th class="cms-table__th" scope="col">Default</th>
                <th class="cms-table__th" scope="col">Archived</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="created_at">Created At</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($versions))
                <tr>
                    <td colspan="7" class="cms-table__empty">No documentation versions found. Add your first version to get started.</td>
                </tr>
            @endif

            @foreach ($versions ?? [] as $version)
                <tr class="cms-table__row">
                    <td class="cms-table__td">
                        <code class="cms-code">{{ $version['slug'] ?? '' }}</code>
                    </td>
                    <td class="cms-table__td">
                        <strong>{{ $version['label'] ?? '' }}</strong>
                    </td>
                    <td class="cms-table__td">{{ $version['framework_version'] ?? '' }}</td>
                    <td class="cms-table__td">
                        @if ($version['is_default'] ?? false)
                            <span class="cms-badge cms-badge--approved" role="status">Default</span>
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @if ($version['is_archived'] ?? false)
                            <span class="cms-badge cms-badge--archived" role="status">Archived</span>
                        @else
                            <span class="cms-badge cms-badge--approved" role="status">Active</span>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        <time datetime="{{ $version['created_at'] ?? '' }}">{{ $version['created_at_human'] ?? $version['created_at'] ?? '' }}</time>
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Version actions">
                            @can('cms.docs.manage')
                                @if (!($version['is_default'] ?? false))
                                    <form method="POST" action="/admin/cms/docs/versions/{{ $version['id'] }}/set-default" class="cms-inline-form">
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--primary">Set as Default</button>
                                    </form>
                                @endif

                                @if ($version['is_archived'] ?? false)
                                    <form method="POST" action="/admin/cms/docs/versions/{{ $version['id'] }}/unarchive" class="cms-inline-form">
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--success">Unarchive</button>
                                    </form>
                                @else
                                    @if (!($version['is_default'] ?? false))
                                        <form method="POST" action="/admin/cms/docs/versions/{{ $version['id'] }}/archive" class="cms-inline-form" data-cms-confirm="Archive this documentation version? It will no longer be visible to users.">
                                            @csrf
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Archive</button>
                                        </form>
                                    @endif
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@can('cms.docs.manage')
    {{-- Add version modal --}}
    <dialog id="add-version-dialog" class="cms-dialog">
        <form method="POST" action="/admin/cms/docs/versions">
            @csrf
            <h2 class="cms-dialog__title">Add Documentation Version</h2>

            <div class="cms-form-group">
                <label for="version-slug" class="cms-form-group__label">Version Slug <span class="cms-form-group__required">*</span></label>
                <input type="text"
                       id="version-slug"
                       name="slug"
                       class="cms-form-group__input"
                       required
                       maxlength="50"
                       pattern="[a-z0-9\-\.]+"
                       placeholder="1.0">
                <p class="cms-form-group__help">URL-safe identifier (e.g., "1.0", "2.0-beta"). Lowercase letters, numbers, hyphens, and dots only.</p>
            </div>

            <div class="cms-form-group">
                <label for="version-label" class="cms-form-group__label">Display Label <span class="cms-form-group__required">*</span></label>
                <input type="text"
                       id="version-label"
                       name="label"
                       class="cms-form-group__input"
                       required
                       maxlength="100"
                       placeholder="Version 1.0">
                <p class="cms-form-group__help">Human-readable label shown in the version selector.</p>
            </div>

            <div class="cms-form-group">
                <label for="version-framework" class="cms-form-group__label">Framework Version</label>
                <input type="text"
                       id="version-framework"
                       name="framework_version"
                       class="cms-form-group__input"
                       maxlength="50"
                       placeholder="1.0.0">
                <p class="cms-form-group__help">The framework release this documentation corresponds to.</p>
            </div>

            <div class="cms-form-group">
                <label class="cms-toggle">
                    <input type="checkbox" name="is_default" value="1">
                    <span class="cms-toggle__slider"></span>
                    Set as default version
                </label>
            </div>

            <div class="cms-dialog__actions">
                <button type="submit" class="cms-btn cms-btn--primary">Create Version</button>
                <button type="button" class="cms-btn cms-btn--outline" data-cms-close-dialog>Cancel</button>
            </div>
        </form>
    </dialog>
@endcan

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var openButtons = document.querySelectorAll('[data-cms-open-dialog]');
        openButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dialogId = btn.getAttribute('data-cms-open-dialog');
                var dialog = document.getElementById(dialogId);
                if (dialog && typeof dialog.showModal === 'function') {
                    dialog.showModal();
                }
            });
        });

        var closeButtons = document.querySelectorAll('[data-cms-close-dialog]');
        closeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dialog = btn.closest('dialog');
                if (dialog && typeof dialog.close === 'function') {
                    dialog.close();
                }
            });
        });
    });
</script>
@endsection
