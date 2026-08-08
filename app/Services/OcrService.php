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

    public function read(string $imagePath): array
    {
        if (! file_exists($imagePath)) {
            throw new RuntimeException("Không tìm thấy file ảnh: {$imagePath}");
        }

        $response = Http::timeout(90)
            ->attach('image', file_get_contents($imagePath), basename($imagePath))
            ->post($this->endpoint . '/ocr');

        if (! $response->successful()) {
            throw new RuntimeException(
                'OCR service lỗi (' . $response->status() . '): ' . $response->body()
            );
        }

        return $response->json();
    }
    public function readPdfScanned(string $pdfPath): string
    {
        $response = Http::timeout(180)
            ->attach('file', file_get_contents($pdfPath), basename($pdfPath))
            ->post($this->endpoint . '/ocr-pdf');

        if (! $response->successful()) {
            throw new RuntimeException('OCR PDF lỗi (' . $response->status() . '): ' . $response->body());
        }

        return $response->json()['raw_text'] ?? '';
    }
}