<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Массовая рассылка: одно сообщение из топика рассылки, отправленное всем клиентам
 *
 * @property int         $id
 * @property int         $source_chat_id
 * @property int         $source_message_id
 * @property array       $source_message_ids Id всех сообщений-источников (у альбома их несколько)
 * @property string      $kind               text | media | album
 * @property bool        $has_follow_up      К медиа отправлено ещё и отдельное текстовое сообщение (длинная подпись)
 * @property string      $status             sending | sent | deleted
 */
class Broadcast extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'source_chat_id',
        'source_message_id',
        'source_message_ids',
        'update_id',
        'kind',
        'has_follow_up',
        'status',
        'deleted_at',
    ];

    protected $casts = [
        'source_message_ids' => 'array',
        'has_follow_up' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    /**
     * @return HasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(BroadcastMessage::class);
    }

    /**
     * Рассылка по любому из сообщений-источников (для альбома — по любой его части)
     *
     * @param int $chatId
     * @param int $messageId
     *
     * @return self|null
     */
    public static function findBySource(int $chatId, int $messageId): ?self
    {
        return static::where('source_chat_id', $chatId)
            ->where('created_at', '>=', now()->subDays(60))
            ->latest('id')
            ->limit(500)
            ->get()
            ->first(fn (self $broadcast) => in_array($messageId, array_map('intval', $broadcast->source_message_ids), true));
    }
}
