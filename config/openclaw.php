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

    // Google Chrome stable, installed via apt in cloud-init.
    // Full desktop Chrome avoids CAPTCHA/bot detection unlike headless Chromium.
    'browser_executable_path' => env('OPENCLAW_BROWSER_PATH', '/usr/bin/google-chrome-stable'),

];
