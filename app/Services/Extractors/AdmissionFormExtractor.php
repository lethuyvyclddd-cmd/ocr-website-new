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
        //
        // FIX (bug "Họ"/"Tên" luôn trống): pattern CŨ dùng preg_match()
        // thường, đòi hỏi khớp CHÍNH XÁC cụm "thí sinh" có dấu. OCR thực
        // tế có thể đọc sai dấu thành "thì sinh" (ì thay vì í), khiến
        // regex không bao giờ khớp dù dòng vẫn đọc được rõ ràng.
        //
        // FIX: chuyển sang fuzzyMatch() (so khớp trên bản không dấu) như
        // các field khác đã được vá trong file này.
        if ($fm = $this->fuzzyMatch(
            $text,
            '/ho\s*va\s*ten\s*thi\s*sinh\s*[:,]?\s*(.+?)(?=\s*(?:nam,?\s*nu|gioi\s*tinh)\s*[:\-]|\n|$)/u'
        )) {
            $result = array_merge(
                $result,
                $this->splitFullName(trim(rtrim(trim($fm[1]), '.')))
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
        //
        // FIX (bug "Tên Ngành" = "3. Họ và tên thí sinh: ..."):
        //
        // Pattern CŨ dùng "[:\s]+" làm phần phân cách ngay sau nhãn. Vì
        // "\s" trong PCRE khớp CẢ ký tự xuống dòng (\n), nên khi thí sinh
        // ĐỂ TRỐNG mục này (chỉ có nhãn, không có giá trị — case thực tế
        // của phiếu "Văn bằng 2": dòng "2. Ngành đăng ký dự tuyển:" rồi
        // xuống dòng ngay, ngành thật được ghi ở mục 10 "Ngành tốt
        // nghiệp"), regex "nuốt" luôn dấu ":" + ký tự xuống dòng, rồi lấy
        // NHẦM TOÀN BỘ dòng câu hỏi KẾ TIẾP ("3. Họ và tên thí sinh: CHU
        // THị HồNG Hảo") làm tên ngành.
        //
        // FIX: đổi phần phân cách sang chỉ chấp nhận khoảng trắng NGANG
        // (space/tab) + dấu ":" — KHÔNG cho khớp "\n" — để giá trị bắt
        // buộc phải nằm CÙNG DÒNG với nhãn, không bao giờ tràn sang dòng
        // sau. Đồng thời validate kết quả: bỏ qua nếu rỗng (mục để trống
        // — hợp lệ, không gán gì cả) hoặc trông giống 1 câu hỏi khác (đề
        // phòng các biến thể OCR/layout khác vẫn có thể lộ giá trị sai
        // dòng theo cách khác).
        if ($fm = $this->fuzzyMatch(
            $text,
            '/nganh\s*dang\s*ky\s*(?:xet|du)\s*tuyen\s*:?[ \t]*([^\n]*)/u'
        )) {
            $major = trim(rtrim(trim($fm[1]), '.'));

            // FIX (bug "Tên Ngành" = "ngành Luật" thay vì "Luật"):
            //
            // OCR đôi khi lặp lại chữ "ngành" ngay đầu giá trị, vì bản
            // thân câu hỏi cũng chứa từ đó (vd: "Ngành đăng ký xét tuyển:
            // ngành Luật"). Nhãn của ta chỉ nuốt tới dấu ":" nên phần rác
            // "ngành " lặp lại vẫn còn dính lại trong giá trị capture
            // được. Cắt bỏ tiền tố này TRƯỚC khi cắt "Văn bằng 2" (2 bước
            // độc lập, không loại trừ lẫn nhau).
            $major = trim(preg_replace('/^ng[àa]nh\s+/iu', '', $major));

            // Cắt bỏ cụm "Văn bằng 2" / "Văn bằng hai" ở đầu nếu có — đây là
            // LOẠI HÌNH đào tạo (tên mẫu phiếu), không phải tên ngành thật.
            // Ngành thật là phần còn lại phía sau, vd:
            // "Văn bằng 2 Ngôn Ngữ Anh" -> "Ngôn Ngữ Anh"
            $major = trim(preg_replace('/^văn\s*bằng\s*(2|hai|ii)\s+/iu', '', $major));

            $normMajor = $this->normalize($major);

            // Ứng viên KHÔNG hợp lệ nếu: rỗng (mục để trống), hoặc bắt
            // đầu bằng số thứ tự câu hỏi kiểu "3.", hoặc chứa nhãn của
            // các câu hỏi khác (dấu hiệu bị lấy nhầm dòng).
            $looksLikeAnotherQuestion =
                $major === ''
                || preg_match('/^\d+\s*[\.\)]/', $major)
                || str_contains($normMajor, 'ho va ten')
                || str_contains($normMajor, 'gioi tinh')
                || str_contains($normMajor, 'ngay thang nam sinh')
                || str_contains($normMajor, 'noi sinh');

            if (! $looksLikeAnotherQuestion) {
                $result['major_name'] = $major;
            }
        }

        // 3. Ngày sinh / Nơi sinh / Dân tộc
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $text, $m)) {
            $result['birth_date'] = $this->toDbDate($m[1]);
        }

        // FIX (bug "Nơi sinh" luôn rỗng): pattern CŨ đòi hỏi ÍT NHẤT 2
        // khoảng trắng liên tiếp ([ \t]{2,}) ngay trước "Dân tộc" — giả
        // định sai rằng layout luôn có khoảng cách rộng kiểu bảng biểu.
        // Thực tế nhiều form/OCR chỉ ra ĐÚNG 1 khoảng trắng đơn giữa các
        // trường trên cùng 1 dòng (vd: "...An Giang Dân tộc: Kinh"),
        // khiến regex không bao giờ khớp và place_of_birth luôn rỗng mà
        // không báo lỗi gì.
        //
        // FIX: chuyển sang fuzzyMatch() để khoan dung lỗi dấu của chính
        // nhãn "Nơi sinh" / "Dân tộc" (OCR có thể đọc thành "Noi sinh",
        // "Dan tôc"...), xử lý tường minh cụm chú thích "(ghi tỉnh)" hay
        // chen giữa nhãn và dấu ":", và chỉ cần >= 1 khoảng trắng trước
        // "Dân tộc" thay vì >= 2. Có thêm fallback khi không tìm thấy
        // nhãn "Dân tộc" ngay sau (một số form/OCR có thể làm mất hẳn
        // nhãn đó).
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
        //
        // FIX (bug "Địa Chỉ Thường trú" dính rác kiểu ". man 191.71..."):
        // Pattern CŨ dùng "[:\s]+" làm phần phân cách ngay sau nhãn — class
        // này CHỈ chứa dấu ":" và khoảng trắng. Nếu ngay sau dấu ":" mà
        // OCR chèn thêm ký tự rác không phải khoảng trắng (vd dấu chấm
        // "." do đọc nhầm 1 ký hiệu/gạch đầu dòng viết tay), regex dừng
        // khớp NGAY TẠI đó và bắt đầu capture từ chính ký tự rác đó, kéo
        // theo toàn bộ phần rác vào giá trị địa chỉ.
        //
        // FIX: mở rộng phần phân cách để cũng nuốt luôn các ký tự rác phổ
        // biến hay xuất hiện ngay sau dấu ":" do OCR (., -, •, *, khoảng
        // trắng), giúp giá trị capture bắt đầu đúng từ nội dung địa chỉ
        // thật thay vì từ ký tự rác.
        if ($fm = $this->fuzzyMatch(
            $text,
            '/ho\s*khau\s*thuong\s*tru\s*:?[\s\.\-•\*]*([^\n]+)/u'
        )) {
            $address = trim(rtrim(trim($fm[1]), '.'));
            $result['permanent_address'] = $address;
            $result = array_merge($result, $this->splitAddress($address));
        }

        // 6. Trường/Tỉnh THPT (dòng năm lớp 12)
        //
        // FIX (bug "Tên Trường THPT" / "Tên Tỉnh THPT" luôn rỗng): pattern
        // CŨ bắt buộc phải có cụm "Tỉnh/TP" nằm ngay trên CÙNG DÒNG với
        // "Năm lớp 12", nếu không khớp thì KHÔNG gán gì cả — kể cả tên
        // trường. Thực tế nhiều phiếu (như phiếu Văn bằng 2) chỉ ghi tên
        // trường ở dòng lớp 12 mà không lặp lại "Tỉnh/TP" ngay sau (khác
        // với dòng lớp 10/11), khiến cả 2 field bị bỏ trống oan dù tên
        // trường vẫn đọc được.
        //
        // FIX: tách thành 2 bước độc lập — luôn lấy tên trường nếu có
        // dòng "Năm lớp 12", và CHỈ gán thêm tỉnh nếu tìm thấy nhãn
        // "Tỉnh/TP" theo sau (không bắt buộc phải cùng dòng).
        // Bắt buộc dòng phải chứa "Trường" phía sau để không dính nhầm dòng
        // "Kết quả học tập năm lớp 12: Học lực..."
        //
        // FIX 2 (bug "Tên Trường THPT" dính luôn phần "Tỉnh/TP..."):
        // capture CŨ ([^\n]+) tham lam, ăn hết tới cuối dòng — nếu cùng
        // dòng có cả tên trường lẫn "Tỉnh/TP..." (case phổ biến, vd:
        // "Trường THPT Nguyễn Đình Chiều Tỉnh/TP.Vĩnh Long (Bến Tre Cũ)")
        // thì tên trường bị dính luôn phần tỉnh vào sau. Đổi capture
        // thành không tham lam và dừng lại TRƯỚC cụm "Tỉnh/TP" nếu có mặt
        // trên cùng dòng, hoặc tới hết dòng nếu không có.
        if (preg_match(
            '/Năm\s*lớp\s*12[:\.\s]+(?=[^\n]*Tr[ưu]ờng)([^\n]+?)(?=\s*T[ỉi]nh\s*\/\s*TP|\s*$)/iu',
            $text,
            $m
        )) {
            $result['highschool_name'] = trim(rtrim(trim($m[1]), '.'));
        }

        // Tỉnh THPT: hỗ trợ CẢ 2 trường hợp — cùng dòng (case thực tế của bạn)
        // và khác dòng (layout cũ)
        if (preg_match(
            '/Năm\s*lớp\s*12[:\.\s]+[^\n]*?T[ỉi]nh\s*\/\s*TP[.:\s]*([^\n(]+)/iu',
            $text,
            $m
        )) {
            $result['highschool_province_name'] = trim(rtrim(trim($m[1]), '.'));
        } elseif (preg_match(
            '/Năm\s*lớp\s*12[:\.\s]+[^\n]+\n\s*T[ỉi]nh\s*\/\s*TP[.:\s]+([^\n]+)/iu',
            $text,
            $m
        )) {
            $result['highschool_province_name'] = trim(rtrim(trim($m[1]), '.'));
        }

        // 7. Năm tốt nghiệp THPT
        //
        // FIX (bug "Năm Tốt Nghiệp" luôn trống): pattern CŨ dùng
        // preg_match() thường, đòi hỏi khớp CHÍNH XÁC "tốt nghiệp" có
        // dấu. OCR thực tế có thể đọc sai dấu thành "sốt nghiệp" (s thay
        // vì t), khiến regex không bao giờ khớp dù năm vẫn đọc được rõ.
        //
        // FIX: chuyển sang fuzzyMatch().
        if ($fm = $this->fuzzyMatch(
            $text,
            '/tot\s*nghiep\s*thpt[^\n]*?[:\s](\d{4})/u'
        )) {
            $result['highschool_graduation_year'] = $fm[1];
        }

        // 8. Học lực / Hạnh kiểm lớp 12
        //
        // FIX: tách riêng 2 field, không bắt buộc cùng dòng hay đúng thứ tự.
        // Hạnh kiểm chỉ xuất hiện 1 lần duy nhất trên phiếu (dành cho THPT) nên
        // tìm độc lập là an toàn.
        if ($fm = $this->fuzzyMatch($text, '/hanh\s*kiem[:\s]+([^\n]+)/u')) {
            $result['highschool_conduct_rank'] = trim(rtrim(trim($fm[1]), '.'));
        }

        // Học lực THPT: neo theo cụm "năm lớp 12" đứng trước nó để phân biệt với
        // "Học lực" của bằng đại học (dòng "Trường cấp bằng ... Học lực" ở dưới).
        // FIX (bug "Học Lực THPT" dính rác "Khá....... Hạnh kiếm: Tốt"):
        // capture CŨ ([^\n]+) tham lam, ăn hết tới cuối dòng, kể cả nhãn
        // "Hạnh kiểm" đứng ngay sau trên cùng dòng. Đổi capture thành
        // không tham lam và dừng lại TRƯỚC cụm "Hạnh kiểm" (nếu có mặt
        // trên cùng dòng) hoặc chuỗi dấu chấm lặp ("......."), hoặc tới
        // hết dòng nếu không có.
        if ($fm = $this->fuzzyMatch(
            $text,
            '/nam\s*lop\s*12[^\n]*?hoc\s*luc[:\s]+([^\n]+?)(?=\s*\.{2,}|\s*hanh\s*kiem|\s*$)/u'
        )) {
            $result['highschool_academic_rank'] = trim(rtrim(trim($fm[1]), '.'));
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
        //
        // FIX (bug "Ghi Chú 2" mất tên ngành, ví dụ ra "Đại học" thay vì
        // "Đại học Hệ thống điện"): pattern CŨ dùng "[:\s]+" làm phân cách
        // ngay sau nhãn — chỉ chấp nhận dấu ":" hoặc khoảng trắng. OCR
        // thực tế có thể ghi nhãn bằng dấu PHẨY thay vì hai chấm (vd:
        // "9 Năm tốt nghiệp trung cấp, cao đảng 2016 Ngành tốt nghiệp,
        // Hệ thống điện"), khiến regex không khớp được gì, $priorMajor
        // luôn null dù tên ngành vẫn đọc được ngay đó.
        //
        // FIX: thêm dấu "," vào class phân cách.
        $priorMajor = null;
        $level = null;

        if ($fm = $this->fuzzyMatch($text, '/nganh\s*tot\s*nghiep[:\s,]+([^\n]+)/u')) {
            $rawAfterLabel = trim(rtrim(trim($fm[1]), '.'));

            if (preg_match('/^(Trung\s*c[aấáàảãạăằắặẳẵâầấậẩẫ]p|Cao\s*đ[aăâeêuư]ng|Đ[aạ]i\s*h[oọ]c)\s+(.+)$/iu', $rawAfterLabel, $mm)) {
                $level = $this->normalizeLevel($mm[1]);
                $priorMajor = trim($mm[2]);
            } else {
                $priorMajor = $rawAfterLabel;
            }
        }

        // ===== THÊM MỚI: ưu tiên lấy trình độ từ nhãn "Trường cấp bằng" =====
        //
        // Lý do đặt Ở ĐÂY (trước Fallback 1 và Fallback 2):
        //
        // "Trường cấp bằng: Đại học Bách Khoa TPHCM" là câu TRẢ LỜI thật
        // của thí sinh, đáng tin hơn nhiều so với việc quét mù toàn văn
        // bản (Fallback 2) — vì phiếu luôn có câu HỎI chứa sẵn cả 3 từ
        // "trung cấp/cao đẳng/đại học" ở mục 9 (vd: "Năm tốt nghiệp trung
        // cấp, cao đẳng..."), khiến Fallback 2 dễ bắt nhầm ngay từ đầu.
        //
        // Chỉ chạy khi bước trên (nhãn "Ngành tốt nghiệp") chưa xác định
        // được $level, và luôn chạy TRƯỚC Fallback 1/2 để chặn không cho
        // 2 fallback kém tin cậy hơn có cơ hội gán sai trước.
        if (! $level && $fm = $this->fuzzyMatch(
            $text,
            '/truong\s*cap\s*bang[:\s]+(trung\s*cap|cao\s*dang|dai\s*hoc)/u'
        )) {
            $level = $this->normalizeLevel($fm[1]);
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
        // FIX: đổi sang fuzzyMatch (so khớp trên bản không dấu) để không bị vỡ
        // khi OCR đọc rớt dấu "bằng" -> "băng" (2 chữ khác dấu nhưng cùng chuẩn
        // hoá về "bang"). preg_match thường trước đây thất bại hoàn toàn khi
        // gặp trường hợp này, làm mất cả university_name lẫn classification.
        if ($fm = $this->fuzzyMatch(
            $text,
            '/truong\s*cap\s*bang[:\s]+([^\n]+?)[ \t]+hoc\s*luc[:\s]+([^\n]+)/u'
        )) {
            $result['university_name'] = trim(rtrim(trim($fm[1]), '.'));
            $result['classification'] = trim(rtrim(trim($fm[2]), '.'));
        }

        // Ghi chú 2 = Trình độ + Ngành tốt nghiệp (trước đó)
        if ($level || $priorMajor) {
            $result['note_2'] = trim(($level ?? '') . ' ' . ($priorMajor ?? ''));
        }

        // 12. Điện thoại
        if (preg_match('/ĐTDĐ của bản thân[:\s]+(\d[\d\s]*)/iu', $text, $m)) {
            $result['phone_1'] = preg_replace('/\D/', '', $m[1]);
        }

        // FIX (bug "Số Điện Thoại 2" luôn rỗng): pattern CŨ dùng preg_match()
        // thường, đòi hỏi đúng chữ "liên lạc" có dấu chuẩn ("liên" với dấu
        // sắc trên "ê"). OCR trên phiếu thật đọc thành "lien lạc" (mất dấu
        // chữ "liên"), nên regex không bao giờ khớp, field bị bỏ trống.
        //
        // FIX: chuyển sang fuzzyMatch() để khoan dung lỗi dấu của cả cụm
        // "liên lạc" và "gia đình".
        if ($fm = $this->fuzzyMatch(
            $text,
            '/dien\s*thoai\s*lien\s*lac\s*cua\s*gia\s*dinh[:\s]+(\d[\d\s]*)/u'
        )) {
            $result['phone_2'] = preg_replace('/\D/', '', $fm[1]);
        }

        return $result;
    }
}