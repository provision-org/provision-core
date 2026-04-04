<?php

namespace App\Enums;

enum GovernanceMode: string
{
    case None = 'none';
    case Standard = 'standard';
    case Strict = 'strict';
}
