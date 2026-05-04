<?php

return [
    'enable_multiple_harness' => env('ENABLE_MULTIPLE_HARNESS', false),

    'docker' => [
        'container' => env('PROVISION_DOCKER_CONTAINER', 'provision-agent-runtime-1'),
    ],

    'provisiond_version' => env('PROVISIOND_VERSION', '0.3.0'),

    'openclaw_version' => env('OPENCLAW_VERSION', '2026.5.3-1'),
];
