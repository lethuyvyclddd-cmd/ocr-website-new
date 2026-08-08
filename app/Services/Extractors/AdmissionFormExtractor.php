<?php

namespace App\Services\Extractors;

class AdmissionFormExtractor extends BaseExtractor
{
    public function extract(string $text): array
    {
        $result = [];

        if (preg_match('/đào tạo từ xa/iu', $text)) {
            $result['training_type'] = 'Đào tạo từ xa';
        }
        if (preg_match('/từ xa năm[:\s]+(\d{4})/iu', $text, $m)) {
            $result['admission_year'] = $m[1];
        }

        // 1. Họ và tên thí sinh + Nam, nữ
        if (preg_match('/Họ và tên thí sinh[:\s]+([^\n]+?)[ \t]{2,}Nam,?\s*nữ/iu', $text, $m)) {
            $result = array_merge($result, $this->splitFullName(trim($m[1])));
        }
        if (preg_match('/Nam,?\s*nữ[:\s]+(Nam|N[ữu])/iu', $text, $m)) {
            $result['gender'] = mb_strtolower($m[1], 'UTF-8') === 'nam' ? 'Nam' : 'Nữ';
        }

        // 2. Ngành đăng ký xét tuyển
        if (preg_match('/Ngành đăng ký xét tuyển[:\s]+([^\n]+)/iu', $text, $m)) {
            $result['major_name'] = trim($m[1]);
        }

        // 3. Ngày sinh / Nơi sinh / Dân tộc
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $text, $m)) {
            $result['birth_date'] = $this->toDbDate($m[1]);
        }
        if (preg_match('/Nơi sinh[^\n]*?[:\s][ \t]*([^\n]+?)[ \t]{2,}Dân\s*tộc/iu', $text, $m)) {
            $result['place_of_birth'] = trim($m[1]);
        }
        if (preg_match('/Dân\s*tộc[:\s]+([^\s\n]+)/iu', $text, $m)) {
            $result['ethnic'] = trim($m[1]);
        }

        // 4. CCCD số
        if (preg_match('/(?<!\d)(\d{12})(?!\d)/', preg_replace('/\s+/', '', $text), $m)) {
            $result['id_number'] = $m[1];
        }

        // 5. Hộ khẩu thường trú
        if (preg_match('/Hộ khẩu thường trú[:\s]+([^\n]+)/iu', $text, $m)) {
            $address = trim($m[1]);
            $result['permanent_address'] = $address;
            $result = array_merge($result, $this->splitAddress($address));
        }

        // 6. Trường/Tỉnh THPT (dòng năm lớp 12)
        if (preg_match('/Năm lớp 12[:\s]+([^\n]+?)[ \t]{2,}Tỉnh\s*\/\s*TP[:\s]+([^\n]+)/iu', $text, $m)) {
            $result['highschool_name'] = trim($m[1]);
            $result['highschool_province_name'] = trim($m[2]);
        }

        // 7. Năm tốt nghiệp THPT
        if (preg_match('/tốt nghiệp THPT[^\n]*?[:\s](\d{4})/iu', $text, $m)) {
            $result['highschool_graduation_year'] = $m[1];
        }

        // 8. Học lực / Hạnh kiểm lớp 12
        if (preg_match('/Học lực[ \t]+([^\n]+?)[ \t]{2,}Hạnh\s*kiểm[:\s]*([^\n]+)/iu', $text, $m)) {
            $result['highschool_academic_rank'] = trim($m[1]);
            $result['highschool_conduct_rank'] = trim($m[2]);
        }

        // 9. Năm tốt nghiệp trung cấp/CĐ + Trường cấp bằng + Học lực (lần 2)
        if (preg_match('/tốt nghiệp trung cấp,?\s*cao đẳng[:\s]+(\d{4})/iu', $text, $m)) {
            $result['university_graduation_year'] = $m[1];
        }
        if (preg_match('/Trường cấp bằng[:\s]+([^\n]+?)[ \t]{2,}Học lực[:\s]+([^\n]+)/iu', $text, $m)) {
            $result['university_name'] = trim($m[1]);
            $result['classification'] = trim($m[2]);
        }

        // 12. Điện thoại
        if (preg_match('/ĐTDĐ của bản thân[:\s]+(\d[\d\s]*)/iu', $text, $m)) {
            $result['phone_1'] = preg_replace('/\D/', '', $m[1]);
        }
        if (preg_match('/Điện thoại liên lạc của gia đình[:\s]+(\d[\d\s]*)/iu', $text, $m)) {
            $result['phone_2'] = preg_replace('/\D/', '', $m[1]);
        }

        return $result;
    }
}