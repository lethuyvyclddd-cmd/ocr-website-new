<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý nhân viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('css/app-style.css') }}" rel="stylesheet">
</head>
<body>

<x-navbar />

<div class="container mt-4">

    <span class="eyebrow">Quản lý quyền truy cập</span>
    <h2 class="mb-4">Danh sách <span class="accent">nhân viên</span></h2>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="table-wrap">
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
                @foreach ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        <span class="badge-role {{ $user->role === 'admin' ? 'badge-admin' : 'badge-user' }}">
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
                        <form action="{{ route('users.destroy', $user->id) }}" method="POST" onsubmit="return confirm('Xóa tài khoản này?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Xóa</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>

    <div class="mt-4">
        <a href="{{ route('dashboard') }}" class="btn btn-secondary">← Quay lại Dashboard</a>
    </div>

</div>

</body>
</html>