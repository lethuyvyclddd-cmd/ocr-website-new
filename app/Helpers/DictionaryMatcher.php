<?php

namespace App\Helpers;

class DictionaryMatcher
{
    public static function match(?string $text, array $dictionary): ?string
    {
        if (empty($text)) {
            return null;
        }

        $text = self::normalize($text);

        $best = null;

        $bestScore = PHP_INT_MAX;

        foreach ($dictionary as $item) {

            $score = levenshtein(

                self::normalize($item),

                $text

            );

            if ($score < $bestScore) {

                $bestScore = $score;

                $best = $item;

            }

        }

        // FIX: ngưỡng tuyệt đối "<=5" trước đây khiến chuỗi NGẮN (vd "ky su"
        // đọc nhầm từ "Kỹ sư") gần như luôn "khớp" bừa với mục ngắn nào đó
        // trong từ điển (vd "Luật") dù không liên quan gì, vì với chuỗi
        // ngắn thì khoảng cách Levenshtein tối đa vốn dĩ đã nhỏ hơn 5.
        // Đổi sang ngưỡng TƯƠNG ĐỐI theo độ dài chuỗi dài hơn giữa 2 bên,
        // đồng thời chỉ áp dụng match khi chuỗi cần so đủ dài (>= 6 ký tự)
        // để tránh việc ép match các mảnh text ngắn/vô nghĩa.
        $bestLen = $best !== null ? mb_strlen(self::normalize($best), 'UTF-8') : 0;
        $textLen = mb_strlen($text, 'UTF-8');
        $maxLen = max($bestLen, $textLen);

        if (
            $textLen >= 6
            && $maxLen > 0
            && ($bestScore / $maxLen) <= 0.3
        ) {

            return $best;

        }

        return $text;
    }

    private static function normalize(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Chuẩn UTF8 trước
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        $text = mb_strtolower($text, 'UTF-8');

        // iconv có thể lỗi nếu OCR sinh ký tự rác
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        if ($converted !== false) {
            $text = $converted;
        }

        $text = preg_replace('/[^a-z0-9 ]/i', ' ', $text);

        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}