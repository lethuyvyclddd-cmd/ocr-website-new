<?php

namespace App\Services\Extractors;

use InvalidArgumentException;

class ExtractorFactory
{
    protected static array $map = [
        'cccd_front' => CccdExtractor::class,
        'cccd_back' => CccdExtractor::class,
        'diploma_transcript' => DiplomaExtractor::class,
        'admission_form' => AdmissionFormExtractor::class,
    ];

    public static function make(string $documentType): BaseExtractor
    {
        if (! isset(self::$map[$documentType])) {
            throw new InvalidArgumentException("Không có extractor cho loại tài liệu: {$documentType}");
        }

        $class = self::$map[$documentType];

        return new $class();
    }
}