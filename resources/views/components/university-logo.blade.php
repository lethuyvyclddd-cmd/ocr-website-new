@props(['size' => 44])

<div {{ $attributes->merge(['class' => 'mku-logo']) }} style="width:{{ $size }}px;height:{{ $size }}px;flex-shrink:0;display:inline-flex;line-height:0;">
    <img src="{{ asset('images/logo.png') }}"
         alt="Logo Trường Đại học Cửu Long"
         width="{{ $size }}"
         height="{{ $size }}"
         style="width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;">
</div>