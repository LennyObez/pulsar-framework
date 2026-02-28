{{-- Status badge partial. Expects: $status (PublishingStatus value string) --}}
<?php
$__badgeClasses = match ($status ?? '') {
    'draft' => 'cms-badge cms-badge--draft',
    'in_review' => 'cms-badge cms-badge--in-review',
    'approved' => 'cms-badge cms-badge--approved',
    'scheduled' => 'cms-badge cms-badge--scheduled',
    'published' => 'cms-badge cms-badge--published',
    'archived' => 'cms-badge cms-badge--archived',
    default => 'cms-badge cms-badge--unknown',
};
$__badgeLabel = match ($status ?? '') {
    'draft' => 'Draft',
    'in_review' => 'In Review',
    'approved' => 'Approved',
    'scheduled' => 'Scheduled',
    'published' => 'Published',
    'archived' => 'Archived',
    default => 'Unknown',
};
?>
<span class="{{ $__badgeClasses }}" role="status">{{ $__badgeLabel }}</span>
