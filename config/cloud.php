<?php

return [

    'default_provider' => env('CLOUD_PROVIDER', 'docker'),

    'provider_selection_enabled' => env('ENABLE_CLOUD_PROVIDER_SELECTION', false),

    'ssh_private_key_path' => env('SSH_PRIVATE_KEY_PATH'),

    'regions' => [
        'us-east' => ['label' => 'US East', 'hetzner' => 'ash', 'digitalocean' => 'nyc1', 'linode' => 'us-east', 'aws' => 'us-east-1'],
        'us-west' => ['label' => 'US West', 'hetzner' => 'hil', 'digitalocean' => 'sfo3', 'linode' => 'us-west', 'aws' => 'us-west-2'],
        'europe' => ['label' => 'Europe', 'hetzner' => 'fsn1', 'digitalocean' => 'fra1', 'linode' => 'eu-west', 'aws' => 'eu-central-1'],
        'asia-pacific' => ['label' => 'Asia Pacific', 'hetzner' => null, 'digitalocean' => 'sgp1', 'linode' => 'ap-south', 'aws' => 'ap-southeast-1'],
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

    'aws' => [
        // Global env fallback exists for parity/testing — the product path
        // for BYO AWS is per-team credentials stored in TeamApiKey.
        'key_id' => env('AWS_CLOUD_KEY_ID'),
        'secret' => env('AWS_CLOUD_SECRET'),
        'default_region' => env('AWS_CLOUD_DEFAULT_REGION', 'us-east-1'),
        'ami' => env('AWS_CLOUD_AMI'),
        'instance_profile' => env('AWS_CLOUD_INSTANCE_PROFILE'),
        'ssh_key_name' => env('AWS_CLOUD_SSH_KEY_NAME'),
    ],

    'warm_pool' => [
        'enabled' => env('WARM_POOL_ENABLED', false),
        'target_size' => (int) env('WARM_POOL_TARGET_SIZE', 4),
    ],

];
