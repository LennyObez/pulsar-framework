<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Validators
    |--------------------------------------------------------------------------
    |
    | List of validator classes to run during accessibility audits.
    | All built-in validators are enabled by default.
    |
    */
    'validators' => [
        'heading_hierarchy' => true,
        'form_labels' => true,
        'alt_text' => true,
        'landmark_structure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Contrast Checking
    |--------------------------------------------------------------------------
    |
    | Configuration for design token contrast analysis.
    |
    */
    'contrast' => [
        // Minimum contrast ratio for normal text (WCAG AA)
        'aa_normal' => 4.5,

        // Minimum contrast ratio for large text (WCAG AA)
        'aa_large' => 3.0,

        // Minimum contrast ratio for normal text (WCAG AAA)
        'aaa_normal' => 7.0,

        // Minimum contrast ratio for large text (WCAG AAA)
        'aaa_large' => 4.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Report
    |--------------------------------------------------------------------------
    |
    | Settings for audit output and reporting.
    |
    */
    'report' => [
        // Default output format: 'text' or 'json'
        'format' => 'text',

        // Minimum severity to include: 'error', 'warning', or 'info'
        'min_severity' => 'warning',

        // Include manual checklist in report output
        'include_checklist' => true,

        // Include limitations disclaimer in report output
        'include_limitations' => true,
    ],
];
