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

        return $matched === $this->normalize($clean) ? $clean : $matched;
    }

    public function extract(string $text): array
    {
        $result = [];

        // ===== Họ tên (nhãn "Cho:" trên bằng, dạng "Ông/Bà <Họ Tên>") =====
        if (preg_match('/\bCho\s*[:\.]{1}\s*([^\n]+?)(?:[ \t]{2,}|\n|$)/iu', $text, $m)) {
            $rawName = trim($m[1]);

            if (preg_match('/^(Ông|Bà)\s+(.+)$/iu', $rawName, $mm)) {
                $result['gender'] = mb_strtolower($mm[1], 'UTF-8') === 'ông' ? 'Nam' : 'Nữ';
                $rawName = trim($mm[2]);
            }

            if ($rawName !== '') {
                $result = array_merge($result, $this->splitFullName($rawName));
            }
        }

        // ===== Ngày sinh =====
        if (preg_match('/Ng[àa]y\s*sinh[:\s]+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/iu', $text, $m)) {
            $result['birth_date'] = $this->toDbDate($m[1]);
        }

        // ===== Tên trường (bắt trọn cả cụm "Trường Đại học ...") =====
        if (preg_match('/(Tr[ưu]ờng\s+(?:Đại\s*h[oọ]c|Cao\s*đ[ẳă]ng)[^\n]{2,80}?)(?:[ \t]{2,}|\n|$)/iu', $text, $m)) {
            $result['university_name'] = $this->matchDictionary($m[1], Universities::all());
        }

        // ===== Hạng tốt nghiệp / Xếp loại =====
        if (preg_match('/H[aạ]ng\s*t[oố]t\s*nghi[eệ]p[:\s]+(Xu[aấ]t\s*s[aắ]c|Gi[oỏ]i|Kh[aá]|Trung\s*b[iì]nh\s*kh[aá]|Trung\s*b[iì]nh)/iu', $text, $m)) {
            $result['classification'] = trim($m[1]);
        } elseif (preg_match('/(Xu[aấ]t\s*s[aắ]c|Gi[oỏ]i|Kh[aá]|Trung\s*b[iì]nh\s*kh[aá]|Trung\s*b[iì]nh)/iu', $text, $m)) {
            $result['classification'] = trim($m[1]);
        } else {
            // Fallback: một số mẫu bằng chỉ ghi mã viết tắt (TB/K/G/XS)
            // nằm gần nhãn "Xếp loại".
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
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

        // ===== Trình độ / Hình thức / Hệ đào tạo =====
        $level = null;

        if (preg_match('/TRUNG\s*C[ÁA]P/iu', $text)) {
            $level = 'Trung cấp';
            $result['training_type'] = $result['training_type'] ?? 'Trung cấp';
        } elseif (preg_match('/CAO\s*Đ[ẲA]NG/iu', $text)) {
            $level = 'Cao đẳng';
            $result['training_type'] = $result['training_type'] ?? 'Cao đẳng';
        } elseif (preg_match('/Đ[ẠA]I\s*H[ỌO]C/iu', $text)) {
            $level = 'Đại học';
            $result['training_type'] = $result['training_type'] ?? 'Đại học';
        }

        if ($training = $this->afterLabel($text, ['Hình thức đào tạo', 'Hệ đào tạo'], 40)) {
            $result['training_type'] = $training;
        } elseif (preg_match('/(Ch[ií]nh\s*quy|V[uừ]a\s*l[aà]m\s*v[uừ]a\s*h[oọ]c|T[uừ]\s*xa|Li[eê]n\s*th[oô]ng)/iu', $text, $m)) {
            $result['training_type'] = $m[1];
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
        } elseif (preg_match('/B[ẰĂă]NG\s+[^\n]{0,60}\n\s*([^\n]{4,120})\n/u', $text, $m)) {
            // Dòng chữ IN HOA ngay sau dòng "BẰNG ..." thường là tên ngành.
            // Nếu OCR gộp 2 cột Anh/Việt trên cùng một dòng,
            // lấy đoạn cuối dòng (cột bên phải = tiếng Việt).
            $segments = array_values(
                array_filter(
                    array_map(
                        'trim',
                        preg_split('/[ \t]{2,}/', $m[1])
                    )
                )
            );

            $candidate = $segments
                ? trim(preg_replace('/\s+/', ' ', end($segments)))
                : '';

            if (
                $candidate !== ''
                && mb_strtoupper($candidate, 'UTF-8') === $candidate
                && !preg_match(
                    '/CỘNG\s*HÒA|\bCHO\b|CẤP|TRƯỜNG|VIỆT\s*NAM/iu',
                    $candidate
                )
            ) {
                $major = $candidate;
                $result['major_name'] = $this->matchDictionary(
                    $candidate,
                    Majors::all()
                );
            }
        }

        // Fallback: ngành học nằm gần dòng tiếng Anh như "Office ... informatics"
        if (empty($result['major_name'])) {
            $lines = array_values(
                array_filter(
                    array_map('trim', explode("\n", $text))
                )
            );

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

        // ===== Số hiệu văn bằng =====
        if (
            preg_match(
                '/S[oố]\s*hi[eệ]u(?:\s*v[aă]n\s*b[aằ]ng)?[:\s]+([^\n]{3,20}?)(?:[ \t]{2,}|\n|$)/iu',
                $text,
                $m
            )
        ) {
            $result['diploma_number'] = trim($m[1]);
        } elseif (!empty($lines)) {
            // Fallback: tìm một dòng chỉ chứa số 4-8 chữ số
            foreach ($lines as $line) {
                if (preg_match('/^\d{4,8}$/', trim($line))) {
                    $result['diploma_number'] = trim($line);
                    break;
                }
            }
        }

        // ===== Số vào sổ gốc cấp văn bằng =====
        if (
            preg_match(
                '/S[oố]\s*v[aà]o\s*s[oổ](?:\s*g[oố]c)?(?:\s*c[aấ]p\s*v[aă]n\s*b[aằ]ng)?[:\s]+([^\n]{3,40}?)(?:[ \t]{2,}|\n|$)/iu',
                $text,
                $m
            )
        ) {
            $result['diploma_registry_number'] = trim($m[1]);
        } elseif (
            preg_match(
                '/S[oố]\s*v[àa]o\s*s[oổ][^\d]{0,40}(\d{5,})/iu',
                $text,
                $m
            )
        ) {
            $result['diploma_registry_number'] = $m[1];
        }

        // ===== Năm tốt nghiệp =====
        if (
            preg_match(
                '/t[oố]t\s*nghi[eệ]p[^\n]{0,20}(\d{4})/iu',
                $text,
                $m
            )
        ) {
            // Chỉ nhận nếu số 4 chữ số thực sự là năm hợp lý.
            if (
                $m[1] >= 1990
                && $m[1] <= (int) date('Y') + 1
            ) {
                $result['university_graduation_year'] = $m[1];
            }
        }

        if (
            empty($result['university_graduation_year'])
            && preg_match(
                '/ng[àa]y\s+\d{1,2}\s+th[áa]ng\s+\d{1,2}\s+n[ăa]m\s+(\d{4})/iu',
                $text,
                $m
            )
        ) {
            // Fallback: lấy năm từ ngày ký cấp bằng.
            $result['university_graduation_year'] = $m[1];
        }

        // ===== Tỉnh trường đại học =====
        // Lấy từ dòng ghi ngày cấp bằng, kiểu:
        // "Kiên Giang, ngày 08 tháng 08 năm 2024"
        if (
            preg_match(
                '/([A-ZÀ-Ỹ][a-zà-ỹ]+(?:\s+[A-ZÀ-Ỹ][a-zà-ỹ]+)*)\s*,?\s*ng[àa]y[.\s]*\d/iu',
                $text,
                $m
            )
        ) {
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