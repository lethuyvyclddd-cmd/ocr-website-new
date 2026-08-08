@extends('layouts.ocr')

@section('title', 'Hồ sơ #' . $applicant->id)

@section('content')

    <h1 class="page-title">
        Hồ sơ: {{ $applicant->full_name ?: '(chưa có tên)' }}
        <small class="text-muted" style="font-size:16px">— CCCD: {{ $applicant->id_number ?: '(chưa có)' }}</small>
    </h1>

    <div class="card-box mb-4">
        <h5 class="mb-3">📷 Upload giấy tờ</h5>
        <p class="text-muted">Chọn loại giấy tờ rồi upload ảnh — hệ thống sẽ OCR và tự điền vào form bên dưới.</p>

        <form action="{{ route('applicants.documents.upload', $applicant->id) }}" method="POST"
              enctype="multipart/form-data" class="row g-2 align-items-center">
            @csrf
            <div class="col-md-4">
                <select name="document_type" class="form-select" required>
                    <option value="">-- Chọn loại giấy tờ --</option>
                    @foreach($documentTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <input type="file" name="image" accept="image/*,.pdf,.docx" class="form-control" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">Upload & OCR</button>
            </div>
        </form>

        @if($applicant->documents->isNotEmpty())
            <hr>
            <h6 class="text-muted">Đã upload:</h6>
            <ul class="list-unstyled">
                @foreach($applicant->documents as $doc)
                    <li>
                        ✅ {{ \App\Models\ApplicantDocument::TYPES[$doc->document_type] ?? $doc->document_type }}
                        <span class="text-muted">— {{ $doc->created_at->format('d/m/Y H:i') }}</span>
                        <a href="{{ asset('storage/' . $doc->image_path) }}" target="_blank" class="ms-2">Xem ảnh</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card-box mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">📝 Thông tin hồ sơ (kiểm tra & chỉnh sửa nếu OCR đọc sai)</h5>
            <a href="{{ route('applicants.export.one', $applicant->id) }}" class="btn btn-success btn-sm">
                Xuất Excel hồ sơ này
            </a>
        </div>

        <form action="{{ route('applicants.update', $applicant->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="row">
                @foreach($exportColumns as $column => $label)
                    @php
                        $rawValue = $applicant->{$column};
                        if ($rawValue instanceof \Carbon\Carbon) {
                            $rawValue = $rawValue->format('d/m/Y');
                        }
                    @endphp
                    <div class="col-md-4 mb-3">
                        <label class="form-label">{{ $label }}</label>
                        <input type="text" name="{{ $column }}" class="form-control"
                               value="{{ old($column, $rawValue) }}">
                    </div>
                @endforeach
            </div>

            <button type="submit" class="btn btn-primary">💾 Lưu thông tin</button>
        </form>
    </div>

    <a href="{{ route('applicants.index') }}" class="btn btn-secondary">← Danh sách hồ sơ</a>

@endsection