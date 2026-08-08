<nav class="ocr-navbar">

    <a href="{{ route('dashboard') }}" class="brand">
        Hồ sơ <span class="accent">Thí sinh</span>
    </a>

    <div class="nav-links">
        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
        <a href="{{ route('applicants.create') }}" class="{{ request()->routeIs('applicants.create') ? 'active' : '' }}">Hồ sơ mới</a>
        <a href="{{ route('applicants.index') }}" class="{{ request()->routeIs('applicants.index') || request()->routeIs('applicants.workspace') ? 'active' : '' }}">Danh sách hồ sơ</a>

        @if (auth()->user()->role === 'admin')
            <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                Quản lý nhân viên
            </a>
        @endif
    </div>

    <div class="user-area">
        <span>{{ auth()->user()->name }}</span>
        <span class="role-chip {{ auth()->user()->role === 'admin' ? 'admin' : 'user' }}">
            {{ auth()->user()->role === 'admin' ? 'Quản lý' : 'Nhân viên' }}
        </span>
        <a href="{{ route('profile.edit') }}">Hồ sơ</a>
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button type="submit" class="logout-btn">Đăng xuất</button>
        </form>
    </div>

</nav>