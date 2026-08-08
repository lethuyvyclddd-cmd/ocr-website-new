@extends('layouts.ocr')

@section('title', 'Tạo hồ sơ thí sinh')

@section('content')

    <h1 class="page-title">Bắt đầu hồ sơ thí sinh</h1>
    <p class="page-subtitle">
        Nhập số CCCD nếu có để hệ thống tự kiểm tra hồ sơ trùng, hoặc để trống để tạo hồ sơ mới hoàn toàn.
    </p>

    <div class="card-box" style="max-width:480px">
        <form action="{{ route('applicants.store') }}" method="POST">
            @csrf
            <div class="mb-3">
                <label class="form-label">Số CCCD (nếu có)</label>
                <input type="text" name="id_number" class="form-control" placeholder="VD: 079123456789">
            </div>
            <button type="submit" class="btn btn-primary">Bắt đầu →</button>
            <a href="{{ route('applicants.index') }}" class="btn btn-outline-secondary">Danh sách hồ sơ</a>
        </form>
    </div>

@endsection