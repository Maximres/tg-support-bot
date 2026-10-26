<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Что именно получил один клиент в рамках рассылки (id его сообщений в личном чате)
 *
 * @property int   $id
 * @property int   $broadcast_id
 * @property int   $bot_user_id
 * @property int   $chat_id
 * @property array $message_ids
 */
class BroadcastMessage extends Model
{
    protected $fillable = [
        'broadcast_id',
        'bot_user_id',
        'chat_id',
        'message_ids',
    ];

    protected $casts = [
        'message_ids' => 'array',
    ];

    /**
     * @return BelongsTo
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }
}
