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

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));

        $fullName = null;
        {
            $excludeKeywords = [
                'cong hoa', 'socialist', 'can cuoc', 'viet nam', 'chu nghia',
                'doc lap', 'tu do', 'hanh phuc', 'independence', 'freedom',
                'happiness', 'republic', 'citizen', 'identity', 'card',
                'fullname', 'full name', 'date of birth', 'place of',
                'nationality', 'quoc tich', 'so /', 'so :',
            ];
            foreach ($lines as $line) {
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

        $result['birth_date'] = $this->firstDateMatch($text);

        if (preg_match('/Gi[oớ][ií]{0,1}\s*t[ií]nh.{0,15}(Nam|N[uữ])/iu', $text, $m)) {
            $result['gender'] = mb_strtolower($m[1]) === 'nam' ? 'Nam' : 'Nữ';
        } elseif (preg_match('/\bNam\b/u', $text)) {
            $result['gender'] = 'Nam';
        } elseif (preg_match('/\bN[uữ]\b/u', $text)) {
            $result['gender'] = 'Nữ';
        }

        $place = $this->findValueNearLabel($lines, 'que quan', 'place of origin');
        if ($place) {
            $result['place_of_birth'] = $place;
        }

        if ($ethnic = $this->afterLabel($text, ['Dân tộc', 'Ethnicity'], 30)) {
            $result['ethnic'] = $ethnic;
        }

        // Không lấy địa chỉ từ CCCD nữa - dùng địa chỉ trên Phiếu ĐKXT (mới hơn,
        // đúng sau khi Việt Nam sáp nhập tỉnh, còn địa chỉ in trên CCCD cũ không còn đúng).

        return $result;
    }

    protected function findValueNearLabel(array $lines, string $vnKeyword, string $enKeyword): ?string
    {
        foreach ($lines as $i => $line) {
            $normLine = $this->normalize($line);

            if (! str_contains($normLine, $vnKeyword) && ! str_contains($normLine, $enKeyword)) {
                continue;
            }

            $pos = mb_stripos($normLine, $enKeyword, 0, 'UTF-8');
            if ($pos !== false) {
                $afterPos = $pos + mb_strlen($enKeyword, 'UTF-8');
                $origRemainder = trim(mb_substr($line, $afterPos, null, 'UTF-8'));
                $origRemainder = preg_replace('/^[\s:\/|.,\-]+/u', '', $origRemainder);
                if (mb_strlen(trim($origRemainder)) > 2 && ! preg_match('/^\d+$/', trim($origRemainder))) {
                    return trim($origRemainder);
                }
            }

            if (isset($lines[$i + 1])) {
                $nextLine = trim($lines[$i + 1]);
                if (mb_strlen($nextLine) > 1) {
                    return $nextLine;
                }
            }

            return null;
        }

        return null;
    }

    protected function findAddressAcrossLines(array $lines, string $vnKeyword, string $enKeyword): ?string
    {
        foreach ($lines as $i => $line) {
            $normLine = $this->normalize($line);

            if (! str_contains($normLine, $vnKeyword) && ! str_contains($normLine, $enKeyword)) {
                continue;
            }

            $parts = [];

            $pos = mb_stripos($normLine, $enKeyword, 0, 'UTF-8');
            if ($pos !== false) {
                $afterPos = $pos + mb_strlen($enKeyword, 'UTF-8');
                $remainder = trim(mb_substr($line, $afterPos, null, 'UTF-8'));
                $remainder = preg_replace('/^[\s:\/|.,\-]+/u', '', $remainder);
                if (mb_strlen($remainder) > 1) {
                    $parts[] = $remainder;
                }
            }

            $j = $i + 1;
            $checked = 0;
            while ($j < count($lines) && $checked < 3 && count($parts) < 2) {
                $nextLine = $lines[$j];
                $nextNorm = $this->normalize($nextLine);

                $isExpiryLine = str_contains($nextNorm, 'gia tri den')
                    || str_contains($nextNorm, 'gia mon')
                    || str_contains($nextNorm, 'co gia')
                    || str_contains($nextNorm, 'expiry')
                    || str_contains($nextNorm, 'date of')
                    || preg_match('/\d{1,2}\/\d{1,2}\/\d{4}/', $nextNorm);

                if ($isExpiryLine) {
                    $j++;
                    $checked++;
                    continue;
                }

                if (str_contains($nextNorm, 'noi thuong tru') || str_contains($nextNorm, 'place of residence')) {
                    break;
                }

                $parts[] = trim($nextLine);
                $j++;
                $checked++;
            }

            if (! empty($parts)) {
                return implode(', ', $parts);
            }
        }

        return null;
    }
}