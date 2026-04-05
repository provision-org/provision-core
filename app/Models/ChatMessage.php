<?php

namespace App\Models;

use App\Enums\ChatMessageRole;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_conversation_id',
        'role',
        'content',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ChatMessageRole::class,
            'content' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /**
     * Extract text from content blocks.
     */
    public function textContent(): string
    {
        return collect($this->content)
            ->filter(fn (array $block) => ($block['type'] ?? null) === 'text')
            ->pluck('text')
            ->implode("\n");
    }

    /**
     * Get non-text content blocks with signed URLs.
     *
     * @return list<array{type: string, url: string, fileName: string, mimeType: string}>
     */
    public function attachments(): array
    {
        return collect($this->content)
            ->filter(fn (array $block) => ($block['type'] ?? null) !== 'text')
            ->map(function (array $block) {
                return [
                    'type' => $block['type'],
                    'url' => URL::signedRoute('agents.chat.attachment', [
                        'conversation' => $this->chat_conversation_id,
                        'filename' => basename($block['path'] ?? ''),
                    ]),
                    'fileName' => $block['fileName'] ?? basename($block['path'] ?? ''),
                    'mimeType' => $block['mimeType'] ?? 'application/octet-stream',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Serialize content blocks with signed URLs for API responses.
     *
     * @return list<array<string, mixed>>
     */
    public function contentWithUrls(): array
    {
        return collect($this->content)
            ->filter(fn (array $block) => in_array($block['type'] ?? null, ['text', 'image', 'file']))
            ->map(function (array $block) {
                if (($block['type'] ?? null) === 'text') {
                    return $block;
                }

                $filename = basename($block['path'] ?? '');
                if (! $filename) {
                    return null;
                }

                return [
                    'type' => $block['type'],
                    'url' => URL::signedRoute('agents.chat.attachment', [
                        'conversation' => $this->chat_conversation_id,
                        'filename' => $filename,
                    ]),
                    'fileName' => $block['fileName'] ?? $filename,
                    'mimeType' => $block['mimeType'] ?? 'application/octet-stream',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
