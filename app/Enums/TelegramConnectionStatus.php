<?php

namespace App\Enums;

enum TelegramConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
