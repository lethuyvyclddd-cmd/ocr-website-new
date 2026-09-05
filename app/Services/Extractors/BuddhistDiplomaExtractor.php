<?php

namespace App\Services\Extractors;

use App\Helpers\DictionaryMatcher;
use App\Dictionaries\Majors;
use App\Dictionaries\Universities;

/**
 * BuddhistDiplomaExtractor
 *
 * Trích xuất thông tin từ BẰNG / BẢNG ĐIỂM CỬ NHÂN của các Học viện Phật
 * giáo Việt Nam (HVPGVN tại TP.HCM, Vietnam Buddhist University...).
 *
 * SỬA (THÊM MỚI — bản trước thiếu hẳn Họ/Tên/Ngày sinh/Giới tính):
 * đổi từ "extends BaseExtractor" sang "extends DiplomaExtractor". Bằng
 * Phật học dùng CÙNG kiểu layout song ngữ "Cho <Tên>" / "Upon <Name>" +
 * "Ngày sinh: dd/mm/yyyy" như bằng Bách Khoa — DiplomaExtractor đã có sẵn
 * logic tìm các field này rất kỹ (qua nhiều lần fix bug OCR thực tế: nhãn
 * bị đọc rớt chữ, thứ tự dòng bị đảo, tên bị OCR đọc toàn HOA...). Kế
 * thừa lại để KHÔNG viết lại (và không lặp lại các bug đã fix) — chỉ
 * override đúng phần THẬT SỰ khác biệt của bằng Phật giáo:
 *
 *   - "Số hiệu" trên phôi bằng Phật giáo là MÃ GHÉP NHIỀU ĐOẠN phân cách
 *     bằng dấu "/" (vd "919/052000362/TX"), trong đó bản thân dấu "/"
 *     MANG Ý NGHĨA — khác hẳn "Số hiệu" kiểu Bách Khoa (chỉ 1 chuỗi
 *     chữ+số dính liền, vd "009167"). KHÔNG dùng cleanDiplomaCode() kiểu
 *     Bách Khoa (xoá hết "/","-" rồi map O->0/I->1/L->1) vì phá vỡ cấu
 *     trúc số hiệu thật.
 *   - Ưu tiên bắt theo NHÃN TIẾNG VIỆT ("Số hiệu", "Số vào sổ") thay vì
 *     nhãn tiếng Anh song ngữ đi kèm ("Serial No.", "Reg. No.") — quan
 *     sát thực tế: trên ảnh chụp/scan chất lượng trung bình, VietOCR đọc
 *     đúng phần tiếng Việt tốt hơn hẳn phần tiếng Anh viết tắt kế bên
 *     (case thực tế: "Số hiệu" đọc đúng, nhưng "Serial No." bị đọc thành
 *     "Sental Nam"). Bản trước ưu tiên ngược lại (tiếng Anh trước) nên bỏ
 *     lỡ chính phần đọc đúng.
 *   - Tên trường/ngành ghi khác vị trí (Phân khoa / "in <Major>" trong
 *     tên bằng tiếng Anh) so với Bách Khoa.
 *
 * LƯU Ý QUAN TRỌNG (giới hạn thực tế, không phải lỗi code): nếu vùng chữ
 * chứa Số hiệu/Số vào sổ trên ẢNH GỐC bị mờ/nét nhỏ tới mức VietOCR đọc
 * sai gần như toàn bộ ký tự (case thực tế gặp: "Số hiệu/Serial No.:
 * 919/052000362/TX" bị đọc thành "Số hiệu/Sental Nam 98mes2000.501X" —
 * mất hết dấu "/", số bị đọc sai lung tung), thì KHÔNG CÓ REGEX NÀO cứu
 * được — đây là giới hạn của chất lượng OCR trên chính tấm ảnh đó, cần
 * chụp/scan lại rõ hơn góc đó, hoặc sửa tay 2 field này.
 *
 * FIX MỚI (bug "Số hiệu bằng TN" bị điền nguyên cả dòng "Số vào sổ cấp
 * bằng/ Rang Nam: 407DL/1/2012" — lộ ra khi nhãn "Số hiệu" ĐỌC ĐƯỢC
 * nhưng phần giá trị ngay sau nó bị OCR đọc hỏng, không có dấu ":" nào):
 *
 * 2 regex "Số hiệu" / "Số vào sổ" bên dưới chạy trên TOÀN VĂN BẢN nhiều
 * dòng (nối bằng "\n"), không phải từng dòng riêng lẻ. Ngay trước group
 * bắt giá trị cuối cùng, bản trước dùng "\s*" — trong PCRE, "\s" MẶC
 * ĐỊNH khớp cả ký tự xuống dòng "\n". Khi dòng chứa nhãn không có dấu
 * ":" nào (vd dòng OCR "so hieu/sental nam 98mes2000.501x" — phần
 * "(?:\/[^:\r\n]*)?" nuốt sạch hết phần còn lại của dòng vì thiếu ":"
 * để dừng), để group bắt buộc "([^\r\n]+)" (đòi >=1 ký tự) khớp được,
 * engine phải backtrack — và vì "\s*" cho phép nhảy qua "\n", nó tự
 * động "trôi" xuống ĐẦU DÒNG KẾ TIẾP (chính là dòng "Số vào sổ..."),
 * bắt luôn cả câu đó làm giá trị của diploma_number.
 *
 * SỬA: đổi "\s*" ngay trước capture group cuối thành "[ \t]*" (chỉ
 * khoảng trắng/tab NGANG, không bao gồm "\n"/"\r") ở CẢ 2 pattern. Nhờ
 * vậy nếu dòng nhãn không còn ký tự hợp lệ nào để bắt trên CÙNG DÒNG,
 * fuzzyMatch() sẽ trả về null thay vì âm thầm tràn sang dòng kế bên —
 * đúng tinh thần "không có regex nào cứu được khi OCR đọc hỏng ký tự"
 * đã nêu ở trên, thay vì lấy nhầm dữ liệu của field khác.
 *
 * FIX MỚI (bug Họ/Tên trống hoàn toàn khi VietOCR đọc tên IN HOA thành
 * CHỮ THƯỜNG — case thực tế: "Cho PHẠM THỊ THƯƠNG" bị đọc thành "Cho
 * phạm thị thương"): looksLikeName() (dùng chung với Bách Khoa) chỉ
 * chấp nhận Title Case hoặc ALL CAPS, không chấp nhận chữ thường toàn
 * bộ — vì trong ngữ cảnh dò mù (không có nhãn thật đứng trước) chữ
 * thường toàn bộ thường là câu văn mô tả, không phải tên người. Nhưng
 * TRONG findNameNearPhapDanh(), khi dòng candidate CÓ nhãn "Cho " thật
 * đứng ngay đầu (rủi ro nhận nhầm thấp hơn hẳn so với dò mù), bổ sung
 * 1 nhánh riêng: bóc nhãn "Cho " ra rồi CHẤP NHẬN LUÔN phần còn lại bất
 * kể hoa/thường, miễn đúng hình dạng tên người (2-5 từ, chỉ chữ cái +
 * khoảng trắng, không số/dấu câu) — không cần qua looksLikeName().
 *
 * FIX MỚI (bug "Ghi chú 2" ra "Đại học in Buddhist Philosophy" thay vì
 * "Đại học Triết học Phật giáo" — case thực tế: phôi bằng in "Bằng Cử
 * nhân" ĐỨNG RIÊNG 1 dòng, tên ngành tiếng Việt nằm CÁCH 2 dòng sau đó
 * (dòng giữa là bản tiếng Anh "in Buddhist Philosophy")): pattern cũ
 * '/(?:Bằng\s+)?Cử\s*nhân\s+([^\n]{2,60})/iu' đòi ngành phải nằm NGAY
 * CÙNG DÒNG với "Cử nhân" nên không khớp được layout này, rơi tuột
 * xuống fallback bắt "in <Major>" và lấy nhầm bản TIẾNG ANH. Thêm 1
 * nhánh quét vài dòng NGAY SAU dòng chứa "Cử nhân", chủ động BỎ QUA
 * dòng không có dấu tiếng Việt (coi là bản dịch tiếng Anh song song),
 * ưu tiên dòng tiếng Việt hợp lệ đầu tiên tìm được làm tên ngành.
 *
 * Cùng chung tên field kết quả với DiplomaExtractor để 2 module tương
 * thích trong cùng pipeline:
 *
 *   last_name / first_name / gender / birth_date  (kế thừa từ cha)
 *   diploma_number            Số hiệu bằng
 *   diploma_registry_number   Số vào sổ cấp bằng
 *   university_name           Tên học viện/trường
 *   classification             Xếp loại tốt nghiệp
 *   training_type              Hình thức đào tạo (Chính quy/Từ xa...)
 *   university_graduation_year Năm tốt nghiệp
 *   note_2                     Trình độ + Ngành (nếu có)
 */
