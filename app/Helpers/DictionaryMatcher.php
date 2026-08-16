<?php

namespace App\Helpers;

class DictionaryMatcher
{
    /**
     * So khớp $text với từ điển $dictionary bằng khoảng cách Levenshtein.
     *
     * QUAN TRỌNG: nếu không tìm được mục nào đủ tin cậy, hàm trả về NULL
     * (KHÔNG trả về bản text đã chuẩn hoá/mất dấu). Trước đây hàm này trả
     * về bản đã chuẩn hoá khi không khớp, khiến nơi gọi vô tình dùng luôn
     * bản mất dấu đó làm giá trị hiển thị cho người dùng — đây chính là lý
     * do "Trường Đại học Sư phạm Huế" từng bị hiển thị sai/mất chữ.
     * Nơi gọi (VD DiplomaExtractor) phải tự quyết định fallback về bản gốc
     * (có dấu) khi hàm này trả NULL.
     */
    public static function match(?string $text, array $dictionary, ?int $maxDistance = null): ?string
    {
        if (empty($text)) {
            return null;
        }

        $normalizedText = self::normalize($text);

        if ($normalizedText === '') {
            return null;
        }

        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($dictionary as $item) {
            $score = levenshtein(self::normalize($item), $normalizedText);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $item;
            }

            if ($bestScore === 0) {
                break;
            }
        }

        if ($best === null) {
            return null;
        }

        // Ngưỡng thích ứng theo độ dài chuỗi thay vì một con số cố định:
        // một ngưỡng cố định (VD 5) quá CHẶT với tên trường/ngành dài (OCR
        // càng dài càng dễ sai nhiều ký tự) và quá LỎNG với tên rất ngắn
        // (dễ khớp nhầm sang một mục hoàn toàn khác).
        $len = max(mb_strlen($normalizedText), mb_strlen(self::normalize($best)));
        $threshold = $maxDistance ?? max(3, (int) round($len * 0.28));

        return $bestScore <= $threshold ? $best : null;
    }

    /**
     * Chuẩn hoá tiếng Việt để so khớp: bỏ dấu, viết thường, gom khoảng trắng.
     *
     * QUAN TRỌNG: dùng bảng thay thế thủ công (giống BaseExtractor) thay vì
     * iconv('...//TRANSLIT//IGNORE'). iconv transliteration cho tiếng Việt
     * KHÔNG đáng tin cậy trên nhiều hệ thống/bảng mã: một số ký tự có dấu
     * (đặc biệt các tổ hợp dấu phụ như ư, ơ, ạ, ọ...) bị ÂM THẦM XOÁ MẤT
     * hoàn toàn thay vì chỉ bị bỏ dấu — ví dụ "Huế" từng bị biến thành
     * "h" + "e" (mất chữ "u") chứ không phải "hue". Bảng thay thế thủ công
     * dưới đây đảm bảo mỗi ký tự có dấu luôn được ánh xạ đúng 1-1 sang ký
     * tự không dấu, không bao giờ làm rơi mất ký tự.
     */
    private static function normalize(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $text = mb_strtolower(self::stripDiacritics($text), 'UTF-8');
        $text = preg_replace('/[^a-z0-9 ]/i', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private static function stripDiacritics(string $str): string
    {
        static $vietnamese = ['à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ','ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ',
            'À','Á','Ạ','Ả','Ã','Â','Ầ','Ấ','Ậ','Ẩ','Ẫ','Ă','Ằ','Ắ','Ặ','Ẳ','Ẵ',
            'È','É','Ẹ','Ẻ','Ẽ','Ê','Ề','Ế','Ệ','Ể','Ễ','Ì','Í','Ị','Ỉ','Ĩ',
            'Ò','Ó','Ọ','Ỏ','Õ','Ô','Ồ','Ố','Ộ','Ổ','Ỗ','Ơ','Ờ','Ớ','Ợ','Ở','Ỡ',
            'Ù','Ú','Ụ','Ủ','Ũ','Ư','Ừ','Ứ','Ự','Ử','Ữ','Ỳ','Ý','Ỵ','Ỷ','Ỹ','Đ'];
        static $ascii = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d',
            'A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A',
            'E','E','E','E','E','E','E','E','E','E','E','I','I','I','I','I',
            'O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O',
            'U','U','U','U','U','U','U','U','U','U','U','Y','Y','Y','Y','Y','D'];

        return str_replace($vietnamese, $ascii, $str);
    }
}