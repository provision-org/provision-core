<?php

namespace App\Services;

use App\Models\Agent;

class SlackManifestService
{
    /**
     * @return array<string, mixed>
     */
    public function generateManifest(Agent $agent): array
    {
        $name = $agent->name;

        return [
            'display_information' => [
                'name' => $name,
                'description' => "Provision AI Agent: {$name}",
            ],
            'features' => [
                'app_home' => [
                    'home_tab_enabled' => false,
                    'messages_tab_enabled' => true,
                    'messages_tab_read_only_enabled' => false,
                ],
                'bot_user' => [
                    'display_name' => $name,
                    'always_online' => true,
                ],
            ],
            'oauth_config' => [
                'redirect_urls' => [
                    route('slack.oauth.callback'),
                ],
                'scopes' => [
                    'bot' => [
                        'app_mentions:read',
                        'channels:history',
                        'channels:read',
                        'chat:write',
                        'files:read',
                        'files:write',
                        'im:history',
                        'im:read',
                        'im:write',
                        'users:read',
                    ],
                ],
            ],
            'settings' => [
                'event_subscriptions' => [
                    'bot_events' => [
                        'app_mention',
                        'message.channels',
                        'message.im',
                    ],
                ],
                'interactivity' => [
                    'is_enabled' => false,
                ],
                'org_deploy_enabled' => false,
                'socket_mode_enabled' => true,
                'token_rotation_enabled' => false,
            ],
        ];
    }
}
