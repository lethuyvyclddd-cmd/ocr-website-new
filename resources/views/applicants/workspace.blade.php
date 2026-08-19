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
        <p class="text-muted small">
            Với ảnh chụp bằng tốt nghiệp / giấy tờ có 2 trang: vui lòng <strong>chỉ chụp 1 trang</strong>
            (ưu tiên trang tiếng Việt), tránh chụp cả 2 trang chung 1 ảnh để OCR đọc chính xác và nhanh hơn.
        </p>

        <form id="uploadForm"
              action="{{ route('applicants.documents.upload', $applicant->id) }}"
              method="POST"
              enctype="multipart/form-data"
              class="row g-2 align-items-center">
            @csrf
            <div class="col-md-4">
                <select name="document_type" id="documentType" class="form-select" required>
                    <option value="">-- Chọn loại giấy tờ --</option>
                    @foreach($documentTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <input type="file" name="image" id="imageInput" accept="image/*,.pdf,.docx" class="form-control" required>
            </div>
            <div class="col-md-3">
                <button type="submit" id="uploadSubmitBtn" class="btn btn-primary w-100">Upload & OCR</button>
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

            @php
                // CHỈ hiện đúng các trường được yêu cầu, mọi trường khác ẩn khỏi form
                // (vẫn còn cột đó khi xuất Excel, chỉ để trống nếu không có dữ liệu).
                $visibleColumns = [
                    'missing_documents', 'last_name', 'first_name', 'gender', 'birth_date',
                    'id_number', 'place_of_birth', 'ethnic', 'ward_name', 'province_name',
                    'highschool_province_name', 'highschool_name', 'highschool_graduation_year',
                    'highschool_academic_rank', 'highschool_conduct_rank',
                    'university_province_name', 'university_name', 'university_graduation_year',
                    'permanent_address', 'phone_1', 'phone_2', 'major_name',
                    'note_1', 'note_2', 'training_type',
                    'diploma_number', 'diploma_registry_number',
                ];

                $hiddenColumns = array_diff(array_keys($exportColumns), $visibleColumns);
            @endphp

            <div class="row">
                @foreach($exportColumns as $column => $label)
                    @continue(! in_array($column, $visibleColumns))
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

            @foreach($hiddenColumns as $hidden)
                <input type="hidden" name="{{ $hidden }}" value="{{ old($hidden, $applicant->{$hidden}) }}">
            @endforeach

            <button type="submit" class="btn btn-primary">💾 Lưu thông tin</button>
        </form>
    </div>

    <a href="{{ route('applicants.index') }}" class="btn btn-secondary">← Danh sách hồ sơ</a>


    {{-- ================================================================
         MODAL CROP ẢNH (chỉ hiện khi chọn file ảnh - image/*)
         PDF/DOCX sẽ bỏ qua bước này, submit form bình thường.
         ================================================================ --}}
    <div class="modal fade" id="cropModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">✂️ Cắt ảnh trước khi OCR</h5>
                    <button type="button" class="btn-close" id="cropCancelBtn" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2">
                        Kéo khung để chọn đúng vùng chứa thông tin cần đọc (bỏ viền trang trí, nền giấy thừa,
                        và nếu là bằng 2 trang thì chỉ chọn 1 trang duy nhất). Nếu ảnh bị chụp ngang/ngược,
                        dùng nút xoay bên dưới trước khi cắt.
                    </p>
                    <div class="btn-group mb-2" role="group">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="rotateLeftBtn" title="Xoay trái 90°">
                            <i class="bi bi-arrow-counterclockwise"></i> Xoay trái
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="rotateRightBtn" title="Xoay phải 90°">
                            <i class="bi bi-arrow-clockwise"></i> Xoay phải
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="flipHorizontalBtn" title="Lật ngang">
                            <i class="bi bi-symmetry-vertical"></i> Lật ngang
                        </button>
                    </div>
                    <div style="max-height: 60vh; overflow: hidden;">
                        <img id="cropperImage" style="display:block; max-width: 100%;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" id="cropUseOriginalBtn">
                        Dùng ảnh gốc (không cắt)
                    </button>
                    <button type="button" class="btn btn-primary" id="cropConfirmBtn">
                        ✅ Cắt & Upload
                    </button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('extra_script')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script>
    (function () {
        const imageInput   = document.getElementById('imageInput');
        const uploadForm   = document.getElementById('uploadForm');
        const submitBtn    = document.getElementById('uploadSubmitBtn');
        const cropperImage = document.getElementById('cropperImage');

        const cropModalEl     = document.getElementById('cropModal');
        const cropModal       = new bootstrap.Modal(cropModalEl);
        const cropConfirmBtn  = document.getElementById('cropConfirmBtn');
        const cropCancelBtn   = document.getElementById('cropCancelBtn');
        const cropUseOrigBtn  = document.getElementById('cropUseOriginalBtn');

        let cropper = null;
        let pendingFile = null;      // file gốc người dùng chọn
        let submittedViaFetch = false; // đánh dấu để form 'submit' listener của layout không can thiệp 2 lần

        function isImageFile(file) {
            return file && file.type && file.type.startsWith('image/');
        }

        function destroyCropper() {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
        }

        // Khi người dùng chọn file
        imageInput.addEventListener('change', function () {
            const file = this.files[0];
            pendingFile = file || null;
            isFlipped = false;

            if (!file || !isImageFile(file)) {
                // Không có file, hoặc PDF/DOCX -> không crop, submit bình
                // thường (native) khi bấm nút Upload.
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                cropperImage.src = e.target.result;
                cropModal.show();
            };
            reader.readAsDataURL(file);
        });

        // Khởi tạo Cropper sau khi ảnh đã load trong modal
        cropperImage.addEventListener('load', function () {
            destroyCropper();
            cropper = new Cropper(cropperImage, {
                viewMode: 1,
                autoCropArea: 0.9,
                movable: true,
                zoomable: true,
                scalable: false,
                background: false,
            });
        });

        // Xoay trái / xoay phải / lật ngang - dùng API sẵn có của Cropper.js
        const rotateLeftBtn = document.getElementById('rotateLeftBtn');
        const rotateRightBtn = document.getElementById('rotateRightBtn');
        const flipHorizontalBtn = document.getElementById('flipHorizontalBtn');
        let isFlipped = false;

        rotateLeftBtn.addEventListener('click', function () {
            if (cropper) cropper.rotate(-90);
        });

        rotateRightBtn.addEventListener('click', function () {
            if (cropper) cropper.rotate(90);
        });

        flipHorizontalBtn.addEventListener('click', function () {
            if (!cropper) return;
            isFlipped = !isFlipped;
            cropper.scaleX(isFlipped ? -1 : 1);
        });

        // Đóng modal mà không upload (huỷ chọn file)
        cropCancelBtn.addEventListener('click', function () {
            destroyCropper();
            imageInput.value = '';
            pendingFile = null;
            cropModal.hide();
        });

        // Dùng ảnh gốc, không cắt -> submit thẳng bằng file gốc qua fetch
        cropUseOrigBtn.addEventListener('click', function () {
            destroyCropper();
            cropModal.hide();
            if (pendingFile) {
                submitFormWithFile(pendingFile);
            }
        });

        // Cắt & Upload -> lấy ảnh đã crop, submit qua fetch
        cropConfirmBtn.addEventListener('click', function () {
            if (!cropper) return;

            cropConfirmBtn.disabled = true;
            cropConfirmBtn.textContent = 'Đang xử lý...';

            cropper.getCroppedCanvas({
                imageSmoothingQuality: 'high',
            }).toBlob(function (blob) {
                destroyCropper();
                cropModal.hide();

                cropConfirmBtn.disabled = false;
                cropConfirmBtn.textContent = '✅ Cắt & Upload';

                submitFormWithFile(blob, pendingFile ? pendingFile.name : 'cropped.jpg');
            }, pendingFile && pendingFile.type ? pendingFile.type : 'image/jpeg', 0.92);
        });

        // Submit form bằng fetch, dùng file/blob được truyền vào thay cho
        // input file gốc trên form.
        function submitFormWithFile(fileOrBlob, fileName) {
            const formData = new FormData(uploadForm);

            formData.delete('image');
            formData.append('image', fileOrBlob, fileName || (fileOrBlob.name || 'upload.jpg'));

            submittedViaFetch = true;

            const loading = document.getElementById('loadingScreen');
            if (loading) loading.style.display = 'flex';

            submitBtn.disabled = true;
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Đang OCR, vui lòng đợi...';

            fetch(uploadForm.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            .then(function (response) {
                if (!response.ok) {
                    return response.text().then(function (text) {
                        throw new Error('Lỗi ' + response.status + ': ' + text);
                    });
                }
                // Thành công -> reload lại trang để thấy dữ liệu OCR mới điền vào form
                window.location.reload();
            })
            .catch(function (err) {
                if (loading) loading.style.display = 'none';
                alert('Upload/OCR thất bại: ' + err.message);
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                submittedViaFetch = false;
            });
        }

        // Chặn submit gốc của form nếu đang chọn ảnh (bắt buộc phải qua
        // modal crop). Nếu là PDF/DOCX thì để form submit bình thường
        // (native, layout sẽ tự hiện loadingScreen như cũ).
        uploadForm.addEventListener('submit', function (e) {
            if (submittedViaFetch) {
                // Đã submit qua fetch rồi (trigger giả từ requestSubmit nếu có)
                return;
            }
            if (pendingFile && isImageFile(pendingFile)) {
                e.preventDefault();

                // Layout (ocr.blade.php) có listener global tự hiện
                // #loadingScreen cho MỌI submit event, kể cả khi mình
                // preventDefault(). Ẩn nó lại để tránh bị kẹt loading
                // screen phía sau modal crop.
                const loading = document.getElementById('loadingScreen');
                if (loading) loading.style.display = 'none';

                cropModal.show();
            }
            // Không phải ảnh (PDF/DOCX) hoặc chưa chọn file -> để form submit bình thường.
        });
    })();
    </script>
@endsection