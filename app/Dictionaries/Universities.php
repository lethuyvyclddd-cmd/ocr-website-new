<?php

namespace App\Dictionaries;

class Universities
{
    /**
     * Tên trường (đại học / cao đẳng / trung cấp) => Tỉnh/Thành nơi trường
     * đặt trụ sở (dùng tên tỉnh CŨ trước sáp nhập 2025 — ProvinceMergeMapper
     * trên Applicant model sẽ tự quy đổi sang tên tỉnh mới khi lưu).
     *
     * Danh sách này KHÔNG thể đầy đủ 100% các trường ở Việt Nam. Khi OCR đọc
     * ra một trường không có trong danh sách, DictionaryMatcher sẽ không tìm
     * được khớp đủ tin cậy và hệ thống sẽ GIỮ NGUYÊN văn bản gốc OCR đọc được
     * (xem DiplomaExtractor::matchDictionary) — không suy diễn/áp đặt.
     */
    protected const MAP = [
        // ===== Đồng bằng sông Cửu Long =====
        'Trường Đại học Cửu Long' => 'Vĩnh Long',
        'Trường Đại học Tiền Giang' => 'Tiền Giang',
        'Trường Đại học Cần Thơ' => 'Cần Thơ',
        'Trường Đại học Đồng Tháp' => 'Đồng Tháp',
        'Trường Đại học Trà Vinh' => 'Trà Vinh',
        'Trường Đại học An Giang' => 'An Giang',
        'Trường Đại học Kỹ thuật - Công nghệ Cần Thơ' => 'Cần Thơ',
        'Trường Đại học Nam Cần Thơ' => 'Cần Thơ',
        'Trường Đại học Tây Đô' => 'Cần Thơ',
        'Trường Đại học Bạc Liêu' => 'Bạc Liêu',
        'Trường Đại học Kiên Giang' => 'Kiên Giang',
        'Trường Đại học Y Dược Cần Thơ' => 'Cần Thơ',

        // ===== Miền Trung =====
        'Trường Đại học Vinh' => 'Nghệ An',
        'Trường Đại học Sư phạm Huế' => 'Thừa Thiên Huế',
        'Trường Đại học Khoa học Huế' => 'Thừa Thiên Huế',
        'Trường Đại học Kinh tế Huế' => 'Thừa Thiên Huế',
        'Trường Đại học Y Dược Huế' => 'Thừa Thiên Huế',
        'Trường Đại học Luật Huế' => 'Thừa Thiên Huế',
        'Trường Đại học Ngoại ngữ Huế' => 'Thừa Thiên Huế',
        'Trường Đại học Nông Lâm Huế' => 'Thừa Thiên Huế',
        'Trường Đại học Bách khoa Đà Nẵng' => 'Đà Nẵng',
        'Trường Đại học Kinh tế Đà Nẵng' => 'Đà Nẵng',
        'Trường Đại học Sư phạm Đà Nẵng' => 'Đà Nẵng',
        'Trường Đại học Ngoại ngữ Đà Nẵng' => 'Đà Nẵng',
        'Trường Đại học Duy Tân' => 'Đà Nẵng',
        'Trường Đại học Quy Nhơn' => 'Bình Định',
        'Trường Đại học Nha Trang' => 'Khánh Hòa',
        'Trường Đại học Tây Nguyên' => 'Đắk Lắk',
        'Trường Đại học Đà Lạt' => 'Lâm Đồng',
        'Trường Đại học Quảng Nam' => 'Quảng Nam',
        'Trường Đại học Phạm Văn Đồng' => 'Quảng Ngãi',

        // ===== Miền Bắc =====
        'Trường Đại học Bách khoa Hà Nội' => 'Hà Nội',
        'Trường Đại học Kinh tế Quốc dân' => 'Hà Nội',
        'Trường Đại học Ngoại thương' => 'Hà Nội',
        'Trường Đại học Sư phạm Hà Nội' => 'Hà Nội',
        'Trường Đại học Khoa học Tự nhiên Hà Nội' => 'Hà Nội',
        'Trường Đại học Khoa học Xã hội và Nhân văn Hà Nội' => 'Hà Nội',
        'Trường Đại học Luật Hà Nội' => 'Hà Nội',
        'Trường Đại học Y Hà Nội' => 'Hà Nội',
        'Trường Đại học Dược Hà Nội' => 'Hà Nội',
        'Trường Đại học Thương mại' => 'Hà Nội',
        'Trường Đại học Giao thông Vận tải' => 'Hà Nội',
        'Trường Đại học Thủy lợi' => 'Hà Nội',
        'Trường Đại học Xây dựng Hà Nội' => 'Hà Nội',
        'Trường Đại học Kiến trúc Hà Nội' => 'Hà Nội',
        'Trường Đại học Công nghiệp Hà Nội' => 'Hà Nội',
        'Trường Đại học Mở Hà Nội' => 'Hà Nội',
        'Học viện Nông nghiệp Việt Nam' => 'Hà Nội',
        'Học viện Báo chí và Tuyên truyền' => 'Hà Nội',
        'Học viện Ngân hàng' => 'Hà Nội',
        'Học viện Tài chính' => 'Hà Nội',
        'Trường Đại học Hải Phòng' => 'Hải Phòng',
        'Trường Đại học Thái Nguyên' => 'Thái Nguyên',

        // ===== TP Hồ Chí Minh =====
        'Trường Đại học Bách khoa TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Kinh tế TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Sư phạm Kỹ thuật TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Sư phạm TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Khoa học Tự nhiên TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Khoa học Xã hội và Nhân văn TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Luật TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Y Dược TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Ngân hàng TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Tài chính - Marketing' => 'TP Hồ Chí Minh',
        'Trường Đại học Mở TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Công nghiệp TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Nông Lâm TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Tôn Đức Thắng' => 'TP Hồ Chí Minh',
        'Trường Đại học Công nghệ TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Văn Lang' => 'TP Hồ Chí Minh',
        'Trường Đại học Hoa Sen' => 'TP Hồ Chí Minh',
        'Trường Đại học Nguyễn Tất Thành' => 'TP Hồ Chí Minh',
        'Trường Đại học Kinh tế - Tài chính TP Hồ Chí Minh' => 'TP Hồ Chí Minh',
        'Trường Đại học Quốc tế Hồng Bàng' => 'TP Hồ Chí Minh',

        // ===== Khối Công an / Quân đội (đặt tại TP Hồ Chí Minh) =====
        'Trường Đại học Cảnh sát nhân dân' => 'TP Hồ Chí Minh',
        'Trường Đại học An ninh nhân dân' => 'TP Hồ Chí Minh',

        // ===== Khối Công an / Quân đội (đặt tại Hà Nội) =====
        'Học viện Cảnh sát nhân dân' => 'Hà Nội',
        'Học viện An ninh nhân dân' => 'Hà Nội',
        'Học viện Kỹ thuật Quân sự' => 'Hà Nội',
        'Học viện Quân y' => 'Hà Nội',

        // ===== Đông Nam Bộ =====
        'Trường Đại học Bình Dương' => 'Bình Dương',
        'Trường Đại học Thủ Dầu Một' => 'Bình Dương',
        'Trường Đại học Lạc Hồng' => 'Đồng Nai',
        'Trường Đại học Đồng Nai' => 'Đồng Nai',
        'Trường Đại học Bà Rịa - Vũng Tàu' => 'Bà Rịa - Vũng Tàu',

        // ===== Cao đẳng / Trung cấp (một số trường phổ biến) =====
        'Trường Cao đẳng Cần Thơ' => 'Cần Thơ',
        'Trường Cao đẳng Kinh tế - Kỹ thuật Cần Thơ' => 'Cần Thơ',
        'Trường Cao đẳng Y tế Đồng Tháp' => 'Đồng Tháp',
        'Trường Cao đẳng Vĩnh Long' => 'Vĩnh Long',
        'Trường Trung cấp Kinh tế - Kỹ thuật Vĩnh Long' => 'Vĩnh Long',
    ];

    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Trả về tỉnh/thành của trường nếu $universityName khớp CHÍNH XÁC với
     * một tên chuẩn trong danh sách (thường là kết quả đã qua DictionaryMatcher).
     */
    public static function provinceOf(string $universityName): ?string
    {
        return self::MAP[$universityName] ?? null;
    }
}