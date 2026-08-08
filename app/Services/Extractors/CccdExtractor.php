<?php

namespace App\Services\Extractors;

class CccdExtractor extends BaseExtractor
{
    public function extract(string $text): array
    {
        $result = [];

        if (preg_match('/(?<!\d)(\d{12})(?!\d)/', preg_replace('/\s+/', '', $text), $m)) {
            $result['id_number'] = $m[1];
        }

        $fullName = $this->afterLabel($text, ['Họ và tên', 'Ho va ten', 'Full name']);
        if (! $fullName) {
            $excludeKeywords = [
                'cong hoa', 'socialist', 'can cuoc', 'viet nam', 'chu nghia',
                'doc lap', 'tu do', 'hanh phuc', 'independence', 'freedom',
                'happiness', 'republic', 'citizen', 'identity', 'card',
                'fullname', 'full name', 'date of birth', 'place of',
                'nationality', 'quoc tich', 'so /', 'so :',
            ];

            foreach (explode("\n", $text) as $line) {
                $line = trim($line);
                $normLine = $this->normalize($line);

                $isExcluded = false;
                foreach ($excludeKeywords as $keyword) {
                    if (str_contains($normLine, $keyword)) {
                        $isExcluded = true;
                        break;
                    }
                }

                if (mb_strlen($line) >= 6 && $line === mb_strtoupper($line, 'UTF-8')
                    && ! preg_match('/\d/', $line) && ! $isExcluded) {
                    $fullName = $line;
                    break;
                }
            }
        }
        if ($fullName) {
            $result = array_merge($result, $this->splitFullName($fullName));
        }

        if ($dob = $this->afterLabel($text, ['Ngày sinh', 'Date of birth'], 12)) {
            $result['birth_date'] = $this->toDbDate($dob);
        } else {
            $result['birth_date'] = $this->firstDateMatch($text);
        }

        if (preg_match('/Gi[oớ][ií]{0,1}\s*t[ií]nh.{0,15}(Nam|N[uữ])/iu', $text, $m)) {
            $result['gender'] = mb_strtolower($m[1]) === 'nam' ? 'Nam' : 'Nữ';
        } elseif (preg_match('/\bNam\b/u', $text)) {
            $result['gender'] = 'Nam';
        } elseif (preg_match('/\bN[uữ]\b/u', $text)) {
            $result['gender'] = 'Nữ';
        }

        if ($place = $this->afterLabel($text, ['Quê quán', 'Nơi sinh', 'Place of origin'], 120)) {
            $result['place_of_birth'] = $place;
        }

        if ($ethnic = $this->afterLabel($text, ['Dân tộc', 'Ethnicity'], 30)) {
            $result['ethnic'] = $ethnic;
        }

        if ($address = $this->afterLabel($text, ['Nơi thường trú', 'Nơi cư trú', 'Place of residence'], 150)) {
            $result['permanent_address'] = $address;
            $result = array_merge($result, $this->splitAddress($address));
        }

        return $result;
    }
}