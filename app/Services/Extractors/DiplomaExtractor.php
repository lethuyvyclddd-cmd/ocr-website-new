<?php

namespace App\Services\Extractors;

use App\Dictionaries\Majors;
use App\Dictionaries\Provinces;
use App\Dictionaries\Universities;

/**
 * Extractor văn bằng / bằng tốt nghiệp tổng quát.
 *
 * Output:
 * - university_province_name
 * - university_name
 * - university_graduation_year
 * - note_2
 * - training_type
 * - diploma_number
 * - diploma_registry_number
 */
class DiplomaExtractor extends BaseExtractor
{
    private const OUTPUT_FIELDS = [
        'university_province_name',
        'university_name',
        'university_graduation_year',
        'note_2',
        'training_type',
        'diploma_number',
        'diploma_registry_number',
    ];

    public function extract(string $text): array
    {
        $lines = $this->splitLines($text);

        if (empty($lines)) {
            return [];
        }

        $normLines = array_map(
            fn(string $line): string => $this->normalize($line),
            $lines
        );

        $level = $this->detectLevel(
            $text,
            $normLines
        );

        $school = $this->findSchool(
            $text,
            $lines,
            $normLines
        );

        $schoolName = $school['name'] ?? null;
        $schoolIndex = $school['index'] ?? null;

        $province = $this->findUniversityProvince(
            $schoolName,
            $schoolIndex,
            $lines
        );

        $major = $this->findMajor(
            $text,
            $lines,
            $normLines
        );

        $trainingType = $this->findTrainingType(
            $text,
            $lines,
            $normLines
        );

        $graduationYear = $this->findGraduationYear(
            $text,
            $lines,
            $normLines
        );

        /*
         * ========================================================
         * QUAN TRỌNG
         * ========================================================
         *
         * Phải tìm SỐ VÀO SỔ trước số hiệu bằng.
         *
         * Ví dụ OCR:
         *
         * Số vào sổ
         * 30
         *
         * thì diploma_registry_number phải = "30".
         */
        $registryNumber = $this->findRegistryNumber(
            $text,
            $lines,
            $normLines,
            $graduationYear
        );

        /*
         * Số hiệu bằng.
         *
         * Chỉ tìm sau khi đã xử lý số vào sổ.
         */
        $diplomaNumber = $this->findDiplomaNumber(
            $text,
            $lines,
            $normLines,
            $registryNumber
        );

        /*
         * Note 2:
         *
         * Cử nhân / Kỹ sư / Bác sĩ / Dược sĩ /
         * Thạc sĩ / Tiến sĩ -> Đại học.
         *
         * Trung cấp -> Trung cấp.
         * Cao đẳng -> Cao đẳng.
         */
        $note2 = $this->buildNote2(
            $level,
            $major
        );

        $result = [
            'university_province_name' => $province,
            'university_name' => $schoolName,
            'university_graduation_year' => $graduationYear,
            'note_2' => $note2,
            'training_type' => $trainingType,
            'diploma_number' => $diplomaNumber,
            'diploma_registry_number' => $registryNumber,
        ];

        return array_intersect_key(
            array_filter(
                $result,
                static function ($value): bool {
                    return $value !== null
                        && $value !== '';
                }
            ),
            array_flip(self::OUTPUT_FIELDS)
        );
    }

    /* ============================================================
     * LINE
     * ============================================================ */

    private function splitLines(string $text): array
    {
        $text = str_replace(
            ["\r\n", "\r", "\f", "\v"],
            "\n",
            $text
        );

        $rawLines = preg_split(
            '/\n+/u',
            $text
        );

        if (!is_array($rawLines)) {
            return [];
        }

        $result = [];

        foreach ($rawLines as $line) {
            $line = preg_replace(
                '/[ \t]+/u',
                ' ',
                trim($line)
            );

            if (
                $line !== null &&
                $line !== ''
            ) {
                $result[] = $line;
            }
        }

        return $result;
    }

