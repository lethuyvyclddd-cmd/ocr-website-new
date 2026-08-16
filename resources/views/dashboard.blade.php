@extends('layouts.ocr')

@section('title', 'Dashboard')

@section('content')

    <h1 class="page-title">Xin chào, {{ auth()->user()->name }} 👋</h1>
    <p class="page-subtitle">Tổng quan hệ thống quản lý hồ sơ thí sinh.</p>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card-box text-center">
                <div style="font-size:32px">📋</div>
                <h2 class="text-primary mb-0">{{ $totalApplicants }}</h2>
                <p class="text-muted mb-0">Tổng hồ sơ thí sinh</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-box text-center">
                <div style="font-size:32px">🆕</div>
                <h2 class="text-success mb-0">{{ $todayApplicants }}</h2>
                <p class="text-muted mb-0">Hồ sơ tạo hôm nay</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-box text-center">
                <div style="font-size:32px">⚠️</div>
                <h2 class="text-warning mb-0">{{ $missingDocsCount }}</h2>
                <p class="text-muted mb-0">Hồ sơ còn thiếu giấy tờ</p>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="{{ route('applicants.create') }}" class="btn btn-primary w-100 p-3">
                + Hồ sơ mới
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('applicants.index') }}" class="btn btn-success w-100 p-3">
                Danh sách hồ sơ
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('applicants.export.batch') }}" class="btn btn-warning w-100 p-3">
                Xuất Excel toàn bộ
            </a>
        </div>
    </div>

    <div class="card-box">
        <h5 class="mb-3">🕒 Hồ sơ mới nhất</h5>

        @if($recentApplicants->isEmpty())
            <p class="text-muted mb-0">Chưa có hồ sơ nào — bấm "+ Hồ sơ mới" để bắt đầu.</p>
        @else
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>CCCD</th>
                            <th>Họ tên</th>
                            <th>Ngành</th>
                            <th>Ngày tạo</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentApplicants as $applicant)
                            <tr>
                                <td>{{ $applicant->id_number ?: '—' }}</td>
                                <td>{{ $applicant->full_name ?: '(chưa có tên)' }}</td>
                                <td>{{ $applicant->major_name ?: '—' }}</td>
                                <td>{{ $applicant->created_at->format('d/m/Y H:i') }}</td>
                                <td>
                                    <a href="{{ route('applicants.workspace', $applicant->id) }}" class="btn btn-info btn-sm">
                                        Xem
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

@endsection