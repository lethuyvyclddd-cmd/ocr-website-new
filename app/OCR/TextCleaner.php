<?php

namespace App\OCR;

class TextCleaner
{
    public static function clean(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Chuẩn UTF8
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Xuống dòng
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Tab -> space
        $text = str_replace("\t", " ", $text);

        // Xóa ký tự OCR rác
        $garbage = [
            '`',
            '~',
            '^',
            '_',
            '¦',
            '§',
            '®',
            '™',
            '©',
            '•',
            '●',
            '►',
            '■',
            '□',
            '▪',
            '¤',
            '…'
        ];

        $text = str_replace($garbage, '', $text);

        // Chuẩn dấu ngoặc kép
        $text = str_replace([
            '“','”','„','‟',
            '‘','’','‚','‛'
        ], "'", $text);

        // Chuẩn gạch ngang
        $text = str_replace([
            '–',
            '—',
            '−'
        ], '-', $text);

        // Xóa nhiều dấu |
        $text = preg_replace('/\|+/u', '|', $text);

        // Xóa nhiều dấu =
        $text = preg_replace('/=+/u', '=', $text);

        // Xóa nhiều dấu .
        $text = preg_replace('/\.{2,}/u', '.', $text);

        // Xóa nhiều dấu ,
        $text = preg_replace('/,{2,}/u', ',', $text);

        // Xóa khoảng trắng đầu cuối dòng
        $text = preg_replace('/^[ ]+/m', '', $text);
        $text = preg_replace('/[ ]+$/m', '', $text);

        // Gom nhiều khoảng trắng
        $text = preg_replace('/[ ]{2,}/u', ' ', $text);

        // Gom nhiều dòng trắng
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}