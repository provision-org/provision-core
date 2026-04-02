<?php

namespace App\Enums;

enum DiscordConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
