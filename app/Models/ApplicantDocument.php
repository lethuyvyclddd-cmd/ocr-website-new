<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantDocument extends Model
{
    protected $fillable = [
        'applicant_id', 'document_type', 'image_path',
        'raw_text', 'parsed_data', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public const TYPES = [
        'cccd_front' => 'CCCD - Mặt trước',
        'cccd_back' => 'CCCD - Mặt sau',
        'diploma_transcript' => 'Bằng tốt nghiệp / Bảng điểm ĐH',
        'admission_form' => 'Phiếu đăng ký xét tuyển',
    ];
}