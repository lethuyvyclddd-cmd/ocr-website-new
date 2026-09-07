<?php

namespace App\Helpers;

class DictionaryMatcher
{
    public static function match(?string $text, array $dictionary): ?string
    {
        if (empty($text)) {
            return null;
        }

        $originalText = trim($text);
        $normalizedText = self::normalize($originalText);

        if ($normalizedText === '') {
            return $originalText;
        }

        /*
         * ============================================================
         * BƯỚC 1: EXACT MATCH
         * ============================================================
         *
         * OCR đọc đúng hoàn toàn -> trả về giá trị trong dictionary.
         */

        foreach ($dictionary as $item) {

            if (self::normalize($item) === $normalizedText) {
                return $item;
            }
        }


        /*
         * ============================================================
         * BƯỚC 2: CONTAINS MATCH
         * ============================================================
         *
         * Trường hợp OCR có thêm chữ như:
         *
         * "Ngành Hóa học"
         * "Chuyên ngành Công nghệ thông tin"
         *
         * nhưng vẫn chứa nguyên tên trong dictionary.
         *
         * Chỉ ưu tiên item dài nhất để tránh:
         *
         * "Luật" match trước "Luật kinh tế"
         */

        $containsMatches = [];

        foreach ($dictionary as $item) {

            $normalizedItem = self::normalize($item);

            if (
                $normalizedItem !== ''
                && str_contains($normalizedText, $normalizedItem)
            ) {
                $containsMatches[] = $item;
            }
        }

        if (!empty($containsMatches)) {

            usort(
                $containsMatches,
                function ($a, $b) {

                    return mb_strlen(
                        self::normalize($b),
                        'UTF-8'
                    )
                    <=>
                    mb_strlen(
                        self::normalize($a),
                        'UTF-8'
                    );
                }
            );

            return $containsMatches[0];
        }


        /*
         * ============================================================
         * BƯỚC 3: FUZZY MATCH
         * ============================================================
         *
         * Chỉ dùng Levenshtein khi không có exact match
         * và không có contains match.
         */

        $best = null;
        $bestScore = PHP_INT_MAX;
        $bestSimilarity = 0;

        foreach ($dictionary as $item) {

            $normalizedItem = self::normalize($item);

            if ($normalizedItem === '') {
                continue;
            }

            $itemLen = mb_strlen(
                $normalizedItem,
                'UTF-8'
            );

            $textLen = mb_strlen(
                $normalizedText,
                'UTF-8'
            );

            $maxLen = max(
                $itemLen,
                $textLen
            );

            if ($maxLen === 0) {
                continue;
            }

            $score = levenshtein(
                $normalizedItem,
                $normalizedText
            );

            $similarity = 1 - (
                $score / $maxLen
            );

            if ($similarity > $bestSimilarity) {

                $bestSimilarity = $similarity;
                $bestScore = $score;
                $best = $item;
            }
        }


        /*
         * ============================================================
         * BƯỚC 4: KIỂM TRA NGƯỠNG
         * ============================================================
         *
         * Chuỗi ngắn cần ngưỡng cao hơn vì chỉ sai vài ký tự
         * là có thể match nhầm.
         *
         * Ví dụ:
         *
         * "Luat"   -> "Luật"
         * "Hoa hoc" -> "Hóa học"
         *
         * Chuỗi dài cho phép OCR sai nhiều hơn một chút.
         */

        if ($best === null) {
            return $originalText;
        }

        $textLen = mb_strlen(
            $normalizedText,
            'UTF-8'
        );


        // Chuỗi rất ngắn
        if ($textLen <= 5) {

            return $bestSimilarity >= 0.85
                ? $best
                : $originalText;
        }


        // Chuỗi trung bình
        if ($textLen <= 10) {

            return $bestSimilarity >= 0.80
                ? $best
                : $originalText;
        }


        // Chuỗi dài
        return $bestSimilarity >= 0.70
            ? $best
            : $originalText;
    }


    /*
     * ============================================================
     * NORMALIZE
     * ============================================================
     */

    private static function normalize(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $text = mb_strtolower(
            trim($text),
            'UTF-8'
        );

        /*
         * Chuyển tiếng Việt có dấu -> không dấu.
         */

        $converted = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $text
        );

        if ($converted !== false) {
            $text = $converted;
        }


        /*
         * Chỉ giữ chữ cái, số và khoảng trắng.
         */

        $text = preg_replace(
            '/[^a-z0-9]+/i',
            ' ',
            $text
        );


        /*
         * Gộp khoảng trắng.
         */

        $text = preg_replace(
            '/\s+/',
            ' ',
            $text
        );

        return trim($text);
    }
}