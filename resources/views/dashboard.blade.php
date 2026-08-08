@extends('layouts.ocr')

@section('title', 'Dashboard')

@section('content')

    <div class="text-center mb-4">
        <span class="badge bg-primary">Tổng quan hệ thống</span>
    </div>

    <h1 class="page-title text-center">Hệ thống quản lý hồ sơ thí sinh</h1>
    <p class="page-subtitle text-center">Chào mừng, {{ auth()->user()->name }} 👋</p>

    <div class="row justify-content-center mt-4">
        <div class="col-md-4">
            <div class="card-box text-center">
                <h2 class="text-primary">{{ \App\Models\Applicant::count() }}</h2>
                <p class="text-muted mb-0">Tổng hồ sơ thí sinh</p>
            </div>
        </div>
    </div>

    <div class="row mt-4 g-3">
        <div class="col-md-4">
            <a href="{{ route('applicants.create') }}" class="btn btn-primary w-100 p-3">+ Hồ sơ mới</a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('applicants.index') }}" class="btn btn-success w-100 p-3">Danh sách hồ sơ</a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('applicants.export.batch') }}" class="btn btn-warning w-100 p-3">Xuất Excel toàn bộ</a>
        </div>
    </div>

@endsection