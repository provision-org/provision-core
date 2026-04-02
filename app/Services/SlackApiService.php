<?php

namespace App\Services;

use App\Exceptions\SlackApiException;
use App\Models\SlackConfigurationToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SlackApiService
{
    /**
     * Get a valid config token, proactively refreshing if expiring within 1 hour.
     */
    public function getValidConfigToken(SlackConfigurationToken $token): string
    {
        if ($token->isExpiringSoon()) {
            $lock = Cache::lock("slack-token-rotate:{$token->id}", 30);

            if ($lock->get()) {
                try {
                    $token->refresh();

                    if ($token->isExpiringSoon()) {
                        $rotated = $this->rotateConfigToken($token->refresh_token);

                        $token->update([
                            'access_token' => $rotated['token'],
                            'refresh_token' => $rotated['refresh_token'],
                            'expires_at' => Carbon::createFromTimestamp($rotated['exp']),
                        ]);
                    }
                } finally {
                    $lock->release();
                }
            } else {
                // Another process is rotating — wait and use the refreshed token
                $lock->block(10);
                $token->refresh();
            }
        }

        return $token->access_token;
    }

    /**
     * Rotate a configuration token using the refresh token.
     *
     * @return array{token: string, refresh_token: string, exp: int}
     */
    public function rotateConfigToken(string $refreshToken): array
    {
        $response = Http::asForm()->post('https://slack.com/api/tooling.tokens.rotate', [
            'refresh_token' => $refreshToken,
        ]);

        $data = $response->json();

        if (! ($data['ok'] ?? false)) {
            throw new SlackApiException(
                'Failed to rotate Slack configuration token',
                $data['error'] ?? 'unknown_error',
            );
        }

        return [
            'token' => $data['token'],
            'refresh_token' => $data['refresh_token'],
            'exp' => $data['exp'],
        ];
    }

    /**
     * Create a Slack app using the Manifest API.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{app_id: string, credentials: array{client_id: string, client_secret: string, signing_secret: string, verification_token: string}, oauth_authorize_url: string}
     */
    public function createApp(string $configToken, array $manifest): array
    {
        $response = Http::withToken($configToken)
            ->post('https://slack.com/api/apps.manifest.create', [
                'manifest' => $manifest,
            ]);

        $data = $response->json();

        if (! ($data['ok'] ?? false)) {
            throw new SlackApiException(
                'Failed to create Slack app',
                $data['error'] ?? 'unknown_error',
            );
        }

        return [
            'app_id' => $data['app_id'],
            'credentials' => $data['credentials'],
            'oauth_authorize_url' => $data['oauth_authorize_url'],
        ];
    }

    /**
     * Delete a Slack app using the Manifest API.
     */
    public function deleteApp(string $configToken, string $appId): void
    {
        $response = Http::withToken($configToken)
            ->post('https://slack.com/api/apps.manifest.delete', [
                'app_id' => $appId,
            ]);

        $data = $response->json();

        if (! ($data['ok'] ?? false)) {
            throw new SlackApiException(
                'Failed to delete Slack app',
                $data['error'] ?? 'unknown_error',
            );
        }
    }

    /**
     * Set a bot user's profile photo.
     */
    public function setBotPhoto(string $botToken, string $imageContents): void
    {
        $response = Http::withToken($botToken)
            ->attach('image', $imageContents, 'avatar.jpg')
            ->post('https://slack.com/api/users.setPhoto');

        $data = $response->json();

        if (! ($data['ok'] ?? false)) {
            throw new SlackApiException(
                'Failed to set bot profile photo',
                $data['error'] ?? 'unknown_error',
            );
        }
    }

    /**
     * Exchange an OAuth code for access tokens.
     *
     * @return array{bot_token: string, team_id: string, bot_user_id: string}
     */
    public function exchangeOAuthCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        $data = $response->json();

        if (! ($data['ok'] ?? false)) {
            throw new SlackApiException(
                'Failed to exchange OAuth code',
                $data['error'] ?? 'unknown_error',
            );
        }

        return [
            'bot_token' => $data['access_token'],
            'team_id' => $data['team']['id'],
            'bot_user_id' => $data['bot_user_id'],
        ];
    }
}
