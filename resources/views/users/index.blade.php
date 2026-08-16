@extends('layouts.ocr')

@section('title', 'Quản lý nhân viên')

@section('content')

    <h1 class="page-title">Quản lý nhân viên</h1>
    <p class="page-subtitle">Danh sách tài khoản và phân quyền truy cập hệ thống.</p>

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