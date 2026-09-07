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
        // Ưu tiên neo theo nhãn "Ngày sinh"/"Date of birth", cho phép thiếu
        // dấu phân cách (OCR đọc "2711/1992" thay vì "27/11/1992")
        if (preg_match(
            '/(?:Ngày\s*sinh|Date\s*of\s*birth)[^\d]{0,15}(\d{1,2})[\/\-\.\s]*(\d{1,2})[\/\-\.\s]*(\d{4})/iu',
            $text,
            $m
        )) {
            return $this->toDbDate($m[1] . '/' . $m[2] . '/' . $m[3]);
        }

        // ================================================================
        // FIX (bug ngày sinh CCCD ra "11/01/1992" thay vì "27/11/1992"):
        // ================================================================
        //
        // OCR có thể làm MẤT dấu "/" thật giữa ngày và tháng, đồng thời
        // CHÈN THÊM 1 chữ số rác trước dấu "/" của năm. Case thực tế:
        //
        //     Ngày sinh 1 Date of birth; 2711/1/1992
        //
        // (đúng ra là "27/11/1992"). Pattern phía trên đòi hỏi phải ghép
        // ra được NHÓM 4 CHỮ SỐ LIÊN TỤC ngay sau 2 dấu phân cách optional
        // — nhưng ở đây có 1 chữ số rác ("1") xen ngay trước dấu "/" của
        // năm, nên KHÔNG CÓ CÁCH CHIA NÀO cho ra đủ 4 chữ số liên tục làm
        // năm mà vẫn còn dư đúng 2 nhóm ngày/tháng hợp lệ phía trước ->
        // preg_match() phía trên THẤT BẠI HOÀN TOÀN (không khớp gì), rơi
        // thẳng xuống fallback quét dòng bên dưới. Fallback đó lại vô
        // tình khớp trúng chuỗi con "11/1/1992" (bỏ sót "27" ở đầu) vì
        // đây vẫn là 1 chuỗi con hợp lệ theo pattern \d{1,2}/\d{1,2}/\d{4}
        // -> ra kết quả SAI "11/01/1992".
        //
        // FIX: thêm bước trung gian này TRƯỚC KHI rơi xuống fallback quét
        // dòng. Giả định: 2 chữ số NGAY SAU nhãn luôn là NGÀY, 2 chữ số
        // kế tiếp NGAY SAU ĐÓ (không cần dấu phân cách) luôn là THÁNG —
        // hợp lý vì nếu có dấu phân cách thật giữa ngày/tháng thì pattern
        // phía trên đã bắt được rồi, không rơi tới đây. Sau đó dùng
        // ".{0,10}?" (khớp bất kỳ ký tự nào, kể cả chữ số rác, không tham
        // lam) để "nhảy qua" phần rác ở giữa, và dùng ranh giới
        // "(?<!\d)...(?!\d)" để tìm ĐÚNG 1 CỤM 4 CHỮ SỐ ĐỘC LẬP (không
        // dính thêm chữ số nào ở 2 đầu) làm năm — tức tìm "năm gần nhất
        // có ranh giới rõ ràng, bỏ qua rác ở giữa" thay vì ép buộc toàn
        // bộ chuỗi phải liên tục như pattern cũ. Có validate day/month
        // hợp lệ (1-31 / 1-12) để tránh khớp nhầm sang trường hợp khác.
        if (preg_match(
            '/(?:Ngày\s*sinh|Date\s*of\s*birth)[^\d]{0,15}(\d{2})(\d{2}).{0,10}?(?<!\d)(\d{4})(?!\d)/isu',
            $text,
            $m
        )) {
            $day = (int) $m[1];
            $month = (int) $m[2];

            if ($day >= 1 && $day <= 31 && $month >= 1 && $month <= 12) {
                return $this->toDbDate($m[1] . '/' . $m[2] . '/' . $m[3]);
            }
        }

        // Fallback: quét từng dòng, bỏ qua dòng "giá trị đến"/"date of expiry"
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/gi[áa]\s*tr[ịi]\s*đ[ếe]n|date\s*of\s*expiry/iu', $line)) {
                continue;
            }
            if (preg_match('/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/', $line, $m)) {
                return $this->toDbDate($m[1]);
            }
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
     * ========================================================================
     * FIX TẬN GỐC lỗi lệch offset (misaligned substring).
     *
     * Trước đây fuzzyMatch()/afterLabelNormalized() TÍNH TOÁN LẠI vị trí
     * ký tự trên bản gốc từ độ dài bản chuẩn hoá, dựa trên GIẢ ĐỊNH ngầm
     * "normalize() giữ nguyên số lượng ký tự 1-đổi-1". Giả định này rất dễ
     * vỡ (chỉ cần sau này ai đó thêm 1 bước trim khoảng trắng thừa, gộp
     * nhiều dòng, hoặc chuẩn hoá kiểu "đ" -> "d"+"j" v.v. trong normalize()
     * là toàn bộ offset trôi lệch âm thầm, không có lỗi/exception nào báo).
     *
     * Cách fix: xây MAP TƯỜNG MINH map[i] = vị trí ký tự gốc tương ứng với
     * ký tự thứ i của chuỗi đã chuẩn hoá, ngay tại thời điểm chuẩn hoá theo
     * từng ký tự một. Sau đó mọi việc tra offset chỉ là lookup mảng, không
     * còn "tính suy ngược" dựa trên giả định độ dài nữa -> đúng tuyệt đối
     * bất kể sau này normalize() có thay đổi logic ra sao.
     *
     * @return array{0: string, 1: array<int,int>} [normalizedText, map]
     */
    protected function normalizeWithMap(string $text): array
    {
        $chars = mb_str_split($text, 1, 'UTF-8');

        $normalizedChars = [];
        $map = [];

        foreach ($chars as $origIndex => $ch) {
            $normCh = $this->normalize($ch);

            // Một ký tự gốc CÓ THỂ chuẩn hoá ra 0, 1 hoặc nhiều ký tự
            // (tuỳ logic normalize() tương lai) — xử lý tổng quát cho cả
            // 3 trường hợp thay vì giả định luôn là 1-1.
            foreach (mb_str_split($normCh, 1, 'UTF-8') as $nc) {
                $normalizedChars[] = $nc;
                $map[] = $origIndex;
            }
        }

        return [implode('', $normalizedChars), $map];
    }

    /**
     * Tra 1 đoạn [charOffset, charOffset+charLen) trên chuỗi CHUẨN HOÁ
     * về đúng đoạn tương ứng trên chuỗi GỐC, dùng $map từ normalizeWithMap().
     * Trả về null nếu offset nằm ngoài phạm vi map (không nên xảy ra, phòng
     * thủ cho an toàn).
     */
    protected function mapNormalizedSpanToOriginal(
        string $text,
        array $map,
        int $charOffset,
        int $charLen
    ): ?string {
        if ($charLen === 0) {
            return '';
        }

        $startOrig = $map[$charOffset] ?? null;
        $endOrig = $map[$charOffset + $charLen - 1] ?? null;

        if ($startOrig === null || $endOrig === null) {
            return null;
        }

        return mb_substr($text, $startOrig, $endOrig - $startOrig + 1, 'UTF-8');
    }

    /**
     * Giống afterLabel(), nhưng so khớp KHÔNG phân biệt dấu tiếng Việt
     * (vì OCR hay đọc rớt dấu). Vẫn trả về đúng đoạn text GỐC (có dấu nếu
     * OCR đọc được) — tra bằng map tường minh, KHÔNG còn tính suy ngược
     * offset từ độ dài như bản cũ.
     */
    protected function afterLabelNormalized(string $text, array $labels, int $maxLen = 80): ?string
    {
        [$normalizedText, $map] = $this->normalizeWithMap($text);

        foreach ($labels as $label) {
            $normLabel = $this->normalize($label);
            $pattern = '/' . preg_quote($normLabel, '/') . '[:\s]{1,5}/';

            if (preg_match($pattern, $normalizedText, $m, PREG_OFFSET_CAPTURE)) {
                // PREG_OFFSET_CAPTURE trả offset theo BYTE trên chuỗi UTF-8,
                // cần đổi sang offset theo KÝ TỰ trước khi tra map.
                $matchByteOffset = $m[0][1];
                $matchCharOffset = mb_strlen(substr($normalizedText, 0, $matchByteOffset), 'UTF-8');
                $matchCharLen = mb_strlen($m[0][0], 'UTF-8');

                $startCharOffset = $matchCharOffset + $matchCharLen;
                $origStart = $map[$startCharOffset] ?? null;

                if ($origStart === null) {
                    continue;
                }

                $candidate = mb_substr($text, $origStart, $maxLen, 'UTF-8');

                if (preg_match('/^([^\n]{1,' . $maxLen . '})/u', $candidate, $mm)) {
                    return trim($mm[1]);
                }
            }
        }

        return null;
    }


    /**
     * So khớp regex trên bản text đã chuẩn hoá (không dấu, chữ thường),
     * nhưng trả kết quả theo đúng text gốc bằng map offset.
     *
     * Pattern phải dùng nội dung phù hợp với normalize(), thường là chữ
     * thường không dấu và có cờ /u.
     *
     * @return array<int|string, string|null>|null
     */
    protected function fuzzyMatch(string $text, string $pattern): ?array
    {
        [$normalizedText, $map] = $this->normalizeWithMap($text);

        if (!preg_match($pattern, $normalizedText, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $result = [];

        foreach ($matches as $key => $entry) {
            [$value, $byteOffset] = $entry;

            if ($byteOffset < 0) {
                $result[$key] = null;
                continue;
            }

            $charOffset = mb_strlen(
                substr($normalizedText, 0, $byteOffset),
                'UTF-8'
            );

            $charLength = mb_strlen($value, 'UTF-8');

            $result[$key] = $this->mapNormalizedSpanToOriginal(
                $text,
                $map,
                $charOffset,
                $charLength
            );
        }

        return $result;
    }

    /**
     * Giống fuzzyMatch(), nhưng trả về tất cả vị trí khớp.
     *
     * Hữu ích khi OCR có nhiều dòng chứa cùng một từ khoá; Extractor con
     * có thể lần lượt kiểm tra từng kết quả thay vì chỉ lấy match đầu tiên.
     *
     * @return array<int, array<int|string, string|null>>
     */
    protected function fuzzyMatchAll(string $text, string $pattern): array
    {
        [$normalizedText, $map] = $this->normalizeWithMap($text);

        if (!preg_match_all(
            $pattern,
            $normalizedText,
            $allMatches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            return [];
        }

        $results = [];

        foreach ($allMatches as $matches) {
            $result = [];

            foreach ($matches as $key => $entry) {
                [$value, $byteOffset] = $entry;

                if ($byteOffset < 0) {
                    $result[$key] = null;
                    continue;
                }

                $charOffset = mb_strlen(
                    substr($normalizedText, 0, $byteOffset),
                    'UTF-8'
                );

                $charLength = mb_strlen($value, 'UTF-8');

                $result[$key] = $this->mapNormalizedSpanToOriginal(
                    $text,
                    $map,
                    $charOffset,
                    $charLength
                );
            }

            $results[] = $result;
        }

        return $results;
    }

    protected function splitAddress(string $address): array
    {
        $result = [];

        $provinces = \App\Dictionaries\Provinces::all();
        usort($provinces, fn ($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        $matchedRaw = null;
        $matchedPos = false;

        foreach ($provinces as $province) {
            $pos = mb_stripos($address, $province, 0, 'UTF-8');
            if ($pos !== false) {
                $matchedRaw = $province;
                $matchedPos = $pos;
                break;
            }
        }

        if ($matchedRaw === null) {
            $segments = $this->splitByComma($address);
            if (count($segments) >= 1) {
                $result['province_name'] = end($segments);
            }
            if (count($segments) >= 2) {
                $result['ward_name'] = $segments[count($segments) - 2];
            }
            return $result;
        }

        $result['province_name'] = \App\Services\ProvinceMergeMapper::toNewName($matchedRaw);

        $remainder = trim(rtrim(mb_substr($address, 0, $matchedPos, 'UTF-8'), " ,"));

        if (preg_match('/(?:phường|xã|thị trấn)\s+([a-zA-ZÀ-ỹ0-9\s]+?)(?=,|\s+(?:thành phố|quận|huyện|thị xã)|$)/iu', $remainder, $m)) {
            $result['ward_name'] = trim($m[1]);
        } else {
            $segments = $this->splitByComma($remainder);
            if (! empty($segments)) {
                $result['ward_name'] = end($segments);
            }
        }

        if (preg_match('/(?:thành phố|quận|huyện|thị xã)\s+([a-zA-ZÀ-ỹ\s]+?)$/iu', $remainder, $m)) {
            $result['district_name'] = trim($m[1]);
        }

        return $result;
    }

    protected function splitByComma(string $str): array
    {
        return array_values(array_filter(array_map(
            fn ($s) => trim(preg_replace('/\s+/', ' ', $s)),
            explode(',', $str)
        ), fn ($s) => $s !== ''));
    }
}