class BuddhistDiplomaExtractor extends DiplomaExtractor
{
    /**
     * Đối chiếu với từ điển tên trường/ngành để sửa lỗi OCR nhẹ, giữ
     * nguyên văn gốc nếu không khớp mục nào đủ gần trong từ điển.
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
     * Làm sạch số hiệu / số vào sổ / số đăng tịch bằng Phật giáo.
     *
     * QUAN TRỌNG: KHÔNG được xoá "/" hay "." — chỉ trim khoảng trắng và
     * các ký tự phân cách rác ở 2 đầu chuỗi. KHÔNG dùng cleanDiplomaCode()
     * của DiplomaExtractor (bản xoá hết "-"/"/" rồi map O->0/I->1/L->1).
     */
    private function cleanIdentifier(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));

        // FIX (bug "Số vào sổ" bị dính rác nhãn "cấp khung/ Xang Nam. "
        // phía trước giá trị thật "407DL.4 (2012" — case thực tế: literal
        // "cap bang" trong regex ở extract() không khớp được vì OCR đọc
        // nhầm hẳn chữ "bằng" thành "khung" (sai ký tự, không phải sai
        // dấu, normalize() không cứu được). Vì cụm đó là optional (?),
        // regex TỔNG THỂ vẫn khớp (coi như cụm optional match 0 lần),
        // nhưng group bắt giá trị bắt đầu chụp SỚM HƠN vị trí thật, nuốt
        // luôn phần chữ nhãn còn sót lại ("cấp khung/ Xang Nam. ") vào
        // trước mã số thật.
        //
        // Toàn bộ Số hiệu / Số vào sổ HỢP LỆ của bằng Phật giáo LUÔN bắt
        // đầu bằng MỘT CHỮ SỐ (xem 2 case chuẩn đã xác nhận trên bằng
        // thật: "919/052000362/TX", "407ĐL/4/2022") — không có mã nào bắt
        // đầu bằng chữ cái. Vì vậy an toàn để cắt bỏ mọi ký tự đứng TRƯỚC
        // chữ số đầu tiên trong chuỗi, coi đó là rác nhãn còn sót do
        // regex label ở extract() match hỏng một phần.
        if (preg_match('/\d/u', $value, $dm, PREG_OFFSET_CAPTURE)) {
            $bytePos = $dm[0][1];
            if ($bytePos > 0) {
                $charPos = mb_strlen(substr($value, 0, $bytePos), 'UTF-8');
                $value = mb_substr($value, $charPos, null, 'UTF-8');
            }
        }

        // OCR trên phôi bằng này hay đọc dấu "/" phân cách các đoạn mã
        // thành "(" hoặc ")" (case thực tế: "407DL.4 (2012" — dấu "/"
        // đúng ra đứng giữa "4" và phần năm, bị đọc thành "("). Không có
        // mã hợp lệ nào chứa "(" ")", nên thay bằng "/" để giữ gần đúng
        // cấu trúc thật hơn là để nguyên ký tự rác — dù vẫn không sửa
        // được các chữ số bị đọc sai bên trong (đó là giới hạn OCR thật,
        // không có regex nào cứu được).
        $value = str_replace(['(', ')'], '/', $value);

        $value = trim($value, " \t\n\r\0\x0B./-");

        return $value;
    }

    public function extract(string $text): array
    {
        // ================================================================
        // 1. LẤY CÁC FIELD DÙNG CHUNG (Họ/Tên/Ngày sinh/Giới tính/Xếp
        //    loại/Hình thức đào tạo...) TỪ DiplomaExtractor — bằng Phật
        //    học dùng cùng layout song ngữ "Cho:/Upon:" nên tái dùng
        //    nguyên vẹn logic đã test kỹ, không viết lại từ đầu.
        // ================================================================
        $result = parent::extract($text);

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));

        // ================================================================
        // 2. HỌ TÊN — neo theo nhãn "Pháp danh"/"Dharma name" (ĐẶC THÙ chỉ
        //    có ở bằng Phật giáo, DiplomaExtractor không biết tới).
        //
        //    QUAN TRỌNG (FIX BUG THỰC TẾ — LUÔN GHI ĐÈ, KHÔNG CHỈ CHẠY KHI
        //    CÒN TRỐNG): fallback "Upon:" dò mù của DiplomaExtractor (class
        //    cha) chỉ kiểm tra HÌNH DẠNG dòng ("2-6 ký tự + khoảng trắng +
        //    4-40 ký tự"), không xác nhận thật sự đúng ngữ cảnh "Upon".
        //    Case thực tế gặp: dòng RÁC do OCR đọc vỡ con dấu chứng thực
        //    ("JUT. HỒ CHÍNH") tình cờ khớp đúng hình dạng đó, khiến class
        //    cha tưởng là dòng neo, rồi lấy dòng KẾ BÊN làm tên — vớ trúng
        //    "Thạch Hồng Chính" (tên NGƯỜI CÔNG CHỨNG bản sao, in ở góc
        //    dưới bằng cùng con dấu đỏ) thay vì tên người nhận bằng thật.
        //    Vì bug này xảy ra ở class cha (chạy TRƯỚC, trong parent::
        //    extract() phía trên), field last_name/first_name lúc này
        //    KHÔNG CÒN TRỐNG nữa dù giá trị đang có là SAI — nếu chỉ chạy
        //    fallback này khi "còn trống" (bản trước) thì sẽ không bao giờ
        //    có cơ hội sửa lại. Nhãn "Pháp danh" đứng NGAY SAU tên thật
        //    trên MỌI phôi bằng Phật giáo, đáng tin cậy hơn hẳn — nên ở
        //    đây LUÔN thử tìm theo nhãn này và GHI ĐÈ bất cứ khi nào tìm
        //    thấy, bất kể class cha đã trót gán gì.
        // ================================================================
        $buddhistName = $this->findNameNearPhapDanh($lines);

        if ($buddhistName !== null) {
            $result = array_merge($result, $buddhistName);
        }

        // ================================================================
        // 3. SỐ HIỆU BẰNG + SỐ VÀO SỔ CẤP BẰNG (GHI ĐÈ hoàn toàn logic
        //    của DiplomaExtractor — pattern Bách Khoa không áp dụng được
        //    cho định dạng có "/" của bằng Phật giáo, xem docblock class).
        //
        //    Mẫu MỚI in nhãn song ngữ trên CÙNG 1 dòng:
        //        "Số hiệu/Serial No.: 919/052000362/TX"
        //        "Số vào sổ cấp bằng/Reg. No.: 407DL/4/2022"
        //
        //    ƯU TIÊN nhãn TIẾNG VIỆT trước (quan sát thực tế: OCR đọc
        //    đúng phần tiếng Việt tốt hơn hẳn phần tiếng Anh viết tắt kế
        //    bên — "Số hiệu" đọc đúng trong khi "Serial No." bị đọc thành
        //    "Sental Nam"). Nhãn tiếng Anh chỉ dùng làm fallback.
        // ================================================================
        unset($result['diploma_number'], $result['diploma_registry_number']);

        // FIX BUG THỰC TẾ ("Số hiệu"/"Số vào sổ" không bao giờ khớp được
        // dù đọc đúng): bản trước viết regex nhãn tiếng Việt bằng ASCII
        // trần trụi (vd "so\s*hi[eệ]u") rồi áp thẳng lên $line — nhưng
        // $line là text GỐC CÓ DẤU ("Số hiệu"), mà "Số" (ố có dấu) không
        // bao giờ khớp được với "so" (o thường, không dấu) trong regex,
        // dù mắt người đọc thấy giống nhau — quên mất là chưa normalize()
        // trước khi so khớp. Giờ dùng $this->fuzzyMatch() (kế thừa từ
        // DiplomaExtractor) — hàm này TỰ ĐỘNG so khớp trên bản đã bỏ dấu
        // rồi map ngược lại lấy đúng đoạn text GỐC (giữ dấu) tương ứng,
        // đúng kỹ thuật "HƯỚNG #1" đã dùng ở khắp DiplomaExtractor.
        //
        // FIX MỚI (xem giải thích đầy đủ ở docblock đầu file class):
        // "\s*" ngay trước "([^\r\n]+)" đổi thành "[ \t]*" để KHÔNG cho
        // phép regex nhảy qua "\n" sang dòng kế tiếp khi dòng hiện tại
        // không có ký tự hợp lệ nào sau nhãn.
        //
        // Số hiệu — thêm "lieu" làm biến thể nhãn (h→L), và chặn nhóm
        // optional không được nuốt qua chữ số (English label không chứa
        // số, giá trị mới có số).
        if ($fm = $this->fuzzyMatch(
            $text,
            '/so\s*(?:hieu|lieu)\s*(?:\/[^:\r\n\d]{0,25})?[:.]?[ \t]*([^\r\n]+)/u'
        )) {
            $candidate = $this->cleanIdentifier($fm[1]);
            if ($candidate !== '' && preg_match('/\d/', $candidate)) {
                $result['diploma_number'] = $candidate;
            }
        }

        // Số vào sổ — cùng fix chặn nhóm optional nuốt qua chữ số.
        if ($fm = $this->fuzzyMatch(
            $text,
            '/so\s*vao\s*so\s*(?:cap\s*bang)?\s*(?:\/[^:\r\n\d]{0,25})?[:.]?[ \t]*([^\r\n]+)/u'
        )) {
            $candidate = $this->cleanIdentifier($fm[1]);
            if ($candidate !== '' && preg_match('/\d/', $candidate)) {
                $result['diploma_registry_number'] = $candidate;
            }
        }

        // ----- Fallback nhãn tiếng Anh (khi nhãn Việt cũng bị đọc mất) -----
        foreach ($lines as $line) {
            if (
                empty($result['diploma_number'])
                && preg_match('/serial\s*no\.?\s*[:.]?\s*([^\r\n]+)/i', $line, $m)
            ) {
                $result['diploma_number'] = $this->cleanIdentifier($m[1]);
            }

            if (
                empty($result['diploma_registry_number'])
                && preg_match('/reg\.?\s*no\.?\s*[:.]?\s*([^\r\n]+)/i', $line, $m)
            ) {
                $result['diploma_registry_number'] = $this->cleanIdentifier($m[1]);
            }

            // Mẫu CŨ: "Enroll no." (chỉ có 1 số duy nhất, không tách
            // riêng số hiệu/số vào sổ).
            if (
                empty($result['diploma_number'])
                && preg_match('/enroll\s*no\.?\s*[:.]?\s*([^\r\n]+)/i', $line, $m)
            ) {
                $result['diploma_number'] = $this->cleanIdentifier($m[1]);
            }

            // Mẫu CŨ: "Số đăng tịch" tiếng Việt.
            if (
                empty($result['diploma_number'])
                && preg_match(
                    '/(?:s[ốồổỗộô]\s*đ[ăằẳẵặâấầẩẫậ]ng\s*t[ịí]ch|so\s*dang\s*tich)\s*[:.]?\s*([^\r\n]+)/iu',
                    $line,
                    $m
                )
            ) {
                $result['diploma_number'] = $this->cleanIdentifier($m[1]);
            }
        }

        // ================================================================
        // 4. TÊN HỌC VIỆN / TRƯỜNG — ghi đè lên kết quả (nếu có) của
        //    DiplomaExtractor bằng pattern chuyên biệt hơn cho "Học viện
        //    Phật giáo", để chắc chắn không dính lệch dấu do OCR.
        // ================================================================
        if ($this->fuzzyMatchExists($text, '/hoc\s*vien\s*phat\s*giao[^\n]{0,60}/u')) {
            if (preg_match('/H[oọ][cạ]?\s*[Vv]i[eệ]n\s*Ph[aậ]t\s*[Gg]i[aá]o[^\n]{0,60}/iu', $text, $m)) {
                $result['university_name'] = $this->matchDictionary(trim($m[0]), Universities::all());
            }
        } elseif (preg_match('/Vietnam\s*Buddhist\s*University[^\n]{0,40}/i', $text, $m)) {
            $result['university_name'] = $this->matchDictionary(trim($m[0]), Universities::all());
        }

        // ================================================================
        // 5. TRÌNH ĐỘ + NGÀNH (Ghi chú 2) — TÍNH LẠI HOÀN TOÀN, LUÔN GHI
        //    ĐÈ (không chỉ khi tìm được) kết quả note_2 của DiplomaExtractor.
        //
        //    FIX BUG THỰC TẾ ("Ghi chú 2" ra "Hệ Đào tạo từ xa" — trùng
        //    y hệt field "Hình thức đào tạo" đã có riêng): fallback đoán
        //    ngành của DiplomaExtractor (class cha) dựa trên giả định
        //    "dòng NGAY SAU dòng 'Bằng ...' là tên ngành" — đúng với layout
        //    Bách Khoa, nhưng bằng Phật giáo in "Bằng Cử nhân Phật học"
        //    rồi NGAY DÒNG SAU lại là "Hệ Đào tạo từ xa" (hình thức đào
        //    tạo, KHÁC HẲN khái niệm ngành) -> class cha lấy nhầm dòng đó
        //    làm $major, rồi gán vào note_2. Vì bug xảy ra BÊN TRONG
        //    parent::extract() (chạy trước, không chặn được từ ngoài),
        //    và trước đây tôi chỉ ghi đè note_2 KHI class này tự tìm được
        //    ngành — lần đó không tìm được (chưa có regex bắt "Cử nhân
        //    <Ngành>") nên giá trị sai của class cha bị giữ nguyên. Giờ
        //    LUÔN gán lại note_2 bằng giá trị TỰ TÍNH của class này (kể cả
        //    khi rỗng), không phụ thuộc điều kiện tìm được hay không.
        //
        //    Trình độ: phôi bằng Phật giáo không in literal chữ "Đại học"
        //    (chỉ ghi "Cử nhân") nên $level của class cha (chỉ dò đúng 3
        //    cụm "dai hoc"/"cao dang"/"trung cap" xuất hiện nguyên văn)
        //    luôn ra null cho loại bằng này — bằng Cử nhân do Học viện
        //    (cơ sở giáo dục đại học) cấp LUÔN tương đương bậc Đại học.
        //
        //    Ngành: ưu tiên bắt trực tiếp "Cử nhân <Ngành>" ngay trong
        //    chính tên văn bằng (vd "Bằng Cử nhân Phật học" -> "Phật
        //    học") — đáng tin cậy hơn hẳn suy đoán dòng kế tiếp của class
        //    cha, vì không phụ thuộc bố cục dòng phía sau là gì.
        //
        //    FIX MỚI (bug "Đại học in Buddhist Philosophy" — xem giải
        //    thích đầy đủ ở docblock đầu file): thêm nhánh quét vài dòng
        //    NGAY SAU dòng chứa "Cử nhân" khi ngành không nằm cùng dòng,
        //    bỏ qua dòng không dấu tiếng Việt (bản dịch tiếng Anh song
        //    song), ưu tiên dòng tiếng Việt hợp lệ đầu tiên.
        // ================================================================
        $level = $this->fuzzyMatchExists($text, '/cu\s*nhan/u') ? 'Đại học' : null;

        $major = null;

        if (preg_match('/(?:Ph[aâ]n\s*)?[Kk]hoa\s*[:.]?\s*([^\n]{2,60})/u', $text, $m)) {
            $major = $this->matchDictionary(trim($m[1]), Majors::all());
        } elseif (preg_match('/(?:B[aằ]ng\s+)?C[uử]\s*nh[aâ]n[ \t]+([^\n]{2,60})/iu', $text, $m)) {
            // FIX (bug "Ghi chú 2" ra "Đại học in Buddhist Philosophy"
            // — VẪN xảy ra dù đã thêm nhánh else quét dòng kế tiếp bên
            // dưới, vì pattern này KHÔNG BAO GIỜ rớt xuống else: "\s+"
            // ngay sau "nhân" mặc định khớp CẢ ký tự xuống dòng "\n"
            // trong PCRE. Khi "Bằng Cử nhân" đứng RIÊNG 1 dòng (không có
            // gì theo sau trên cùng dòng), "\s+" nhảy qua "\n", tràn
            // sang dòng kế tiếp "in Buddhist Philosophy" và bắt trọn
            // dòng đó — khiến elseif này LUÔN khớp "thành công" (dù sai)
            // trước khi kịp chạy tới nhánh else. Đổi "\s+" thành "[ \t]+"
            // (chỉ khoảng trắng/tab NGANG) để bắt buộc phải có nội dung
            // trên CÙNG DÒNG với "Cử nhân" mới khớp — nếu "Cử nhân" đứng
            // cuối dòng, pattern này thất bại đúng như mong đợi, nhường
            // chỗ cho nhánh else quét dòng kế tiếp bên dưới xử lý.
            $major = trim($m[1]);
        } else {
            // FIX MỚI: "Bằng Cử nhân" đứng RIÊNG 1 dòng, tên ngành tiếng
            // Việt nằm CÁCH vài dòng sau đó (dòng xen giữa thường là bản
            // dịch tiếng Anh). Tìm dòng chứa "Cử nhân" trước, rồi quét
            // tối đa 3 dòng kế tiếp, bỏ qua dòng không có dấu tiếng Việt
            // (coi là bản tiếng Anh), lấy dòng tiếng Việt hợp lệ đầu
            // tiên làm tên ngành.
            $degreeLineIndex = null;

            foreach ($lines as $i => $line) {
                if ($this->fuzzyMatchExists($line, '/cu\s*nhan/u')) {
                    $degreeLineIndex = $i;
                    break;
                }
            }

            if ($degreeLineIndex !== null) {
                for ($offset = 1; $offset <= 3; $offset++) {
                    $nextIndex = $degreeLineIndex + $offset;

                    if (!isset($lines[$nextIndex])) {
                        break;
                    }

                    $nextLine = trim($lines[$nextIndex]);

                    $nextIsNoise = $nextLine === ''
                        || preg_match('/\d/', $nextLine)
                        || mb_strlen($nextLine, 'UTF-8') < 3
                        || mb_strlen($nextLine, 'UTF-8') > 60
                        // Dòng không có dấu tiếng Việt -> khả năng cao là
                        // bản dịch tiếng Anh song song, bỏ qua để tìm tiếp.
                        || $nextLine === $this->stripDiacritics($nextLine);

                    if ($nextIsNoise) {
                        continue;
                    }

                    $major = $this->matchDictionary($nextLine, Majors::all());
                    break;
                }
            }

            if (empty($major) && preg_match('/\bin\s+([A-Z][A-Za-z\s\(\)]{2,60})(?:\n|$)/u', $text, $m)) {
                $major = trim($m[1]);
            } elseif (empty($major) && preg_match('/Department\s*[:.]?\s*([^\n]{2,60})/i', $text, $m)) {
                $major = trim($m[1]);
            }
        }

        // ================================================================
        // 6. XẾP LOẠI — ghi đè bằng pattern hỗ trợ thêm "Rất giỏi"/"Xuất
        //    sắc" (thang điểm của Học viện Phật giáo có thể khác Bách
        //    Khoa) nếu tìm thấy, giữ nguyên kết quả của cha nếu không.
        // ================================================================
        if (preg_match(
            '/X[eế]p\s*lo[aạ]i\s*t[oố]t\s*nghi[eệ]p[:\s]+(R[aấ]t\s*gi[oỏ]i|Xu[aấ]t\s*s[aắ]c|Gi[oỏ]i|Kh[aá]|Trung\s*b[iì]nh\s*kh[aá]|Trung\s*b[iì]nh)/iu',
            $text,
            $m
        )) {
            $result['classification'] = trim($m[1]);
        } elseif (preg_match('/Degree\s*classification[:\s]+([A-Za-z\s]+)/i', $text, $m)) {
            $result['classification'] = trim($m[1]);
        }

        // ================================================================
        // 7. HÌNH THỨC ĐÀO TẠO — bằng Phật học hay ghi "Hệ Đào tạo từ xa"
        //    (khác cấu trúc nhãn của Bách Khoa) — ghi đè nếu tìm thấy.
        // ================================================================
        if (preg_match('/H[eệ]\s*[ĐđDd][aà]o\s*t[aạ]o[:\s]+([^\n]{2,40})/u', $text, $m)) {
            $result['training_type'] = trim($m[1]);
        } elseif (preg_match('/Mode\s*of\s*study[:\s]+([^\n]{2,40})/i', $text, $m)) {
            $result['training_type'] = trim($m[1]);
        }

        // ================================================================
        // 8. NĂM TỐT NGHIỆP — hỗ trợ thêm câu văn kiểu mẫu bằng CŨ (không
        //    có nhãn riêng), ghi đè nếu tìm thấy khớp tốt hơn.
        // ================================================================
        if (preg_match('/Y[eê]?ar\s*of\s*graduation[:\s]+(\d{4})/i', $text, $m)) {
            $result['university_graduation_year'] = $m[1];
        } elseif (preg_match('/N[aă]m\s*t[oố]t\s*nghi[eệ]p[:\s]+(\d{4})/iu', $text, $m)) {
            $result['university_graduation_year'] = $m[1];
        } elseif (empty($result['university_graduation_year']) && preg_match('/t[oố]t\s*nghi[eệ]p\s*n[aă]m\s*(\d{4})/iu', $text, $m)) {
            $result['university_graduation_year'] = $m[1];
        } elseif (empty($result['university_graduation_year']) && preg_match('/held\s*in\s*(\d{4})/i', $text, $m)) {
            $result['university_graduation_year'] = $m[1];
        }

        // ===== Ghi chú 2 = Trình độ + Ngành — LUÔN ghi đè, kể cả rỗng =====
        $note2 = trim(($level ?? '') . ' ' . ($major ?? ''));

        if ($note2 !== '') {
            $result['note_2'] = $note2;
        } else {
            unset($result['note_2']);
        }

        return $result;
    }

    /**
     * Helper nhỏ: kiểm tra pattern (không dấu) có xuất hiện trong bản
     * text đã chuẩn hoá (normalize() kế thừa từ BaseExtractor) hay
     * không, không cần lấy giá trị.
     */
    private function fuzzyMatchExists(string $text, string $pattern): bool
    {
        return (bool) preg_match($pattern, $this->normalize($text));
    }

    /**
     * Tìm họ tên bằng cách neo theo nhãn "Pháp danh"/"Dharma name" — xem
     * giải thích chi tiết (và case bug thực tế) ở nơi gọi hàm này trong
     * extract(). Trả về mảng kết quả của splitFullName() nếu tìm được
     * ứng viên hợp lệ, hoặc null nếu không tìm thấy gì đáng tin.
     *
     * @param string[] $lines
     * @return array<string,string>|null
     */
    private function findNameNearPhapDanh(array $lines): ?array
    {
        $labelIndex = null;

        foreach ($lines as $i => $line) {
            $norm = $this->normalize($line);
            if (str_contains($norm, 'phap danh') || str_contains($norm, 'dharma name')) {
                $labelIndex = $i;
                break;
            }
        }

        if ($labelIndex === null) {
            return null;
        }

        $noise = [
            'giao hoi', 'cong hoa', 'doc lap', 'buddhist', 'vietnam',
            'university', 'hoc vien', 'rector', 'vien truong',
            'bang cu nhan', 'degree', 'bachelor', 'socialist',
            'republic', 'sangha', 'has conferred', 'phat giao',
        ];

        $offsets = [-1, -2, 1, 2, -3, 3];

        foreach ($offsets as $offset) {
            $idx = $labelIndex + $offset;

            if (!isset($lines[$idx])) {
                continue;
            }

            $candidate = trim($lines[$idx]);

            // FIX MỚI (bug Họ/Tên trống hoàn toàn khi VietOCR đọc tên IN
            // HOA thành CHỮ THƯỜNG — xem giải thích đầy đủ ở docblock đầu
            // file): case thực tế "Cho PHẠM THỊ THƯƠNG" bị đọc thành "Cho
            // phạm thị thương" — looksLikeName() ở dưới từ chối vì chữ
            // đầu không viết hoa, khiến field bị bỏ trống hoàn toàn dù
            // dòng CÓ nhãn "Cho " thật đứng ngay đầu (rủi ro nhận nhầm
            // thấp hơn hẳn so với các fallback dò mù khác). Bóc riêng
            // nhãn "Cho " ra rồi CHẤP NHẬN LUÔN phần còn lại bất kể
            // hoa/thường, miễn đúng hình dạng tên người (2-5 từ, chỉ chữ
            // cái + khoảng trắng, không số/dấu câu) — không cần qua
            // looksLikeName() ở nhánh này.
            if (preg_match('/^cho\s+(.+)$/iu', $candidate, $mm)) {
                $nameOnly = trim($mm[1]);
                $words = preg_split('/\s+/u', $nameOnly, -1, PREG_SPLIT_NO_EMPTY);

                if (
                    $words !== false
                    && count($words) >= 2
                    && count($words) <= 5
                    && !preg_match('/\d/', $nameOnly)
                    && !preg_match('/[^\p{L}\s]/u', $nameOnly)
                ) {
                    // FIX (bug tên vẫn hiển thị CHỮ THƯỜNG "phạm thị
                    // thương" thay vì viết hoa đúng chuẩn): nhánh này cố
                    // tình CHẤP NHẬN mọi kiểu hoa/thường (xem lý do ở
                    // trên) để không mất field, nhưng chưa từng chuẩn hoá
                    // lại cách viết hoa trước khi lưu — dẫn tới field
                    // hiển thị y nguyên bản OCR (có thể toàn chữ thường).
                    // Chuẩn hoá về Title Case (mỗi từ viết hoa chữ đầu)
                    // trước khi tách Họ/Tên, để kết quả nhất quán và
                    // đúng chuẩn hiển thị tên người Việt.
                    $nameOnly = mb_convert_case($nameOnly, MB_CASE_TITLE, 'UTF-8');

                    return $this->splitFullName($nameOnly);
                }
            }

            $normCandidate = $this->normalize($candidate);

            if (
                $candidate === ''
                || preg_match('/\d/', $candidate)
                || mb_strlen($candidate, 'UTF-8') < 6
                || mb_strlen($candidate, 'UTF-8') > 50
            ) {
                continue;
            }

            // FIX (bug "Thích Nữ Rơn Quy" bị nhận nhầm làm tên thật): pháp
            // danh của tăng ni Phật giáo LUÔN bắt đầu bằng "Thích" (hoặc
            // "Thích Nữ" với ni) — đây là surname tôn giáo, KHÔNG PHẢI tên
            // khai sinh thật. Case thực tế: layout "Văn bằng Cử nhân" (không
            // song ngữ) khiến box giá trị Pháp danh bị xếp lệch lên NGAY
            // TRƯỚC nhãn "Pháp danh" (đáng lẽ phải đứng sau), tình cờ có
            // hình dạng giống tên người (2-6 từ, Title Case, không số) nên
            // lọt qua looksLikeName(). Chặn tường minh mọi dòng bắt đầu bằng
            // "Thích"/"Thích Nữ" ngay tại đây — bất kể vị trí offset nào —
            // để không bao giờ nhận nhầm chính giá trị Pháp danh làm tên thật.
            if (preg_match('/^thich(\s+nu)?\s+/u', $normCandidate)) {
                continue;
            }

            $isNoise = false;
            foreach ($noise as $bad) {
                if (str_contains($normCandidate, $bad)) {
                    $isNoise = true;
                    break;
                }
            }

            if (!$isNoise && $this->looksLikeName($candidate, true)) {
                return $this->splitFullName($candidate);
            }
        }

        return null;
    }
}