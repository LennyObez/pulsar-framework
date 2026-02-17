<?php

declare(strict_types=1);

return [
    // Navigation
    'nav.brand' => 'Pulsar Admin',
    'nav.dashboard' => 'Dashboard',
    'nav.resources' => 'Resources',
    'nav.database' => 'Database',
    'nav.activity' => 'Activity',
    'nav.search_placeholder' => 'Search resources...',
    'nav.search_label' => 'Global search',
    'nav.nav_label' => 'Admin navigation',

    // Dashboard
    'dashboard.title' => 'Dashboard',
    'dashboard.resources' => 'Resources',

    // Resource list
    'resource.create' => 'Create {resource}',
    'resource.export_csv' => 'Export CSV',
    'resource.total_records' => '{count} total records',
    'resource.actions' => 'Actions',
    'resource.view' => 'View',
    'resource.edit' => 'Edit',
    'resource.back_to_list' => 'Back to list',
    'resource.save_changes' => 'Save changes',
    'resource.select' => 'Select...',
    'resource.cancel' => 'Cancel',
    'resource.redacted' => 'Redacted field',

    // Resources index
    'resources.no_resources' => 'No resources registered',
    'resources.no_resources_hint' => 'Register data resources in your application to manage them here.',

    // Search
    'search.title' => 'Search',
    'search.placeholder' => 'Search all resources...',
    'search.submit' => 'Search',
    'search.results_found' => '{count} results found',

    // Schema
    'schema.title' => 'Database schema',
    'schema.create' => 'Create table',
    'schema.changelog' => 'Changelog',
    'schema.view' => 'View table',
    'schema.table_name' => 'Table Name',
    'schema.columns' => 'Columns',
    'schema.primary_key' => 'Primary Key',
    'schema.actions' => 'Actions',
    'schema.no_tables' => 'No tables found',
    'schema.no_tables_hint' => 'Create your first database table using the button above.',
    'schema.limited_alter' => 'Limited ALTER support: this database driver does not support DROP COLUMN',
    'schema.non_atomic' => 'Schema changes are not atomic on this driver. Changes are applied sequentially.',
    'schema.all_tables' => 'All tables',
    'schema.col_name' => 'Name',
    'schema.col_type' => 'Type',
    'schema.col_nullable' => 'Nullable',
    'schema.col_default' => 'Default',
    'schema.col_pk' => 'PK',
    'schema.drop' => 'Drop',
    'schema.drop_unsupported' => 'DROP COLUMN not supported by this driver',
    'schema.js_required' => 'JavaScript is required for schema modification actions.',
    'schema.back_to_database' => 'Database',
    'schema.export_sql' => 'Export SQL bundle',
    'schema.no_changes' => 'No schema changes recorded',
    'schema.no_changes_hint' => 'Schema changes will appear here with audit trails and evidence hashes.',
    'schema.operation' => 'Operation',
    'schema.table' => 'Table',
    'schema.reason' => 'Reason',
    'schema.evidence_hash' => 'Evidence Hash',

    // Activity
    'activity.title' => 'Activity log',
    'activity.no_activity' => 'No activity yet',
    'activity.no_activity_hint' => 'Activity is recorded when you create, edit, or delete records through the admin panel.',
    'activity.status' => 'Status',
    'activity.action' => 'Action',
    'activity.resource' => 'Resource',
    'activity.record' => 'Record',
    'activity.actor' => 'Actor',
    'activity.time' => 'Time',
    'activity.detail' => 'Detail',

    // Pagination
    'pagination.previous' => 'Previous',
    'pagination.next' => 'Next',
    'pagination.page' => 'Page {page} of {pages}',

    // General
    'skip_to_content' => 'Skip to main content',
];
