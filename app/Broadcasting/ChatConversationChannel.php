<?php

namespace App\Broadcasting;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ChatConversationChannel
{
    public function join(User $user, string $conversationId): bool
    {
        return ChatConversation::query()
            ->whereKey($conversationId)
            ->where('user_id', $user->id)
            ->whereHas(
                'agent.team.members',
                fn (Builder $query) => $query->whereKey($user->id),
            )
            ->exists();
    }
}
