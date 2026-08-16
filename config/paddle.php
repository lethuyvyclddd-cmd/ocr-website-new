<?php

return [

    'python_bin' => 'C:/Users/PC ACER/AppData/Local/Programs/Python/Python311/python.exe',

    'script_path' => env(
        'PADDLE_SCRIPT_PATH',
        base_path('python/ocr.py')
    ),

    'timeout' => (int) env(
        'PADDLE_TIMEOUT',
        60
    ),

];