<?php

namespace App\Services\Extractors;

class DiplomaExtractor extends BaseExtractor
{
    public function extract(string $text): array
    {
        $result = [];

        if ($university = $this->afterLabel($text, ['Trường', 'Trường Đại học', 'Trường đại học'], 150)) {
            $result['university_name'] = $university;
        }

        if (preg_match('/t[oố]t nghi[eệ]p.{0,20}(\d{4})/iu', $text, $m)) {
            $result['university_graduation_year'] = $m[1];
        }

        if (preg_match('/(Xu[aấ]t s[aắ]c|Gi[oỏ]i|Kh[aá]|Trung b[iì]nh kh[aá]|Trung b[iì]nh)/iu', $text, $m)) {
            $result['classification'] = $m[1];
        }

        if ($training = $this->afterLabel($text, ['Hình thức đào tạo', 'Hệ đào tạo'], 40)) {
            $result['training_type'] = $training;
        } elseif (preg_match('/(Ch[ií]nh quy|V[uừ]a l[aà]m v[uừ]a h[oọ]c|T[uừ] xa|Li[eê]n th[oô]ng)/iu', $text, $m)) {
            $result['training_type'] = $m[1];
        }

        if ($diplomaNo = $this->afterLabel($text, ['Số hiệu', 'Số hiệu bằng'], 30)) {
            $result['diploma_number'] = $diplomaNo;
        }

        if ($registryNo = $this->afterLabel($text, ['Số vào sổ', 'Số vào sổ cấp bằng', 'Vào sổ số'], 30)) {
            $result['diploma_registry_number'] = $registryNo;
        }

        return $result;
    }
}