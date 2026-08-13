<?php

namespace App\Services;

class ProvinceMergeMapper
{
    /**
     * Tên tỉnh CŨ (trước 01/07/2025) => tên tỉnh MỚI (sau sáp nhập).
     * Theo Nghị quyết 202/2025/QH15 (63 tỉnh -> 34 tỉnh/thành).
     */
    protected const MAP = [
        'bà rịa vũng tàu' => 'TP. Hồ Chí Minh',
        'ba ria vung tau' => 'TP. Hồ Chí Minh',
        'bạc liêu' => 'Cà Mau',
        'bac lieu' => 'Cà Mau',
        'bắc giang' => 'Bắc Ninh',
        'bac giang' => 'Bắc Ninh',
        'bắc kạn' => 'Thái Nguyên',
        'bac kan' => 'Thái Nguyên',
        'bến tre' => 'Vĩnh Long',
        'ben tre' => 'Vĩnh Long',
        'bình dương' => 'TP. Hồ Chí Minh',
        'binh duong' => 'TP. Hồ Chí Minh',
        'bình định' => 'Gia Lai',
        'binh dinh' => 'Gia Lai',
        'bình phước' => 'Đồng Nai',
        'binh phuoc' => 'Đồng Nai',
        'bình thuận' => 'Lâm Đồng',
        'binh thuan' => 'Lâm Đồng',
        'đắk nông' => 'Lâm Đồng',
        'dak nong' => 'Lâm Đồng',
        'hà giang' => 'Tuyên Quang',
        'ha giang' => 'Tuyên Quang',
        'hà nam' => 'Ninh Bình',
        'ha nam' => 'Ninh Bình',
        'hải dương' => 'Hải Phòng',
        'hai duong' => 'Hải Phòng',
        'hậu giang' => 'Cần Thơ',
        'hau giang' => 'Cần Thơ',
        'hòa bình' => 'Phú Thọ',
        'hoa binh' => 'Phú Thọ',
        'kiên giang' => 'An Giang',
        'kien giang' => 'An Giang',
        'kon tum' => 'Quảng Ngãi',
        'lạng sơn' => 'Lạng Sơn', // không sáp nhập
        'long an' => 'Tây Ninh',
        'nam định' => 'Ninh Bình',
        'nam dinh' => 'Ninh Bình',
        'ninh thuận' => 'Khánh Hòa',
        'ninh thuan' => 'Khánh Hòa',
        'phú yên' => 'Đắk Lắk',
        'phu yen' => 'Đắk Lắk',
        'quảng bình' => 'Quảng Trị',
        'quang binh' => 'Quảng Trị',
        'quảng nam' => 'Đà Nẵng',
        'quang nam' => 'Đà Nẵng',
        'sóc trăng' => 'Cần Thơ',
        'soc trang' => 'Cần Thơ',
        'thái bình' => 'Hưng Yên',
        'thai binh' => 'Hưng Yên',
        'tiền giang' => 'Đồng Tháp',
        'tien giang' => 'Đồng Tháp',
        'trà vinh' => 'Vĩnh Long',
        'tra vinh' => 'Vĩnh Long',
        'vĩnh phúc' => 'Phú Thọ',
        'vinh phuc' => 'Phú Thọ',
        'yên bái' => 'Lào Cai',
        'yen bai' => 'Lào Cai',
    ];

    /**
     * Chuyển tên tỉnh cũ về tên tỉnh mới sau sáp nhập.
     * Nếu không nhận diện được (tỉnh không đổi tên, hoặc tên lạ), trả về nguyên văn.
     */
    public static function toNewName(?string $oldProvinceName): ?string
    {
        if (empty($oldProvinceName)) {
            return $oldProvinceName;
        }

        $normalized = self::normalize($oldProvinceName);

        // Bỏ tiền tố "tỉnh"/"thành phố" để so khớp chính xác hơn
        $normalized = preg_replace('/^(tinh|thanh pho|tp\.?)\s+/u', '', $normalized);

        return self::MAP[$normalized] ?? $oldProvinceName;
    }

    protected static function normalize(string $str): string
    {
        $str = trim(mb_strtolower($str, 'UTF-8'));

        $vietnamese = ['à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ','ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ'];
        $ascii = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d'];

        return str_replace($vietnamese, $ascii, $str);
    }
}