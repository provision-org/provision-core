<?php

use App\Enums\CloudProvider;

test('defaultProviderRegion returns DigitalOcean code', function () {
    expect(CloudProvider::DigitalOcean->defaultProviderRegion())->toBe('nyc1');
});

test('defaultProviderRegion returns Hetzner code', function () {
    expect(CloudProvider::Hetzner->defaultProviderRegion())->toBe('ash');
});

test('defaultProviderRegion returns Linode code', function () {
    expect(CloudProvider::Linode->defaultProviderRegion())->toBe('us-east');
});

test('defaultProviderRegion returns "local" for Docker', function () {
    expect(CloudProvider::Docker->defaultProviderRegion())->toBe('local');
});

test('defaultProviderRegion falls back to per-provider default when config missing', function () {
    config()->set('cloud.regions.us-east.digitalocean', null);
    config()->set('cloud.regions.us-east.hetzner', null);
    config()->set('cloud.regions.us-east.linode', null);

    expect(CloudProvider::DigitalOcean->defaultProviderRegion())->toBe('nyc1')
        ->and(CloudProvider::Hetzner->defaultProviderRegion())->toBe('ash')
        ->and(CloudProvider::Linode->defaultProviderRegion())->toBe('us-east');
});
