<?php

namespace App\OCR;

class VietnameseNormalizer
{
    public static function normalize(?string $text): ?string
    {
        if (!$text) {
            return $text;
        }

        $text = trim($text);

        $replace = [

            // =========================
            // OCR lỗi phổ biến
            // =========================

            'Tran Thi' => 'Trần Thị',
            'Tran Van' => 'Trần Văn',
            'Tran' => 'Trần',

            'Nguyen' => 'Nguyễn',
            'Le ' => 'Lê ',
            'Pham' => 'Phạm',
            'Huynh' => 'Huỳnh',
            'Vo ' => 'Võ ',
            'Dang' => 'Đặng',
            'Do ' => 'Đỗ ',
            'Bui' => 'Bùi',
            'Phan' => 'Phan',

            'hoc' => 'học',
            'Hoc' => 'Học',

            'hanh' => 'hạnh',
            'Hanh' => 'Hạnh',

            'kiem' => 'kiểm',
            'Kiem' => 'Kiểm',

            'Dan toc' => 'Dân tộc',
            'dan toc' => 'dân tộc',

            'Noi sinh' => 'Nơi sinh',

            'Tinh' => 'Tỉnh',
            'tinh' => 'tỉnh',

            'Thanh pho' => 'Thành phố',
            'thanh pho' => 'thành phố',

            'Phuong' => 'Phường',
            'Xa ' => 'Xã ',
            'Thi tran' => 'Thị trấn',

            'duong' => 'đường',
            'Duong' => 'Đường',

            'ap ' => 'ấp ',
            'Ap ' => 'Ấp ',

            'Khu pho' => 'Khu phố',
            'khu pho' => 'khu phố',

            'Quan' => 'Quận',
            'Huyen' => 'Huyện',

            // =========================
            // Đại học
            // =========================

            'DH' => 'Đại học',
            'DH ' => 'Đại học ',
            'dh ' => 'đại học ',
            'dai hoc' => 'đại học',
            'Dai hoc' => 'Đại học',
            'Dai Hoc' => 'Đại học',

            // =========================
            // Ngành
            // =========================

            'Ngon ngu Anh' => 'Ngôn ngữ Anh',
            'Ngon Ngu Anh' => 'Ngôn ngữ Anh',

            'Ke toan' => 'Kế toán',
            'Luat' => 'Luật',
            'Thu y' => 'Thú y',
            'Cong nghe thong tin' => 'Công nghệ thông tin',

            // =========================
            // Tỉnh
            // =========================

            'Tien Giang' => 'Tiền Giang',
            'Dong Thap' => 'Đồng Tháp',
            'Can Tho' => 'Cần Thơ',
            'Vinh Long' => 'Vĩnh Long',
            'Ben Tre' => 'Bến Tre',
            'Tra Vinh' => 'Trà Vinh',
            'Soc Trang' => 'Sóc Trăng',
            'An Giang' => 'An Giang',
            'Kien Giang' => 'Kiên Giang',
            'Ca Mau' => 'Cà Mau',
            'Bac Lieu' => 'Bạc Liêu',
            'Hau Giang' => 'Hậu Giang',

            // =========================
            // THPT
            // =========================

            'Trung tam' => 'Trung tâm',
            'GDTX' => 'GDTX',

            // =========================
            // Các từ hay sai
            // =========================

            'Sinh vien' => 'Sinh viên',
            'Cong tac' => 'Công tác',

            'Ngay' => 'Ngày',
            'Thang' => 'Tháng',
            'Nam ' => 'Năm ',

            'Ho va ten' => 'Họ và tên',

            'Dien thoai' => 'Điện thoại',

            'Gia dinh' => 'Gia đình',

            'Ban than' => 'Bản thân',

            'Thuong tru' => 'Thường trú',

            'Dang ky' => 'Đăng ký',

            'Xet tuyen' => 'Xét tuyển',

            'Tot nghiep' => 'Tốt nghiệp',

            'Ket qua' => 'Kết quả',

            'Hoc luc' => 'Học lực',

            'Dia chi' => 'Địa chỉ',

            'Bao tin' => 'Báo tin',

            // =========================
            // OCR ký tự rác
            // =========================

            '“' => '',
            '”' => '',
            '‘' => '',
            '’' => '',
            '`' => '',
            '|' => '',
            '¦' => '',
            '…' => '',
            '•' => '',
            '™' => '',
            '®' => '',
            '©' => '',

            '  ' => ' ',
            '   ' => ' ',
        ];

        $text = strtr($text, $replace);

        // Xóa khoảng trắng thừa
        $text = preg_replace('/\s+/u', ' ', $text);

        // Xóa khoảng trắng trước dấu câu
        $text = preg_replace('/\s+([,.])/u', '$1', $text);

        return trim($text);
    }
}