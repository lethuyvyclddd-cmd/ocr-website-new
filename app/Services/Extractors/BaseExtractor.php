<?php

namespace App\Services\Extractors;

abstract class BaseExtractor
{
    abstract public function extract(string $text): array;

    protected function afterLabel(string $text, array $labels, int $maxLen = 80): ?string
    {
        foreach ($labels as $label) {
            // [:.\s]{0,6} thay vì [:\s]{1,5}: nhiều mẫu bằng/giấy tờ dùng dòng
            // kẻ CHẤM CHẤM (....) làm khoảng trống điền tay thay vì dấu ":" hay
            // khoảng trắng, và đôi khi nhãn dính liền ngay giá trị (0 ký tự đệm).
            $pattern = '/' . preg_quote($label, '/') . '[:.\s]{0,6}([^\n]{2,' . $maxLen . '})/iu';
            if (preg_match($pattern, $text, $m)) {
                return $this->trimFillerChars($m[1]);
            }
        }

        return null;
    }

    /**
     * Bỏ dấu chấm/gạch chấm/khoảng trắng còn sót lại ở đầu-cuối giá trị bóc tách được
     * (thường là tàn dư của dòng kẻ chấm chấm dùng để điền tay trên biểu mẫu).
     */
    protected function trimFillerChars(string $value): string
    {
        return trim($value, " \t\n\r\0\x0B.:·");
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
            // [:.\s]{0,6}: chấp nhận cả dòng kẻ chấm chấm (....) hoặc nhãn dính
            // liền giá trị, giống lý do sửa ở afterLabel() bên trên.
            $pattern = '/' . preg_quote($normLabel, '/') . '[:.\s]{0,6}/';

            if (preg_match($pattern, $normalized, $m, PREG_OFFSET_CAPTURE)) {
                // QUAN TRỌNG: $m[0][1] là offset tính theo BYTE (PREG_OFFSET_CAPTURE
                // luôn trả byte offset, kể cả khi dùng flag /u), còn $text gốc cần
                // cắt bằng mb_substr() lại tính theo KÝ TỰ. Hễ có bất kỳ ký tự
                // tiếng Việt nào (chiếm 2-3 byte/ký tự) nằm TRƯỚC vị trí khớp, 2 đơn
                // vị đo này sẽ lệch nhau -> cộng thẳng byte offset với độ dài ký tự
                // như code cũ (đã sửa ở dưới) sẽ ra sai vị trí, cắt lẹm mất vài ký tự
                // đầu của giá trị thật. Phải đổi byte offset sang ký tự offset trước
                // bằng mb_strlen(substr(...)) rồi mới cộng dồn.
                $charOffsetOfMatchStart = mb_strlen(substr($normalized, 0, $m[0][1]), 'UTF-8');
                $charIndex = $charOffsetOfMatchStart + mb_strlen($m[0][0], 'UTF-8');
                $candidate = mb_substr($text, $charIndex, $maxLen, 'UTF-8');

                if (preg_match('/^([^\n]{1,' . $maxLen . '})/u', $candidate, $mm)) {
                    return $this->trimFillerChars($mm[1]);
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