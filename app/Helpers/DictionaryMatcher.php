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

        if ($bestScore <= 5) {

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