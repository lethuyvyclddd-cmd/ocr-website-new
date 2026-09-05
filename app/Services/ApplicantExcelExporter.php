<?php

namespace App\Services;

use App\Models\Applicant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Điền dữ liệu từ bảng `applicants` (dữ liệu đã OCR + parse) vào đúng
 * các cột tương ứng trong file Excel mẫu của trường (mau_exel_project.xlsx).
 *
 * Chỉ những cột có nguồn dữ liệu từ OCR mới được điền.
 * Các cột nghiệp vụ khác (Đợt TS, Mã HS, Học Phí, Mã Ngành...) KHÔNG
 * thuộc phạm vi của class này và sẽ được để trống.
 */
class ApplicantExcelExporter
{
    /**
     * Mapping: tên cột trong bảng applicants => cột (chữ cái) trong Excel.
     * Nếu sau này đổi vị trí cột trong file mẫu, chỉ cần sửa ở đây.
     *
     * @var array<string, string>
     */
    private array $mapping = [
        'missing_documents'           => 'F',  // Hồ Sơ Thiếu
        'last_name'                   => 'G',  // Họ
        'first_name'                  => 'H',  // Tên
        'gender'                      => 'I',  // Giới Tính
        'birth_date'                  => 'J',  // Ngày Sinh
        'id_number'                   => 'K',  // CMND/CCCD
        'place_of_birth'              => 'L',  // Nơi Sinh
        'ethnic'                      => 'M',  // Dân Tộc
        'ward_name'                   => 'N',  // Tên Phường
        'province_name'               => 'O',  // Tên Tỉnh
        'highschool_province_name'    => 'Q',  // Tên Tỉnh THPT
        'highschool_name'             => 'S',  // Tên Trường THPT
        'highschool_graduation_year'  => 'T',  // Năm Tốt Nghiệp (THPT)
        'highschool_academic_rank'    => 'W',  // Học Lực THPT
        'highschool_conduct_rank'     => 'X',  // Hạnh Kiểm THPT
        'university_province_name'    => 'Z',  // Tên Tỉnh Trường Đại học
        'university_name'             => 'AB', // Tên Trường Đại học
        'university_graduation_year'  => 'AC', // Năm Tốt Nghiệp Đại học
        'permanent_address'           => 'AD', // Địa Chỉ Thường trú
        'phone_1'                     => 'AE', // Số Điện Thoại 1
        'phone_2'                     => 'AF', // Số Điện Thoại 2
        'major_name'                  => 'AH', // Tên Ngành
        'note_1'                      => 'AS', // Ghi Chú 1
        'note_2'                      => 'AT', // Ghi Chú 2
        'training_type'               => 'AU', // Hình thức đào tạo
        'diploma_number'              => 'AV', // Số hiệu bằng TN
        'diploma_registry_number'     => 'AW', // Số vào sổ bằng TN
    ];

    /**
     * Dòng bắt đầu ghi dữ liệu (dòng 1 là header trong file mẫu).
     */
    private int $startRow = 2;

    /**
     * @param  Collection<int, Applicant>|iterable  $applicants
     */
    public function export(iterable $applicants, string $templatePath, string $outputPath): string
    {
        if (! file_exists($templatePath)) {
            throw new \RuntimeException("Không tìm thấy file mẫu tại: {$templatePath}");
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $row = $this->startRow;

        foreach ($applicants as $applicant) {
            $this->fillRow($sheet, $applicant, $row);
            $row++;
        }

        $this->save($spreadsheet, $outputPath);

        return $outputPath;
    }

    private function fillRow($sheet, Applicant $applicant, int $row): void
    {
        foreach ($this->mapping as $field => $col) {
            $value = $applicant->{$field};
            $value = $this->formatValue($field, $value);

            $sheet->setCellValue("{$col}{$row}", $value);
        }
    }

    /**
     * Chuẩn hoá giá trị trước khi ghi vào Excel.
     * Thêm rule format ở đây nếu phát sinh thêm trường cần xử lý riêng.
     */
    private function formatValue(string $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($field === 'birth_date') {
            try {
                return Carbon::parse($value)->format('d/m/Y');
            } catch (\Throwable) {
                // Nếu OCR trả về ngày sinh không hợp lệ, giữ nguyên chuỗi gốc
                // để người kiểm tra dễ phát hiện thay vì làm mất dữ liệu.
                return (string) $value;
            }
        }

        return (string) $value;
    }

    private function save(Spreadsheet $spreadsheet, string $outputPath): void
    {
        $directory = dirname($outputPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);
    }
}