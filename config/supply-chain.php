<?php

declare(strict_types=1);

/**
 * Supply Chain Security Configuration
 *
 * Controls VEX generation, pipeline auditing, artifact signing,
 * and dependency license compliance checking.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | License Allowlist
    |--------------------------------------------------------------------------
    |
    | SPDX license identifiers that are considered compliant for dependencies.
    | Override this to match your organization's license policy.
    |
    */
    'allowed_licenses' => [
        'MIT',
        'Apache-2.0',
        'BSD-2-Clause',
        'BSD-3-Clause',
        'ISC',
        'LGPL-2.1-only',
        'LGPL-3.0-only',
        'GPL-2.0-only',
        'GPL-3.0-only',
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Pipeline Tools
    |--------------------------------------------------------------------------
    |
    | Security tools that must be present in at least one CI/CD workflow.
    | The pipeline auditor checks for these tools and reports any missing ones.
    |
    */
    'required_pipeline_tools' => [
        'phpstan',
        'psalm',
        'composer-audit',
        'deptrac',
    ],

    /*
    |--------------------------------------------------------------------------
    | VEX Generation
    |--------------------------------------------------------------------------
    */
    'vex' => [
        'source_dir' => 'src',
        'output_path' => 'vex.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Artifact Signing
    |--------------------------------------------------------------------------
    */
    'signing' => [
        'artifact_dir' => 'dist',
        'manifest_path' => 'signatures.json',
    ],
];
