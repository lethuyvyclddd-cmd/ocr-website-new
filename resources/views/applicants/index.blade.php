@extends('layouts.ocr')

@section('title', 'Danh sách hồ sơ thí sinh')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0">
        Danh sách hồ sơ thí sinh
    </h1>

    <a href="{{ route('applicants.create') }}" class="btn btn-primary">
        + Hồ sơ mới
    </a>
</div>

<form method="GET" class="mb-3 row g-2">

    <div class="col-md-4">
        <input
            type="text"
            name="keyword"
            value="{{ request('keyword') }}"
            class="form-control"
            placeholder="Tìm theo CCCD, họ tên, mã hồ sơ..."
        >
    </div>

    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-primary w-100">
            Tìm kiếm
        </button>
    </div>

</form>


@if(auth()->user()->role === 'admin')

<div class="mb-2">

    <button
        type="button"
        id="exportBtn"
        class="btn btn-success btn-sm"
    >
        📊 Xuất Excel (đã chọn / tất cả nếu không chọn)
    </button>

</div>

@endif


<div class="table-wrap">

    <table class="table table-bordered">

        <thead>

            <tr>

                <th>
                    <input type="checkbox" id="checkAll">
                </th>

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

                <td>

                    <input
                        type="checkbox"
                        value="{{ $applicant->id }}"
                        class="rowCheck"
                    >

                </td>


                <td>
                    {{ $applicant->id_number }}
                </td>


                <td>
                    {{ $applicant->full_name }}
                </td>


                <td>
                    {{ $applicant->birth_date?->format('d/m/Y') }}
                </td>


                <td>
                    {{ $applicant->major_name }}
                </td>


                <td>

                    <a
                        href="{{ route('applicants.workspace', $applicant->id) }}"
                        class="btn btn-info btn-sm"
                    >
                        Xem / Sửa
                    </a>


                    {{-- FORM XÓA: CHỈ ADMIN mới thấy --}}
                    @if(auth()->user()->role === 'admin')
                    <form
                        action="{{ route('applicants.destroy', $applicant->id) }}"
                        method="POST"
                        style="display:inline"
                        class="delete-form"
                    >

                        @csrf

                        @method('DELETE')

                        <button
                            type="submit"
                            class="btn btn-danger btn-sm"
                        >
                            Xóa
                        </button>

                    </form>
                    @endif

                </td>

            </tr>

            @empty

            <tr>

                <td
                    colspan="6"
                    class="text-center text-muted"
                >
                    Chưa có hồ sơ nào
                </td>

            </tr>

            @endforelse

        </tbody>

    </table>

</div>


<div class="mt-3">

    {{ $applicants->links() }}

</div>

@endsection


@section('extra_script')

<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | CHECK ALL
    |--------------------------------------------------------------------------
    */

    const checkAll = document.getElementById('checkAll');

    if (checkAll) {

        checkAll.addEventListener('change', function () {

            document
                .querySelectorAll('.rowCheck')
                .forEach(function (checkbox) {

                    checkbox.checked = checkAll.checked;

                });

        });

    }


    /*
    |--------------------------------------------------------------------------
    | EXPORT EXCEL
    |--------------------------------------------------------------------------
    */

    const exportBtn = document.getElementById('exportBtn');

    if (exportBtn) {

        exportBtn.addEventListener('click', function () {

            const ids = Array
                .from(
                    document.querySelectorAll('.rowCheck:checked')
                )
                .map(function (checkbox) {

                    return checkbox.value;

                });


            const url = new URL(
                '{{ route('applicants.export.batch') }}',
                window.location.origin
            );


            if (ids.length > 0) {

                url.searchParams.set(
                    'ids',
                    ids.join(',')
                );

            }


            window.location.href = url.toString();

        });

    }

});

</script>

@endsection