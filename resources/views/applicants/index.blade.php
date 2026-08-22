@extends('layouts.ocr')

@section('title', 'Danh sách hồ sơ thí sinh')

@section('content')

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="page-title mb-0">Danh sách hồ sơ thí sinh</h1>
        <a href="{{ route('applicants.create') }}" class="btn btn-primary">+ Hồ sơ mới</a>
    </div>

    <form method="GET" class="mb-3 row g-2">
        <div class="col-md-4">
            <input type="text" name="keyword" value="{{ request('keyword') }}" class="form-control"
                   placeholder="Tìm theo CCCD, họ tên, mã hồ sơ...">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary w-100">Tìm kiếm</button>
        </div>
    </form>

    @if(auth()->user()->role === 'admin')
        <div class="mb-2">
            <button type="button" id="exportBtn" class="btn btn-success btn-sm">
                📊 Xuất Excel (đã chọn / tất cả nếu không chọn)
            </button>
        </div>
    @endif

    <div class="table-wrap">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th><input type="checkbox" id="checkAll"></th>
                    <th>CCCD</th>
                    <th>Họ tên</th>
                    <th>Ngày sinh</th>
                    <th>Ngành xét tuyển</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse($applicants as $applicant)
                    <tr>
                        <td><input type="checkbox" value="{{ $applicant->id }}" class="rowCheck"></td>
                        <td>{{ $applicant->id_number }}</td>
                        <td>{{ $applicant->full_name }}</td>
                        <td>{{ $applicant->birth_date?->format('d/m/Y') }}</td>
                        <td>{{ $applicant->major_name }}</td>
                        <td>
                            <a href="{{ route('applicants.workspace', $applicant->id) }}" class="btn btn-info btn-sm">Xem / Sửa</a>
                            <form action="{{ route('applicants.destroy', $applicant->id) }}" method="POST" style="display:inline"
                                  onsubmit="return confirm('Xoá hồ sơ này?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-danger btn-sm">Xoá</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">Chưa có hồ sơ nào</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $applicants->links() }}</div>

@endsection

@section('extra_script')
<script>
document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = this.checked);
});

document.getElementById('exportBtn').addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.rowCheck:checked')).map(cb => cb.value);
    const url = new URL('{{ route('applicants.export.batch') }}', window.location.origin);
    if (ids.length > 0) {
        url.searchParams.set('ids', ids.join(','));
    }
    window.location.href = url.toString();
});
</script>
@endsection