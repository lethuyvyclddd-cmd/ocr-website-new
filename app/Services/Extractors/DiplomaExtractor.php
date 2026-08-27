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
        // ===== Họ tên — fallback khi nhãn "Cho:" bị mất hoàn toàn =====
        // Case thực tế: dòng ghi tên chỉ còn "- Nguyễn Hữu Đạt" (dấu gạch ngang
        // thay nhãn, không có chữ "Cho" nào). 2 nhánh trên đều đòi phải tìm
        // được literal "Cho"/"ho" nên bỏ qua hoàn toàn dòng này.
        // Neo theo dòng tiếng Anh "UPON ...Name..." liền kề (luôn xuất hiện
        // cùng cặp với dòng tên tiếng Việt trên bằng song ngữ) rồi lấy dòng
        // tiếng Việt tương ứng ngay sau/trước nó.
        if (empty($result['last_name']) && empty($result['first_name'])) {
            foreach ($lines as $i => $line) {
                $normLine = trim($this->normalize($line), " \t:.-");

                // Dòng dạng "aron Nguyen Huu Dat" / "upon Nguyen Huu Dat" —
                // OCR có thể làm rớt/méo chữ "UPON" (ví dụ ra "aron"), nhưng
                // luôn có ít nhất 1 dòng KHÔNG DẤU chứa họ tên viết thường sau
                // khi chuẩn hoá, đi kèm 1 dòng CÓ DẤU tương ứng liền kề.
                if (preg_match('/^[a-z]{2,6}\s+[a-z\s]{4,40}$/u', $normLine)
                    && !str_contains($normLine, 'born')
                    && !str_contains($normLine, 'nam')
                ) {
                    foreach ([$i - 1, $i + 1] as $nearbyIndex) {
                        if (!isset($lines[$nearbyIndex])) {
                            continue;
                        }

                        $candidate = trim($lines[$nearbyIndex], " \t:.-");
                        $normCandidate = $this->normalize($candidate);

                        $isNoise = preg_match('/\d/', $candidate)
                            || mb_strlen($candidate, 'UTF-8') < 4
                            || mb_strlen($candidate, 'UTF-8') > 50
                            || str_contains($normCandidate, 'bang')
                            || str_contains($normCandidate, 'ky su')
                            || str_contains($normCandidate, 'cap')
                            || str_contains($normCandidate, 'may tinh');

                        if (!$isNoise) {
                            $result = array_merge($result, $this->splitFullName($candidate));
                            break 2;
                        }
                    }
                }
            }
        }

                // ===== Ngày sinh =====
        if (preg_match('/Ng[àa]y\s*sinh[:\s]+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/iu', $text, $m)) {
            $result['birth_date'] = $this->toDbDate($m[1]);
        } elseif (preg_match(
            // FIX (bug birth_date luôn rỗng trên bằng song ngữ): một số
            // phôi bằng ghi ĐẢO NGƯỢC thứ tự "SINH NGÀY dd/mm/yyyy" thay vì
            // "Ngày sinh dd/mm/yyyy" — thường do layout dịch song song với
            // dòng tiếng Anh "BORN ON ..." ngay phía trên. 2 pattern còn
            // lại trong hàm này đều neo cứng thứ tự "Ngày...sinh" nên
            // không khớp được trường hợp đảo ngược này.
            '/[Ss]inh\s*ng[àa]y[:\s]+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/iu',
            $text,
            $m
        )) {
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
        // Các pattern trên đều giả định nhãn (dù "ngày sinh" hay "sinh
        // ngày") đứng NGAY TRƯỚC ngày tháng, sát cùng dòng. Nhưng nhiều
        // mẫu bằng (do layout: dòng kẻ để điền giá trị nằm trên, chú
        // thích nhãn in nhỏ nằm dưới) khiến OCR đọc ra thứ tự dòng NGƯỢC
        // LẠI, ví dụ thực tế:
        //   "26/11/1995"
        //   "ngày sinh:"
        // Trường hợp này các pattern trên không khớp được gì cả. Ở đây
        // quét các dòng GẦN nhãn ngày sinh theo CẢ HAI HƯỚNG (trước lẫn
        // sau, giống cách đã làm với mã xếp loại viết tắt và ngành học
        // tiếng Anh ở dưới) để tìm dòng chỉ chứa 1 ngày tháng năm.
        //
        // FIX: mở rộng điều kiện nhận diện dòng nhãn để chấp nhận cả 2
        // thứ tự chữ ("ngay sinh" VÀ "sinh ngay"), đồng bộ với 2 nhánh
        // preg_match phía trên.
        if (empty($result['birth_date'])) {
            $labelIndex = null;

            foreach ($lines as $i => $line) {
                $normLine = $this->normalize($line);
                if (str_contains($normLine, 'ngay sinh') || str_contains($normLine, 'sinh ngay')) {
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
                // FIX (bug ngành ra tiếng Anh "Electrical - Electronics
                // Engineering" thay vì tên tiếng Việt): bằng song ngữ luôn có 1
                // dòng tiếng Anh + 1 dòng tiếng Việt liền kề cho tên ngành, nhưng
                // dòng "Bằng..." lại đứng ngay TRƯỚC dòng tiếng Anh nên regex
                // luôn vớ đúng bản Anh. Nếu dòng vừa lấy KHÔNG có dấu tiếng
                // Việt, và dòng NGAY SAU nó có dấu tiếng Việt và không phải rác,
                // ưu tiên dùng dòng tiếng Việt đó thay vì bản tiếng Anh.
                $hasVietnameseDiacritics = $candidate !== $this->stripDiacritics($candidate);

                if (!$hasVietnameseDiacritics) {
                    $candidateLineIndex = null;
                    foreach ($lines as $i => $line) {
                        if (trim($line) === trim($lineRaw)) {
                            $candidateLineIndex = $i;
                            break;
                        }
                    }

                    if ($candidateLineIndex !== null && isset($lines[$candidateLineIndex + 1])) {
                        $nextLine = trim($lines[$candidateLineIndex + 1]);
                        $nextNorm = $this->normalize($nextLine);

                        $nextIsViet = $nextLine !== $this->stripDiacritics($nextLine);
                        $nextIsNoise = preg_match('/\d/', $nextLine)
                            || mb_strlen($nextLine, 'UTF-8') < 3
                            || mb_strlen($nextLine, 'UTF-8') > 60
                            || str_contains($nextNorm, 'cho ')
                            || str_contains($nextNorm, 'sinh ngay')
                            || str_contains($nextNorm, 'nam sinh');

                        if ($nextIsViet && !$nextIsNoise) {
                            $candidate = $nextLine;
                            $normCandidate = $this->normalize($candidate);
                        }
                    }
                }

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
        // Mục tiêu:
        //
        // diploma_number
        //     Ví dụ: 009167
        //
        // diploma_registry_number
        //     Ví dụ: 691-12/TNOBĐM
        //
        // QUAN TRỌNG:
        // - Không lấy "Số hiệu bản sao"
        // - Không lấy số đứng trước nhãn "Số hiệu"
        // - Không làm mất "-" hoặc "/" của số vào sổ
        // - Ưu tiên giá trị nằm ngay sau nhãn
        // - Hỗ trợ OCR đảo thứ tự nhãn / giá trị
        // - Số vào sổ KHÔNG BAO GIỜ được trùng với số hiệu bằng (2 field
        //   khác nhau trên phôi bằng thật) — nếu trùng thì đó là dấu hiệu
        //   OCR/label bị đọc lệch, phải bỏ qua ứng viên đó, KHÔNG được
        //   "vá" bằng cách tự chế thêm ký tự (vd tự thêm "C") vào giá trị
        //   của số hiệu bằng để biến nó thành số vào sổ.
        // ================================================================


        // ================================================================
        // HÀM PHỤ 1: LÀM SẠCH SỐ HIỆU BẰNG
        // ================================================================

        $cleanDiplomaCode = function (?string $value): ?string {

            if ($value === null) {
                return null;
            }

            $value = trim($value);

            if ($value === '') {
                return null;
            }

            // Số hiệu bằng thường chỉ gồm chữ + số.
            // Bỏ khoảng trắng, "-", "/", ".", ":"...
            $value = preg_replace('/[^A-Za-z0-9]/', '', $value);

            if ($value === null || $value === '') {
                return null;
            }

            $value = strtoupper($value);

            // Một số lỗi OCR thường gặp
            $value = strtr($value, [
                'O' => '0',
                'I' => '1',
                'L' => '1',
            ]);

            return $value;
        };


        // ================================================================
        // HÀM PHỤ 2: LÀM SẠCH SỐ VÀO SỔ
        // ================================================================
        //
        // KHÔNG được dùng cleanDiplomaCode() ở đây.
        //
        // Vì số vào sổ có thể có:
        //
        //     691-12/TNOBĐM
        //     891-12/TNOBĐM
        //     C2024058
        //     2024058
        //
        // Phải giữ nguyên "-" và "/"
        // ================================================================

        $cleanRegistryCode = function (?string $value): ?string {

            if ($value === null) {
                return null;
            }

            $value = trim($value);

            if ($value === '') {
                return null;
            }

            // Xóa khoảng trắng nhưng GIỮ "-" và "/"
            $value = preg_replace('/\s+/u', '', $value);

            if ($value === null || $value === '') {
                return null;
            }

            // Bỏ dấu câu dư ở đầu/cuối
            $value = trim($value, ".,:;");

            if ($value === '') {
                return null;
            }

            $value = strtoupper($value);

            return $value;
        };


        // ================================================================
        // HÀM PHỤ 3: KIỂM TRA ỨNG VIÊN CÓ PHẢI MÃ SỐ HỢP LỆ KHÔNG
        // ================================================================

        $isValidDiplomaCandidate = function (?string $candidate) use (&$result, $cleanDiplomaCode): bool {

            if ($candidate === null) {
                return false;
            }

            $candidate = trim($candidate);

            if ($candidate === '') {
                return false;
            }

            // Không lấy dòng mô tả
            $normCandidate = trim($this->normalize($candidate));

            if (
                str_contains($normCandidate, 'ban sao')
                || str_contains($normCandidate, 'hieu truong')
                || str_contains($normCandidate, 'vao so')
                || str_contains($normCandidate, 'cap bang')
            ) {
                return false;
            }

            // Phải có số THẬT trong OCR gốc.
            // Không kiểm tra sau khi O/I/L đã bị đổi thành số.
            if (!preg_match('/\d/', $candidate)) {
                return false;
            }

            return true;
        };

        /**
         * HÀM PHỤ 4 (THÊM MỚI — FIX BUG "C001471"):
         *
         * Kiểm tra ứng viên có đang TRÙNG với diploma_number đã tìm được
         * hay không (so sánh sau khi làm sạch theo kiểu số hiệu bằng, vì
         * số hiệu bằng và số vào sổ có thể được OCR đọc lệch nhãn cho
         * nhau nhưng giá trị số thì giống hệt nhau).
         *
         * Case thực tế gây bug:
         *
         *     Số hiệu:
         *     001471
         *     171/C/2023
         *
         * Do dòng nhãn "Số vào sổ gốc cấp bằng..." bị OCR đọc nát thành
         * một câu vô nghĩa nhưng vẫn chứa cụm "vào số", hệ thống tưởng
         * đó là nhãn "Số vào sổ" thật, rồi quét các dòng lân cận và lấy
         * NHẦM "001471" (vốn là Số hiệu bằng) làm ứng viên Số vào sổ.
         * Vì "001471" là chuỗi số thuần 6 chữ số, code phía dưới tưởng
         * OCR làm rớt chữ "C" ở đầu số vào sổ nên tự thêm "C" vào ->
         * ra kết quả SAI "C001471", trong khi giá trị đúng "171/C/2023"
         * nằm ngay dòng kế tiếp lại không được xét tới vì vòng lặp đã
         * break sớm.
         *
         * Số hiệu bằng và Số vào sổ LUÔN LÀ 2 GIÁ TRỊ KHÁC NHAU trên
         * phôi bằng thật, nên nếu ứng viên trùng diploma_number, chắc
         * chắn đây là lấy nhầm dòng — phải bỏ qua để đi tiếp ứng viên
         * khác, KHÔNG được nhận rồi "vá" bằng cách tự thêm ký tự.
         */
        $isSameAsDiplomaNumber = function (?string $candidate) use (&$result, $cleanDiplomaCode): bool {

            if (empty($result['diploma_number']) || $candidate === null) {
                return false;
            }

            $cleaned = $cleanDiplomaCode($candidate);

            return $cleaned !== null && $cleaned === $result['diploma_number'];
        };


        // ================================================================
        // SỐ HIỆU BẰNG
        // ================================================================
        //
        // Case quan trọng:
        //
        //     20100
        //     Số hiệu
        //     009167
        //
        // Kết quả phải:
        //
        //     diploma_number = 009167
        //
        // KHÔNG được lấy 20100.
        //
        // ================================================================

        if (empty($result['diploma_number'])) {

            foreach ($lines as $i => $line) {

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $normLine = trim($this->normalize($line));


                // ------------------------------------------------------------
                // Bỏ hoàn toàn "Số hiệu bản sao"
                // ------------------------------------------------------------

                if (
                    str_contains($normLine, 'so hieu ban sao')
                    || str_contains($normLine, 'hieu ban sao')
                ) {
                    continue;
                }


                // ------------------------------------------------------------
                // Nhận diện nhãn Số hiệu
                // ------------------------------------------------------------

                $isDiplomaLabel =
                    str_contains($normLine, 'so hieu')
                    || str_contains($normLine, 'so hieu bang')
                    || str_contains($normLine, 'so hieu van bang')
                    || str_contains($normLine, 'hieu bang');


                // OCR đôi khi chỉ đọc:
                //
                //     hiệu:
                //
                if (
                    !$isDiplomaLabel
                    && preg_match('/^hieu\s*[:.]?$/u', $normLine)
                ) {
                    $isDiplomaLabel = true;
                }


                if (!$isDiplomaLabel) {
                    continue;
                }


                // ------------------------------------------------------------
                // 1. ƯU TIÊN GIÁ TRỊ NGAY TRÊN CÙNG DÒNG
                //
                // Ví dụ:
                //
                //     Số hiệu: 009167
                //
                // Phải lấy:
                //
                //     009167
                // ------------------------------------------------------------

                $sameLineCandidate = null;

                $labelPatterns = [
                    'so hieu van bang',
                    'so hieu bang',
                    'so hieu',
                    'hieu bang',
                    'hieu',
                ];

                foreach ($labelPatterns as $label) {

                    $pos = mb_stripos(
                        $normLine,
                        $label,
                        0,
                        'UTF-8'
                    );

                    if ($pos === false) {
                        continue;
                    }

                    $labelLength = mb_strlen(
                        $label,
                        'UTF-8'
                    );

                    $afterLabel = mb_substr(
                        $line,
                        $pos + $labelLength,
                        null,
                        'UTF-8'
                    );

                    $afterLabel = trim(
                        $afterLabel,
                        " \t:.-–"
                    );

                    if (
                        $afterLabel !== ''
                        && preg_match('/\d/', $afterLabel)
                    ) {
                        $sameLineCandidate = $afterLabel;
                        break;
                    }
                }


                // ------------------------------------------------------------
                // 2. TẠO DANH SÁCH ỨNG VIÊN
                //
                // Ưu tiên:
                //
                //     cùng dòng
                //     dòng sau
                //     dòng trước
                //     2 dòng sau
                //     2 dòng trước
                //
                // Điều này cực kỳ quan trọng với:
                //
                //     20100
                //     Số hiệu
                //     009167
                //
                // Vì dòng SAU "Số hiệu" mới là giá trị đúng.
                // ------------------------------------------------------------

                $allCandidates = [];

                if ($sameLineCandidate !== null) {
                    $allCandidates[] = $sameLineCandidate;
                }

                $candidateIndexes = [
                    $i + 1,
                    $i - 1,
                    $i + 2,
                    $i - 2,
                    $i + 3,
                    $i - 3,
                ];

                foreach ($candidateIndexes as $candidateIndex) {

                    if (!isset($lines[$candidateIndex])) {
                        continue;
                    }

                    $candidate = trim($lines[$candidateIndex]);

                    if ($candidate === '') {
                        continue;
                    }

                    $allCandidates[] = $candidate;
                }


                // ------------------------------------------------------------
                // 3. DUYỆT ỨNG VIÊN
                // ------------------------------------------------------------

                foreach ($allCandidates as $candidate) {

                    if (!$isValidDiplomaCandidate($candidate)) {
                        continue;
                    }

                    $cleaned = $cleanDiplomaCode($candidate);

                    if ($cleaned === null) {
                        continue;
                    }


                    // Số hiệu thường không quá dài
                    if (
                        strlen($cleaned) < 3
                        || strlen($cleaned) > 15
                    ) {
                        continue;
                    }


                    // Không nhận toàn chữ
                    if (preg_match('/^[A-Z]+$/', $cleaned)) {
                        continue;
                    }


                    $result['diploma_number'] = $cleaned;

                    break;
                }


                if (!empty($result['diploma_number'])) {
                    break;
                }
            }
        }


        // ================================================================
        // FALLBACK SỐ HIỆU BẰNG
        // ================================================================
        //
        // Hỗ trợ OCR đảo:
        //
        //     009167
        //     Số hiệu
        //
        // ================================================================

        if (empty($result['diploma_number'])) {

            foreach ($lines as $i => $line) {

                $normLine = trim(
                    $this->normalize($line)
                );

                if (
                    !str_contains($normLine, 'hieu')
                    && !str_contains($normLine, 'so hieu')
                ) {
                    continue;
                }

                // Tuyệt đối bỏ "hiệu bản sao"
                if (str_contains($normLine, 'ban sao')) {
                    continue;
                }


                // Ưu tiên dòng SAU trước
                $candidateIndexes = [
                    $i + 1,
                    $i - 1,
                    $i + 2,
                    $i - 2,
                    $i + 3,
                    $i - 3,
                ];


                foreach ($candidateIndexes as $candidateIndex) {

                    if (!isset($lines[$candidateIndex])) {
                        continue;
                    }

                    $candidate = trim($lines[$candidateIndex]);

                    if (!$isValidDiplomaCandidate($candidate)) {
                        continue;
                    }

                    $cleaned = $cleanDiplomaCode($candidate);

                    if ($cleaned === null) {
                        continue;
                    }

                    if (
                        strlen($cleaned) < 3
                        || strlen($cleaned) > 15
                    ) {
                        continue;
                    }

                    if (preg_match('/^[A-Z]+$/', $cleaned)) {
                        continue;
                    }


                    $result['diploma_number'] = $cleaned;

                    break;
                }


                if (!empty($result['diploma_number'])) {
                    break;
                }
            }
        }


        // ================================================================
        // SỐ VÀO SỔ CẤP BẰNG
        // ================================================================
        //
        // Case của Vy:
        //
        //     Số vào số cấp bằng 691-12/TNOBĐM:
        //
        // Phải lấy NGUYÊN:
        //
        //     691-12/TNOBĐM
        //
        // KHÔNG được lấy:
        //
        //     12/TNOBĐM
        //
        // KHÔNG được biến thành:
        //
        //     12TNOBĐM
        //
        // ================================================================

        if (empty($result['diploma_registry_number'])) {

            foreach ($lines as $i => $line) {

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $normLine = trim(
                    $this->normalize($line)
                );


                // ------------------------------------------------------------
                // Nhận diện nhãn số vào sổ
                // ------------------------------------------------------------

                $isRegistryLabel =
                    str_contains($normLine, 'so vao so cap bang')
                    || str_contains($normLine, 'vao so cap bang')
                    || str_contains($normLine, 'vao so cap')
                    || str_contains($normLine, 'so vao so')
                    || str_contains($normLine, 'vao so goc cap bang')
                    || str_contains($normLine, 'so vao goc cap bang')
                    || str_contains($normLine, 'vao so goc');


                if (!$isRegistryLabel) {
                    continue;
                }


                // ------------------------------------------------------------
                // 1. GIÁ TRỊ NẰM CÙNG DÒNG
                //
                // KHÔNG được dùng:
                //
                //     /^[^:\-–]*[:\-–]+(.+)$/
                //
                // vì dấu "-" có thể là một phần của mã.
                //
                // Ví dụ:
                //
                //     691-12/TNOBĐM
                //
                // ------------------------------------------------------------

                $sameLineCandidate = null;

                $labelPatterns = [
                    'so vao so cap bang',
                    'vao so cap bang',
                    'vao so cap',
                    'so vao so',
                    'vao so goc cap bang',
                    'so vao goc cap bang',
                    'vao so goc',
                ];


                foreach ($labelPatterns as $label) {

                    $pos = mb_stripos(
                        $normLine,
                        $label,
                        0,
                        'UTF-8'
                    );

                    if ($pos === false) {
                        continue;
                    }

                    $labelLength = mb_strlen(
                        $label,
                        'UTF-8'
                    );

                    $afterLabel = mb_substr(
                        $line,
                        $pos + $labelLength,
                        null,
                        'UTF-8'
                    );


                    // Chỉ bỏ ":" hoặc khoảng trắng ở đầu.
                    //
                    // KHÔNG bỏ "-" vì "-" có thể là một phần mã.
                    $afterLabel = trim(
                        $afterLabel,
                        " \t:."
                    );


                    if (
                        $afterLabel !== ''
                        && preg_match('/\d/', $afterLabel)
                    ) {
                        $sameLineCandidate = $afterLabel;
                        break;
                    }
                }


                // ------------------------------------------------------------
                // 2. DANH SÁCH ỨNG VIÊN
                // ------------------------------------------------------------

                $allCandidates = [];

                if ($sameLineCandidate !== null) {
                    $allCandidates[] = $sameLineCandidate;
                }


                $candidateIndexes = [
                    $i + 1,
                    $i - 1,
                    $i + 2,
                    $i - 2,
                    $i + 3,
                    $i - 3,
                ];


                foreach ($candidateIndexes as $candidateIndex) {

                    if (!isset($lines[$candidateIndex])) {
                        continue;
                    }

                    $candidate = trim(
                        $lines[$candidateIndex]
                    );

                    if ($candidate === '') {
                        continue;
                    }

                    $allCandidates[] = $candidate;
                }


                // ------------------------------------------------------------
                // 3. XỬ LÝ ỨNG VIÊN
                // ------------------------------------------------------------

                foreach ($allCandidates as $candidate) {

                    if (!$isValidDiplomaCandidate($candidate)) {
                        continue;
                    }


                    // FIX (bug "C001471"): không chấp nhận ứng viên trùng
                    // với diploma_number — 2 field này không bao giờ giống
                    // nhau trên phôi bằng thật, trùng nghĩa là lấy nhầm dòng.
                    if ($isSameAsDiplomaNumber($candidate)) {
                        continue;
                    }


                    // QUAN TRỌNG:
                    // số vào sổ dùng cleanRegistryCode()
                    //
                    // Không được dùng cleanDiplomaCode()
                    // vì nó xóa "-" và "/".
                    $cleaned = $cleanRegistryCode($candidate);


                    if ($cleaned === null) {
                        continue;
                    }


                    // Số vào sổ thường có ít nhất 3 ký tự
                    if (
                        strlen($cleaned) < 3
                        || strlen($cleaned) > 30
                    ) {
                        continue;
                    }


                    // Phải có ít nhất một chữ số thật
                    if (!preg_match('/\d/', $candidate)) {
                        continue;
                    }


                    // --------------------------------------------------------
                    // Nếu mã chỉ toàn số:
                    //
                    //     2024058
                    //
                    // thì giữ logic cũ:
                    //
                    //     C2024058
                    //
                    // Nhưng nếu đã có "-" hoặc "/" thì KHÔNG thêm C.
                    // --------------------------------------------------------

                    if (
                        preg_match('/^\d{6,10}$/', $cleaned)
                    ) {
                        $cleaned = 'C' . $cleaned;
                    }


                    $result['diploma_registry_number'] = $cleaned;

                    break;
                }


                if (!empty($result['diploma_registry_number'])) {
                    break;
                }
            }
        }


        // ================================================================
        // FALLBACK: OCR ĐẢO THỨ TỰ NHÃN / GIÁ TRỊ
        // ================================================================
        //
        // Ví dụ:
        //
        //     691-12/TNOBĐM
        //     Số vào số cấp bằng
        //
        // Hoặc:
        //
        //     2024058
        //     Vào số gốc cấp bằng
        //
        // ================================================================

        if (empty($result['diploma_registry_number'])) {

            foreach ($lines as $i => $line) {

                $normLine = trim(
                    $this->normalize($line)
                );


                if (
                    !str_contains($normLine, 'vao so')
                    && !str_contains($normLine, 'vao so goc')
                    && !str_contains($normLine, 'so vao so')
                ) {
                    continue;
                }


                $candidateIndexes = [
                    $i - 1,
                    $i + 1,
                    $i - 2,
                    $i + 2,
                    $i - 3,
                    $i + 3,
                    $i - 4,
                    $i + 4,
                ];


                foreach ($candidateIndexes as $candidateIndex) {

                    if (!isset($lines[$candidateIndex])) {
                        continue;
                    }


                    $candidate = trim(
                        $lines[$candidateIndex]
                    );

                    if ($candidate === '') {
                        continue;
                    }


                    $normCandidate = trim(
                        $this->normalize($candidate)
                    );


                    // Không lấy lại dòng nhãn
                    if (
                        str_contains($normCandidate, 'vao so')
                        || str_contains($normCandidate, 'cap bang')
                        || str_contains($normCandidate, 'ban sao')
                        || str_contains($normCandidate, 'hieu truong')
                    ) {
                        continue;
                    }


                    // Phải có số thật trong OCR gốc
                    if (!preg_match('/\d/', $candidate)) {
                        continue;
                    }


                    // FIX (bug "C001471"): case thực tế —
                    //
                    //     Được quét bằng Cam Sícaring vào số gốp bằng tối
                    //     nghiệp -11 nczozs        <- bị coi nhầm là nhãn
                    //     Số hiệu:
                    //     001471                   <- BỊ LẤY NHẦM ở đây
                    //     171/C/2023               <- giá trị ĐÚNG, không
                    //                                 bao giờ được xét tới
                    //                                 vì vòng lặp break sớm
                    //
                    // "001471" trùng diploma_number nên chắc chắn là lấy
                    // nhầm dòng "Số hiệu" — bỏ qua để đi tiếp tới ứng viên
                    // kế tiếp (ở đây là "171/C/2023").
                    if ($isSameAsDiplomaNumber($candidate)) {
                        continue;
                    }


                    $cleaned = $cleanRegistryCode($candidate);

                    if ($cleaned === null) {
                        continue;
                    }


                    if (
                        strlen($cleaned) < 3
                        || strlen($cleaned) > 30
                    ) {
                        continue;
                    }


                    // Chỉ thêm C khi giá trị là số thuần.
                    if (
                        preg_match('/^\d{6,10}$/', $cleaned)
                    ) {
                        $cleaned = 'C' . $cleaned;
                    }


                    $result['diploma_registry_number'] = $cleaned;

                    break 2;
                }
            }
        }
        // ================================================================
        // FALLBACK CUỐI: TÌM MÃ SỐ VÀO SỔ GẦN "SỐ HIỆU"
        // ================================================================
        //
        // Một số bằng có bố cục:
        //
        //     Số hiệu
        //     001471
        //     Số vào sổ gốc cấp bằng tốt nghiệp
        //     171/TC/2023
        //
        // Nhưng OCR có thể làm mất hoàn toàn dòng nhãn "Số vào sổ..."
        // và đọc thành:
        //
        //     Số hiệu
        //     001471
        //     175002013
        //
        // Trường hợp này chỉ lấy ứng viên nằm SAU số hiệu.
        //
        // Lưu ý: đoạn này KHÔNG tự đoán lại số thật.
        // Nếu OCR đọc 175002013 thì chỉ lưu giá trị OCR đọc được.

        if (empty($result['diploma_registry_number'])) {

            foreach ($lines as $i => $line) {

                $normLine = trim($this->normalize($line));

                $isDiplomaLabel =
                    str_contains($normLine, 'so hieu')
                    || preg_match('/^hieu\s*[:.]?$/u', $normLine);

                if (!$isDiplomaLabel) {
                    continue;
                }

                // Tìm các dòng phía sau "Số hiệu"
                for ($offset = 2; $offset <= 5; $offset++) {

                    $candidateIndex = $i + $offset;

                    if (!isset($lines[$candidateIndex])) {
                        continue;
                    }

                    $candidate = trim($lines[$candidateIndex]);

                    if ($candidate === '') {
                        continue;
                    }

                    // Chỉ nhận chuỗi có số
                    if (!preg_match('/\d/', $candidate)) {
                        continue;
                    }

                    $normCandidate = trim(
                        $this->normalize($candidate)
                    );

                    // Bỏ các dòng chữ mô tả
                    if (
                        str_contains($normCandidate, 'hieu truong')
                        || str_contains($normCandidate, 'cap bang')
                        || str_contains($normCandidate, 'ban sao')
                    ) {
                        continue;
                    }

                    // FIX (bug "C001471"): không nhận ứng viên trùng
                    // diploma_number ở fallback này nữa — cùng lý do như
                    // 2 chỗ trên. Với offset bắt đầu từ 2, ứng viên đầu
                    // tiên (offset=2) thường CHÍNH LÀ dòng chứa số hiệu
                    // (i+1 là dòng số hiệu, i+2 là dòng kế tiếp — tuỳ bố
                    // cục có thể trùng), nên vẫn cần chặn ở đây để an toàn.
                    if ($isSameAsDiplomaNumber($candidate)) {
                        continue;
                    }

                    $cleaned = $cleanRegistryCode($candidate);

                    if ($cleaned === null) {
                        continue;
                    }

                    if (
                        strlen($cleaned) < 5
                        || strlen($cleaned) > 30
                    ) {
                        continue;
                    }

                    // Phải có số
                    if (!preg_match('/\d/', $cleaned)) {
                        continue;
                    }

                    $result['diploma_registry_number'] = $cleaned;

                    break 2;
                }
            }
        }
        // ================================================================
        // FALLBACK RIÊNG CHO PHÔI BẰNG ĐẠI HỌC BÁCH KHOA (ĐHQG-HCM)
        // ================================================================
        //
        // Case thực tế: nhãn thật là "Số đăng ký : 071/A152" nhưng OCR đọc
        // lệch dấu ":" thành "1." (VietOCR nhầm ký tự), ra "Số đăng ký
        // 1.071/A152". Pattern CŨ chỉ cho phép ":" hoặc khoảng trắng ngay
        // sau nhãn ([:\s]*) nên gặp "1." là gãy luôn, field bị bỏ trống im
        // lặng dù giá trị thật (071/A152) vẫn nằm ngay đó.
        //
        // FIX: nới phần phân cách thành "vùng đệm rác tối đa 10 ký tự"
        // ([^\n]{0,10}?, non-greedy) thay vì neo cứng ký tự cụ thể, để
        // regex tự tìm tới đúng cụm "số/số-hoặc-chữ" thật sự bất kể OCR
        // chèn thêm ký tự rác gì ở giữa.
        if (empty($result['diploma_registry_number'])) {
            if (preg_match(
                '/S[ốo]\s*đăng\s*k[ýy][^\n]{0,10}?(\d{1,4}\s*\/\s*[A-Za-z0-9]+)/iu',
                $text,
                $m
            )) {
                $candidate = preg_replace('/\s+/u', '', $m[1]);
                if (!$isSameAsDiplomaNumber($candidate)) {
                    $result['diploma_registry_number'] = strtoupper($candidate);
                }
            }
        }

        if (empty($result['diploma_number'])) {
            if (preg_match('/\bBB[\s\/]*([0-9]{4,6}\/[0-9]{1,3}[A-Z]{1,4}[0-9]?\/[0-9]{4})/iu', $text, $m)) {
                $result['diploma_number'] = 'BB/' . trim($m[1]);
            }
        }

        // Dự phòng: một số bằng chữ tiếng Việt "Số đăng ký" có thể bị OCR đọc
        // sai/rớt hoàn toàn, nhưng nhãn tiếng Anh song ngữ "Registration N°"
        // đi kèm giá trị vẫn đọc được — khớp thêm trường hợp này.
        if (empty($result['diploma_registry_number'])) {
            if (preg_match('/Registration\s*N[o°ơ]?[.:\s]*([0-9]+\s*\/\s*[A-Za-z0-9]+)/iu', $text, $m)) {
                $candidate = preg_replace('/\s+/u', '', $m[1]);
                if (!$isSameAsDiplomaNumber($candidate)) {
                    $result['diploma_registry_number'] = strtoupper($candidate);
                }
            }
        }


        // ================================================================
        // KẾT THÚC SỐ HIỆU BẰNG + SỐ VÀO SỔ BẰNG
        // ================================================================

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
        if (preg_match(
            '/([A-ZÀ-Ỹ][^\n]{1,40}?)[ \t]*,?[ \t]*ng[àa]y[\s\.]*\d{1,2}[\s\.]*th[áa]ng[\s\.]*\d{1,2}[\s\.]*n[ăa]m[\s\.]*\d{4}/iu',
            $text,
            $m
        )) {
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