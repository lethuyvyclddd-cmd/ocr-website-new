<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    protected $fillable = [
        'missing_documents',
        'last_name', 'first_name', 'gender', 'birth_date', 'id_number',
        'place_of_birth', 'ethnic', 'ward_name', 'province_name',
        'highschool_province_name',
        'highschool_name', 'highschool_graduation_year',
        'highschool_academic_rank',
        'highschool_conduct_rank',
        'university_province_name', 'university_name',
        'university_graduation_year', 'permanent_address', 'phone_1', 'phone_2',
        'major_name',
        'note_1', 'note_2',
        'training_type', 'diploma_number', 'diploma_registry_number',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
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

    public const EXPORT_COLUMNS = [
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
        'highschool_province_name' => 'Tên Tỉnh THPT',
        'highschool_name' => 'Tên Trường THPT',
        'highschool_graduation_year' => 'Năm Tốt Nghiệp',
        'highschool_academic_rank' => 'Học Lực THPT',
        'highschool_conduct_rank' => 'Hạnh Kiểm THPT',
        'university_province_name' => 'Tên Tỉnh Trường Đại học',
        'university_name' => 'Tên Trường Đại học',
        'university_graduation_year' => 'Năm Tốt Nghiệp Đại học',
        'permanent_address' => 'Địa Chỉ Thường trú',
        'phone_1' => 'Số Điện Thoại 1',
        'phone_2' => 'Số Điện Thoại 2',
        'major_name' => 'Tên Ngành',
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