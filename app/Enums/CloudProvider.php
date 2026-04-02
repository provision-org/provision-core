<?php

namespace App\Enums;

enum CloudProvider: string
{
    case Hetzner = 'hetzner';
    case DigitalOcean = 'digitalocean';
    case Linode = 'linode';
    case Docker = 'docker';

    public function label(): string
    {
        return match ($this) {
            self::Hetzner => 'Hetzner',
            self::DigitalOcean => 'DigitalOcean',
            self::Linode => 'Linode',
            self::Docker => 'Docker',
        };
    }

    public function defaultRegion(): string
    {
        return match ($this) {
            self::Hetzner => 'us-east',
            self::DigitalOcean => 'us-east',
            self::Linode => 'us-east',
            self::Docker => 'local',
        };
    }
}
