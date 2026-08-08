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
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText() . "\n";
                        }
                    }
                }
            }
        }

        return $text;
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