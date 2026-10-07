<?php

declare(strict_types=1);

/**
 * Versioned-documentation configuration.
 *
 * Opt-in. When enabled with at least one version, requests under
 * /docs/{version}/{slug} resolve the matching version (falling back to the
 * latest) and carry it as the `doc_version` request attribute, with the
 * remaining path in `doc_path`.
 */
return [
    'enabled' => false,

    // Newest-first is not required; the registry sorts by semantic version.
    'versions' => [
        // [
        //     'version' => '1.0',
        //     'label' => '1.0 (stable)',
        //     'base_path' => 'docs/1.0',
        //     'is_latest' => true,
        //     'is_prerelease' => false,
        // ],
    ],
];
