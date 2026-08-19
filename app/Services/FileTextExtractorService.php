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

    public function extract(string $filePath, string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'bmp', 'webp' => $this->ocrService->read($filePath)['raw_text'] ?? '',
            'docx' => $this->extractWord($filePath),
            'pdf' => $this->extractPdf($filePath),
            default => throw new \RuntimeException('Định dạng không hỗ trợ: ' . $extension),
        };
    }

    protected function extractWord(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractElementText($element) . "\n";
            }
        }

        return $text;
    }

    /**
     * Đọc chữ từ 1 phần tử Word bất kỳ, kể cả TextRun/lồng nhau
     * (getText() có thể trả về chuỗi HOẶC mảng phần tử con tuỳ loại).
     *
     * QUAN TRỌNG: Table KHÔNG có getElements() như các phần tử khác - nó có
     * cấu trúc riêng Table -> Row[] -> Cell[] -> Element[]. Nếu không xử lý
     * riêng, toàn bộ nội dung bên trong bảng sẽ bị bỏ qua ÂM THẦM (rơi vào
     * "return ''" ở cuối hàm), dẫn đến mất trắng dữ liệu nếu phiếu/form được
     * trình bày dạng bảng trong Word (rất phổ biến với mẫu đơn hành chính).
     */
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

    /**
     * Trích text từ 1 bảng Word: Table -> Row -> Cell -> Element.
     *
     * Các ô trong CÙNG 1 hàng được nối bằng 2 khoảng trắng ("  ") - khớp với
     * quy ước "[ \t]{2,}" mà các Extractor (vd AdmissionFormExtractor) đang
     * dùng để tách 2 trường nằm cạnh nhau trên cùng 1 dòng (ví dụ:
     * "Họ và tên thí sinh: Nguyễn Văn A  Nam, nữ: Nam").
     * Hết 1 hàng thì xuống dòng ("\n") để bắt đầu hàng kế tiếp.
     */
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

                $cellText = trim(implode(' ', array_filter($cellParts, fn ($p) => $p !== '')));

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

    protected function extractPdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        $text = trim($pdf->getText());

        // Có đủ chữ thật -> là PDF dạng chữ, dùng luôn, khỏi OCR
        if (mb_strlen(preg_replace('/\s+/u', '', $text)) > 30) {
            return $text;
        }

        // Rất ít/không có chữ -> là PDF ảnh scan, gửi qua Python để OCR
        return $this->ocrService->readPdfScanned($path);
    }
}