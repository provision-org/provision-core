<?php

return [

    'default_provider' => env('CLOUD_PROVIDER', 'docker'),

    'provider_selection_enabled' => env('ENABLE_CLOUD_PROVIDER_SELECTION', false),

    'ssh_private_key_path' => env('SSH_PRIVATE_KEY_PATH'),

    'regions' => [
        'us-east' => ['label' => 'US East', 'hetzner' => 'ash', 'digitalocean' => 'nyc1', 'linode' => 'us-east'],
        'us-west' => ['label' => 'US West', 'hetzner' => 'hil', 'digitalocean' => 'sfo3', 'linode' => 'us-west'],
        'europe' => ['label' => 'Europe', 'hetzner' => 'fsn1', 'digitalocean' => 'fra1', 'linode' => 'eu-west'],
        'asia-pacific' => ['label' => 'Asia Pacific', 'hetzner' => null, 'digitalocean' => 'sgp1', 'linode' => 'ap-south'],
    ],

    'hetzner' => [
        'api_token' => env('HETZNER_API_TOKEN'),
        'ssh_key_id' => env('HETZNER_SSH_KEY_ID'),
        'default_image' => 'ubuntu-24.04',
    ],

    'digitalocean' => [
        'api_token' => env('DIGITALOCEAN_API_TOKEN'),
        'ssh_key_id' => env('DIGITALOCEAN_SSH_KEY_ID'),
        'default_image' => 'ubuntu-24-04-x64',
    ],

    'linode' => [
        'api_token' => env('LINODE_API_KEY'),
        'ssh_public_key_path' => env('SSH_PUBLIC_KEY_PATH', env('SSH_PRIVATE_KEY_PATH') ? env('SSH_PRIVATE_KEY_PATH').'.pub' : null),
        'default_image' => 'linode/ubuntu24.04',
    ],

    'warm_pool' => [
        'enabled' => env('WARM_POOL_ENABLED', false),
        'target_size' => (int) env('WARM_POOL_TARGET_SIZE', 4),
    ],

];
