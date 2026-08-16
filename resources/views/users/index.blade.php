@extends('layouts.ocr')

@section('title', 'Quản lý nhân viên')

@section('content')

    <h1 class="page-title">Quản lý nhân viên</h1>
    <p class="page-subtitle">Danh sách tài khoản và phân quyền truy cập hệ thống.</p>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card-box mb-4">
        <h5 class="mb-3">Tạo tài khoản mới</h5>
        <form action="{{ route('users.store') }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label">Họ tên</label>
                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="{{ old('email') }}" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Mật khẩu</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Xác nhận mật khẩu</label>
                <input type="password" name="password_confirmation" class="form-control" required>
            </div>
            <div class="col-md-1">
                <label class="form-label">Quyền</label>
                <select name="role" class="form-select">
                    <option value="user" selected>Nhân viên</option>
                    <option value="admin">Quản lý</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100">Tạo</button>
            </div>
        </form>
    </div>

    <div class="card-box">
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead>
                    <tr>
                        <th>Họ tên</th>
                        <th>Email</th>
                        <th>Quyền hiện tại</th>
                        <th>Đổi quyền</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                <span class="badge {{ $user->role === 'admin' ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ $user->role === 'admin' ? 'Quản lý' : 'Nhân viên' }}
                                </span>
                            </td>
                            <td>
                                @if ($user->id !== auth()->id())
                                    <form action="{{ route('users.updateRole', $user->id) }}" method="POST" class="d-flex gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <select name="role" class="form-select form-select-sm" style="width:auto;">
                                            <option value="user" {{ $user->role === 'user' ? 'selected' : '' }}>Nhân viên</option>
                                            <option value="admin" {{ $user->role === 'admin' ? 'selected' : '' }}>Quản lý</option>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm">Lưu</button>
                                    </form>
                                @else
                                    <span class="text-muted">(Tài khoản của bạn)</span>
                                @endif
                            </td>
                            <td>
                                @if ($user->id !== auth()->id())
                                    <form action="{{ route('users.destroy', $user->id) }}" method="POST"
                                          onsubmit="return confirm('Xóa tài khoản này?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm">Xóa</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">Chưa có tài khoản nào</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $users->links() }}
        </div>
    </div>

    <div class="mt-4">
        <a href="{{ route('dashboard') }}" class="btn btn-secondary">← Quay lại Dashboard</a>
    </div>

@endsection