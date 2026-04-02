<?php

namespace App\Enums;

enum ServerStatus: string
{
    case Provisioning = 'provisioning';
    case SetupComplete = 'setup_complete';
    case Running = 'running';
    case Stopped = 'stopped';
    case Error = 'error';
    case Destroying = 'destroying';
}
