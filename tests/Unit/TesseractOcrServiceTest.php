<?php

namespace Tests\Unit;

use App\Services\TesseractOcrService;
use PHPUnit\Framework\TestCase;

class TesseractOcrServiceTest extends TestCase
{
    public function test_build_text_from_words_preserves_line_structure_for_cccd(): void
    {
        $service = new TesseractOcrService();

        $words = [
            ['text' => 'Họ', 'top' => 10, 'left' => 5, 'height' => 20],
            ['text' => 'và', 'top' => 10, 'left' => 25, 'height' => 20],
            ['text' => 'tên', 'top' => 10, 'left' => 50, 'height' => 20],
            ['text' => 'NGUYEN', 'top' => 40, 'left' => 5, 'height' => 20],
            ['text' => 'VAN', 'top' => 40, 'left' => 60, 'height' => 20],
            ['text' => 'A', 'top' => 40, 'left' => 95, 'height' => 20],
            ['text' => 'Ngày', 'top' => 70, 'left' => 5, 'height' => 20],
            ['text' => 'sinh', 'top' => 70, 'left' => 35, 'height' => 20],
            ['text' => '01/01/2000', 'top' => 70, 'left' => 80, 'height' => 20],
        ];

        $text = $service->buildTextFromWords($words);

        $this->assertStringContainsString("Họ và tên", $text);
        $this->assertStringContainsString("NGUYEN VAN A", $text);
        $this->assertStringContainsString("Ngày sinh 01/01/2000", $text);
    }

    public function test_cccd_extractor_handles_noisy_ocr_variants(): void
    {
        $extractor = new \App\Extractors\CCCDExtractor();
        $text = "CANCUOCCONG DAN\nHo va ten\nNGUYEN VAN A\nNgay sinh 01/01/2000\nGioi tinh Nam\nQue quan Ha Noi";

        $result = $extractor->extract($text, []);

        $this->assertSame('NGUYEN VAN A', $result['full_name']);
        $this->assertSame('2000-01-01', $result['birth_date']);
        $this->assertSame('Nam', $result['gender']);
    }
}
