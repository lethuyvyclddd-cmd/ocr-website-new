<?php

namespace App\Services\Extractors;

class AdmissionFormExtractor extends BaseExtractor
{
    /**
     * ========================================================================
     * CHUẨN HOÁ TRƯỚC KHI SO KHỚP (fix hệ thống, khoan dung lỗi thiếu/sai dấu
     * tiếng Việt từ OCR/DOCX, vd: "cao đẳng" -> "cao đảng", "Xếp loại" ->
     * "Kếp loai"...).
     *
     * So khớp một regex KHÔNG DẤU/viết thường trên bản đã chuẩn hoá của
     * $text, nhưng trả về các đoạn khớp được LẤY TỪ TEXT GỐC (giữ dấu nếu
     * OCR đọc đúng phần đó), bằng cách ánh xạ lại vị trí offset từ bản
     * chuẩn hoá sang bản gốc.
     *
     * $pattern phải là 1 pattern PCRE đầy đủ (có '/.../ u'), nội dung viết
     * bằng chữ thường KHÔNG DẤU (vì $this->normalize() đã đưa text về dạng
     * đó trước khi so khớp).
     *
     * Giả định: normalize() giữ nguyên SỐ LƯỢNG ký tự (1 ký tự có dấu -> 1
     * ký tự không dấu), nên vị trí ký tự tìm được trên bản chuẩn hoá áp
     * dụng thẳng được vào text gốc.
     *
     * (Cùng kỹ thuật với DiplomaExtractor::fuzzyMatch() - tạm thời để riêng
     * ở đây vì BaseExtractor chưa có, có thể gộp lên BaseExtractor sau để
     * tránh trùng lặp.)
     *
     * @return array<int|string, string|null>|null
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

            if ($byteOffset < 0) {
                $mapped[$key] = null;
                continue;
            }

            $charOffset = mb_strlen(substr($normalizedText, 0, $byteOffset), 'UTF-8');
            $charLen = mb_strlen($value, 'UTF-8');

            $mapped[$key] = $charLen > 0
                ? mb_substr($text, $charOffset, $charLen, 'UTF-8')
                : '';
        }

        return $mapped;
    }

    /**
     * Ánh xạ 1 chuỗi trình độ đọc được từ OCR (có thể thiếu/sai dấu, vd:
     * "trung cap", "cao dang") về đúng dạng chuẩn có dấu. Nếu không khớp
     * mục nào đã biết, giữ nguyên bản gốc.
     */
    protected function normalizeLevel(string $raw): string
    {
        $norm = $this->normalize($raw);

        $map = [
            'trung cap' => 'Trung cấp',
            'cao dang' => 'Cao đẳng',
            'dai hoc' => 'Đại học',
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

        $lines = array_values(
            array_filter(
                array_map('trim', explode("\n", $text))
            )
        );

        if (preg_match('/từ xa năm[:\s]+(\d{4})/iu', $text, $m)) {
            $result['admission_year'] = $m[1];
        }

        // 1. Họ và tên thí sinh
        if (preg_match(
            '/Họ và tên thí sinh[:\s]+(.+?)(?=\s*(?:Nam,?\s*nữ|Giới\s*tính)\s*[:\-]|\n|$)/iu',
            $text,
            $m
        )) {
            $result = array_merge(
                $result,
                $this->splitFullName(trim($m[1]))
            );
        }

        // 2. Giới tính
        if (preg_match(
            '/(?:Nam,?\s*nữ|Giới\s*tính)\s*[:\-]?\s*(Nam|Nữ)/iu',
            $text,
            $m
        )) {
            $result['gender'] =
                mb_strtolower($m[1], 'UTF-8') === 'nam'
                    ? 'Nam'
                    : 'Nữ';
        }

        // 2. Ngành đăng ký xét tuyển / dự tuyển
        // Dùng fuzzyMatch để khoan dung OCR sai dấu, và chấp nhận cả 2 cách
        // diễn đạt "xét tuyển" (form thường/từ xa) lẫn "dự tuyển" (form Văn bằng 2).
        if ($fm = $this->fuzzyMatch($text, '/nganh\s*dang\s*ky\s*(?:xet|du)\s*tuyen[:\s]+([^\n]+)/u')) {
            $major = trim(rtrim(trim($fm[1]), '.'));

            // Cắt bỏ cụm "Văn bằng 2" / "Văn bằng hai" ở đầu nếu có — đây là
            // LOẠI HÌNH đào tạo (tên mẫu phiếu), không phải tên ngành thật.
            // Ngành thật là phần còn lại phía sau, vd:
            // "Văn bằng 2 Ngôn Ngữ Anh" -> "Ngôn Ngữ Anh"
            $major = preg_replace('/^văn\s*bằng\s*(2|hai|ii)\s+/iu', '', $major);

            $result['major_name'] = trim($major);
        }

        // 3. Ngày sinh / Nơi sinh / Dân tộc
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $text, $m)) {
            $result['birth_date'] = $this->toDbDate($m[1]);
        }
        // FIX: bản trước đòi hỏi >= 2 khoảng trắng liên tiếp ngay trước "Dân
        // tộc" ([ \t]{2,}) — giả định sai rằng layout luôn có khoảng cách rộng
        // kiểu bảng biểu. Thực tế nhiều form/OCR chỉ ra ĐÚNG 1 khoảng trắng
        // đơn giữa các trường trên cùng 1 dòng (vd: "...An Giang Dân tộc:
        // Kinh"), khiến regex không bao giờ khớp và place_of_birth luôn rỗng
        // mà không báo lỗi gì.
        //
        // Đồng thời chuyển sang fuzzyMatch() để khoan dung lỗi dấu của chính
        // nhãn "Nơi sinh" / "Dân tộc" (vd OCR đọc thành "Noi sinh", "Dan tôc"),
        // và xử lý tường minh cụm chú thích "(ghi tỉnh)" hay chen giữa nhãn và
        // dấu ":".
        if ($fm = $this->fuzzyMatch(
            $text,
            '/noi\s*sinh\s*(?:\([^)]*\))?\s*[:\s]+\s*([^\n]+?)\s*dan\s*toc/u'
        )) {
            $rawPlaceOfBirth = trim($fm[1]);

            $province = \App\Services\ProvinceMergeMapper::extractProvinceFromText(
                $rawPlaceOfBirth
            );

            if ($province) {
                $result['place_of_birth'] = $province;
            }
        } elseif ($fm = $this->fuzzyMatch(
            // Fallback: một số form không có nhãn "Dân tộc" ngay sau, hoặc OCR
            // làm mất hẳn nhãn đó trên cùng dòng — khi ấy chỉ cắt tới cuối dòng.
            $text,
            '/noi\s*sinh\s*(?:\([^)]*\))?\s*[:\s]+\s*([^\n]+)/u'
        )) {
            $rawPlaceOfBirth = trim($fm[1]);

            $province = \App\Services\ProvinceMergeMapper::extractProvinceFromText(
                $rawPlaceOfBirth
            );

            if ($province) {
                $result['place_of_birth'] = $province;
            }
        }
        if (preg_match('/Dân\s*tộc[:\s]+([^\s\n]+)/iu', $text, $m)) {
            $result['ethnic'] = trim(rtrim(trim($m[1]), '.'));
        }

