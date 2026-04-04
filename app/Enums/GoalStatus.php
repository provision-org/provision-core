<?php

namespace App\Enums;

enum GoalStatus: string
{
    case Active = 'active';
    case Achieved = 'achieved';
    case Abandoned = 'abandoned';
    case Paused = 'paused';
}
