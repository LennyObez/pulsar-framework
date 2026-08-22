@extends('admin.layout')

@section('title', 'Users')

@section('content')
<div class="cms-users">
    <header class="cms-users__header">
        <h1 class="cms-users__title">Users</h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    <div class="cms-users__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/users" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-role" class="cms-filter-form__label">Role</label>
                <select id="filter-role" name="role" class="cms-filter-form__select">
                    <option value="">All Roles</option>
                    @foreach ($roles ?? [] as $role)
                        <option value="{{ $role['slug'] ?? $role }}" @if (($filters['role'] ?? '') === ($role['slug'] ?? $role)) selected @endif>{{ $role['label'] ?? ucfirst($role) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    <table class="cms-table" data-cms-sortable-table>
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="name">Name</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="email">Email</th>
                <th class="cms-table__th" scope="col">Roles</th>
                <th class="cms-table__th" scope="col">2FA</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="last_login">Last Login</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($users))
                <tr>
                    <td colspan="6" class="cms-table__empty">No users found.</td>
                </tr>
            @endif

            @foreach ($users as $user)
                <tr class="cms-table__row" data-cms-user-id="{{ $user['id'] }}">
                    <td class="cms-table__td cms-table__td--title">{{ $user['name'] ?? '' }}</td>
                    <td class="cms-table__td">{{ $user['email'] ?? '' }}</td>
                    <td class="cms-table__td">
                        <div class="cms-tag-list cms-tag-list--inline">
                            @foreach ($user['roles'] ?? [] as $role)
                                <?php /** @var string $role */
                                $__roleBadge = match ($role) {
                                    'admin', 'administrator' => 'cms-badge cms-badge--published',
                                    'editor' => 'cms-badge cms-badge--approved',
                                    'author' => 'cms-badge cms-badge--in-review',
                                    'moderator' => 'cms-badge cms-badge--scheduled',
                                    default => 'cms-badge cms-badge--draft',
                                };
                                ?>
                                <span class="{{ $__roleBadge }}">{{ ucfirst($role) }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="cms-table__td">
                        @if ($user['two_factor_enabled'] ?? false)
                            <span class="cms-users__2fa-icon cms-users__2fa-icon--enabled" title="Two-factor authentication enabled" aria-label="2FA enabled">&#128737;</span>
                        @else
                            <span class="cms-users__2fa-icon cms-users__2fa-icon--disabled" title="Two-factor authentication not enabled" aria-label="2FA not enabled">&#128737;</span>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @if (isset($user['last_login_at']))
                            <time datetime="{{ $user['last_login_at'] }}">{{ $user['last_login_at_human'] ?? $user['last_login_at'] }}</time>
                        @else
                            <span class="cms-users__never-logged-in">Never</span>
                        @endif
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="User actions">
                            @can('cms.users.edit')
                                <a href="/admin/cms/users/{{ $user['id'] }}/edit" class="cms-btn cms-btn--sm cms-btn--outline">Edit</a>
                            @endcan

                            @if ($user['two_factor_enabled'] ?? false)
                                @can('cms.users.manage_2fa')
                                    <form method="POST" action="/admin/cms/users/{{ $user['id'] }}/reset-2fa" class="cms-inline-form" data-cms-confirm="Reset two-factor authentication for this user? They will need to re-enroll." data-cms-confirm-reason>
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning" data-cms-step-up>Reset 2FA</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 20,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/users',
    ])
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
