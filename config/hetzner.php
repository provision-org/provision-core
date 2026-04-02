<?php

return [

    'api_token' => env('HETZNER_API_TOKEN'),

    'default_server_type' => 'cpx21',

    'default_image' => 'ubuntu-24.04',

    'default_location' => 'ash',

    'ssh_key_id' => env('HETZNER_SSH_KEY_ID'),

    'ssh_private_key_path' => env('HETZNER_SSH_PRIVATE_KEY_PATH'),

    'default_volume_size' => (int) env('HETZNER_DEFAULT_VOLUME_SIZE', 10),

];
