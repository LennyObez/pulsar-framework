<?php

declare(strict_types=1);

/**
 * Document retention policy configuration.
 *
 * Defines retention periods and destruction rules for different
 * document categories in legal practice management.
 *
 * WARNING: These are scaffold defaults. Review and adjust retention
 * periods according to your jurisdiction's bar association rules and
 * applicable statutes of limitation.
 */
return [
    'retention' => [
        'default_period_years' => 7,

        'categories' => [
            'client_correspondence' => [
                'period_years' => 7,
                'description' => 'General client correspondence',
            ],
            'court_filings' => [
                'period_years' => 10,
                'description' => 'Court filings and pleadings',
            ],
            'contracts' => [
                'period_years' => 10,
                'description' => 'Contracts and agreements',
            ],
            'real_estate' => [
                'period_years' => 15,
                'description' => 'Real estate transaction documents',
            ],
            'tax_records' => [
                'period_years' => 7,
                'description' => 'Tax-related documents',
            ],
            'estate_planning' => [
                'period_years' => 0,
                'description' => 'Estate planning documents (permanent retention)',
            ],
            'trust_accounting' => [
                'period_years' => 7,
                'description' => 'Trust account records',
            ],
        ],

        'litigation_hold' => [
            'enabled' => true,
            'override_destruction' => true,
        ],

        'destruction' => [
            'method' => 'secure_delete',
            'require_approval' => true,
            'log_destruction' => true,
        ],
    ],
];