    private function cleanValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        );

        if ($value === null) {
            return null;
        }

        $value = trim(
            $value,
            " \t\n\r\0\x0B:;,.|-"
        );

        return $value !== ''
            ? $value
            : null;
    }

    private function compact(string $value): string
    {
        $normalized = $this->normalize($value);

        $result = preg_replace(
            '/\s+/u',
            '',
            $normalized
        );

        return $result !== null
            ? $result
            : '';
    }

    private function containsAny(
        string $value,
        array $needles
    ): bool {
        foreach ($needles as $needle) {
            if (
                str_contains(
                    $value,
                    $this->normalize($needle)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================
     * TRÌNH ĐỘ
     * ============================================================ */

    private function detectLevel(
        string $text,
        array $normLines
    ): ?string {
        $normText = $this->normalize($text);

        /*
         * Thứ tự rất quan trọng.
         *
         * Không để "bachelor" / "cu nhan" bị đọc sai
         * thành một level khác.
         */
        $patterns = [
            'trung hoc pho thong' => 'THPT',
            'thpt' => 'THPT',
            'high school' => 'THPT',
            'secondary school' => 'THPT',

            'chuyen khoa ii' => 'Chuyên khoa II',
            'chuyen khoa 2' => 'Chuyên khoa II',
            'chuyen khoa i' => 'Chuyên khoa I',
            'chuyen khoa 1' => 'Chuyên khoa I',

            'doctor of philosophy' => 'Tiến sĩ',
            'doctorate' => 'Tiến sĩ',
            'tien si' => 'Tiến sĩ',
            'phd' => 'Tiến sĩ',
            'ph d' => 'Tiến sĩ',

            'master degree' => 'Thạc sĩ',
            'master of' => 'Thạc sĩ',
            'thac si' => 'Thạc sĩ',
            'master' => 'Thạc sĩ',
            'msc' => 'Thạc sĩ',
            'ma degree' => 'Thạc sĩ',

            'doctor of medicine' => 'Bác sĩ',
            'medical doctor' => 'Bác sĩ',
            'bac si' => 'Bác sĩ',
            'md degree' => 'Bác sĩ',

            'duoc si' => 'Dược sĩ',
            'pharmacist' => 'Dược sĩ',
            'pharmacy' => 'Dược sĩ',

            'ky su' => 'Kỹ sư',
            'engineer' => 'Kỹ sư',
            'engineering' => 'Kỹ sư',

            'bachelor of' => 'Cử nhân',
            'bachelor degree' => 'Cử nhân',
            'bachelor' => 'Cử nhân',
            'cu nhan' => 'Cử nhân',

            'dai hoc' => 'Đại học',
            'university' => 'Đại học',

            'cao dang' => 'Cao đẳng',
            'college' => 'Cao đẳng',

            'trung cap' => 'Trung cấp',
            'vocational secondary' => 'Trung cấp',
            'intermediate' => 'Trung cấp',

            'so cap' => 'Sơ cấp',
            'elementary level' => 'Sơ cấp',

            'hoc vien' => 'Học viện',
            'academy' => 'Học viện',

            'chung chi' => 'Chứng chỉ',
            'certificate' => 'Chứng chỉ',

            'chung nhan' => 'Chứng nhận',
            'certification' => 'Chứng nhận',
        ];

        foreach ($patterns as $needle => $level) {
            if (str_contains($normText, $needle)) {
                return $level;
            }
        }

        foreach ($normLines as $line) {
            $level = $this->detectLevelFromLine($line);

            if ($level !== null) {
                return $level;
            }
        }

        return null;
    }

    private function detectLevelFromLine(
        string $line
    ): ?string {
        $rules = [
            'trung hoc pho thong' => 'THPT',
            'thpt' => 'THPT',
            'high school' => 'THPT',
            'secondary school' => 'THPT',

            'chuyen khoa ii' => 'Chuyên khoa II',
            'chuyen khoa 2' => 'Chuyên khoa II',
            'chuyen khoa i' => 'Chuyên khoa I',
            'chuyen khoa 1' => 'Chuyên khoa I',

            'thac si' => 'Thạc sĩ',
            'master' => 'Thạc sĩ',

            'tien si' => 'Tiến sĩ',
            'doctorate' => 'Tiến sĩ',
            'phd' => 'Tiến sĩ',

            'bac si' => 'Bác sĩ',
            'medical doctor' => 'Bác sĩ',

            'duoc si' => 'Dược sĩ',
            'pharmacist' => 'Dược sĩ',

            'ky su' => 'Kỹ sư',
            'engineer' => 'Kỹ sư',

            'cu nhan' => 'Cử nhân',
            'bachelor' => 'Cử nhân',

            'dai hoc' => 'Đại học',
            'university' => 'Đại học',

            'cao dang' => 'Cao đẳng',
            'college' => 'Cao đẳng',

            'trung cap' => 'Trung cấp',
            'intermediate' => 'Trung cấp',

            'hoc vien' => 'Học viện',
            'academy' => 'Học viện',

            'so cap' => 'Sơ cấp',
            'elementary level' => 'Sơ cấp',

            'chung chi' => 'Chứng chỉ',
            'certificate' => 'Chứng chỉ',

            'chung nhan' => 'Chứng nhận',
            'certification' => 'Chứng nhận',
        ];

        foreach ($rules as $needle => $value) {
            if (str_contains($line, $needle)) {
                return $value;
            }
        }

        return null;
    }

    /* ============================================================
     * TRƯỜNG
     * ============================================================ */

    private function findSchool(
        string $text,
        array $lines,
        array $normLines
    ): ?array {
        $schools = array_values(
            array_filter(
                Universities::all(),
                'is_string'
            )
        );

        usort(
            $schools,
            static function (
                string $a,
                string $b
            ): int {
                return mb_strlen($b, 'UTF-8')
                    <=> mb_strlen($a, 'UTF-8');
            }
        );

        foreach ($schools as $school) {
            $schoolNorm = $this->normalize($school);

            if (
                mb_strlen(
                    $schoolNorm,
                    'UTF-8'
                ) < 5
            ) {
                continue;
            }

            $short = preg_replace(
                '/^truong\s+/u',
                '',
                $schoolNorm
            );

            if ($short === null) {
                $short = $schoolNorm;
            }

            foreach ($normLines as $index => $line) {
                if ($this->isSchoolNoise($line)) {
                    continue;
                }

                if (
                    str_contains(
                        $line,
                        $schoolNorm
                    )
                ) {
                    return [
                        'name' => $school,
                        'index' => $index,
                    ];
                }

                if (
                    $short !== '' &&
                    mb_strlen(
                        $short,
                        'UTF-8'
                    ) >= 8 &&
                    str_contains(
                        $line,
                        $short
                    )
                ) {
                    return [
                        'name' => $school,
                        'index' => $index,
                    ];
                }

                $lineCompact = $this->compact($line);
                $schoolCompact = $this->compact($school);
                $shortCompact = $this->compact($short);

                if (
                    $schoolCompact !== '' &&
                    str_contains(
                        $lineCompact,
                        $schoolCompact
                    )
                ) {
                    return [
                        'name' => $school,
                        'index' => $index,
                    ];
                }

                if (
                    $shortCompact !== '' &&
                    mb_strlen(
                        $shortCompact,
                        'UTF-8'
                    ) >= 8 &&
                    str_contains(
                        $lineCompact,
                        $shortCompact
                    )
                ) {
                    return [
                        'name' => $school,
                        'index' => $index,
                    ];
                }
            }
        }

        foreach ($normLines as $index => $line) {
            if ($this->isSchoolNoise($line)) {
                continue;
            }

            if (
                !$this->looksLikeSchoolLine($line)
            ) {
                continue;
            }

            for (
                $count = 1;
                $count <= 4;
                $count++
            ) {
                $parts = [];
                $rawParts = [];

                for (
                    $j = 0;
                    $j < $count;
                    $j++
                ) {
                    $current = $index + $j;

                    if (
                        !isset(
                            $normLines[$current]
                        )
                    ) {
                        break;
                    }

                    if (
                        $this->isSchoolNoise(
                            $normLines[$current]
                        )
                    ) {
                        break;
                    }

                    $parts[] =
                        $normLines[$current];

                    $rawParts[] =
                        $lines[$current];
                }

                if (
                    count($parts) !== $count
                ) {
                    continue;
                }

                $candidateNorm =
                    implode(' ', $parts);

                $candidateRaw =
                    implode(' ', $rawParts);

                if (
                    !$this->looksLikeSchoolLine(
                        $candidateNorm
                    )
                ) {
                    continue;
                }

                $matched = $this->matchSchool(
                    $candidateRaw
                );

                if ($matched !== null) {
                    return [
                        'name' => $matched,
                        'index' => $index,
                    ];
                }
            }
        }

        $best = null;
        $bestScore = -PHP_INT_MAX;

        foreach ($normLines as $index => $line) {
            if (
                $this->isSchoolNoise($line)
            ) {
                continue;
            }

            if (
                !$this->looksLikeSchoolLine($line)
            ) {
                continue;
            }

            $candidate = $this->compact($line);

            if (
                mb_strlen(
                    $candidate,
                    'UTF-8'
                ) < 10
            ) {
                continue;
            }

            foreach ($schools as $school) {
                $schoolCompact =
                    $this->compact($school);

                if ($schoolCompact === '') {
                    continue;
                }

                if (
                    str_contains(
                        $candidate,
                        $schoolCompact
                    )
                ) {
                    return [
                        'name' => $school,
                        'index' => $index,
                    ];
                }

                $ratio =
                    $this->levenshteinRatio(
                        $candidate,
                        $schoolCompact
                    );

                $score =
                    (1 - min($ratio, 1))
                    * 300;

                if (
                    str_contains(
                        $line,
                        'truong'
                    )
                ) {
                    $score += 100;
                }

                if (
                    str_contains(
                        $line,
                        'dai hoc'
                    )
                ) {
                    $score += 100;
                }

                if (
                    str_contains(
                        $line,
                        'cao dang'
                    )
                ) {
                    $score += 100;
                }

                if (
                    str_contains(
                        $line,
                        'trung cap'
                    )
                ) {
                    $score += 100;
                }

                if (
                    str_contains(
                        $line,
                        'hoc vien'
                    )
                ) {
                    $score += 100;
                }

                if (
                    $this->hasVietnameseCharacters(
                        $lines[$index]
                    )
                ) {
                    $score += 50;
                }

                if (
                    $ratio <= 0.32 &&
                    $score > $bestScore
                ) {
                    $bestScore = $score;

                    $best = [
                        'name' => $school,
                        'index' => $index,
                    ];
                }
            }
        }

        foreach ($normLines as $index => $line) {
            if (
                $this->isSchoolNoise($line)
            ) {
                continue;
            }

            if (
                $this->looksLikeSchoolLine($line)
            ) {
                return [
                    'name' => $this->cleanValue(
                        $lines[$index]
                    ),
                    'index' => $index,
                ];
            }
        }

        return $best;
    }

    private function matchSchool(
        string $raw
    ): ?string {
        $raw = $this->cleanValue($raw);

        if ($raw === null) {
            return null;
        }

        $normRaw = $this->normalize($raw);

        $schools = array_values(
            array_filter(
                Universities::all(),
                'is_string'
            )
        );

        usort(
            $schools,
            static function (
                string $a,
                string $b
            ): int {
                return mb_strlen($b, 'UTF-8')
                    <=> mb_strlen($a, 'UTF-8');
            }
        );

        foreach ($schools as $school) {
            $normSchool =
                $this->normalize($school);

            if (
                $normRaw === $normSchool
            ) {
                return $school;
            }

            if (
                str_contains(
                    $normRaw,
                    $normSchool
                )
            ) {
                return $school;
            }

            $short = preg_replace(
                '/^truong\s+/u',
                '',
                $normSchool
            );

            if ($short === null) {
                $short = $normSchool;
            }

            $rawShort = preg_replace(
                '/^truong\s+/u',
                '',
                $normRaw
            );

            if ($rawShort === null) {
                $rawShort = $normRaw;
            }

            if (
                $short !== '' &&
                (
                    $rawShort === $short ||
                    str_contains(
                        $rawShort,
                        $short
                    )
                )
            ) {
                return $school;
            }

            $compactRaw =
                $this->compact($raw);

            $compactSchool =
                $this->compact($school);

            $compactShort =
                $this->compact($short);

            if (
                $compactSchool !== '' &&
                str_contains(
                    $compactRaw,
                    $compactSchool
                )
            ) {
                return $school;
            }

            if (
                $compactShort !== '' &&
                str_contains(
                    $compactRaw,
                    $compactShort
                )
            ) {
                return $school;
            }
        }

        return null;
    }

    private function looksLikeSchoolLine(
        string $norm
    ): bool {
        $keywords = [
            'truong dai hoc',
            'truong cao dang',
            'truong trung cap',
            'truong thpt',
            'dai hoc',
            'cao dang',
            'trung cap',
            'hoc vien',
            'trung hoc pho thong',
            'thpt',
            'university',
            'college',
            'academy',
            'institute',
            'school',
            'technical school',
            'vocational school',
            'polytechnic',
        ];

        foreach ($keywords as $keyword) {
            if (
                str_contains(
                    $norm,
                    $keyword
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasVietnameseCharacters(
        string $value
    ): bool {
        return preg_match(
            '/[ăâđêôơưáàảãạắằẳẵặấầẩẫậéèẻẽẹếềểễệíìỉĩịóòỏõọốồổỗộớờởỡợúùủũụứừửữựýỳỷỹỵ]/iu',
            $value
        ) === 1;
    }

    private function isSchoolNoise(
        string $norm
    ): bool {
        $noise = [
            'cong hoa xa hoi',
            'socialist republic',
            'doc lap tu do',
            'independence',
            'hanh phuc',

            'bo giao duc',
            'ministry of education',

            'bang tot nghiep',
            'degree of bachelor',
            'diploma',

            'so hieu',
            'so hiu',
            'diploma no',
            'diploma number',
            'degree no',
            'degree number',

            /*
             * KHÔNG coi "so vao so" là số hiệu.
             */
            'hinh thuc dao tao',
            'he dao tao',
            'loai hinh dao tao',
            'training mode',
            'mode of study',

            'nganh dao tao',
            'chuyen nganh',
            'field of study',
            'major',

            'xep loai',

            'ngay sinh',
            'date of birth',

            'hieu truong',
            'rector',
            'president',
            'principal',
        ];

        foreach ($noise as $item) {
            if (
                str_contains(
                    $norm,
                    $item
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================
     * TỈNH / THÀNH PHỐ
     * ============================================================ */

    private function findUniversityProvince(
        ?string $schoolName,
        ?int $schoolIndex,
        array $lines
    ): ?string {
        if ($schoolName !== null) {
            try {
                $province =
                    Universities::provinceOf(
                        $schoolName
                    );

                if (
                    $province !== null &&
                    $province !== ''
                ) {
                    return $province;
                }
            } catch (\Throwable $e) {
            }
        }

        if ($schoolIndex !== null) {
            $indexes = [
                $schoolIndex,
                $schoolIndex - 1,
                $schoolIndex + 1,
                $schoolIndex - 2,
                $schoolIndex + 2,
                $schoolIndex - 3,
                $schoolIndex + 3,
                $schoolIndex + 4,
                $schoolIndex + 5,
            ];

            foreach ($indexes as $index) {
                if (!isset($lines[$index])) {
                    continue;
                }

                $province =
                    $this->findProvinceInLine(
                        $lines[$index]
                    );

                if ($province !== null) {
                    return $province;
                }
            }
        }

        foreach ($lines as $line) {
            $province =
                $this->findProvinceInLine(
                    $line
                );

            if ($province !== null) {
                return $province;
            }
        }

        return null;
    }

    private function findProvinceInLine(
        string $line
    ): ?string {
        $norm = $this->normalize($line);

        $provinces = array_values(
            array_filter(
                Provinces::all(),
                'is_string'
            )
        );

        usort(
            $provinces,
            static function (
                string $a,
                string $b
            ): int {
                return mb_strlen($b, 'UTF-8')
                    <=> mb_strlen($a, 'UTF-8');
            }
        );

        foreach ($provinces as $province) {
            $p = $this->normalize($province);

            if (
                $p !== '' &&
                str_contains($norm, $p)
            ) {
                return $province;
            }
        }

        $aliases = [
            'tp hcm' => 'TP Hồ Chí Minh',
            'tphcm' => 'TP Hồ Chí Minh',
            'tp ho chi minh' => 'TP Hồ Chí Minh',
            'ho chi minh' => 'TP Hồ Chí Minh',

            'ha noi' => 'Hà Nội',
            'tp ha noi' => 'Hà Nội',

            'hai phong' => 'Hải Phòng',
            'da nang' => 'Đà Nẵng',
            'can tho' => 'Cần Thơ',

            'ba ria vung tau' =>
                'Bà Rịa - Vũng Tàu',
        ];

        foreach (
            $aliases as $needle => $province
        ) {
            if (
                str_contains(
                    $norm,
                    $needle
                )
            ) {
                return $province;
            }
        }

        return null;
    }

    /* ============================================================
     * CHUYÊN NGÀNH
     * ============================================================ */

    private function findMajor(
        string $text,
        array $lines,
        array $normLines
    ): ?string {
        $labels = [
            'chuyên ngành đào tạo',
            'chuyên ngành',
            'ngành đào tạo',
            'ngành học',
            'ngành',
            'major',
            'field of study',
            'field',
            'specialization',
            'speciality',
        ];

        foreach (
            $lines as $index => $line
        ) {
            $norm = $normLines[$index];

            foreach ($labels as $label) {
                $labelNorm =
                    $this->normalize($label);

                if (
                    !str_contains(
                        $norm,
                        $labelNorm
                    )
                ) {
                    continue;
                }

                $after =
                    $this->textAfterLabel(
                        $line,
                        $label
                    );

                if (
                    $after === null &&
                    isset($lines[$index + 1])
                ) {
                    $after =
                        $lines[$index + 1];
                }

                $major =
                    $this->matchMajor($after);

                if ($major !== null) {
                    return $major;
                }
            }
        }

        $majors = array_values(
            array_filter(
                Majors::all(),
                'is_string'
            )
        );

        usort(
            $majors,
            static function (
                string $a,
                string $b
            ): int {
                return mb_strlen($b, 'UTF-8')
                    <=> mb_strlen($a, 'UTF-8');
            }
        );

        $normText =
            $this->normalize($text);

        foreach ($majors as $major) {
            $m = $this->normalize($major);

            if (
                mb_strlen($m, 'UTF-8') >= 4 &&
                str_contains(
                    $normText,
                    $m
                )
            ) {
                return $major;
            }
        }

        return null;
    }

    private function matchMajor(
        ?string $raw
    ): ?string {
        $raw = $this->cleanValue($raw);

        if ($raw === null) {
            return null;
        }

        $parts = preg_split(
            '/(?:hình thức đào tạo|hệ đào tạo|loại hình đào tạo|xếp loại|số hiệu|số vào sổ|ngày sinh|date of birth|ngày cấp|training mode|mode of study)/iu',
            $raw,
            2
        );

        $raw = $parts[0] ?? null;
        $raw = $this->cleanValue($raw);

        if ($raw === null) {
            return null;
        }

        $norm =
            $this->normalize($raw);

        if (
            $this->looksLikeMajorNoise(
                $norm
            )
        ) {
            return null;
        }

        $majors = array_values(
            array_filter(
                Majors::all(),
                'is_string'
            )
        );

        usort(
            $majors,
            static function (
                string $a,
                string $b
            ): int {
                return mb_strlen($b, 'UTF-8')
                    <=> mb_strlen($a, 'UTF-8');
            }
        );

        foreach ($majors as $major) {
            $m = $this->normalize($major);

            if (
                $norm === $m ||
                (
                    mb_strlen(
                        $m,
                        'UTF-8'
                    ) >= 4 &&
                    str_contains(
                        $norm,
                        $m
                    )
                )
            ) {
                return $major;
            }
        }

        if (
            mb_strlen(
                $norm,
                'UTF-8'
            ) >= 5
        ) {
            $best = null;
            $bestRatio = 1.0;

            $a = preg_replace(
                '/\s+/u',
                '',
                $norm
            );

            if ($a === null) {
                $a = '';
            }

            foreach ($majors as $major) {
                $b = preg_replace(
                    '/\s+/u',
                    '',
                    $this->normalize($major)
                );

                if (
                    $b === null ||
                    $b === ''
                ) {
                    continue;
                }

                $ratio =
                    $this->levenshteinRatio(
                        $a,
                        $b
                    );

                if (
                    $ratio < $bestRatio
                ) {
                    $bestRatio = $ratio;
                    $best = $major;
                }
            }

            if (
                $best !== null &&
                $bestRatio <= 0.20
            ) {
                return $best;
            }
        }

        return null;
    }

    private function looksLikeMajorNoise(
        string $norm
    ): bool {
        $noise = [
            'cong hoa xa hoi',
            'socialist republic',
            'doc lap tu do',
            'independence',
            'hanh phuc',

            'bo giao duc',
            'ministry of education',

            'truong dai hoc',
            'truong cao dang',
            'truong trung cap',

            'dai hoc',
            'cao dang',
            'trung cap',
            'hoc vien',

            'hieu truong',
            'rector',
            'president',
            'principal',

            'bang tot nghiep',
            'degree of bachelor',

            'so hieu',
            'so hiu',
            'vao so',
            'registry',
            'reg no',

            'hinh thuc dao tao',
            'xep loai',

            'full time',
            'part time',
        ];

        foreach ($noise as $item) {
            if (
                str_contains(
                    $norm,
                    $item
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================
     * HÌNH THỨC ĐÀO TẠO
     * ============================================================ */

    private function findTrainingType(
        string $text,
        array $lines,
        array $normLines
    ): ?string {
        $labels = [
            'hình thức đào tạo',
            'hệ đào tạo',
            'loại hình đào tạo',
            'training mode',
            'mode of study',
            'form of study',
            'study mode',
            'education mode',
        ];

        foreach (
            $lines as $index => $line
        ) {
            $norm = $normLines[$index];

            $hasLabel = false;

            foreach ($labels as $label) {
                if (
                    str_contains(
                        $norm,
                        $this->normalize($label)
                    )
                ) {
                    $hasLabel = true;
                    break;
                }
            }

            if (!$hasLabel) {
                continue;
            }

            $training =
                $this->canonicalTrainingType(
                    $line
                );

            if ($training !== null) {
                return $training;
            }

            if (
                isset($lines[$index + 1])
            ) {
                $training =
                    $this->canonicalTrainingType(
                        $lines[$index + 1]
                    );

                if ($training !== null) {
                    return $training;
                }
            }
        }

        return $this->canonicalTrainingType(
            $text
        );
    }

    private function canonicalTrainingType(
        string $value
    ): ?string {
        $norm =
            $this->normalize($value);

        $patterns = [
            'vua hoc vua lam' =>
                'Vừa học vừa làm',

            'vua lam vua hoc' =>
                'Vừa học vừa làm',

            'khong chinh quy' =>
                'Không chính quy',

            'khong tap trung' =>
                'Không tập trung',

            'chuyen tu' =>
                'Chuyên tu',

            'tai chuc' =>
                'Tại chức',

            'tu xa' =>
                'Từ xa',

            'distance learning' =>
                'Từ xa',

            'lien thong' =>
                'Liên thông',

            'van bang 2' =>
                'Văn bằng 2',

            'van bang hai' =>
                'Văn bằng 2',

            'song bang' =>
                'Song bằng',

            'part time' =>
                'Vừa học vừa làm',

            'parttime' =>
                'Vừa học vừa làm',

            'full time' =>
                'Chính quy',

            'fulltime' =>
                'Chính quy',

            'chinh quy' =>
                'Chính quy',

            'tap trung' =>
                'Tập trung',
        ];

        foreach (
            $patterns as $needle => $label
        ) {
            if (
                str_contains(
                    $norm,
                    $needle
                )
            ) {
                return $label;
            }
        }

        return null;
    }

    /* ============================================================
     * NĂM TỐT NGHIỆP
     * ============================================================ */

    private function findGraduationYear(
        string $text,
        array $lines,
        array $normLines
    ): ?string {
        $patterns = [
            '/(?:năm\s+tốt\s+nghiệp|tốt\s+nghiệp\s+năm|công\s+nhận\s+tốt\s+nghiệp\s+năm|graduation\s+year|year\s+of\s+graduation)[^\d]{0,80}(19\d{2}|20\d{2}|21\d{2})/iu',

            '/(?:tốt\s+nghiệp|graduation)[^\d]{0,30}(19\d{2}|20\d{2}|21\d{2})/iu',

            '/(?:graduated|graduate)[^\d]{0,30}(19\d{2}|20\d{2}|21\d{2})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $m
                ) &&
                $this->isValidGraduationYear(
                    $m[1]
                )
            ) {
                return $m[1];
            }
        }

        foreach (
            $lines as $index => $line
        ) {
            $norm = $normLines[$index];

            if (
                !str_contains(
                    $norm,
                    'tot nghiep'
                ) &&
                !str_contains(
                    $norm,
                    'graduation'
                ) &&
                !str_contains(
                    $norm,
                    'graduated'
                )
            ) {
                continue;
            }

            if (
                preg_match(
                    '/\b(19\d{2}|20\d{2}|21\d{2})\b/u',
                    $line,
                    $m
                ) &&
                $this->isValidGraduationYear(
                    $m[1]
                )
            ) {
                return $m[1];
            }

            for (
                $offset = 1;
                $offset <= 3;
                $offset++
            ) {
                if (
                    !isset(
                        $lines[
                            $index + $offset
                        ]
                    )
                ) {
                    continue;
                }

                if (
                    preg_match(
                        '/\b(19\d{2}|20\d{2}|21\d{2})\b/u',
                        $lines[
                            $index + $offset
                        ],
                        $m
                    ) &&
                    $this->isValidGraduationYear(
                        $m[1]
                    )
                ) {
                    return $m[1];
                }
            }
        }

        foreach (
            $lines as $index => $line
        ) {
            $norm = $normLines[$index];

            if (
                str_contains(
                    $norm,
                    'ngay sinh'
                ) ||
                str_contains(
                    $norm,
                    'date of birth'
                )
            ) {
                continue;
            }

            if (
                preg_match(
                    '/(?:ngày|ngay)\s+\d{1,2}\s+(?:tháng|thang)\s+\d{1,2}\s+(?:năm|nam)\s+(19\d{2}|20\d{2}|21\d{2})/iu',
                    $line,
                    $m
                ) &&
                $this->isValidGraduationYear(
                    $m[1]
                )
            ) {
                return $m[1];
            }

            if (
                preg_match(
                    '/\b\d{1,2}[\/\-]\d{1,2}[\/\-](19\d{2}|20\d{2}|21\d{2})\b/u',
                    $line,
                    $m
                ) &&
                $this->isValidGraduationYear(
                    $m[1]
                )
            ) {
                return $m[1];
            }
        }

        $years = [];

        foreach (
            $lines as $index => $line
        ) {
            $norm = $normLines[$index];

            if (
                str_contains(
                    $norm,
                    'ngay sinh'
                ) ||
                str_contains(
                    $norm,
                    'date of birth'
                )
            ) {
                continue;
            }

            if (
                preg_match_all(
                    '/\b(19\d{2}|20\d{2}|21\d{2})\b/u',
                    $line,
                    $matches
                )
            ) {
                foreach (
                    $matches[1] as $year
                ) {
                    if (
                        $this->isValidGraduationYear(
                            $year
                        )
                    ) {
                        $years[] = [
                            'year' => $year,
                            'score' =>
                                $this->scoreYearLine(
                                    $norm
                                ),
                        ];
                    }
                }
            }
        }

        if (!empty($years)) {
            usort(
                $years,
                static function (
                    array $a,
                    array $b
                ): int {
                    return
                        $b['score']
                        <=>
                        $a['score'];
                }
            );

            return $years[0]['year'];
        }

        return null;
    }

    private function scoreYearLine(
        string $norm
    ): int {
        $score = 0;

        if (
            str_contains(
                $norm,
                'tot nghiep'
            )
        ) {
            $score += 500;
        }

        if (
            str_contains(
                $norm,
                'graduation'
            )
        ) {
            $score += 500;
        }

        if (
            str_contains(
                $norm,
                'graduated'
            )
        ) {
            $score += 400;
        }

        if (
            str_contains(
                $norm,
                'cap bang'
            )
        ) {
            $score += 100;
        }

        if (
            str_contains(
                $norm,
                'so vao so'
            )
        ) {
            $score -= 300;
        }

        if (
            str_contains(
                $norm,
                'so hieu'
            )
        ) {
            $score -= 300;
        }

        if (
            str_contains(
                $norm,
                'ngay sinh'
            )
        ) {
            $score -= 1000;
        }

        return $score;
    }

    private function isValidGraduationYear(
        string $year
    ): bool {
        $year = (int) $year;

        return $year >= 1950
            && $year <= (
                (int) date('Y') + 1
            );
    }

    /* ============================================================
     * SỐ VÀO SỔ
     *
     * PHẦN NÀY ƯU TIÊN TUYỆT ĐỐI.
     *
     * Có thể đọc:
     *
     * Số vào sổ: 30
     * Số vào sổ 30
     * Số vào sổ cấp bằng: 30
     * Số vào sổ
     * 30
     *
     * SO VAO SO: 30
     * SO VAO SO
     * 30
     *
     * SOVAO SO30
     *
     * 30/2025/ABC
     *
     * ============================================================ */

    private function findRegistryNumber(
        string $text,
        array $lines,
        array $normLines,
        ?string $graduationYear
    ): ?string {
        /*
         * ========================================================
         * 1. TÌM THEO DÒNG TRƯỚC
         * ========================================================
         *
         * Đây là cách đáng tin cậy nhất với OCR.
         */

        foreach (
            $lines as $index => $line
        ) {
            $norm =
                $normLines[$index];

            if (
                !$this->isRegistryLabel(
                    $norm
                )
            ) {
                continue;
            }

            /*
             * -----------------------------------------------
             * Ví dụ:
             * Số vào sổ: 30
             * -----------------------------------------------
             */
            $sameLine =
                $this->extractRegistryValueAfterLabel(
                    $line
                );

            if (
                $sameLine !== null
            ) {
                return $sameLine;
            }

            /*
             * -----------------------------------------------
             * OCR tách thành:
             *
             * Số vào sổ
             * 30
             * -----------------------------------------------
             */
            for (
                $offset = 1;
                $offset <= 5;
                $offset++
            ) {
                $target =
                    $index + $offset;

                if (
                    !isset(
                        $lines[$target]
                    )
                ) {
                    break;
                }

                $targetNorm =
                    $normLines[$target];

                /*
                 * Nếu gặp một field khác,
                 * không được chạy tiếp xuống dưới.
                 */
                if (
                    $offset > 1 &&
                    $this->isHardStopField(
                        $targetNorm
                    )
                ) {
                    break;
                }

                $candidate =
                    $this->extractRegistryStandaloneValue(
                        $lines[$target],
                        $graduationYear
                    );

                if (
                    $candidate !== null
                ) {
                    return $candidate;
                }
            }
        }

        /*
         * ========================================================
         * 2. TÌM TRỰC TIẾP TRÊN TOÀN TEXT
         * ========================================================
         *
         * Dùng cho trường hợp OCR không xuống dòng.
         */

        $labelPattern =
            $this->registryLabelRegex();

        /*
         * Mã:
         *
         * 30/2025/ABC
         * 30 / 2025 / ABC
         * 123/2024/DH-ABC
         */
        $fullPattern =
            '/'
            . $labelPattern
            . '\s*[:.\-]?\s*'
            . '(\d{1,8}'
            . '\s*/\s*'
            . '(?:19|20|21)\d{2}'
            . '\s*/\s*'
            . '[A-ZÀ-ỸĐ0-9]+'
            . '(?:\s*-\s*[A-ZÀ-ỸĐ0-9]+)*)'
            . '/iu';

        if (
            preg_match(
                $fullPattern,
                $text,
                $match
            )
        ) {
            return $this->normalizeRegistryCode(
                $match[1]
            );
        }

        /*
         * -----------------------------------------------
         * SỐ THUẦN.
         *
         * Cực kỳ quan trọng:
         *
         * 30 phải match.
         *
         * Không dùng \d{4,8}.
         * -----------------------------------------------
         */
        $numberPattern =
            '/'
            . $labelPattern
            . '\s*[:.\-]?\s*'
            . '(\d{1,8})'
            . '(?![\d\/])'
            . '/iu';

        if (
            preg_match(
                $numberPattern,
                $text,
                $match
            )
        ) {
            $number =
                trim($match[1]);

            if (
                !$this->isYearNumber(
                    $number
                )
            ) {
                return $number;
            }
        }

        /*
         * ========================================================
         * 3. OCR DÍNH LABEL VỚI SỐ
         * ========================================================
         *
         * SOVAO SO30
         * SOVAO SO:30
         * SOVAO SO CAPBANG30
         */
        $compactText =
            $this->compact($text);

        $compactPatterns = [
            '/sovaosocapvanbang(\d{1,8})(?!\d)/iu',
            '/sovaosocapbang(\d{1,8})(?!\d)/iu',
            '/sovaosogoc(\d{1,8})(?!\d)/iu',
            '/sovaoso(\d{1,8})(?!\d)/iu',
            '/vaosocapvanbang(\d{1,8})(?!\d)/iu',
            '/vaosocapbang(\d{1,8})(?!\d)/iu',
            '/vaoso(\d{1,8})(?!\d)/iu',
        ];

        foreach (
            $compactPatterns as $pattern
        ) {
            if (
                preg_match(
                    $pattern,
                    $compactText,
                    $match
                )
            ) {
                $number =
                    $match[1];

                if (
                    !$this->isYearNumber(
                        $number
                    )
                ) {
                    return $number;
                }
            }
        }

        /*
         * ========================================================
         * 4. OCR CỰC XẤU:
         *
         * label ở một dòng,
         * số ở dòng kế tiếp nhưng có ký tự rác.
         *
         * Ví dụ:
         *
         * Số vào sổ
         * : 30
         *
         * hoặc:
         *
         * 30.
         * ========================================================
         */
        foreach (
            $lines as $index => $line
        ) {
            if (
                !$this->isRegistryLabel(
                    $normLines[$index]
                )
            ) {
                continue;
            }

            for (
                $offset = 0;
                $offset <= 3;
                $offset++
            ) {
                $target =
                    $index + $offset;

                if (
                    !isset(
                        $lines[$target]
                    )
                ) {
                    continue;
                }

                $raw =
                    $lines[$target];

                /*
                 * Bỏ label nếu nó vẫn còn nằm trong dòng.
                 */
                $raw =
                    preg_replace(
                        '/.*?'
                        . $this->registryLabelRegex()
                        . '/iu',
                        '',
                        $raw
                    );

                if ($raw === null) {
                    continue;
                }

                $raw =
                    $this->cleanValue(
                        $raw
                    );

                if (
                    $raw === null
                ) {
                    continue;
                }

                /*
                 * Chấp nhận:
                 *
                 * 30
                 * 30.
                 * :30
                 * (30)
                 */
                if (
                    preg_match(
                        '/^[^\d]{0,5}(\d{1,8})(?:[^\d]{0,5})$/u',
                        $raw,
                        $match
                    )
                ) {
                    $number =
                        $match[1];

                    if (
                        !$this->isYearNumber(
                            $number
                        )
                    ) {
                        return $number;
                    }
                }

                /*
                 * Mã đầy đủ.
                 */
                $codes =
                    $this->extractRegistryCodes(
                        $raw
                    );

                if (
                    !empty($codes)
                ) {
                    return $codes[0];
                }
            }
        }

        /*
         * ========================================================
         * 5. FALLBACK MÃ REGISTRY
         * ========================================================
         *
         * Chỉ dùng nếu đã có dạng:
         *
         * 30/2025/ABC
         *
         * mà label bị OCR sai.
         */
        $candidates = [];

        foreach (
            $lines as $index => $line
        ) {
            $codes =
                $this->extractRegistryCodes(
                    $line
                );

            foreach (
                $codes as $code
            ) {
                $score = 100;

                $norm =
                    $normLines[$index];

                if (
                    $this->isRegistryLabel(
                        $norm
                    )
                ) {
                    $score += 2000;
                }

                if (
                    $graduationYear !== null &&
                    str_contains(
                        $code,
                        '/' .
                        $graduationYear .
                        '/'
                    )
                ) {
                    $score += 500;
                }

                if (
                    preg_match(
                        '/^\d{1,8}\/\d{4}\//u',
                        $code
                    )
                ) {
                    $score += 300;
                }

                if (
                    str_contains(
                        $norm,
                        'so hieu'
                    ) ||
                    str_contains(
                        $norm,
                        'diploma no'
                    ) ||
                    str_contains(
                        $norm,
                        'degree no'
                    )
                ) {
                    $score -= 1000;
                }

                $candidates[] = [
                    'value' => $code,
                    'score' => $score,
                ];
            }
        }

        if (!empty($candidates)) {
            usort(
                $candidates,
                static function (
                    array $a,
                    array $b
                ): int {
                    return
                        $b['score']
                        <=>
                        $a['score'];
                }
            );

            return $candidates[0]['value'];
        }

        return null;
    }

    /**
     * Regex label số vào sổ.
     *
     * Không phụ thuộc dấu tiếng Việt.
     */
    private function registryLabelRegex(): string
    {
        return
            '(?:'
            . 'số\s*vào\s*sổ\s*cấp\s*văn\s*bằng'
            . '|số\s*vào\s*sổ\s*cấp\s*bằng'
            . '|số\s*vào\s*sổ\s*gốc'
            . '|số\s*vào\s*sổ'
            . '|vao\s*so\s*cap\s*van\s*bang'
            . '|vao\s*so\s*cap\s*bang'
            . '|so\s*vao\s*so\s*goc'
            . '|so\s*vao\s*so'
            . '|registry\s*(?:no|number)'
            . '|registration\s*(?:no|number)'
            . '|reg\.?\s*no'
            . ')';
    }

    /**
     * Xác định dòng có phải label số vào sổ không.
     */
    private function isRegistryLabel(
        string $norm
    ): bool {
        $labels = [
            'so vao so cap van bang',
            'so vao so cap bang',
            'so vao so goc',
            'so vao so',
            'vao so cap van bang',
            'vao so cap bang',
            'registry no',
            'registry number',
            'registration no',
            'registration number',
            'reg no',
        ];

        foreach ($labels as $label) {
            if (
                str_contains(
                    $norm,
                    $label
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lấy value ngay sau label.
     *
     * Ví dụ:
     *
     * Số vào sổ: 30
     *
     * => 30
     */
    private function extractRegistryValueAfterLabel(
        string $line
    ): ?string {
        $pattern =
            '/.*?'
            . $this->registryLabelRegex()
            . '\s*[:.\-]?\s*'
            . '(.*)$/iu';

        if (
            !preg_match(
                $pattern,
                $line,
                $match
            )
        ) {
            return null;
        }

        $value =
            $this->cleanValue(
                $match[1] ?? null
            );

        if (
            $value === null
        ) {
            return null;
        }

        /*
         * Full registry code.
         */
        $codes =
            $this->extractRegistryCodes(
                $value
            );

        if (
            !empty($codes)
        ) {
            return $codes[0];
        }

        /*
         * Số thuần.
         *
         * CHẤP NHẬN 30.
         */
        if (
            preg_match(
                '/^[^\d]{0,10}(\d{1,8})[^\d]{0,10}$/u',
                $value,
                $match
            )
        ) {
            $number =
                $match[1];

            if (
                !$this->isYearNumber(
                    $number
                )
            ) {
                return $number;
            }
        }

        /*
         * Nếu OCR trả thêm ký tự,
         * ví dụ:
         *
         * "30 /"
         */
        if (
            preg_match(
                '/^[^\d]{0,10}(\d{1,8})(?:\s*\/.*)?$/u',
                $value,
                $match
            )
        ) {
            $number =
                $match[1];

            if (
                !$this->isYearNumber(
                    $number
                )
            ) {
                return $number;
            }
        }

        return null;
    }

    /**
     * Đọc một dòng nằm sau label.
     *
     * Cực kỳ quan trọng cho OCR:
     *
     * Số vào sổ
     * 30
     */
    private function extractRegistryStandaloneValue(
        string $line,
        ?string $graduationYear
    ): ?string {
        $value =
            $this->cleanValue($line);

        if (
            $value === null
        ) {
            return null;
        }

        /*
         * Nếu chính dòng này vẫn chứa label,
         * thử lấy phần sau label.
         */
        if (
            $this->isRegistryLabel(
                $this->normalize($value)
            )
        ) {
            $after =
                $this->extractRegistryValueAfterLabel(
                    $value
                );

            if (
                $after !== null
            ) {
                return $after;
            }

            return null;
        }

        /*
         * Full registry code.
         */
        $codes =
            $this->extractRegistryCodes(
                $value
            );

        if (
            !empty($codes)
        ) {
            return $codes[0];
        }

        /*
         * Trường hợp lý tưởng:
         *
         * 30
         */
        if (
            preg_match(
                '/^(\d{1,8})$/u',
                $value,
                $match
            )
        ) {
            $number =
                $match[1];

            /*
             * Không nhận năm.
             */
            if (
                !$this->isYearNumber(
                    $number
                )
            ) {
                return $number;
            }
        }

        /*
         * OCR có thể tạo:
         *
         * 30.
         * :30
         * (30)
         */
        if (
            preg_match(
                '/^[^\d]{0,5}(\d{1,8})[^\d]{0,5}$/u',
                $value,
                $match
            )
        ) {
            $number =
                $match[1];

            if (
                !$this->isYearNumber(
                    $number
                )
            ) {
                return $number;
            }
        }

        /*
         * Trường hợp:
         *
         * 30 / 2025 / ABC
         */
        if (
            preg_match(
                '/^(\d{1,8})\s*\/\s*(19|20|21)\d{2}/u',
                $value,
                $match
            )
        ) {
            $codes =
                $this->extractRegistryCodes(
                    $value
                );

            if (
                !empty($codes)
            ) {
                return $codes[0];
            }

            return $match[1];
        }

        /*
         * Nếu graduationYear trùng trong dòng,
         * không lấy năm đó làm registry.
         */
        if (
            $graduationYear !== null &&
            preg_match_all(
                '/\b\d{1,8}\b/u',
                $value,
                $matches
            )
        ) {
            foreach (
                $matches[0] as $number
            ) {
                if (
                    $number ===
                    $graduationYear
                ) {
                    continue;
                }

                if (
                    $this->isYearNumber(
                        $number
                    )
                ) {
                    continue;
                }

                return $number;
            }
        }

        return null;
    }

    private function isYearNumber(
        string $number
    ): bool {
        return preg_match(
            '/^(?:19|20|21)\d{2}$/',
            trim($number)
        ) === 1;
    }

    /**
     * Các field phía sau mà nếu gặp thì không được
     * tiếp tục chạy xuống lấy số.
     */
    private function isHardStopField(
        string $norm
    ): bool {
        $labels = [
            'so hieu',
            'so hiu',
            'diploma no',
            'diploma number',
            'degree no',
            'degree number',

            'ngay sinh',
            'date of birth',

            'ngay cap',
            'date of issue',

            'tot nghiep',
            'graduation',

            'hinh thuc dao tao',
            'he dao tao',
            'loai hinh dao tao',
            'training mode',
            'mode of study',

            'nganh dao tao',
            'chuyen nganh',
            'major',

            'xep loai',

            'hieu truong',
            'rector',
            'president',
            'principal',
        ];

        foreach ($labels as $label) {
            if (
                str_contains(
                    $norm,
                    $label
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trích mã registry dạng:
     *
     * 30/2025/ABC
     * 0873/2025/CN-DHTG
     */
    private function extractRegistryCodes(
        string $value
    ): array {
        $result = [];

        $value =
            $this->cleanValue($value);

        if (
            $value === null
        ) {
            return [];
        }

        /*
         * Chuẩn hóa slash.
         */
        $value =
            preg_replace(
                '/\s*\/\s*/u',
                '/',
                $value
            );

        if ($value === null) {
            return [];
        }

        /*
         * Chuẩn hóa dấu "-".
         */
        $value =
            preg_replace(
                '/\s*-\s*/u',
                '-',
                $value
            );

        if ($value === null) {
            return [];
        }

        /*
         * Dạng chuẩn:
         *
         * 30/2025/CN-DHTG
         */
        if (
            preg_match_all(
                '/(?<![\d\/])'
                . '(\d{1,8})'
                . '\/'
                . '(19\d{2}|20\d{2}|21\d{2})'
                . '\/'
                . '([A-ZÀ-ỸĐ0-9]{1,30}'
                . '(?:-[A-ZÀ-ỸĐ0-9]{1,30})*)'
                . '(?![\d\/])/iu',
                $value,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            foreach (
                $matches as $match
            ) {
                $code =
                    $match[1]
                    . '/'
                    . $match[2]
                    . '/'
                    . $match[3];

                $result[] =
                    $this->normalizeCode(
                        $code
                    );
            }
        }

        /*
         * OCR tách khoảng trắng.
         */
        if (
            preg_match_all(
                '/\b'
                . '(\d{1,8})\s*\/\s*'
                . '(19\d{2}|20\d{2}|21\d{2})'
                . '\s*\/\s*'
                . '([A-ZÀ-ỸĐ0-9]{1,30}'
                . '(?:\s*-\s*[A-ZÀ-ỸĐ0-9]{1,30})*)'
                . '\b/iu',
                $value,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            foreach (
                $matches as $match
            ) {
                $suffix =
                    preg_replace(
                        '/\s+/u',
                        '',
                        $match[3]
                    );

                if (
                    $suffix === null
                ) {
                    continue;
                }

                $code =
                    $match[1]
                    . '/'
                    . $match[2]
                    . '/'
                    . $suffix;

                $result[] =
                    $this->normalizeCode(
                        $code
                    );
            }
        }

        return array_values(
            array_unique($result)
        );
    }

    private function normalizeRegistryCode(
        string $code
    ): string {
        $code =
            preg_replace(
                '/\s+/u',
                '',
                trim($code)
            );

        if ($code === null) {
            return '';
        }

        return trim(
            $code,
            ".:;,"
        );
    }

    /* ============================================================
     * SỐ HIỆU BẰNG
     * ============================================================ */

    private function findDiplomaNumber(
        string $text,
        array $lines,
        array $normLines,
        ?string $registryNumber = null
    ): ?string {
        $candidates = [];

        foreach (
            $lines as $index => $line
        ) {
            $norm =
                $normLines[$index];

            /*
             * Không để field số vào sổ bị xử lý
             * như số hiệu bằng.
             */
            if (
                $this->isRegistryLabel(
                    $norm
                )
            ) {
                continue;
            }

            if (
                !$this->containsAny(
                    $norm,
                    [
                        'số hiệu',
                        'so hieu',
                        'so hiu',
                        'số hiệu bằng',
                        'số hiệu văn bằng',
                        'diploma no',
                        'diploma number',
                        'degree no',
                        'degree number',
                        'certificate no',
                        'certificate number',
                        'serial no',
                        'serial number',
                    ]
                )
            ) {
                continue;
            }

            for (
                $offset = 0;
                $offset <= 3;
                $offset++
            ) {
                $target =
                    $index + $offset;

                if (
                    !isset(
                        $lines[$target]
                    )
                ) {
                    continue;
                }

                /*
                 * Nếu target là dòng số vào sổ,
                 * bỏ qua.
                 */
                if (
                    $this->isRegistryLabel(
                        $normLines[$target]
                    )
                ) {
                    continue;
                }

                $value =
                    $lines[$target];

                if (
                    $offset === 0
                ) {
                    $value =
                        preg_replace(
                            '/^.*?(?:số\s*hiệu(?:\s*bằng|\s*văn\s*bằng)?|so\s*hieu(?:\s*bang|\s*van\s*bang)?|so\s*hiu|diploma\s*(?:no|number)|degree\s*(?:no|number)|certificate\s*(?:no|number)|serial\s*(?:no|number))\s*[:.\-]?\s*/iu',
                            '',
                            $value
                        );

                    if ($value === null) {
                        $value =
                            $lines[$target];
                    }
                }

                $code =
                    $this->extractDiplomaCode(
                        $value
                    );

                if (
                    $code === null
                ) {
                    continue;
                }

                /*
                 * Không bao giờ trả số hiệu bằng
                 * trùng số vào sổ.
                 */
                if (
                    $registryNumber !== null &&
                    $code === $registryNumber
                ) {
                    continue;
                }

                $score =
                    1000 -
                    ($offset * 100);

                if (
                    preg_match(
                        '/[A-ZÀ-ỸĐ]/u',
                        $code
                    )
                ) {
                    $score += 80;
                }

                if (
                    preg_match(
                        '/\d{4,12}/',
                        $code
                    )
                ) {
                    $score += 30;
                }

                if (
                    preg_match(
                        '/\/\d{4}\//',
                        $code
                    )
                ) {
                    $score -= 200;
                }

                $candidates[] = [
                    'value' => $code,
                    'score' => $score,
                ];
            }
        }

        if (
            !empty($candidates)
        ) {
            usort(
                $candidates,
                static function (
                    array $a,
                    array $b
                ): int {
                    return
                        $b['score']
                        <=>
                        $a['score'];
                }
            );

            return $candidates[0]['value'];
        }

        /*
         * Fallback:
         * chỉ lấy số hiệu có chữ cái,
         * tránh lấy số vào sổ 30.
         */
        foreach (
            $lines as $index => $line
        ) {
            if (
                $this->isSchoolNoise(
                    $normLines[$index]
                )
            ) {
                continue;
            }

            if (
                $this->isRegistryLabel(
                    $normLines[$index]
                )
            ) {
                continue;
            }

            $code =
                $this->extractDiplomaCode(
                    $line
                );

            if (
                $code === null
            ) {
                continue;
            }

            if (
                $registryNumber !== null &&
                $code === $registryNumber
            ) {
                continue;
            }

            if (
                preg_match(
                    '/[A-ZÀ-ỸĐ]/u',
                    $code
                )
            ) {
                return $code;
            }
        }

        return null;
    }

    private function extractDiplomaCode(
        ?string $value
    ): ?string {
        $value =
            $this->cleanValue($value);

        if (
            $value === null
        ) {
            return null;
        }

        $patterns = [
            '/\b([A-ZÀ-ỸĐ]{1,10}\s*[-.]?\s*\d{3,15})\b/u',

            '/\b(\d{1,6}\s*[-\/]?\s*[A-ZÀ-ỸĐ]{1,10}\s*[-.]?\s*\d{1,15})\b/u',

            '/\b([A-ZÀ-ỸĐ]{1,10}\d{4,15})\b/u',
        ];

        foreach (
            $patterns as $pattern
        ) {
            if (
                preg_match(
                    $pattern,
                    $value,
                    $m
                )
            ) {
                return $this->normalizeCode(
                    $m[1]
                );
            }
        }

        /*
         * Chỉ lấy số >= 4 ở fallback.
         *
         * Vì số 30 thuộc số vào sổ,
         * không được để findDiplomaNumber ăn mất.
         */
        if (
            preg_match_all(
                '/(?<![\/\d])(\d{4,12})(?![\/\d])/u',
                $value,
                $matches
            )
        ) {
            foreach (
                $matches[1] as $number
            ) {
                if (
                    preg_match(
                        '/^(19|20|21)\d{2}$/',
                        $number
                    )
                ) {
                    continue;
                }

                return $number;
            }
        }

        return null;
    }

    /* ============================================================
     * NOTE 2
     * ============================================================ */

    private function buildNote2(
        ?string $level,
        ?string $major
    ): ?string {
        $level =
            $this->cleanValue($level);

        $major =
            $this->cleanValue($major);

        /*
         * ========================================================
         * QUY TẮC:
         * ========================================================
         *
         * CỬ NHÂN -> ĐẠI HỌC
         * KỸ SƯ -> ĐẠI HỌC
         * BÁC SĨ -> ĐẠI HỌC
         * DƯỢC SĨ -> ĐẠI HỌC
         * THẠC SĨ -> ĐẠI HỌC
         * TIẾN SĨ -> ĐẠI HỌC
         * CHUYÊN KHOA -> ĐẠI HỌC
         *
         * Còn:
         *
         * TRUNG CẤP -> TRUNG CẤP
         * CAO ĐẲNG -> CAO ĐẲNG
         * THPT -> THPT
         * HỌC VIỆN -> HỌC VIỆN
         * SƠ CẤP -> SƠ CẤP
         * CHỨNG CHỈ -> CHỨNG CHỈ
         * CHỨNG NHẬN -> CHỨNG NHẬN
         */

        if ($level !== null) {
            $norm =
                $this->normalize($level);

            $canonicalLevels = [
                /*
                 * THPT
                 */
                'thpt' => 'THPT',
                'trung hoc pho thong' =>
                    'THPT',
                'high school' =>
                    'THPT',
                'secondary school' =>
                    'THPT',

                /*
                 * TRUNG CẤP
                 */
                'trung cap' =>
                    'Trung cấp',
                'vocational secondary' =>
                    'Trung cấp',
                'intermediate' =>
                    'Trung cấp',

                /*
                 * CAO ĐẲNG
                 */
                'cao dang' =>
                    'Cao đẳng',
                'college' =>
                    'Cao đẳng',

                /*
                 * ĐẠI HỌC
                 */
                'dai hoc' =>
                    'Đại học',
                'university' =>
                    'Đại học',

                /*
                 * HỌC VIỆN
                 */
                'hoc vien' =>
                    'Học viện',
                'academy' =>
                    'Học viện',

                /*
                 * CỬ NHÂN
                 */
                'cu nhan' =>
                    'Đại học',
                'bachelor of' =>
                    'Đại học',
                'bachelor degree' =>
                    'Đại học',
                'bachelor' =>
                    'Đại học',

                /*
                 * KỸ SƯ
                 */
                'ky su' =>
                    'Đại học',
                'engineer' =>
                    'Đại học',
                'engineering' =>
                    'Đại học',

                /*
                 * BÁC SĨ
                 */
                'bac si' =>
                    'Đại học',
                'doctor of medicine' =>
                    'Đại học',
                'medical doctor' =>
                    'Đại học',
                'md degree' =>
                    'Đại học',

                /*
                 * DƯỢC SĨ
                 */
                'duoc si' =>
                    'Đại học',
                'pharmacist' =>
                    'Đại học',
                'pharmacy' =>
                    'Đại học',

                /*
                 * THẠC SĨ
                 */
                'thac si' =>
                    'Đại học',
                'master degree' =>
                    'Đại học',
                'master of' =>
                    'Đại học',
                'master' =>
                    'Đại học',
                'msc' =>
                    'Đại học',
                'ma degree' =>
                    'Đại học',

                /*
                 * TIẾN SĨ
                 */
                'tien si' =>
                    'Đại học',
                'doctorate' =>
                    'Đại học',
                'doctor of philosophy' =>
                    'Đại học',
                'phd' =>
                    'Đại học',
                'ph d' =>
                    'Đại học',

                /*
                 * CHUYÊN KHOA
                 */
                'chuyen khoa ii' =>
                    'Đại học',
                'chuyen khoa 2' =>
                    'Đại học',
                'chuyen khoa i' =>
                    'Đại học',
                'chuyen khoa 1' =>
                    'Đại học',

                /*
                 * SƠ CẤP
                 */
                'so cap' =>
                    'Sơ cấp',
                'elementary level' =>
                    'Sơ cấp',

                /*
                 * CHỨNG CHỈ
                 */
                'chung chi' =>
                    'Chứng chỉ',
                'certificate' =>
                    'Chứng chỉ',

                /*
                 * CHỨNG NHẬN
                 */
                'chung nhan' =>
                    'Chứng nhận',
                'certification' =>
                    'Chứng nhận',
            ];

            foreach (
                $canonicalLevels
                as $needle => $value
            ) {
                if (
                    str_contains(
                        $norm,
                        $needle
                    )
                ) {
                    $level = $value;
                    break;
                }
            }
        }

        if (
            $level !== null &&
            $major !== null
        ) {
            return
                $level .
                ' - ' .
                $major;
        }

        return $level ?? $major;
    }

    /* ============================================================
     * LABEL
     * ============================================================ */

    private function textAfterLabel(
        string $line,
        string $label
    ): ?string {
        $pattern =
            '/^.*?'
            . preg_quote(
                $label,
                '/'
            )
            . '\s*[:.\-]?\s*(.*)$/iu';

        if (
            preg_match(
                $pattern,
                $line,
                $m
            )
        ) {
            $value =
                $m[1] ?? null;

            return $this->cleanValue(
                $value
            );
        }

        $parts = preg_split(
            '/\s*[:.\-]\s*/u',
            $line,
            2
        );

        if (
            is_array($parts) &&
            count($parts) === 2
        ) {
            return $this->cleanValue(
                $parts[1]
            );
        }

        return null;
    }

    /* ============================================================
     * FUZZY
     * ============================================================ */

    private function levenshteinRatio(
        string $a,
        string $b
    ): float {
        $a =
            preg_replace(
                '/\s+/u',
                '',
                $a
            );

        $b =
            preg_replace(
                '/\s+/u',
                '',
                $b
            );

        if ($a === null) {
            $a = '';
        }

        if ($b === null) {
            $b = '';
        }

        if ($a === $b) {
            return 0.0;
        }

        if (
            $a === '' ||
            $b === ''
        ) {
            return 1.0;
        }

        $distance =
            levenshtein(
                $a,
                $b
            );

        $length =
            max(
                strlen($a),
                strlen($b),
                1
            );

        return
            $distance /
            $length;
    }

    /* ============================================================
     * CODE
     * ============================================================ */

    private function normalizeCode(
        string $code
    ): string {
        $code =
            preg_replace(
                '/\s+/u',
                '',
                trim($code)
            );

        if ($code === null) {
            return '';
        }

        return trim(
            $code,
            ".:;,"
        );
    }
}