<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Pending = 'pending';
    case Deploying = 'deploying';
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';
}
