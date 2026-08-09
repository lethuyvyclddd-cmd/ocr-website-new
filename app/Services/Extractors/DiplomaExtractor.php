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
        } else {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $labelIndex = null;

            foreach ($lines as $i => $line) {
                if (str_contains($this->normalize($line), 'xep loai')) {
                    $labelIndex = $i;
                    break;
                }
            }

            if ($labelIndex !== null) {
                $map = ['TB' => 'Trung bình', 'K' => 'Khá', 'G' => 'Giỏi', 'XS' => 'Xuất sắc'];
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

        if ($training = $this->afterLabel($text, ['Hình thức đào tạo', 'Hệ đào tạo'], 40)) {
            $result['training_type'] = $training;
        } elseif (preg_match('/(Ch[ií]nh quy|V[uừ]a l[aà]m v[uừ]a h[oọ]c|T[uừ] xa|Li[eê]n th[oô]ng)/iu', $text, $m)) {
            $result['training_type'] = $m[1];
        }

        if (preg_match('/S[oố]\s*hi[eệ]u[.:\s]+(\d+)/iu', $text, $m)) {
            $result['diploma_number'] = $m[1];
        }

        if (preg_match('/S[oố]\s*v[àa]o\s*s[oổ][^\d]{0,40}(\d{5,})/iu', $text, $m)) {
            $result['diploma_registry_number'] = $m[1];
        }

        return $result;
    }
}