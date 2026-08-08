<x-guest-layout>

    <div class="auth-title">Tạo tài khoản <span class="accent">mới</span> 🌿</div>
    <p class="auth-subtext">Đăng ký để bắt đầu sử dụng hệ thống nhập liệu OCR</p>

    @if ($errors->any())
        <div class="error-box">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <label for="name">Họ tên</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" placeholder="Nguyễn Văn A">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="ban@email.com">

        <label for="password">Mật khẩu</label>
        <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="••••••••">

        <label for="password_confirmation">Xác nhận mật khẩu</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="••••••••">

        <button type="submit" class="btn-auth">Đăng ký 🌸</button>

        <div class="auth-links">
            Đã có tài khoản? <a href="{{ route('login') }}">Đăng nhập</a>
        </div>
    </form>

</x-guest-layout>