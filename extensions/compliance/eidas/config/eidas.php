<?php

declare(strict_types=1);

return [
    'signature_service' => 'hmac',
    'seal_service' => 'hmac',
    'timestamp_service' => 'local',
    'delivery_service' => 'memory',
    'default_signature_format' => 'jades',
    'tsa_name' => 'Pulsar Local TSA',
];
