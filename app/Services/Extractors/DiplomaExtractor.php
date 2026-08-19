<?php

namespace App\Services\Extractors;

use App\Helpers\DictionaryMatcher;
use App\Dictionaries\Majors;
use App\Dictionaries\Universities;

class DiplomaExtractor extends BaseExtractor
{
    /**
     * Đối chiếu với từ điển (tên trường / tên ngành) để sửa lỗi OCR nhẹ.
     * Nếu không tìm thấy mục nào đủ gần trong từ điển, DictionaryMatcher sẽ trả về
     * bản đã chuẩn hoá (mất dấu, viết thường) — trường hợp đó ta giữ lại nguyên văn
     * gốc (có dấu) thay vì bản đã bị chuẩn hoá, để không làm mất thông tin thật.
     */
    private function matchDictionary(string $raw, array $dictionary): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $raw));
        $matched = DictionaryMatcher::match($clean, $dictionary);

        if (in_array($matched, $dictionary, true)) {
            return $matched;
        }

        return $clean;
    }

    /**
     * ========================================================================
     * HƯỚNG #1: CHUẨN HOÁ TRƯỚC KHI SO KHỚP (fix hệ thống)
     * ========================================================================
     *
     * So khớp một regex KHÔNG DẤU/viết thường trên bản đã chuẩn hoá của $text
     * (để khoan dung việc OCR đọc thiếu/sai dấu tiếng Việt: "Xếp loại" ->
     * "Kếp loai", "Xep loai", "Đại học" -> "Dai hoc", v.v.), nhưng trả về các
     * đoạn khớp được LẤY TỪ TEXT GỐC (giữ nguyên dấu nếu OCR đọc đúng phần
     * đó), bằng cách ánh xạ lại vị trí offset từ bản chuẩn hoá sang bản gốc.
     *
     * $pattern phải là một pattern PCRE ĐẦY ĐỦ (có dấu phân cách '/.../',
     * cờ 'u' bắt buộc), nhưng nội dung bên trong viết bằng chữ thường KHÔNG
     * DẤU (vì $this->normalize() đã đưa text về dạng đó trước khi so khớp).
     *
     * Giả định (giống afterLabelFuzzy() đã có sẵn trong BaseExtractor):
     * normalize() giữ nguyên SỐ LƯỢNG ký tự (1 ký tự có dấu -> 1 ký tự
     * không dấu, không gộp/tách ký tự), nên vị trí byte-offset tìm được
     * trên bản chuẩn hoá có thể quy đổi sang vị trí ký tự và áp dụng thẳng
     * vào text gốc.
     *
     * Trả về mảng giống $matches của preg_match (index 0 = toàn bộ đoạn
     * khớp, 1, 2... = các nhóm con), nhưng mỗi phần tử là chuỗi lấy từ TEXT
     * GỐC. Trả về null nếu không khớp.
     *
     * @return array<int|string, string>|null
     */
    protected function fuzzyMatch(string $text, string $pattern): ?array
    {
        $normalizedText = $this->normalize($text);

        if (!preg_match($pattern, $normalizedText, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $mapped = [];

        foreach ($m as $key => $entry) {
            [$value, $byteOffset] = $entry;

            // Nhóm không tham gia khớp (optional group không match) sẽ có
            // offset = -1, PCRE quy ước như vậy.
            if ($byteOffset < 0) {
                $mapped[$key] = null;
                continue;
            }

            $charOffset = mb_strlen(substr($normalizedText, 0, $byteOffset), 'UTF-8');
            $charLen    = mb_strlen($value, 'UTF-8');

            $mapped[$key] = $charLen > 0
                ? mb_substr($text, $charOffset, $charLen, 'UTF-8')
                : '';
        }

        return $mapped;
    }

    /**
     * BaseExtractor đã có sẵn afterLabelNormalized() làm đúng việc này (tìm
     * nhãn khoan dung lỗi dấu, lấy giá trị từ text gốc) — trước đây file này
     * tự định nghĩa lại một hàm riêng (afterLabelFuzzy) làm y hệt, nay bỏ đi
     * để tránh 2 nguồn sự thật cho cùng 1 logic. Giữ lại alias này chỉ để
     * code gọi bên dưới không phải đổi tên khắp nơi.
     */
    protected function afterLabelFuzzyAny(string $text, array $labels, int $maxLength = 80): ?string
    {
        return $this->afterLabelNormalized($text, $labels, $maxLength);
    }

    /**
     * Ánh xạ 1 chuỗi xếp loại đọc được từ OCR (có thể thiếu/sai dấu, vd:
     * "Kha", "Gioi", "Xuat sac") về đúng dạng chuẩn có dấu. Nếu không khớp
     * mục nào đã biết, giữ nguyên bản gốc thay vì trả về rỗng.
     */
    protected function normalizeClassification(string $raw): string
    {
        $norm = $this->normalize($raw);

        $map = [
            'xuat sac' => 'Xuất sắc',
            'gioi' => 'Giỏi',
            'trung binh kha' => 'Trung bình khá',
            'trung binh' => 'Trung bình',
            'kha' => 'Khá',
        ];

        foreach ($map as $key => $canonical) {
            if (str_starts_with($norm, $key)) {
                return $canonical;
            }
        }

        return trim($raw);
    }

    public function extract(string $text): array
    {
        $result = [];

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));

        // ===== Họ tên (nhãn "Cho:" trên bằng, dạng "Ông/Bà <Họ Tên>") =====
        if (preg_match('/\bCho\s*[:\.]?\s*([^\n]+?)(?:[ \t]{2,}|\n|$)/iu', $text, $m)) {
            $rawName = trim($m[1]);

            if (preg_match('/^(Ông|Bà)\s+(.+)$/iu', $rawName, $mm)) {
                $result['gender'] = mb_strtolower($mm[1], 'UTF-8') === 'ông' ? 'Nam' : 'Nữ';
                $rawName = trim($mm[2]);
            }

            if ($rawName !== '') {
                $result = array_merge($result, $this->splitFullName($rawName));
            }
        }

        // ===== Họ tên — fallback khi nhãn "Cho:" bị OCR đọc rớt chữ =====
        // Gặp thực tế: "Cho:" bị đọc rớt hẳn chữ "C" thành "ho:", ĐỒNG THỜI
        // tên lại đứng TRƯỚC nhãn (giống kiểu đảo thứ tự đã thấy ở ngày
        // sinh/xếp loại). Literal "Cho" ở trên vì vậy trượt luôn cả 2 lỗi
        // cùng lúc. Ở đây: tìm dòng dạng "ho:"/"cho:" (khoan dung dấu +
        // thiếu chữ đầu), rồi quét các dòng GẦN nó (ưu tiên gần nhất, cả 2
        // hướng) tìm một dòng VIẾT HOA TOÀN BỘ, không phải tên trường/tiêu
        // đề/quốc hiệu, để làm ứng viên họ tên.
        if (empty($result['last_name']) && empty($result['first_name'])) {
            $labelIndex = null;

            foreach ($lines as $i => $line) {
                $norm = trim($this->normalize($line), " \t:.");
                if ($norm === 'ho' || $norm === 'cho') {
                    $labelIndex = $i;
                    break;
                }
            }

            if ($labelIndex !== null) {
                $denylist = [
                    'hieu truong', 'truong trung cap', 'truong dai hoc', 'truong cao dang',
                    'truong hoc vien', 'cong hoa', 'viet nam', 'doc lap', 'bang tot nghiep',
                    'chung nhan', 'bang chung nhan',
                ];

                $offsets = [];
                for ($d = 1; $d <= 4; $d++) {
                    $offsets[] = $labelIndex - $d;
                    $offsets[] = $labelIndex + $d;
                }

                foreach ($offsets as $i) {
                    if (!isset($lines[$i])) {
                        continue;
                    }

                    $candidate = trim($lines[$i]);
                    $normCandidate = $this->normalize($candidate);

                    if (
                        mb_strlen($candidate, 'UTF-8') < 6
                        || mb_strlen($candidate, 'UTF-8') > 50
                        || preg_match('/\d/', $candidate)
                        || mb_strtoupper($candidate, 'UTF-8') !== $candidate
                    ) {
                        continue;
                    }

                    $isNoise = false;
                    foreach ($denylist as $bad) {
                        if (str_contains($normCandidate, $bad)) {
                            $isNoise = true;
                            break;
                        }
                    }

                    if ($isNoise) {
                        continue;
                    }

                    $result = array_merge($result, $this->splitFullName($candidate));
                    break;
                }
            }
        }

        // ===== Ngày sinh =====
        if (preg_match('/Ng[àa]y\s*sinh[:\s]+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/iu', $text, $m)) {
            $result['birth_date'] = $this->toDbDate($m[1]);
        } elseif (preg_match(
            '/Ng[àa]y\s*sinh[^\d]{0,10}(\d{1,4})\D{0,3}(\d{1,4})\D{0,3}(\d{2,6})/iu',
            $text,
            $m
        )) {
            $digits = $m[1] . $m[2] . $m[3];

            if (strlen($digits) >= 5 && strlen($digits) <= 8) {
                $year = (int) substr($digits, -4);
                $rest = substr($digits, 0, -4);

                $day = null;
                $month = null;

                if (strlen($rest) === 4) {
                    $day = (int) substr($rest, 0, 2);
                    $month = (int) substr($rest, 2, 2);
                } elseif (strlen($rest) === 3) {
                    $day = (int) substr($rest, 0, 2);
                    $month = (int) substr($rest, 2, 1);
                } elseif (strlen($rest) === 2) {
                    $day = (int) substr($rest, 0, 1);
                    $month = (int) substr($rest, 1, 1);
                }

                $valid = $day && $month
                    && $day >= 1 && $day <= 31
                    && $month >= 1 && $month <= 12;

                if (! $valid && strlen($rest) === 4) {
                    $retryDay = (int) substr($rest, 0, 2);
                    $retryMonth = (int) substr($rest, 2, 1);

                    if ($retryDay >= 1 && $retryDay <= 31 && $retryMonth >= 1 && $retryMonth <= 12) {
                        $day = $retryDay;
                        $month = $retryMonth;
                        $valid = true;
                    }
                }

                if ($valid) {
                    $result['birth_date'] = $this->toDbDate(
                        sprintf('%02d/%02d/%04d', $day, $month, $year)
                    );
                }
            }
        }

        // ===== Ngày sinh — fallback KHÔNG PHỤ THUỘC THỨ TỰ nhãn/giá trị =====
        // 2 pattern trên đều giả định "Ngày sinh" đứng TRƯỚC ngày tháng.
        // Nhưng nhiều mẫu bằng (do layout: dòng kẻ để điền giá trị nằm
        // trên, chú thích nhãn in nhỏ nằm dưới) khiến OCR đọc ra thứ tự
        // NGƯỢC LẠI, ví dụ thực tế:
        //   "26/11/1995"
        //   "ngày sinh:"
        // Trường hợp này 2 pattern trên không khớp được gì cả. Ở đây quét
        // các dòng GẦN nhãn "ngày sinh" theo CẢ HAI HƯỚNG (trước lẫn sau,
        // giống cách đã làm với mã xếp loại viết tắt và ngành học tiếng Anh
        // ở dưới) để tìm dòng chỉ chứa 1 ngày tháng năm.
        if (empty($result['birth_date'])) {
            $labelIndex = null;

            foreach ($lines as $i => $line) {
                if (str_contains($this->normalize($line), 'ngay sinh')) {
                    $labelIndex = $i;
                    break;
                }
            }

            if ($labelIndex !== null) {
                $from = max(0, $labelIndex - 3);
                $to = min(count($lines) - 1, $labelIndex + 3);

                for ($i = $from; $i <= $to; $i++) {
                    if (preg_match('/^(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})$/', trim($lines[$i]), $mm)) {
                        $result['birth_date'] = $this->toDbDate($mm[1]);
                        break;
                    }
                }
            }
        }

        // ===== Tên trường (HƯỚNG #1: chuẩn hoá trước khi so khớp) =====
        // Trước đây chỉ khớp được các biến thể dấu đã liệt kê thủ công
        // (vd: "Tr[ưu]ờng", "vi[eệ]n"...) — bỏ sót nhiều kiểu lỗi dấu khác.
        // Giờ so khớp trên bản không dấu "dai hoc / cao dang / hoc vien /
        // nhac vien / vien / trung cap", rồi lấy lại đoạn text GỐC tương ứng.
        //
        // FIX: bản trước THIẾU "trung cấp" — với bằng Trung cấp (vd:
        // "TRƯỜNG TRUNG CẤP NGHỀ ...") thì university_name luôn bị bỏ trống
        // vì tên trường không chứa "đại học/cao đẳng/học viện/nhạc viện".
        if ($fm = $this->fuzzyMatch(
            $text,
            '/(?:truong\s+)?(?:dai\s*hoc|cao\s*dang|hoc\s*vien|nhac\s*vien|trung\s*cap(?:\s*nghe)?|vien)[^\n]{2,80}?(?=[ \t]{2,}|\n|$)/u'
        )) {
            $result['university_name'] = $this->matchDictionary($fm[0], Universities::all());
        }

        // ===== Hạng tốt nghiệp / Xếp loại (HƯỚNG #1) =====
        if ($fm = $this->fuzzyMatch(
            $text,
            '/(?:hang|xep\s*loai)\s*tot\s*nghiep[:\s]+(xuat\s*sac|gioi|kha|trung\s*binh\s*kha|trung\s*binh)/u'
        )) {
            $result['classification'] = $this->normalizeClassification($fm[1]);
        } elseif ($fm = $this->fuzzyMatch(
            $text,
            '/(xuat\s*sac|gioi|kha|trung\s*binh\s*kha|trung\s*binh)/u'
        )) {
            $result['classification'] = $this->normalizeClassification($fm[1]);
        } else {
            // Fallback: một số mẫu bằng chỉ ghi mã viết tắt (TB/K/G/XS)
            // nằm gần nhãn "Xếp loại".
            $labelIndex = null;

            foreach ($lines as $i => $line) {
                if (str_contains($this->normalize($line), 'xep loai')) {
                    $labelIndex = $i;
                    break;
                }
            }

            if ($labelIndex !== null) {
                $map = [
                    'TB' => 'Trung bình',
                    'K' => 'Khá',
                    'G' => 'Giỏi',
                    'XS' => 'Xuất sắc',
                ];

                $from = max(0, $labelIndex - 3);
                $to = min(count($lines) - 1, $labelIndex + 3);

                for ($i = $from; $i <= $to; $i++) {
                    $candidate = mb_strtoupper(trim($lines[$i]), 'UTF-8');

                    if (isset($map[$candidate])) {
                        $result['classification'] = $map[$candidate];
                        break;
                    }
                }
            }
        }

        // ===== Trình độ đào tạo (Trung cấp / Cao đẳng / Đại học) =====
        // CHỈ dùng để ghép vào Ghi chú 2 (hệ thống export không có cột
        // riêng cho "trình độ"). TUYỆT ĐỐI không gán vào training_type —
        // training_type đại diện cho "HÌNH THỨC đào tạo" (Chính quy / Vừa
        // làm vừa học / Từ xa / Liên thông / Chuyên tu), là một khái niệm
        // KHÁC HẲN với trình độ (bằng thuộc bậc nào).
        //
        // FIX: bản trước lỡ gán mặc định $level vào training_type khi
        // không tìm thấy hình thức đào tạo thật trong text, khiến trường
        // "Hình thức đào tạo" hiển thị sai thành "Trung cấp"/"Cao đẳng"/
        // "Đại học" — đây là lỗi thiết kế, không phải lỗi OCR.
        $level = null;

        // FIX: bản trước dùng char class [ÁA] chỉ chấp nhận chữ "á" (á
        // thường) hoặc "A" — nhưng chính tả đúng của "cấp" dùng "ấ" (â +
        // dấu sắc, KHÁC hẳn ký tự unicode với "á"), nên regex CHƯA BAO GIỜ
        // khớp được với "Trung cấp"/"Cao đẳng" viết đúng chính tả, chỉ tình
        // cờ khớp khi OCR gõ sai dấu. Đổi sang fuzzyMatch (so khớp trên bản
        // không dấu) để không còn phụ thuộc liệt kê thủ công từng biến thể.
        if ($this->fuzzyMatch($text, '/trung\s*cap/u')) {
            $level = 'Trung cấp';
        } elseif ($this->fuzzyMatch($text, '/cao\s*dang/u')) {
            $level = 'Cao đẳng';
        } elseif ($this->fuzzyMatch($text, '/dai\s*hoc/u')) {
            $level = 'Đại học';
        }

        // ===== Hình thức đào tạo (Chính quy / Vừa làm vừa học / Từ xa /
        // Liên thông / Chuyên tu) — CHỈ gán khi thực sự tìm thấy thông tin
        // này trong text, không suy luận/mặc định từ trình độ.
        if ($training = $this->afterLabelFuzzyAny($text, ['Hình thức đào tạo', 'Hệ đào tạo'], 40)) {
            $result['training_type'] = $training;
        } elseif ($fm = $this->fuzzyMatch(
            $text,
            // Thêm "chuyên tu" — bạn có nhắc tới nhưng bản trước chưa có
            // trong danh sách nhận diện.
            '/(chinh\s*quy|chuyen\s*tu|vua\s*lam\s*vua\s*hoc|tu\s*xa|lien\s*thong)/u'
        )) {
            $result['training_type'] = $fm[1];
        }

        // ===== Ngành / chuyên ngành đào tạo =====
        $major = null;

        if ($majorLabel = $this->afterLabelNormalized(
            $text,
            ['Ngành đào tạo', 'Chuyên ngành', 'Ngành'],
            80
        )) {
            $major = trim($majorLabel);
            $result['major_name'] = DictionaryMatcher::match(
                $major,
                Majors::all()
            );
        } elseif ($fm = $this->fuzzyMatch(
            // HƯỚNG #1: "bang" (BẰNG) không dấu, khoan dung lỗi OCR trên
            // chính từ "BẰNG" đứng đầu dòng.
            $text,
            '/bang\s+[^\n]{0,60}\n\s*([^\n]{4,120})\n/u'
        )) {
            $lineRaw = $fm[1];

            $segments = array_values(
                array_filter(
                    array_map(
                        'trim',
                        preg_split('/[ \t]{2,}/', $lineRaw)
                    )
                )
            );

            $candidate = $segments
                ? trim(preg_replace('/\s+/', ' ', end($segments)))
                : '';

            // So khớp loại trừ trên bản KHÔNG DẤU để không bỏ sót các dòng
            // rác kiểu "CỘNG HÒA" bị OCR đọc thiếu dấu.
            $normCandidate = $this->normalize($candidate);

            if (
                $candidate !== ''
                && !preg_match('/cong\s*hoa|\bcho\b|cap|truong|viet\s*nam/u', $normCandidate)
            ) {
                $major = $candidate;
                $result['major_name'] = $this->matchDictionary(
                    $candidate,
                    Majors::all()
                );
            }
        }

        // Fallback: ngành học nằm gần dòng tiếng Anh như "Office ... informatics"
        if (empty($result['major_name'])) {
            foreach ($lines as $i => $line) {
                $normLine = $this->normalize($line);

                if (
                    str_contains($normLine, 'informatics')
                    || str_contains($normLine, 'office')
                ) {
                    foreach ([$i - 1, $i + 1] as $nearbyIndex) {
                        if (!isset($lines[$nearbyIndex])) {
                            continue;
                        }

                        $candidate = $lines[$nearbyIndex];
                        $normCandidate = $this->normalize($candidate);

                        $isEnglishLine =
                            str_contains($normCandidate, 'informatics')
                            || str_contains($normCandidate, 'office')
                            || str_contains($normCandidate, 'in ');

                        if (
                            mb_strlen($candidate) > 2
                            && mb_strlen($candidate) < 60
                            && !$isEnglishLine
                        ) {
                            $major = $candidate;

                            $result['major_name'] = $this->matchDictionary(
                                $candidate,
                                Majors::all()
                            );

                            break 2;
                        }
                    }
                }
            }
        }

        // ===== Mã ngành =====
        if (preg_match('/M[aã]\s*ng[aà]nh[:\s]+(\d{4,8})/iu', $text, $m)) {
            $result['major_code'] = $m[1];
        }

        // ===== Điểm trung bình chung (nếu là bảng điểm kèm theo) =====
        if (
            preg_match(
                '/[ĐD]i[eể]m\s*trung\s*b[iì]nh\s*chung(?:\s*t[ií]ch\s*lu[ỹy])?(?:\s*to[aà]n\s*kh[oó]a)?[:\s]+(\d+[.,]\d+)/iu',
                $text,
                $m
            )
        ) {
            $result['average_score'] = str_replace(',', '.', $m[1]);
        }

        // ================================================================
        // SỐ HIỆU BẰNG + SỐ VÀO SỔ BẰNG
        // ================================================================
        //
        // Bằng bản sao thường có OCR bị đảo thứ tự dòng, ví dụ:
        //
        //   hiệu bản sao:..coo451
        //   ...
        //   C2024083
        //   vào số cấp bằng bản sao
        //
        // Không được lấy "hiệu bản sao" vì đó là số của TỜ BẢN SAO.
        //
        // Ưu tiên:
        // 1. Số hiệu bằng gốc
        // 2. Số vào sổ cấp bằng
        // 3. Nếu OCR đảo vị trí nhãn/giá trị thì tìm giá trị
        //    gần nhãn tương ứng.
        //

        // ===== SỐ HIỆU BẰNG =====

        if ($fm = $this->fuzzyMatch(
            $text,
            '/so\s*hieu(?!\s*ban\s*sao)(?:\s*(?:van\s*)?bang)?[^\n]{0,10}[:\s]+([A-Za-z0-9][A-Za-z0-9.\-\/]{2,30})/u'
        )) {
            $cleaned = preg_replace('/[^A-Za-z0-9]/', '', $fm[1]);

            if ($cleaned !== '') {
                $result['diploma_number'] = $cleaned;
            }
        }


        // ===== SỐ VÀO SỔ BẰNG =====

        if ($fm = $this->fuzzyMatch(
            $text,
            '/so\s*vao\s*so[^\n:]{0,40}[:\s]+([A-Za-z0-9][A-Za-z0-9.\-\/]{2,30})/u'
        )) {
            $cleaned = preg_replace('/[^A-Za-z0-9]/', '', $fm[1]);

            if ($cleaned !== '') {
                $result['diploma_registry_number'] = $cleaned;
            }
        }


        // ================================================================
        // FALLBACK CHO OCR BỊ ĐẢO NHÃN / GIÁ TRỊ
        // ================================================================
        //
        // Ví dụ thực tế:
        //
        //   C2024083
        //   vào số cấp bằng bản sao
        //
        // OCR đọc giá trị trước nhãn.
        // Khi đó lấy dòng có dạng mã bằng nằm gần dòng
        // "vào số cấp bằng".
        //

        if (
            empty($result['diploma_registry_number'])
            && !empty($lines)
        ) {
            foreach ($lines as $i => $line) {

                $normLine = $this->normalize($line);

                // Nhận diện các biến thể OCR của:
                // "vào số cấp bằng"
                // "số vào sổ cấp bằng"
                // "vào sổ cấp bằng"
                if (
                    str_contains($normLine, 'vao so')
                    || str_contains($normLine, 'vao so cap bang')
                    || str_contains($normLine, 'so vao so')
                ) {

                    // Tìm giá trị ở 3 dòng trước/sau
                    for ($d = 1; $d <= 3; $d++) {

                        foreach ([$i - $d, $i + $d] as $candidateIndex) {

                            if (!isset($lines[$candidateIndex])) {
                                continue;
                            }

                            $candidate = trim($lines[$candidateIndex]);

                            // Bỏ dòng quá ngắn/dài
                            if (
                                mb_strlen($candidate, 'UTF-8') < 3
                                || mb_strlen($candidate, 'UTF-8') > 30
                            ) {
                                continue;
                            }

                            // Không lấy chính dòng nhãn
                            $normCandidate = $this->normalize($candidate);

                            if (
                                str_contains($normCandidate, 'vao so')
                                || str_contains($normCandidate, 'cap bang')
                                || str_contains($normCandidate, 'ban sao')
                                || str_contains($normCandidate, 'hieu truong')
                            ) {
                                continue;
                            }

                            // Giá trị số hiệu thường chứa chữ + số
                            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-\/]{2,30}$/u', $candidate)) {

                                $cleaned = preg_replace(
                                    '/[^A-Za-z0-9]/',
                                    '',
                                    $candidate
                                );

                                if ($cleaned !== '') {
                                    $result['diploma_registry_number'] = $cleaned;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }


        // ================================================================
        // FALLBACK SỐ HIỆU BẰNG
        // ================================================================
        //
        // Trường hợp OCR đọc:
        //
        //   C2024083
        //   ...
        //   hiệu bằng
        //
        // thì lấy mã gần nhãn "hiệu bằng".
        //

        if (
            empty($result['diploma_number'])
            && !empty($lines)
        ) {
            foreach ($lines as $i => $line) {

                $normLine = $this->normalize($line);

                if (
                    str_contains($normLine, 'hieu bang')
                    || str_contains($normLine, 'so hieu bang')
                    || str_contains($normLine, 'so hieu van bang')
                ) {

                    for ($d = 1; $d <= 3; $d++) {

                        foreach ([$i - $d, $i + $d] as $candidateIndex) {

                            if (!isset($lines[$candidateIndex])) {
                                continue;
                            }

                            $candidate = trim($lines[$candidateIndex]);

                            if (
                                mb_strlen($candidate, 'UTF-8') < 3
                                || mb_strlen($candidate, 'UTF-8') > 30
                            ) {
                                continue;
                            }

                            $normCandidate = $this->normalize($candidate);

                            if (
                                str_contains($normCandidate, 'ban sao')
                                || str_contains($normCandidate, 'hieu bang')
                                || str_contains($normCandidate, 'so vao so')
                            ) {
                                continue;
                            }

                            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-\/]{2,30}$/u', $candidate)) {

                                $cleaned = preg_replace(
                                    '/[^A-Za-z0-9]/',
                                    '',
                                    $candidate
                                );

                                if ($cleaned !== '') {
                                    $result['diploma_number'] = $cleaned;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        // ===== Năm tốt nghiệp (HƯỚNG #1) =====
        if ($fm = $this->fuzzyMatch($text, '/tot\s*nghiep[^\n]{0,20}(\d{4})/u')) {
            if (
                $fm[1] >= 1990
                && $fm[1] <= (int) date('Y') + 1
            ) {
                $result['university_graduation_year'] = $fm[1];
            }
        }

        if (
            empty($result['university_graduation_year'])
            && preg_match(
                // FIX: bản gốc bắt buộc \s+ (khoảng trắng) giữa "ngày"/"tháng"/
                // "năm" và số — nhưng gặp thực tế OCR dùng DẤU CHẤM làm phân
                // cách: "ngày.08.tháng 10.năm.2024" (không có khoảng trắng sau
                // "ngày" và "năm"). Đổi \s+ thành [\s\.]* để chấp nhận cả 2 kiểu.
                '/ng[àa]y[\s\.]*\d{1,2}[\s\.]*th[áa]ng[\s\.]*\d{1,2}[\s\.]*n[ăa]m[\s\.]*(\d{4})/iu',
                $text,
                $m
            )
        ) {
            // Fallback: lấy năm từ ngày ký cấp bằng.
            $result['university_graduation_year'] = $m[1];
        }

        // ===== Tỉnh trường đại học =====
        if (
            preg_match(
                '/([A-ZÀ-Ỹ][^\n]{1,40}?)[ \t]*,?[ \t]*ng[àa]y[.\s]*\d/iu',
                $text,
                $m
            )
        ) {
            $result['university_province_name'] = trim($m[1]);
        }

        // ===== Ghi chú 2 = Trình độ + Ngành =====
        if ($level || $major) {
            $result['note_2'] = trim(
                ($level ?? '')
                . ' '
                . ($major ?? '')
            );
        }

        return $result;
    }
}