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
        set_time_limit(360);
        $response = Http::timeout(300)
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
        set_time_limit(240);
        $response = Http::timeout(180)
            ->attach('file', file_get_contents($pdfPath), basename($pdfPath))
            ->post($this->endpoint . '/ocr-pdf');

        if (! $response->successful()) {
            throw new RuntimeException('OCR PDF lỗi (' . $response->status() . '): ' . $response->body());
        }

        return $response->json()['raw_text'] ?? '';
    }
}