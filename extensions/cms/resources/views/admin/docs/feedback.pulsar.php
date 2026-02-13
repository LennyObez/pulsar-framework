@extends('admin.layout')

@section('title', 'Documentation Feedback')

@section('content')
<div class="cms-doc-feedback">
    <header class="cms-doc-feedback__header">
        <h1 class="cms-doc-feedback__title">Documentation Feedback</h1>
        <div class="cms-doc-feedback__actions">
            <a href="/admin/cms/docs/versions" class="cms-btn cms-btn--outline">Versions</a>
            <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
        </div>
    </header>

    {{-- Filters --}}
    <div class="cms-doc-feedback__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/docs/feedback" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-version" class="cms-filter-form__label">Version</label>
                <select id="filter-version" name="version" class="cms-filter-form__select">
                    <option value="">All Versions</option>
                    @foreach ($versions ?? [] as $ver)
                        <option value="{{ $ver['slug'] }}" @if (($filters['version'] ?? '') === $ver['slug']) selected @endif>{{ $ver['label'] ?? $ver['slug'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-search" class="cms-filter-form__label">Search</label>
                <input type="text" id="filter-search" name="search" value="{{ $filters['search'] ?? '' }}" class="cms-filter-form__input" placeholder="Search by page title...">
            </div>

            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    {{-- Feedback summary table --}}
    <table class="cms-table" data-cms-expandable-table>
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col" style="width: 24px;"></th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="title">Doc Page Title</th>
                <th class="cms-table__th" scope="col">Version</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="helpful_pct">Helpful %</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="unhelpful_pct">Unhelpful %</th>
                <th class="cms-table__th" scope="col">Total Votes</th>
                <th class="cms-table__th" scope="col">Recent Comments</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($pages))
                <tr>
                    <td colspan="7" class="cms-table__empty">No feedback recorded yet.</td>
                </tr>
            @endif

            @foreach ($pages ?? [] as $page)
                <?php
                $__totalVotes = ($page['helpful_count'] ?? 0) + ($page['unhelpful_count'] ?? 0);
                $__helpfulPct = $__totalVotes > 0
                    ? round(($page['helpful_count'] ?? 0) / $__totalVotes * 100, 1)
                    : 0;
                $__unhelpfulPct = $__totalVotes > 0
                    ? round(($page['unhelpful_count'] ?? 0) / $__totalVotes * 100, 1)
                    : 0;
                $__hasFeedbackEntries = !empty($page['feedback_entries']);
                ?>
                <tr class="cms-table__row @if ($__hasFeedbackEntries) cms-table__row--expandable @endif"
                    data-cms-expand-row="{{ $page['id'] ?? '' }}">
                    <td class="cms-table__td">
                        @if ($__hasFeedbackEntries)
                            <button type="button"
                                    class="cms-doc-feedback__expand-btn"
                                    aria-expanded="false"
                                    aria-controls="feedback-{{ $page['id'] ?? '' }}"
                                    aria-label="Expand feedback for {{ $page['title'] ?? '' }}">
                                <i class="fa-solid fa-chevron-right cms-doc-feedback__expand-icon" aria-hidden="true"></i>
                            </button>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        <strong>{{ $page['title'] ?? '(Untitled)' }}</strong>
                        @if (isset($page['path']))
                            <br>
                            <code class="cms-code">{{ $page['path'] }}</code>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        <span class="cms-badge">{{ $page['version'] ?? '' }}</span>
                    </td>
                    <td class="cms-table__td">
                        <span class="@if ($__helpfulPct >= 80) cms-text--success @elseif ($__helpfulPct >= 50) cms-text--warning @else cms-text--danger @endif">
                            {{ $__helpfulPct }}%
                        </span>
                    </td>
                    <td class="cms-table__td">
                        <span class="@if ($__unhelpfulPct > 50) cms-text--danger @elseif ($__unhelpfulPct > 20) cms-text--warning @endif">
                            {{ $__unhelpfulPct }}%
                        </span>
                    </td>
                    <td class="cms-table__td">{{ $__totalVotes }}</td>
                    <td class="cms-table__td">{{ $page['comment_count'] ?? 0 }}</td>
                </tr>

                {{-- Expandable feedback entries --}}
                @if ($__hasFeedbackEntries)
                    <tr class="cms-table__row cms-table__row--expanded" id="feedback-{{ $page['id'] ?? '' }}" hidden>
                        <td colspan="7" class="cms-table__td cms-table__td--nested">
                            <div class="cms-doc-feedback__entries">
                                <h4 class="cms-doc-feedback__entries-title">Individual Feedback</h4>
                                <table class="cms-table cms-table--compact cms-table--nested">
                                    <thead class="cms-table__head">
                                        <tr>
                                            <th class="cms-table__th" scope="col">Vote</th>
                                            <th class="cms-table__th" scope="col">Comment</th>
                                            <th class="cms-table__th" scope="col">Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody class="cms-table__body">
                                        @foreach ($page['feedback_entries'] as $entry)
                                            <tr class="cms-table__row">
                                                <td class="cms-table__td">
                                                    @if ($entry['is_helpful'] ?? false)
                                                        <span class="cms-badge cms-badge--approved">
                                                            <i class="fa-solid fa-thumbs-up" aria-hidden="true"></i> Helpful
                                                        </span>
                                                    @else
                                                        <span class="cms-badge cms-badge--spam">
                                                            <i class="fa-solid fa-thumbs-down" aria-hidden="true"></i> Unhelpful
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="cms-table__td">
                                                    @if (isset($entry['comment']) && $entry['comment'] !== '' && $entry['comment'] !== null)
                                                        {{ $entry['comment'] }}
                                                    @else
                                                        <span class="cms-text--muted">(No comment)</span>
                                                    @endif
                                                </td>
                                                <td class="cms-table__td">
                                                    <time datetime="{{ $entry['created_at'] ?? '' }}">{{ $entry['created_at_human'] ?? $entry['created_at'] ?? '' }}</time>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 20,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/docs/feedback',
    ])
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.cms-doc-feedback__expand-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('aria-controls');
                var targetRow = document.getElementById(targetId);
                if (!targetRow) return;

                var isExpanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');

                var icon = btn.querySelector('.cms-doc-feedback__expand-icon');
                if (icon) {
                    icon.style.transform = isExpanded ? '' : 'rotate(90deg)';
                }

                if (isExpanded) {
                    targetRow.hidden = true;
                } else {
                    targetRow.hidden = false;
                }
            });
        });
    });
</script>
@endsection
