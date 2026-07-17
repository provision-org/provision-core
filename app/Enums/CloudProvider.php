<?php

namespace App\Enums;

enum CloudProvider: string
{
    case Hetzner = 'hetzner';
    case DigitalOcean = 'digitalocean';
    case Linode = 'linode';
    case Docker = 'docker';
    case Aws = 'aws';

    public function label(): string
    {
        return match ($this) {
            self::Hetzner => 'Hetzner',
            self::DigitalOcean => 'DigitalOcean',
            self::Linode => 'Linode',
            self::Docker => 'Docker',
            self::Aws => 'AWS (your account)',
        };
    }

    public function defaultRegion(): string
    {
        return match ($this) {
            self::Hetzner => 'us-east',
            self::DigitalOcean => 'us-east',
            self::Linode => 'us-east',
            self::Aws => 'us-east',
            self::Docker => 'local',
        };
    }

    /**
     * Return the provider-specific region code corresponding to this
     * provider's default region group (e.g. 'nyc1' for DigitalOcean,
     * 'ash' for Hetzner, 'us-east' for Linode). Used to seed the
     * servers.region column so the stored value reflects the provider
     * the droplet will actually be created in — not the Hetzner-centric
     * 'nbg1' migration default. Fixes issue #30.
     */
    public function defaultProviderRegion(): string
    {
        if ($this === self::Docker) {
            return 'local';
        }

        $group = $this->defaultRegion();

        return config("cloud.regions.{$group}.{$this->value}")
            ?? match ($this) {
                self::Hetzner => 'ash',
                self::DigitalOcean => 'nyc1',
                self::Linode => 'us-east',
                self::Aws => 'us-east-1',
                self::Docker => 'local',
            };
    }
}
