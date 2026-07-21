<?php

return [

    'default_gateway_port' => 18789,

    'gateway_bind' => 'loopback',

    'onboard_flags' => [
        '--non-interactive',
        '--accept-risk',
        '--install-daemon',
        '--skip-skills',
    ],

    'health_check_interval' => 5, // minutes

    'mobile_pairing' => [
        // Override when the dashboard's APP_URL is not the public HTTPS URL
        // reachable by a physical phone (for example, through a dev tunnel).
        'exchange_url' => env('MOBILE_PAIRING_EXCHANGE_URL'),
        'handoff_ttl_seconds' => (int) env('MOBILE_PAIRING_HANDOFF_TTL', 300),
    ],

    // Google Chrome stable, installed via apt in cloud-init.
    // Full desktop Chrome avoids CAPTCHA/bot detection unlike headless Chromium.
    'browser_executable_path' => env('OPENCLAW_BROWSER_PATH', '/usr/bin/google-chrome-stable'),

];
