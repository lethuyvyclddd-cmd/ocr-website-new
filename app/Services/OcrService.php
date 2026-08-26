<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OcrService
{
    protected string $endpoint;

    public function __construct()
    {
        $this->endpoint = rtrim(config('services.ocr.url'), '/');
    }

    /**
     * OCR ảnh (jpg, png, webp...)
     */
    public function read(string $imagePath): array
    {
        if (!file_exists($imagePath)) {
            throw new RuntimeException("Không tìm thấy file ảnh: {$imagePath}");
        }

        set_time_limit(420);

        $response = Http::connectTimeout(10)
            ->timeout(300)
            ->attach(
                'image',
                file_get_contents($imagePath),
                basename($imagePath)
            )
            ->post($this->endpoint . '/ocr');

        if (!$response->successful()) {
            throw new RuntimeException(
                'OCR service lỗi (' . $response->status() . '): ' . $response->body()
            );
        }

        return $response->json();
    }

    /**
     * OCR PDF scan.
     * Trả về toàn bộ JSON gồm:
     * - raw_text
     * - pages[] (mỗi phần tử có thêm 'type' và 'vietocr_ran' - xem
     *   Python service để biết chi tiết)
     *
     * @param string|null $documentType Loại giấy tờ user đã chọn khi
     *        upload (vd: 'cccd_front', 'admission_form',
     *        'diploma_transcript'). Được gửi xuống Python OCR service để
     *        service đó CHỈ chạy VietOCR (bước chậm) cho những trang
     *        thuộc đúng loại giấy tờ cần lấy, bỏ qua các trang khác
     *        (bảng điểm, học bạ, bằng THPT...) trong PDF nhiều trang mà
     *        sinh viên gộp chung khi nộp hồ sơ — giúp giảm đáng kể thời
     *        gian OCR so với xử lý toàn bộ mọi trang.
     *
     *        Nếu truyền null (không rõ loại giấy tờ), Python service sẽ
     *        tự fallback về xử lý MỌI trang như hành vi cũ (an toàn,
     *        không mất dữ liệu, chỉ là không được tối ưu tốc độ).
     */
    public function readPdfScanned(string $pdfPath, ?string $documentType = null): array
    {
        if (!file_exists($pdfPath)) {
            throw new RuntimeException("Không tìm thấy file PDF: {$pdfPath}");
        }

        // THÊM MỚI: tăng timeout đáng kể so với trước (300/360s). Dù đã
        // tối ưu bằng cách skip VietOCR cho trang không cần, vẫn nên giữ
        // timeout rộng rãi để tránh bị ngắt kết nối giữa chừng khi máy
        // đang tải nặng hoặc PDF có nhiều trang cần OCR đầy đủ (vd bằng
        // trung cấp/CĐ/ĐH có nhiều vùng chữ). Phải khớp với
        // set_time_limit()/max_execution_time bên Controller và giới hạn
        // Timeout của Apache/XAMPP - xem ghi chú ở ApplicantController.
        set_time_limit(1800);

        $response = Http::connectTimeout(10)
            ->timeout(1800)
            ->attach(
                'file',
                file_get_contents($pdfPath),
                basename($pdfPath)
            )
            ->post($this->endpoint . '/ocr-pdf', [
                // THÊM MỚI: gửi kèm document_type dưới dạng form field
                // (Python nhận qua Form(None) ở endpoint /ocr-pdf).
                'document_type' => $documentType,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'OCR PDF lỗi (' . $response->status() . '): ' . $response->body()
            );
        }

        // Quan trọng: trả về cả pages[] để FileTextExtractorService
        // chọn đúng bằng Đại học/Cao đẳng/Trung cấp/CCCD/Phiếu đăng ký.
        return $response->json();
    }
}