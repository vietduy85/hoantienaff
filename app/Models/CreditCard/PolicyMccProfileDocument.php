<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * PolicyMccProfileDocument — tài liệu ngân hàng đính kèm của một hồ sơ MCC.
 *
 * File được lưu trên PRIVATE disk (`local` → `storage/app/private`), KHÔNG public.
 * Truy cập qua route tải có kiểm tra quyền; `path` không bao giờ lộ ra view công
 * khai (chỉ hiển thị `original_name`).
 *
 * @property int $id
 * @property int $policy_mcc_profile_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property string|null $extension
 * @property int $size
 * @property int|null $uploaded_by
 */
class PolicyMccProfileDocument extends CreditCardModel
{
    protected $table = 'credit_card_policy_mcc_documents';

    protected $fillable = [
        'policy_mcc_profile_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'extension',
        'size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'policy_mcc_profile_id' => 'integer',
            'size' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PolicyMccProfile::class, 'policy_mcc_profile_id');
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    }
}
