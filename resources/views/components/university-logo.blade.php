@props(['size' => 44])

<div {{ $attributes->merge(['class' => 'mku-logo']) }} style="width:{{ $size }}px;height:{{ $size }}px;flex-shrink:0;display:inline-flex;line-height:0;">
    <svg viewBox="0 0 64 64" width="100%" height="100%" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Logo Trường Đại học Cửu Long">
        <circle cx="32" cy="32" r="31" fill="#DCEEFF" stroke="#A9D3F5" stroke-width="1.5"/>
        <circle cx="32" cy="32" r="24" fill="#EFF7FF"/>
        <polygon points="32,19 50,27 32,35 14,27" fill="#5AA3E0"/>
        <path d="M21 29 V36.5 C21 40 26 42.5 32 42.5 C38 42.5 43 40 43 36.5 V29"
              fill="none" stroke="#2E6DA4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <line x1="47" y1="27" x2="47" y2="37" stroke="#2E6DA4" stroke-width="1.6" stroke-linecap="round"/>
        <circle cx="47" cy="39" r="2" fill="#2E6DA4"/>
        <text x="32" y="53" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="9" font-weight="700" fill="#1A5490" letter-spacing="0.5">CỬU LONG</text>
    </svg>
</div>