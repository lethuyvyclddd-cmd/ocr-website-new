<x-guest-layout>

    <div class="auth-title">Chào mừng <span class="accent">trở lại</span> 🌸</div>
    <p class="auth-subtext">Đăng nhập để tiếp tục sử dụng hệ thống nhập liệu OCR</p>

    @if (session('status'))
        <div class="status-box">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="error-box">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <p style="font-size:10px">Token: {{ csrf_token() }}</p>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="ban@email.com">

        <label for="password">Mật khẩu</label>
        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">

        <button type="submit" class="btn-auth">Đăng nhập 💗</button>

        <div class="auth-links">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}">Quên mật khẩu?</a>
            @endif
            <p style="margin-top:10px; font-size:13px; color:#888;">
                Chưa có tài khoản? Vui lòng liên hệ Quản trị viên để được cấp tài khoản.
            </p>
        </div>
    </form>

</x-guest-layout>