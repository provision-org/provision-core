<?php

namespace App\Enums;

enum UsageSource: string
{
    case Daemon = 'daemon';
    case Channel = 'channel';
    case WebChat = 'web_chat';
}
