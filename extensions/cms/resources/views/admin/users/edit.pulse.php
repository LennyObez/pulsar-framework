@extends('admin.layout')

@section('title', 'Edit User: ' . ($user['name'] ?? ''))

@section('content')
<div class="cms-user-edit">
    <header class="cms-user-edit__header">
        <h1 class="cms-user-edit__title">Edit User</h1>
        <a href="/admin/cms/users" class="cms-btn cms-btn--outline">Back to Users</a>
    </header>

    {{-- User identity (read-only) --}}
    <section class="cms-user-edit__identity" aria-labelledby="identity-heading">
        <h2 class="cms-user-edit__section-title" id="identity-heading">User Details</h2>
        <dl class="cms-detail-list">
            <dt class="cms-detail-list__term">Name</dt>
            <dd class="cms-detail-list__value">{{ $user['name'] ?? '' }}</dd>

            <dt class="cms-detail-list__term">Email</dt>
            <dd class="cms-detail-list__value">{{ $user['email'] ?? '' }}</dd>
        </dl>
    </section>

    {{-- Role assignment --}}
    <section class="cms-user-edit__roles" aria-labelledby="roles-heading">
        <h2 class="cms-user-edit__section-title" id="roles-heading">Role Assignment</h2>

        <div class="cms-alert cms-alert--info" role="note">
            <p>Changing roles requires step-up authentication. You will be prompted to verify your identity before saving.</p>
        </div>

        <form method="POST" action="/admin/cms/users/{{ $user['id'] }}/roles" class="cms-form" data-cms-step-up-form>
            @csrf
            @method('PUT')

            <div class="cms-form-group">
                <label for="user-roles" class="cms-form-group__label">Assigned Roles</label>
                <select id="user-roles"
                        name="roles[]"
                        class="cms-filter-form__select"
                        multiple
                        size="{{ min(count($availableRoles ?? []), 8) }}">
                    @foreach ($availableRoles ?? [] as $role)
                        <option value="{{ $role['slug'] ?? $role }}"
                                @if (in_array($role['slug'] ?? $role, $user['roles'] ?? [], true)) selected @endif>
                            {{ $role['label'] ?? ucfirst($role['slug'] ?? $role) }}
                        </option>
                    @endforeach
                </select>
                <p class="cms-form-group__hint">Hold Ctrl (Cmd on Mac) to select multiple roles.</p>
            </div>

            @can('cms.users.edit')
                <button type="submit" class="cms-btn cms-btn--primary" data-cms-step-up>Save Roles</button>
            @endcan
        </form>
    </section>

    {{-- 2FA status --}}
    <section class="cms-user-edit__2fa" aria-labelledby="2fa-heading">
        <h2 class="cms-user-edit__section-title" id="2fa-heading">Two-Factor Authentication</h2>

        <dl class="cms-detail-list">
            <dt class="cms-detail-list__term">Status</dt>
            <dd class="cms-detail-list__value">
                @if ($user['two_factor_enabled'] ?? false)
                    <span class="cms-badge cms-badge--published">Enabled</span>
                @else
                    <span class="cms-badge cms-badge--draft">Not Enabled</span>
                @endif
            </dd>
        </dl>

        @if ($user['two_factor_enabled'] ?? false)
            @can('cms.users.manage_2fa')
                <form method="POST" action="/admin/cms/users/{{ $user['id'] }}/reset-2fa" class="cms-inline-form" data-cms-confirm="Reset two-factor authentication for this user? They will need to re-enroll." data-cms-confirm-reason>
                    @csrf
                    <button type="submit" class="cms-btn cms-btn--warning" data-cms-step-up>Reset 2FA</button>
                </form>
            @endcan
        @endif
    </section>

    {{-- Extension-contributed sections (orders, forum activity, badges, etc.) --}}
    @if (!empty($sections))
        <section class="cms-user-edit__extensions" aria-labelledby="extensions-heading">
            <h2 class="cms-user-edit__section-title" id="extensions-heading">@t('admin.users.activity')</h2>

            <div class="cms-tabs" role="tablist">
                <a href="/admin/cms/users/{{ $user['id'] }}?section=details"
                   class="cms-tabs__tab @if (($active_section ?? 'details') === 'details') cms-tabs__tab--active @endif"
                   role="tab">@t('admin.users.details')</a>

                @foreach ($sections as $section)
                    <a href="/admin/cms/users/{{ $user['id'] }}?section={{ $section->id }}"
                       class="cms-tabs__tab @if (($active_section ?? '') === $section->id) cms-tabs__tab--active @endif"
                       role="tab">
                        {{ $section->label }}
                        @if ($section->badgeCount !== null)
                            <span class="cms-badge cms-badge--sm">{{ $section->badgeCount }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            @if (($active_section ?? 'details') !== 'details' && !empty($section_html))
                <div class="cms-tabs__panel" role="tabpanel">
                    {!! $section_html !!}
                </div>
            @endif
        </section>
    @endif
</div>

@include('cms::admin._partials.confirm-modal')
@include('cms::admin._partials.step-up-prompt')
@endsection
