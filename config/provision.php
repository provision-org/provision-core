<?php

return [
    'enable_multiple_harness' => env('ENABLE_MULTIPLE_HARNESS', false),

    /*
     * Task agents (the autonomous "workforce" mode) and the governance views
     * that go with them — Task Board, Goals, Approvals, Audit Log — are gated
     * behind this flag while the workflow is still being designed. When off,
     * agent creation only offers the Chat agent type and the governance routes
     * are not registered. Flip TASK_AGENTS_ENABLED=true to restore everything.
     */
    'task_agents_enabled' => env('TASK_AGENTS_ENABLED', false),

    'docker' => [
        'container' => env('PROVISION_DOCKER_CONTAINER', 'provision-agent-runtime-1'),
    ],

    'provisiond_version' => env('PROVISIOND_VERSION', '0.3.0'),

    'openclaw_version' => env('OPENCLAW_VERSION', '2026.7.1'),

    'provision_web_plugin_version' => env('PROVISION_WEB_PLUGIN_VERSION', 'latest'),
];
