<?php

declare(strict_types=1);

return [
    'csrf' => [
        'enabled' => true,
        'ttl' => 3600,
        'field_name' => '_csrf_token',
    ],
    'renderer' => [
        'theme' => 'default',
        'error_class' => 'form-error',
        'label_class' => 'form-label',
        'input_class' => 'form-input',
        'error_summary_class' => 'form-error-summary',
    ],
    'upload' => [
        'directory' => 'storage/uploads',
        'max_size' => 10_485_760,
        'regulated_preset' => false,
    ],
    'wizard' => [
        'ttl' => 1800,
        'storage' => 'server',
    ],
];