        // 4. CCCD số
        if (preg_match('/(?<!\d)(\d{12})(?!\d)/', preg_replace('/\s+/', '', $text), $m)) {
            $result['id_number'] = $m[1];
        }

        // 5. Hộ khẩu thường trú (dùng fuzzyMatch vì OCR hay đọc sai dấu:
        // "khẩu" -> "khầu")
        if ($fm = $this->fuzzyMatch($text, '/ho\s*khau\s*thuong\s*tru[:\s]+([^\n]+)/u')) {
            $address = trim(rtrim(trim($fm[1]), '.'));
            $result['permanent_address'] = $address;
            $result = array_merge($result, $this->splitAddress($address));
        }

        // 6. Trường/Tỉnh THPT (dòng năm lớp 12)
        if (preg_match('/Năm lớp 12[:\s]+([^\n]+?)[ \t]+Tỉnh\s*\/\s*TP[:\s]+([^\n]+)/iu', $text, $m)) {
            $result['highschool_name'] = trim($m[1]);
            $result['highschool_province_name'] = trim(rtrim(trim($m[2]), '.'));
        }

        // 7. Năm tốt nghiệp THPT
        if (preg_match('/tốt nghiệp THPT[^\n]*?[:\s](\d{4})/iu', $text, $m)) {
            $result['highschool_graduation_year'] = $m[1];
        }

        // 8. Học lực / Hạnh kiểm lớp 12
        if (preg_match('/Học lực[:\s]+([^\n]+?)[ \t]+Hạnh\s*kiểm[:\s]*([^\n]+)/iu', $text, $m)) {
            $result['highschool_academic_rank'] = trim(rtrim(trim($m[1]), '.'));
            $result['highschool_conduct_rank'] = trim(rtrim(trim($m[2]), '.'));
        }

        // 9. Năm tốt nghiệp trung cấp/CĐ
        if ($fm = $this->fuzzyMatch(
            $text,
            '/tot\s*nghiep\s*trung\s*cap,?\s*cao\s*dang[:\s]+(\d{4})/u'
        )) {
            $result['university_graduation_year'] = $fm[1];
        }

        // ===== Ngành tốt nghiệp + TRÌNH ĐỘ (HƯỚNG #1, đọc từ nhãn thay vì
        // đoán ô tick) =====
        //
        // Nhãn "Ngành tốt nghiệp:" thường đi kèm TRÌNH ĐỘ ngay trước tên
        // ngành, vd "Ngành tốt nghiệp: Trung cấp Tin Học." — đây là nguồn
        // ĐÁNG TIN CẬY NHẤT để biết trình độ, vì nó nói rõ bằng chữ, không
        // phải suy đoán từ ký hiệu tick (☒/X...) rất dễ bị OCR đọc sai
        // thành chữ rác (vd "Bì", "Ở" như thực tế đã gặp). Ưu tiên lấy
        // trình độ từ đây trước, đồng thời tách trình độ ra khỏi tên ngành
        // (bản trước để lẫn "Trung cấp" vào $priorMajor luôn, làm bẩn tên
        // ngành).
        $priorMajor = null;
        $level = null;

