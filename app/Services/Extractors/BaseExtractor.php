<?php

namespace App\Services\Extractors;

abstract class BaseExtractor
{
    abstract public function extract(string $text): array;

    protected function afterLabel(string $text, array $labels, int $maxLen = 80): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '[:\s]{1,5}([^\n]{2,' . $maxLen . '})/iu';
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    protected function splitFullName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
        $parts = explode(' ', $fullName);

        if (count($parts) < 2) {
            return ['last_name' => $fullName, 'first_name' => ''];
        }

        $firstName = array_pop($parts);
        $lastName = implode(' ', $parts);

        return ['last_name' => $lastName, 'first_name' => $firstName];
    }

    protected function toDbDate(?string $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        $normalized = str_replace(['-', '.'], '/', trim($raw));

        if (! preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $normalized, $m)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }

    protected function firstDateMatch(string $text): ?string
    {
        if (preg_match('/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/', $text, $m)) {
            return $this->toDbDate($m[1]);
        }

        return null;
    }
    protected function stripDiacritics(string $str): string
    {
        // FIX: phần tử cuối của nhóm â/ầ/ấ/ậ/ẩ/ẫ/ă/ằ/ắ/ặ/ẳ/ẵ trước đây bị gõ
        // nhầm thành 'ẵapp' (thừa chữ "app" dính vào cuối). Hậu quả: str_replace
        // tìm chuỗi "ẵapp" - gần như không bao giờ xuất hiện trong text thật -
        // nên ký tự 'ẵ' đứng một mình KHÔNG BAO GIỜ được strip dấu, khiến
        // normalize() bỏ sót các từ có ẵ (vd "vẵng", "ẵm"...). Sửa lại đúng 'ẵ'.
        $vietnamese = ['à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ','ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ',
            'À','Á','Ạ','Ả','Ã','Â','Ầ','Ấ','Ậ','Ẩ','Ẫ','Ă','Ằ','Ắ','Ặ','Ẳ','Ẵ',
            'È','É','Ẹ','Ẻ','Ẽ','Ê','Ề','Ế','Ệ','Ể','Ễ','Ì','Í','Ị','Ỉ','Ĩ',
            'Ò','Ó','Ọ','Ỏ','Õ','Ô','Ồ','Ố','Ộ','Ổ','Ỗ','Ơ','Ờ','Ớ','Ợ','Ở','Ỡ',
            'Ù','Ú','Ụ','Ủ','Ũ','Ư','Ừ','Ứ','Ự','Ử','Ữ','Ỳ','Ý','Ỵ','Ỷ','Ỹ','Đ'];
        $ascii = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d',
            'A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A',
            'E','E','E','E','E','E','E','E','E','E','E','I','I','I','I','I',
            'O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O',
            'U','U','U','U','U','U','U','U','U','U','U','Y','Y','Y','Y','Y','D'];

        return str_replace($vietnamese, $ascii, $str);
    }

    protected function normalize(string $text): string
    {
        return mb_strtolower($this->stripDiacritics($text), 'UTF-8');
    }

    /**
     * Giống afterLabel(), nhưng so khớp KHÔNG phân biệt dấu tiếng Việt
     * (vì OCR hay đọc rớt dấu). Vẫn trả về đúng đoạn text GỐC (có dấu nếu OCR đọc được).
     */
    protected function afterLabelNormalized(string $text, array $labels, int $maxLen = 80): ?string
    {
        $normalized = $this->normalize($text);

        foreach ($labels as $label) {
            $normLabel = $this->normalize($label);
            $pattern = '/' . preg_quote($normLabel, '/') . '[:\s]{1,5}/';

            if (preg_match($pattern, $normalized, $m, PREG_OFFSET_CAPTURE)) {
                $charIndex = $m[0][1] + mb_strlen($m[0][0]);
                $candidate = mb_substr($text, $charIndex, $maxLen, 'UTF-8');

                if (preg_match('/^([^\n]{1,' . $maxLen . '})/u', $candidate, $mm)) {
                    return trim($mm[1]);
                }
            }
        }

        return null;
    }
    protected function splitAddress(string $address): array
    {
        $segments = array_values(array_filter(array_map(
            fn ($s) => trim(preg_replace('/\s+/', ' ', $s)),
            explode(',', $address)
        )));

        $result = [];
        if (count($segments) >= 1) {
            $result['province_name'] = end($segments);
        }
        if (count($segments) >= 2) {
            $result['ward_name'] = $segments[count($segments) - 2];
        }

        return $result;
    }
}