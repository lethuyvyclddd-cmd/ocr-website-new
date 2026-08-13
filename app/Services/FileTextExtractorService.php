<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
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
     */
    protected function extractElementText($element): string
    {
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