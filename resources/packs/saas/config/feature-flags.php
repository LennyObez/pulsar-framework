<?php

declare(strict_types=1);

/**
 * Feature flag configuration.
 *
 * Controls which features are available per plan tier and allows
 * gradual feature rollout.
 */
return [
    'feature_flags' => [
        'storage' => 'database',

        'defaults' => [
            'api_access' => [
                'enabled' => true,
                'plans' => ['starter', 'professional', 'enterprise'],
            ],
            'advanced_reporting' => [
                'enabled' => true,
                'plans' => ['professional', 'enterprise'],
            ],
            'custom_integrations' => [
                'enabled' => true,
                'plans' => ['enterprise'],
            ],
            'white_label' => [
                'enabled' => true,
                'plans' => ['enterprise'],
            ],
            'priority_support' => [
                'enabled' => true,
                'plans' => ['professional', 'enterprise'],
            ],
        ],

        'rollout' => [
            'strategy' => 'percentage',
            'default_percentage' => 100,
        ],
    ],
];
