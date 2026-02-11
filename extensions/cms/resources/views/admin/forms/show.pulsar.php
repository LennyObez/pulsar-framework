@extends('admin.layout')

@section('title', 'Submission Detail')

@section('content')
<div class="cms-submission-detail">
    <header class="cms-submission-detail__header">
        <div class="cms-submission-detail__meta">
            <h1 class="cms-submission-detail__title">Submission Detail</h1>
            @if ($submission['is_spam'] ?? false)
                <span class="cms-badge cms-badge--spam" role="status">Spam (score: {{ $submission['spam_score'] ?? 0 }})</span>
            @elseif ($submission['is_read'] ?? false)
                <span class="cms-badge cms-badge--approved" role="status">Read</span>
            @else
                <span class="cms-badge cms-badge--in-review" role="status">Unread</span>
            @endif
        </div>
        <div class="cms-submission-detail__actions">
            @can('cms.forms.manage')
                @if (!($submission['is_read'] ?? false))
                    <form method="POST" action="/admin/cms/forms/{{ $submission['id'] }}/read" class="cms-inline-form">
                        @csrf
                        <button type="submit" class="cms-btn cms-btn--success">Mark as Read</button>
                    </form>
                @endif
                @if (!($submission['is_spam'] ?? false))
                    <form method="POST" action="/admin/cms/forms/{{ $submission['id'] }}/spam" class="cms-inline-form">
                        @csrf
                        <input type="hidden" name="reason" value="Manually marked as spam">
                        <button type="submit" class="cms-btn cms-btn--danger">Mark as Spam</button>
                    </form>
                @endif
            @endcan
            <a href="/admin/cms/forms" class="cms-btn cms-btn--outline">Back to List</a>
        </div>
    </header>

    <div class="cms-submission-detail__layout">
        {{-- Form data --}}
        <article class="cms-submission-detail__body-panel">
            <div class="cms-submission-detail__content">
                <h2 class="cms-submission-detail__section-title">Submitted Data</h2>
                <table class="cms-table cms-table--compact">
                    <thead class="cms-table__head">
                        <tr>
                            <th class="cms-table__th" scope="col">Field</th>
                            <th class="cms-table__th" scope="col">Value</th>
                        </tr>
                    </thead>
                    <tbody class="cms-table__body">
                        @foreach (($submission['data'] ?? []) as $fieldName => $fieldValue)
                            <tr class="cms-table__row">
                                <td class="cms-table__td" style="font-weight:bold">{{ $fieldName }}</td>
                                <td class="cms-table__td">{{ is_string($fieldValue) ? $fieldValue : json_encode($fieldValue) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Spam info --}}
            @if ($submission['is_spam'] ?? false)
                <div class="cms-submission-detail__spam-info">
                    <h2 class="cms-submission-detail__section-title">Spam Detection</h2>
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Spam Score</dt>
                        <dd class="cms-detail-list__value">{{ $submission['spam_score'] ?? 0 }}</dd>

                        @if (isset($submission['spam_reason']))
                            <dt class="cms-detail-list__term">Reason</dt>
                            <dd class="cms-detail-list__value">{{ $submission['spam_reason'] }}</dd>
                        @endif
                    </dl>
                </div>
            @endif
        </article>

        {{-- Sidebar --}}
        <aside class="cms-submission-detail__sidebar">
            {{-- Metadata --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Metadata</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Submission ID</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ $submission['id'] ?? '' }}</code></dd>

                        <dt class="cms-detail-list__term">Content ID</dt>
                        <dd class="cms-detail-list__value">
                            <a href="/admin/cms/content/{{ $submission['content_id'] ?? '' }}">
                                <code class="cms-hash">{{ $submission['content_id'] ?? '' }}</code>
                            </a>
                        </dd>

                        <dt class="cms-detail-list__term">Form Block ID</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ $submission['form_block_id'] ?? '' }}</code></dd>

                        @if (isset($submission['tenant_id']))
                            <dt class="cms-detail-list__term">Tenant ID</dt>
                            <dd class="cms-detail-list__value"><code class="cms-hash">{{ $submission['tenant_id'] }}</code></dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Privacy-safe identifiers --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Privacy Identifiers</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">IP Hash</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($submission['ip_hash'] ?? '', 0, 16) }}...</code></dd>

                        <dt class="cms-detail-list__term">UA Hash</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($submission['user_agent_hash'] ?? '', 0, 16) }}...</code></dd>
                    </dl>
                </div>
            </div>

            {{-- Evidence --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Evidence Chain</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Evidence Hash</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($submission['evidence_hash'] ?? '', 0, 24) }}...</code></dd>

                        <dt class="cms-detail-list__term">Submitted At</dt>
                        <dd class="cms-detail-list__value">
                            <time datetime="{{ $submission['submitted_at'] ?? '' }}">{{ $submission['submitted_at'] ?? '' }}</time>
                        </dd>
                    </dl>
                </div>
            </div>
        </aside>
    </div>
</div>
@endsection
