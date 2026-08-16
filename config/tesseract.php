<?php
return [
    // Trên Linux (server), để trống '' hoặc env TESSERACT_BINARY=tesseract sẽ
    // tự dùng bản cài qua "sudo apt install tesseract-ocr tesseract-ocr-vie".
    // Trên Windows (máy dev), set trong .env:
    // TESSERACT_BINARY="C:\Program Files\Tesseract-OCR\tesseract.exe"
    'binary' => env('TESSERACT_BINARY', 'tesseract'),

    // Chỉ set khi tessdata KHÔNG nằm ở vị trí mặc định của hệ thống.
    // Trên Linux thường để trống (null) là tự tìm đúng.
    // Trên Windows nếu cần: TESSERACT_TESSDATA_DIR="C:\Program Files\Tesseract-OCR\tessdata"
    'tessdata_dir' => env('TESSERACT_TESSDATA_DIR', null),

    'lang' => env('TESSERACT_LANG', 'vie+eng'),
    'psm'  => env('TESSERACT_PSM', 6),

    // Dùng cho chuyển PDF -> ảnh (uploadPdf). Trên Linux: apt install poppler-utils
    // rồi để mặc định 'pdftoppm'. Trên Windows set full path tới pdftoppm.exe.
    'poppler_bin' => env('POPPLER_PDFTOPPM_BIN', 'pdftoppm'),
];