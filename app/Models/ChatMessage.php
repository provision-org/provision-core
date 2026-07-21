<?php

namespace App\Models;

use App\Enums\ChatMessageRole;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory, HasUlids;

    private const ATTACHMENT_URL_TTL_MINUTES = 15;

    private const AGENT_MEDIA_URL_TTL_MINUTES = 60;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_conversation_id',
        'role',
        'upstream_id',
        'client_message_id',
        'reply_to_message_id',
        'enqueued_at',
        'content',
        'is_internal',
        'sent_at',
        'outbound_to_agent_at',
        'delivery_status',
        'upstream_run_id',
        'delivery_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ChatMessageRole::class,
            'content' => 'array',
            'is_internal' => 'boolean',
            'sent_at' => 'datetime',
            'outbound_to_agent_at' => 'datetime',
            'enqueued_at' => 'datetime',
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
                $url = $this->attachmentUrl($block);
                if ($url === null) {
                    return null;
                }

                return [
                    'type' => $block['type'],
                    'url' => $url,
                    'fileName' => $block['fileName'] ?? basename($block['path'] ?? ''),
                    'mimeType' => $block['mimeType'] ?? 'application/octet-stream',
                ];
            })
            ->filter()
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

                $url = $this->attachmentUrl($block);
                if ($url === null) {
                    return null;
                }

                $filename = basename($block['path'] ?? (parse_url($url, PHP_URL_PATH) ?: ''));

                return [
                    'type' => $block['type'],
                    'url' => $url,
                    'fileName' => $block['fileName'] ?? $filename,
                    'mimeType' => $block['mimeType'] ?? 'application/octet-stream',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Generate a fresh, short-lived URL from durable attachment metadata.
     *
     * @param  array<string, mixed>  $block
     */
    private function attachmentUrl(array $block): ?string
    {
        $path = $block['path'] ?? null;
        $disk = $block['disk'] ?? null;

        if (is_string($path) && $path !== '' && is_string($disk) && $disk !== '') {
            try {
                return Storage::disk($disk)->temporaryUrl(
                    $path,
                    now()->addMinutes(self::AGENT_MEDIA_URL_TTL_MINUTES),
                );
            } catch (Throwable) {
                return is_string($block['url'] ?? null) && $block['url'] !== ''
                    ? $block['url']
                    : null;
            }
        }

        if (is_string($path) && $path !== '') {
            return URL::temporarySignedRoute(
                'agents.chat.attachment',
                now()->addMinutes(self::ATTACHMENT_URL_TTL_MINUTES),
                [
                    'conversation' => $this->chat_conversation_id,
                    'filename' => basename($path),
                ],
            );
        }

        return is_string($block['url'] ?? null) && $block['url'] !== ''
            ? $block['url']
            : null;
    }
}
