<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    protected $fillable = [
        'admission_year', 'admission_round_code', 'admission_round_name',
        'student_code', 'student_id_number', 'missing_documents',
        'last_name', 'first_name', 'gender', 'birth_date', 'id_number',
        'place_of_birth', 'ethnic', 'ward_name', 'province_name',
        'highschool_province_code', 'highschool_province_name',
        'highschool_code', 'highschool_name', 'highschool_graduation_year',
        'priority_area', 'priority_subject', 'highschool_academic_rank',
        'highschool_conduct_rank', 'college_province_code',
        'university_province_name', 'university_code', 'university_name',
        'university_graduation_year', 'permanent_address', 'phone_1', 'phone_2',
        'major_code', 'major_name', 'average_score', 'classification',
        'gb_date', 'gb_template', 'entry_date', 'entered_by', 'admission_result',
        'tuition_fee_hk1', 'admission_fee', 'total_amount', 'note_1', 'note_2',
        'training_type', 'diploma_number', 'diploma_registry_number',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gb_date' => 'date',
            'entry_date' => 'date',
            'average_score' => 'decimal:2',
            'tuition_fee_hk1' => 'decimal:2',
            'admission_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->last_name ?? '') . ' ' . ($this->first_name ?? ''));
    }

    /**
     * Tìm hồ sơ (applicant) đã có sẵn trùng với người trong $attributes,
     * nếu có thì trả về hồ sơ đó thay vì tạo mới — để tránh tình trạng
     * upload nhiều tài liệu (CCCD, bằng tốt nghiệp, phiếu đăng ký...) của
     * CÙNG 1 người lại bị tách thành nhiều hồ sơ khác nhau.
     *
     * Thứ tự ưu tiên khớp:
     *   1) Số CCCD/CMND (id_number) — đáng tin nhất vì là duy nhất theo
     *      người, không trùng giữa 2 người khác nhau.
     *   2) Họ + Tên + Ngày sinh — dùng khi chưa có CCCD để so (vd tài liệu
     *      đầu tiên upload chỉ là bằng tốt nghiệp, chưa có ảnh CCCD).
     *
     * Nếu không khớp được với ai, tạo hồ sơ mới như bình thường.
     */
    public static function findOrCreateMatching(array $attributes): self
    {
        $applicant = null;

        if (!empty($attributes['id_number'])) {
            $applicant = static::query()
                ->where('id_number', trim((string) $attributes['id_number']))
                ->first();
        }

        if (
            !$applicant
            && !empty($attributes['last_name'])
            && !empty($attributes['first_name'])
            && !empty($attributes['birth_date'])
        ) {
            $applicant = static::query()
                ->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower(trim($attributes['last_name']), 'UTF-8')])
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower(trim($attributes['first_name']), 'UTF-8')])
                ->whereDate('birth_date', $attributes['birth_date'])
                ->first();
        }

        if ($applicant) {
            return $applicant;
        }

        return static::create($attributes);
    }

    /**
     * Gộp dữ liệu OCR mới trích xuất được vào hồ sơ hiện tại. Nguyên tắc:
     * CHỈ điền vào các field đang trống, KHÔNG ghi đè lên field đã có dữ
     * liệu — vì tài liệu upload sau có thể OCR tệ hơn (hoặc thiếu thông
     * tin) so với tài liệu upload trước, ghi đè vô điều kiện sẽ làm mất
     * dữ liệu tốt đã có (đây là lỗi khiến CCCD/tên trường THPT bị "biến
     * mất" đã gặp ở các lần trước — do một bước xử lý nào đó ghi đè bằng
     * giá trị rỗng thay vì bỏ qua).
     */
    public function mergeExtractedData(array $newData): self
    {
        foreach ($newData as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (!in_array($key, $this->fillable, true)) {
                continue;
            }

            if (empty($this->{$key})) {
                $this->{$key} = $value;
            }
        }

        $this->save();

        return $this;
    }

    public const EXPORT_COLUMNS = [
        'admission_year' => 'Năm TS - Khóa',
        'admission_round_code' => 'Đợt TS',
        'admission_round_name' => 'Tên Đợt',
        'student_code' => 'Mã HS',
        'student_id_number' => 'MSSV',
        'missing_documents' => 'Hồ Sơ Thiếu',
        'last_name' => 'Họ',
        'first_name' => 'Tên',
        'gender' => 'Giới Tính',
        'birth_date' => 'Ngày Sinh',
        'id_number' => 'CMND/CCCD',
        'place_of_birth' => 'Nơi Sinh',
        'ethnic' => 'Dân Tộc',
        'ward_name' => 'Tên Phường',
        'province_name' => 'Tên Tỉnh',
        'highschool_province_code' => 'Mã Tỉnh THPT',
        'highschool_province_name' => 'Tên Tỉnh THPT',
        'highschool_code' => 'Mã Trường THPT',
        'highschool_name' => 'Tên Trường THPT',
        'highschool_graduation_year' => 'Năm Tốt Nghiệp',
        'priority_area' => 'Khu Vực',
        'priority_subject' => 'Đối Tượng',
        'highschool_academic_rank' => 'Học Lực THPT',
        'highschool_conduct_rank' => 'Hạnh Kiểm THPT',
        'college_province_code' => 'Mã Tỉnh Trung Cấp Cao Đẳng',
        'university_province_name' => 'Tên Tỉnh Trường Đại học',
        'university_code' => 'Mã Trường Đại học',
        'university_name' => 'Tên Trường Đại học',
        'university_graduation_year' => 'Năm Tốt Nghiệp Đại học',
        'permanent_address' => 'Địa Chỉ Thường trú',
        'phone_1' => 'Số Điện Thoại 1',
        'phone_2' => 'Số Điện Thoại 2',
        'major_code' => 'Mã Ngành Xét Tuyển',
        'major_name' => 'Tên Ngành',
        'average_score' => 'Điểm Trung Bình Xét Tuyển',
        'classification' => 'Xếp Loại',
        'gb_date' => 'Ngày GB',
        'gb_template' => 'Mẫu GB',
        'entry_date' => 'Ngày Nhập',
        'entered_by' => 'Người Nhập',
        'admission_result' => 'Kết Quả Xét Tuyển',
        'tuition_fee_hk1' => 'Học Phí HK1',
        'admission_fee' => 'Lệ Phí Xét Tuyển',
        'total_amount' => 'Tổng Cộng',
        'note_1' => 'Ghi Chú 1',
        'note_2' => 'Ghi Chú 2',
        'training_type' => 'Hình thức đào tạo',
        'diploma_number' => 'Số hiệu bằng TN',
        'diploma_registry_number' => 'Số vào sổ bằng TN',
    ];
    protected function provinceName(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn ($value) => \App\Services\ProvinceMergeMapper::toNewName($value),
        );
    }

    protected function highschoolProvinceName(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn ($value) => \App\Services\ProvinceMergeMapper::toNewName($value),
        );
    }

    protected function universityProvinceName(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn ($value) => \App\Services\ProvinceMergeMapper::toNewName($value),
        );
    }
}