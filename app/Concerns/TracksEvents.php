<?php

namespace App\Concerns;

use App\Services\MixpanelService;

trait TracksEvents
{
    protected function mixpanel(): MixpanelService
    {
        return app(MixpanelService::class);
    }
}
