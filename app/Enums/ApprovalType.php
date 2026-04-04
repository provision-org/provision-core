<?php

namespace App\Enums;

enum ApprovalType: string
{
    case HireAgent = 'hire_agent';
    case ExternalAction = 'external_action';
    case StrategyProposal = 'strategy_proposal';
}
