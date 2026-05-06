<?php

namespace App\Concerns;

use App\Services\AnalyticsService;

trait TracksEvents
{
    protected function analytics(): AnalyticsService
    {
        return app(AnalyticsService::class);
    }
}
