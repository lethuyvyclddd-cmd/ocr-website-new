<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Table;
use Smalot\PdfParser\Parser as PdfParser;

class FileTextExtractorService
{
    public function __construct(protected OcrService $ocrService)
    {
    }

    /**
     * @param string|null $documentType Loại giấy tờ user đã chọn khi upload
     *        (vd: 'cccd_front', 'cccd_back', 'admission_form',
     *        'diploma_transcript'). Dùng để lọc đúng nhóm trang trong PDF
     *        nhiều trang/nhiều loại giấy tờ trộn chung.
     */
    public function extract(string $filePath, string $extension, ?string $documentType = null): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'bmp', 'webp' => $this->ocrService->read($filePath)['raw_text'] ?? '',
            'docx' => $this->extractWord($filePath),
            'pdf' => $this->extractPdf($filePath, $documentType),
            default => throw new \RuntimeException('Định dạng không hỗ trợ: ' . $extension),
        };
    }

    protected function extractWord(string $path): string
    {
        try {
            $phpWord = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Không đọc được file Word (.docx), có thể file bị hỏng: ' . $e->getMessage(),
                previous: $e
            );
        }

        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractElementText($element) . "\n";
            }
        }

        return $text;
    }

    protected function extractElementText($element): string
    {
        if ($element instanceof Table) {
            return $this->extractTableText($element);
        }

        if (method_exists($element, 'getText')) {
            $value = $element->getText();

            if (is_string($value)) {
                return $value;
            }

            if (is_array($value)) {
                $parts = [];
                foreach ($value as $child) {
                    $parts[] = $this->extractElementText($child);
                }
                return implode('', $parts);
            }
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->extractElementText($child);
            }
            return implode('', $parts);
        }

        return '';
    }

    protected function extractTableText(Table $table): string
    {
        $rowsText = [];

        foreach ($table->getRows() as $row) {
            $cellsText = [];

            foreach ($row->getCells() as $cell) {
                $cellParts = [];

                foreach ($cell->getElements() as $cellElement) {
                    $cellParts[] = $this->extractElementText($cellElement);
                }

                $cellText = trim(implode(' ', array_filter($cellParts, fn($p) => $p !== '')));

                if ($cellText !== '') {
                    $cellsText[] = $cellText;
                }
            }

            if (!empty($cellsText)) {
                $rowsText[] = implode('  ', $cellsText);
            }
        }

        return implode("\n", $rowsText);
    }

    /**
     * Chuẩn hóa text để nhận diện loại giấy tờ / loại bằng.
     */
    protected function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $map = [
            'á'=>'a','à'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a',
            'ă'=>'a','ắ'=>'a','ằ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
            'â'=>'a','ấ'=>'a','ầ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
            'é'=>'e','è'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e',
            'ê'=>'e','ế'=>'e','ề'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
            'í'=>'i','ì'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
            'ó'=>'o','ò'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o',
            'ô'=>'o','ố'=>'o','ồ'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
            'ơ'=>'o','ớ'=>'o','ờ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
            'ú'=>'u','ù'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
            'ư'=>'u','ứ'=>'u','ừ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
            'ý'=>'y','ỳ'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
            'đ'=>'d'
        ];

        return strtr($text, $map);
    }

    /**
     * Phân loại NỘI DUNG của 1 trang PDF (đã OCR) thành 1 trong các nhóm:
     * 'cccd', 'phieu_dang_ky', 'bang_dai_hoc', 'bang_cao_dang',
     * 'bang_trung_cap', hoặc 'khac' (loại bỏ, không dùng cho bất kỳ field
     * nào — bảng điểm, học bạ, bằng THPT...).
     *
     * QUAN TRỌNG: thứ tự kiểm tra rất quan trọng — CCCD và Phiếu đăng ký
     * kiểm tra trước, sau đó LOẠI TRỪ TƯỜNG MINH các loại giấy tờ không
     * cần (bảng điểm, học bạ, bằng THPT) TRƯỚC KHI thử match các bucket
     * bằng ĐH/CĐ/TC — vì một số cụm như "trường tcn" xuất hiện cả trong
     * header của bảng điểm lẫn trong bằng trung cấp thật, nếu không loại
     * trừ trước sẽ bị match nhầm (đã xảy ra thực tế: bảng điểm bị coi là
     * bằng trung cấp).
     *
     * LƯU Ý: đây là lớp lọc THỨ HAI, chạy trên text VietOCR thật (chính
     * xác hơn). Python OCR service đã có 1 lớp lọc NHANH tương tự
     * (classify_page_quick(), dựa trên text thô Paddle đọc được) chạy
     * TRƯỚC để quyết định có đáng chạy VietOCR cho từng trang hay không
     * — 2 lớp lọc là ĐỘC LẬP và bổ trợ nhau: lớp Python giúp tiết kiệm
     * thời gian OCR, lớp PHP này đảm bảo phân loại cuối cùng chính xác.
     */
    protected function classifyPage(string $normalizedText): string
    {
        // ===== 1. CCCD =====
        if (
            str_contains($normalizedText, 'can cuoc cong dan') ||
            str_contains($normalizedText, 'citizen identity card') ||
            str_contains($normalizedText, 'so dinh danh ca nhan')
        ) {
            return 'cccd';
        }

        // ===== 2. Phiếu đăng ký xét tuyển / dự tuyển =====
        if (
            str_contains($normalizedText, 'phieu dang ky xet tuyen') ||
            str_contains($normalizedText, 'phieu dang ky du tuyen') ||
            (str_contains($normalizedText, 'phieu dang ky') && str_contains($normalizedText, 'tuyen'))
        ) {
            return 'phieu_dang_ky';
        }

        // ===== 3. LOẠI TRỪ TƯỜNG MINH (kiểm tra TRƯỚC 3 bucket bằng cấp) ====
        // Bằng tốt nghiệp THPT: cố tình không lấy (Nơi sinh/Dân tộc đã lấy
        // từ Phiếu ĐKXT), nhưng vẫn phải NHẬN DIỆN ra để loại, không được
        // để lọt xuống bucket bằng trung cấp/cao đẳng/đại học bên dưới.
        if (
            str_contains($normalizedText, 'bang tot nghiep trung hoc pho thong') ||
            (str_contains($normalizedText, 'trung hoc pho thong') && str_contains($normalizedText, 'bang'))
        ) {
            return 'khac';
        }

        $excludeKeywords = [
            'bang diem',            // Bảng điểm toàn khóa / bảng điểm môn học
            'ket qua hoc tap',
            'diem trung binh',      // các trang chỉ có bảng điểm chi tiết
            'hoc ba',               // Học bạ
            'giay khai sinh',
            'so ho khau',
            'ly lich',
        ];

        foreach ($excludeKeywords as $keyword) {
            if (str_contains($normalizedText, $keyword)) {
                return 'khac';
            }
        }

        // ===== 4. Bằng Đại học =====
        if (
            str_contains($normalizedText, 'bang dai hoc') ||
            str_contains($normalizedText, 'bang cu nhan') ||
            str_contains($normalizedText, 'bang ky su') ||
            str_contains($normalizedText, 'bang thac si')
        ) {
            return 'bang_dai_hoc';
        }

        // ===== 5. Bằng Cao đẳng =====
        if (str_contains($normalizedText, 'bang cao dang')) {
            return 'bang_cao_dang';
        }

        // ===== 6. Bằng Trung cấp =====
        // Bỏ hẳn keyword "truong tcn" (quá chung chung, khớp cả header
        // trường trong bảng điểm/phụ lục) — chỉ giữ cụm đặc trưng của
        // chính tờ bằng thật.
        if (
            str_contains($normalizedText, 'bang tot nghiep trung cap') ||
            str_contains($normalizedText, 'bang trung cap') ||
            str_contains($normalizedText, 'bang tot nghiep trung cai') || // OCR sai
            (str_contains($normalizedText, 'diploma') && str_contains($normalizedText, 'trung cap'))
        ) {
            return 'bang_trung_cap';
        }

        // Không khớp bucket nào -> loại bỏ.
        return 'khac';
    }

    /**
     * Phân loại toàn bộ các trang OCR được của 1 file PDF thành từng nhóm.
     *
     * @return array{
     *     phieu_dang_ky: array, cccd: array,
     *     bang_dai_hoc: array, bang_cao_dang: array, bang_trung_cap: array
     * }
     */
    protected function groupPagesByType(array $pages): array
    {
        $grouped = [
            'phieu_dang_ky' => [],
            'cccd' => [],
            'bang_dai_hoc' => [],
            'bang_cao_dang' => [],
            'bang_trung_cap' => [],
        ];

        foreach ($pages as $page) {
            $raw = $page['text'] ?? '';
            $normalized = $this->normalizeText($raw);
            $type = $this->classifyPage($normalized);

            if (isset($grouped[$type])) {
                $grouped[$type][] = $page;
            }
            // $type === 'khac' -> bỏ qua vĩnh viễn, không dùng cho field nào.
            // Các trang bị Python service skip VietOCR (page['text'] rỗng)
            // cũng sẽ tự nhiên rơi vào 'khac' ở đây vì classifyPage() trên
            // chuỗi rỗng luôn trả 'khac' — không cần xử lý riêng.
        }

        return $grouped;
    }

    protected function extractPdf(string $path, ?string $documentType = null): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($path);
            $text = trim($pdf->getText());
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Không đọc được file PDF, có thể file bị hỏng hoặc có mật khẩu: ' . $e->getMessage(),
                previous: $e
            );
        }

        // PDF có text thật (không phải scan) -> chưa có cơ chế phân trang
        // theo document_type cho trường hợp này, trả nguyên văn như cũ.
        if (mb_strlen(preg_replace('/\s+/u', '', $text)) > 30) {
            return $text;
        }

        // PDF scan -> OCR.
        // FIX: bản trước THIẾU truyền $documentType xuống readPdfScanned(),
        // khiến Python OCR service không biết cần lọc/skip trang nào, luôn
        // chạy VietOCR cho MỌI trang (mất toàn bộ lợi ích tối ưu tốc độ đã
        // thêm ở Python service). Giờ truyền đúng xuống.
        $ocrResult = $this->ocrService->readPdfScanned($path, $documentType);
        $grouped = $this->groupPagesByType($ocrResult['pages'] ?? []);

        // ===== Chọn nhóm trang theo đúng document_type user đã yêu cầu =====
        $selectedPages = match ($documentType) {
            'cccd_front', 'cccd_back' => $grouped['cccd'],
            'admission_form' => $grouped['phieu_dang_ky'],
            'diploma_transcript' => match (true) {
                !empty($grouped['bang_dai_hoc'])   => $grouped['bang_dai_hoc'],
                !empty($grouped['bang_cao_dang'])  => $grouped['bang_cao_dang'],
                !empty($grouped['bang_trung_cap']) => $grouped['bang_trung_cap'],
                default => [],
            },
            // Không rõ document_type -> fallback hành vi cũ (ưu tiên bằng)
            default => $grouped['bang_dai_hoc']
                ?: $grouped['bang_cao_dang']
                ?: $grouped['bang_trung_cap']
                ?: [],
        };

        if (!empty($selectedPages)) {
            return implode("\n\n", array_column($selectedPages, 'text'));
        }

        // QUAN TRỌNG: nếu ĐÃ biết document_type nhưng không tìm thấy trang
        // nào khớp loại đó, KHÔNG fallback về raw_text (toàn bộ PDF gộp) —
        // nếu không sẽ lặp lại lỗi cũ: nội dung bằng cấp/bảng điểm bị nhét
        // nhầm vào CccdExtractor chỉ vì user chọn document_type=cccd_front.
        // Trả rỗng để Controller phát hiện và báo lỗi rõ ràng cho user.
        if ($documentType !== null) {
            return '';
        }

        return $ocrResult['raw_text'] ?? '';
    }
}