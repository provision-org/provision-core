<?php

namespace App\Enums;

enum SlackConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