        if ($fm = $this->fuzzyMatch($text, '/nganh\s*tot\s*nghiep[:\s]+([^\n]+)/u')) {
            $rawAfterLabel = trim(rtrim(trim($fm[1]), '.'));

            if (preg_match('/^(Trung\s*c[aấáàảãạăằắặẳẵâầấậẩẫ]p|Cao\s*đ[aăâeêuư]ng|Đ[aạ]i\s*h[oọ]c)\s+(.+)$/iu', $rawAfterLabel, $mm)) {
                $level = $this->normalizeLevel($mm[1]);
                $priorMajor = trim($mm[2]);
            } else {
                $priorMajor = $rawAfterLabel;
            }
        }

        // Fallback 1: dấu tích ở 1 trong 3 dòng checkbox "Trung cấp / Cao
        // đẳng / Đại học". CHỈ dùng khi nhãn "Ngành tốt nghiệp" ở trên
        // không có (hoặc không xác định được trình độ từ đó).
        //
        // FIX: bản trước dùng stripos() so khớp CHÍNH XÁC có dấu — nếu OCR
        // đọc "Cao đẳng" sai dấu thành "Cao đảng" thì dòng đó bị bỏ qua
        // hoàn toàn (im lặng, không lỗi). Đổi sang so khớp trên bản chuẩn
        // hoá (normalize) để khoan dung mọi kiểu sai dấu.
        if (! $level) {
            foreach ($lines as $line) {
                $normLine = $this->normalize($line);

                foreach (['Trung cấp' => 'trung cap', 'Cao đẳng' => 'cao dang', 'Đại học' => 'dai hoc'] as $lvl => $normLvl) {
                    $pos = mb_strpos($normLine, $normLvl);

                    if ($pos === false) {
                        continue;
                    }

                    // Lấy phần TRƯỚC nhãn trên dòng GỐC (giả định normalize()
                    // giữ nguyên số lượng ký tự, nên vị trí $pos áp dụng
                    // thẳng được vào $line gốc).
                    $beforeMark = trim(mb_substr($line, 0, $pos, 'UTF-8'));

                    if ($beforeMark !== '' && preg_match('/^(IXI|X|\[X\]|☒|✓|✗)/iu', $beforeMark)) {
                        $level = $lvl;
                        break 2;
                    }
                }
            }
        }

        // Fallback 2 (kém tin cậy nhất): quét toàn văn bản tìm từ khoá
        // trình độ bất kỳ đâu. Trước đây dùng char class dấu liệt kê thủ
        // công (vd C[ÁA]P chỉ nhận á/A, KHÔNG nhận ấ) nên "Trung cấp" viết
        // đúng chính tả bị trượt trong khi "Cao đảng" (OCR gõ sai dấu) lại
        // lọt qua elseif kế tiếp — đây chính là nguyên nhân Ghi chú 2 bị
        // ghi nhầm "Cao đẳng" dù văn bản ghi rõ "Trung cấp". Đổi sang
        // fuzzyMatch (chuẩn hoá không dấu) để không còn phụ thuộc liệt kê
        // thủ công từng biến thể dấu.
        if (! $level) {
            if ($this->fuzzyMatch($text, '/trung\s*cap/u')) {
                $level = 'Trung cấp';
            } elseif ($this->fuzzyMatch($text, '/cao\s*dang/u')) {
                $level = 'Cao đẳng';
            } elseif ($this->fuzzyMatch($text, '/dai\s*hoc/u')) {
                $level = 'Đại học';
            }
        }

        // Trường cấp bằng + Học lực (của bằng trung cấp/CĐ/ĐH trước đó)
        if (preg_match('/Trường cấp bằng[:\s]+([^\n]+?)[ \t]+Học lực[:\s]+([^\n]+)/iu', $text, $m)) {
            $result['university_name'] = trim($m[1]);
            $result['classification'] = trim(rtrim(trim($m[2]), '.'));
        }

        // Ghi chú 2 = Trình độ + Ngành tốt nghiệp (trước đó)
        if ($level || $priorMajor) {
            $result['note_2'] = trim(($level ?? '') . ' ' . ($priorMajor ?? ''));
        }

        // 12. Điện thoại
        if (preg_match('/ĐTDĐ của bản thân[:\s]+(\d[\d\s]*)/iu', $text, $m)) {
            $result['phone_1'] = preg_replace('/\D/', '', $m[1]);
        }
        if (preg_match('/Điện thoại liên lạc của gia đ[ìi]nh[:\s]+(\d[\d\s]*)/iu', $text, $m)) {
            $result['phone_2'] = preg_replace('/\D/', '', $m[1]);
        }

        return $result;
    }
}