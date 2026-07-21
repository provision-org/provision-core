<?php

use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Telescope\Telescope;

it('hides pairing tokens from Telescope request capture in every environment', function (string $environment) {
    $originalParameters = Telescope::$hiddenRequestParameters;
    $originalResponseParameters = Telescope::$hiddenResponseParameters;
    $originalHeaders = Telescope::$hiddenRequestHeaders;

    $application = new Application(dirname(__DIR__, 3));
    $application->detectEnvironment(fn (): string => $environment);

    $provider = new class($application) extends TelescopeServiceProvider
    {
        public function applySensitiveRequestHiding(): void
        {
            $this->hideSensitiveRequestDetails();
        }
    };

    try {
        Telescope::$hiddenRequestParameters = [];
        Telescope::$hiddenResponseParameters = [];
        Telescope::$hiddenRequestHeaders = [];

        $provider->applySensitiveRequestHiding();

        expect(Telescope::$hiddenRequestParameters)
            ->toContain('_token')
            ->toContain('token')
            ->and(Telescope::$hiddenResponseParameters)
            ->toContain('pairingCode')
            ->toContain('qrSvg')
            ->toContain('setupCode');
    } finally {
        Telescope::$hiddenRequestParameters = $originalParameters;
        Telescope::$hiddenResponseParameters = $originalResponseParameters;
        Telescope::$hiddenRequestHeaders = $originalHeaders;
    }
})->with(['local', 'testing', 'production']);
