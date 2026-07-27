<?php

namespace App\Models;

use App\Enums\SafeCodeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $code
 * @property SafeCodeType $type
 * @property int|null    $created_by_chat_id
 * @property \Carbon\Carbon $created_at
 */
class SafeCode extends Model
{
    use HasFactory;

    protected $table = 'safe_codes';

    protected $fillable = [
        'code',
        'type',
        'created_by_chat_id',
    ];

    protected $casts = [
        'type' => SafeCodeType::class,
    ];

    /**
     * Возвращает актуальное (последнее установленное) значение для данного типа
     *
     * @param SafeCodeType $type
     *
     * @return self|null
     */
    public static function current(SafeCodeType $type = SafeCodeType::SAFE): ?self
    {
        return static::where('type', $type->value)->latest('id')->first();
    }
}
