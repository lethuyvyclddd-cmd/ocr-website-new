<?php

namespace App\Services\Extractors;

use InvalidArgumentException;

class ExtractorFactory
{
    protected static array $map = [
        'cccd_front' => CccdExtractor::class,
        'diploma_transcript' => DiplomaExtractor::class,
        'admission_form' => AdmissionFormExtractor::class,
    ];

    /**
     * THÊM MỚI ($rawText): hệ thống hiện dùng CHUNG 1 document_type
     * 'diploma_transcript' cho MỌI loại bằng ĐH/CĐ/TC (không có mục riêng
     * trên dropdown cho bằng Học viện Phật giáo) — vì vậy KHÔNG THỂ chỉ
     * dựa vào $documentType để chọn đúng extractor giữa DiplomaExtractor
     * (Bách Khoa và các trường kỹ thuật nói chung) và BuddhistDiplomaExtractor.
     *
     * Giải pháp: truyền thêm $rawText (nội dung đã OCR/đọc được của chính
     * tài liệu đang xử lý — Controller đã có sẵn biến này TRƯỚC khi gọi
     * make(), xem ApplicantController::uploadDocument()) để tự soi nội
     * dung, phát hiện các cụm từ CHỈ xuất hiện trên bằng Phật giáo (tên
     * học viện, "Phật học"...) rồi mới quyết định.
     *
     * $rawText để nullable (mặc định null) để không phá vỡ các lệnh gọi
     * make() cũ (nếu còn sót ở nơi nào khác) chỉ truyền 1 tham số — khi đó
     * rơi về hành vi cũ (luôn dùng DiplomaExtractor cho 'diploma_transcript').
     */
    public static function make(string $documentType, ?string $rawText = null): BaseExtractor
    {
        if (
            $documentType === 'diploma_transcript'
            && $rawText !== null
            && self::isBuddhistDiploma($rawText)
        ) {
            return new BuddhistDiplomaExtractor();
        }

        if (! isset(self::$map[$documentType])) {
            throw new InvalidArgumentException("Không có extractor cho loại tài liệu: {$documentType}");
        }

        $class = self::$map[$documentType];

        return new $class();
    }

    /**
     * Nhận diện NHANH bằng Học viện Phật giáo dựa trên vài cụm từ ĐẶC
     * TRƯNG (chỉ xuất hiện trên phôi bằng Phật giáo, không trùng với bằng
     * kỹ thuật/Bách Khoa), khoan dung lỗi thiếu dấu tiếng Việt do OCR bằng
     * cách so khớp trên bản đã chuẩn hoá (viết thường + bỏ dấu) — cùng
     * cách tiếp cận với classifyPage() ở FileTextExtractorService.
     *
     * Cố tình KHÔNG dùng cụm chung chung kiểu "bang cu nhan" ở đây (dễ
     * trùng với các trường ĐH khác cũng cấp bằng cử nhân) — việc đó đã có
     * classifyPage() lo ở tầng phân loại trang PDF. Hàm này chỉ cần phân
     * biệt ĐÚNG 1 việc: trong số các trang ĐÃ được xác định là bằng
     * ĐH/CĐ/TC, trang nào là của Phật giáo.
     */
    private static function isBuddhistDiploma(string $text): bool
    {
        $normalized = self::normalize($text);

        return str_contains($normalized, 'hoc vien phat giao')
            || str_contains($normalized, 'phat hoc')
            || str_contains($normalized, 'buddhist');
    }

    /**
     * Bản rút gọn của BaseExtractor::normalize() (lowercase + bỏ dấu tiếng
     * Việt). ExtractorFactory không extend BaseExtractor nên không tái
     * dùng trực tiếp được — viết lại tối thiểu (static) chỉ để phục vụ
     * isBuddhistDiploma(), KHÔNG dùng cho mục đích khác.
     */
    private static function normalize(string $text): string
    {
        $vietnamese = [
            'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ','ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ',
        ];
        $ascii = [
            'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d',
        ];

        return str_replace($vietnamese, $ascii, mb_strtolower($text, 'UTF-8'));
    }
}