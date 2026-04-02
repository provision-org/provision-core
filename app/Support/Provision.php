<?php

namespace App\Support;

use App\Models\Team;

class Provision
{
    protected static string $teamModel = Team::class;

    public static function useTeamModel(string $model): void
    {
        static::$teamModel = $model;
    }

    public static function teamModel(): string
    {
        return static::$teamModel;
    }

    public static function newTeamModel(): mixed
    {
        return new (static::$teamModel);
    }

    /**
     * Resolve a Team to the billing-aware model when the billing module is installed.
     */
    public static function resolveBillingTeam(Team $team): Team
    {
        if (static::$teamModel !== Team::class && ! $team instanceof static::$teamModel) {
            return static::$teamModel::find($team->id) ?? $team;
        }

        return $team;
    }
}
