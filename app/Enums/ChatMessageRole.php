<?php

namespace App\Enums;

enum ChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
