<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Services\Extractors\ExtractorFactory;
use App\Services\FileTextExtractorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ApplicantController extends Controller
{
    public function __construct(protected FileTextExtractorService $fileExtractor)
    {
    }

    public function index(Request $request): View
    {
        $query = Applicant::query();

        if ($keyword = $request->keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('id_number', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%");
            });
        }

        $applicants = $query->latest()->paginate(20)->withQueryString();

        return view('applicants.index', compact('applicants'));
    }

    public function create(): View
    {
        return view('applicants.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'id_number' => 'nullable|string|max:20',
        ]);

        if ($request->filled('id_number')) {
            $existing = Applicant::where('id_number', $request->id_number)->first();
            if ($existing) {
                return redirect()
                    ->route('applicants.workspace', $existing->id)
                    ->with('info', 'Đã tìm thấy hồ sơ có sẵn với số CCCD này, tiếp tục bổ sung giấy tờ.');
            }
        }

        $applicant = Applicant::create([
            'id_number' => $request->id_number,
        ]);

        return redirect()->route('applicants.workspace', $applicant->id);
    }

    public function workspace(Applicant $applicant): View
    {
        $applicant->load('documents');

        return view('applicants.workspace', [
            'applicant' => $applicant,
            'documentTypes' => ApplicantDocument::TYPES,
            'exportColumns' => Applicant::EXPORT_COLUMNS,
        ]);
    }

        /**
     * Bọc 1 RedirectResponse đã có flash data (->with(...)) để dùng an toàn
     * cho CẢ 2 luồng: submit form thường (native) VÀ submit qua fetch()
     * (luồng crop ảnh ở workspace.blade.php).
     *
     * LÝ DO CẦN HÀM NÀY: ->with() trên RedirectResponse flash dữ liệu vào
     * session NGAY LẬP TỨC (không cần đợi response được gửi đi), nên flash
     * data đã có sẵn trong session tại thời điểm gọi hàm này, bất kể ta trả
     * về JSON hay redirect thật ở cuối.
     *
     * Nếu trả thẳng RedirectResponse (302) cho fetch(): fetch() mặc định tự
     * động follow redirect, khiến trang đích được tải NGẦM trong chính lần
     * fetch đó (flash message được hiển thị + "dùng hết" trong response bị
     * fetch() âm thầm bỏ qua), rồi JS gọi window.location.reload() thêm 1
     * lần nữa -> lúc này flash đã mất, người dùng không thấy thông báo lỗi/
     * cảnh báo/thành công nào cả.
     *
     * Do đó với AJAX, ta trả JSON chỉ chứa URL đích; JS sẽ tự điều hướng
     * bằng window.location.href (một request GET hoàn toàn mới), lúc đó
     * session flash mới được "age" và hiển thị đúng 1 lần.
     */
    protected function respondWithRedirect(Request $request, RedirectResponse $redirect): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect->getTargetUrl(),
            ]);
        }

        return $redirect;
    }

    public function uploadDocument(Request $request, Applicant $applicant): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,bmp,webp,pdf,docx|max:20480',
            'document_type' => 'required|in:' . implode(',', array_keys(ApplicantDocument::TYPES)),
        ]);

        $file = $request->file('image');
        $extension = strtolower($file->getClientOriginalExtension());
        $fileName = 'applicant_' . $applicant->id . '_' . $request->document_type . '_' . time() . '.' . $extension;
        $destinationPath = storage_path('app/public/applicants');
        $file->move($destinationPath, $fileName);
        $filePath = $destinationPath . DIRECTORY_SEPARATOR . $fileName;

        // FIX: bọc bước đọc file (OCR ảnh/PDF scan, parse DOCX, parse PDF
        // chữ) bằng try/catch rộng (\Throwable) thay vì để lỗi văng thẳng
        // thành 500. Nguồn lỗi có thể đến từ nhiều nơi khác nhau:
        //   - OcrService: RuntimeException khi service OCR sập/timeout/trả lỗi
        //   - PhpWord (docx hỏng): các exception riêng của PhpOffice\PhpWord
        //   - Smalot\PdfParser (pdf hỏng/mã hoá): exception riêng của thư viện,
        //     đôi khi cả Error cấp thấp (vd file corrupt nặng)
        // \RuntimeException đơn thuần không bắt được hết các trường hợp trên,
        // nên phải dùng \Throwable để đồng nhất trải nghiệm lỗi cho mọi loại
        // file, đồng thời dọn file vừa upload để tránh rác tồn đọng trên đĩa.
        // Bước 1: Đọc nội dung file / OCR
        try {
            $rawText = $this->fileExtractor->extract($filePath, $extension, $request->document_type);
        } catch (\Throwable $e) {
            @unlink($filePath);
            return $this->respondWithRedirect($request, back()->with(
                'error',
                'Không thể đọc được tài liệu: ' . $e->getMessage()
            ));
        }

        // THÊM MỚI
        if (trim($rawText) === '' && $extension === 'pdf') {
            @unlink($filePath);
            return $this->respondWithRedirect($request, back()->with(
                'error',
                'Không tìm thấy nội dung phù hợp với loại giấy tờ "'
                    . ApplicantDocument::TYPES[$request->document_type]
                    . '" trong file PDF đã tải lên. Vui lòng kiểm tra lại file hoặc chọn đúng loại giấy tờ.'
            ));
        }


        // Bước 2: Trích xuất dữ liệu và lưu database
        try {

            // Chọn Extractor phù hợp với loại tài liệu.
            // THÊM MỚI: truyền thêm $rawText để ExtractorFactory tự nhận
            // diện bằng Học viện Phật giáo (dùng chung document_type
            // 'diploma_transcript' với bằng Bách Khoa, không có mục riêng
            // trên dropdown) — xem chi tiết ở ExtractorFactory::make().
            $extractor = ExtractorFactory::make($request->document_type, $rawText);

            // Trích xuất dữ liệu từ text OCR
            $parsedData = $extractor->extract($rawText);

            // Tìm hồ sơ trùng nếu có
            $duplicateApplicant = $this->findDuplicateApplicant(
                $applicant,
                $parsedData
            );

            // Nếu có hồ sơ trùng thì lưu tài liệu vào hồ sơ đó
            $targetApplicant = $duplicateApplicant ?? $applicant;

            DB::transaction(function () use (
                $targetApplicant,
                $request,
                $fileName,
                $rawText,
                $parsedData
            ) {

                ApplicantDocument::create([
                    'applicant_id' => $targetApplicant->id,
                    'document_type' => $request->document_type,
                    'image_path' => 'applicants/' . $fileName,
                    'raw_text' => $rawText,
                    'parsed_data' => $parsedData,
                    'uploaded_by' => $request->user()->id,
                ]);

                $alwaysOverrideFields = [
                    'permanent_address',
                    'ward_name',
                    'province_name',
                ];

                $cccdPriorityFields = [
                    'last_name',
                    'first_name',
                    'birth_date',
                    'id_number',
                    'gender',
                    'ethnic',
                ];

                $diplomaPriorityFields = [
                    'diploma_number',
                    'diploma_registry_number',
                ];

                $updates = [];

                foreach ($parsedData as $column => $value) {

                    if (empty($value)) {
                        continue;
                    }

                    // Nơi sinh chỉ lấy từ Phiếu đăng ký xét tuyển
                    if (
                        $column === 'place_of_birth'
                        && $request->document_type !== 'admission_form'
                    ) {
                        continue;
                    }

                    $shouldOverride =
                        ($request->document_type === 'admission_form'
                            && in_array($column, $alwaysOverrideFields, true))
                        || ($request->document_type === 'cccd_front'
                            && in_array($column, $cccdPriorityFields, true))
                        || ($request->document_type === 'diploma_transcript'
                            && in_array($column, $diplomaPriorityFields, true));

                    if (
                        $shouldOverride
                        || empty($targetApplicant->{$column})
                    ) {
                        $updates[$column] = $value;
                    }
                }

                if (!empty($updates)) {
                    $targetApplicant->update($updates);
                }
            });

        } catch (\Throwable $e) {

            // Nếu Extractor hoặc Database bị lỗi
            // thì xóa file vừa upload để tránh file rác
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            return $this->respondWithRedirect($request, back()->with(
                'error',
                'Không thể xử lý và lưu tài liệu: ' . $e->getMessage()
            ));
        }

        if ($duplicateApplicant) {
            $currentIsEmpty = empty($applicant->id_number)
                && empty($applicant->last_name)
                && empty($applicant->first_name)
                && $applicant->documents()->count() === 0;

            return $this->respondWithRedirect($request, redirect()
                ->route('applicants.workspace', $duplicateApplicant->id)
                ->with('warning',
                    'Tài liệu này thuộc về người đã có hồ sơ #' . $duplicateApplicant->id
                    . ' (' . ($duplicateApplicant->full_name ?: 'chưa có tên') . '). '
                    . 'Đã tự động gộp dữ liệu + tài liệu vào hồ sơ đó thay vì tạo thêm ở hồ sơ #' . $applicant->id . '.'
                    . ($currentIsEmpty
                        ? ' Hồ sơ #' . $applicant->id . ' hiện đang trống, bạn có thể xoá nó.'
                        : ' LƯU Ý: hồ sơ #' . $applicant->id . ' đã có dữ liệu khác, vui lòng kiểm tra lại.')
                ));
        }

        // Trường hợp CCCD đọc được trùng với hồ sơ khác NHƯNG không đủ an
        // toàn để tự gộp (hồ sơ hiện tại đã có tên/CCCD xung đột) — giữ
        // hành vi cảnh báo, KHÔNG tự động ghi đè id_number, nhưng cho phép
        // admin tự bấm nút "Gộp vào hồ sơ cũ" nếu xác nhận đúng là cùng 1
        // người (ví dụ do OCR đọc sai tên ở 1 trong 2 hồ sơ).
        if (! empty($parsedData['id_number'])) {
            $conflicting = Applicant::where('id_number', $parsedData['id_number'])
                ->where('id', '!=', $applicant->id)
                ->first();

            if ($conflicting) {
                return $this->respondWithRedirect($request, back()
                    ->with('duplicate_message',
                        'Đã đọc và điền dữ liệu từ ' . ApplicantDocument::TYPES[$request->document_type]
                        . '. LƯU Ý: số CCCD ' . $parsedData['id_number'] . ' đọc được đã tồn tại ở hồ sơ #'
                        . $conflicting->id . ' (' . ($conflicting->full_name ?: 'chưa có tên') . ') nhưng hồ sơ '
                        . 'hiện tại đã có thông tin khác nên KHÔNG tự động gộp. Nếu chắc chắn đây là cùng 1 '
                        . 'người (do OCR đọc sai), bấm nút bên dưới để gộp thủ công.'
                    )
                    ->with('duplicate_target_id', $conflicting->id)
                    ->with('duplicate_target_name', $conflicting->full_name ?: ('Hồ sơ #' . $conflicting->id)));
            }
        }

        return $this->respondWithRedirect($request, back()->with('success', 'Đã đọc và điền dữ liệu từ ' . ApplicantDocument::TYPES[$request->document_type]));
    }

    /**
     * Tìm hồ sơ (applicant) KHÁC $current đã tồn tại, trùng với người vừa
     * OCR được trong $parsedData — chỉ trả về khi đủ AN TOÀN để tự động
     * gộp (xem giải thích ở uploadDocument()).
     */
    protected function findDuplicateApplicant(
        Applicant $current,
        array $parsedData
    ): ?Applicant {
        $match = null;

        // Chuẩn hóa CCCD từ dữ liệu OCR
        $normalizedIdNumber = $this->normalizeIdNumber(
            $parsedData['id_number'] ?? null
        );

        // 1. Ưu tiên tìm trùng bằng CCCD
        if ($normalizedIdNumber) {

            // Lấy các hồ sơ khác hồ sơ hiện tại
            $applicants = Applicant::where('id', '!=', $current->id)
                ->whereNotNull('id_number')
                ->get();

            // So sánh CCCD sau khi chuẩn hóa
            $match = $applicants->first(function ($applicant) use ($normalizedIdNumber) {
                return $this->normalizeIdNumber($applicant->id_number)
                    === $normalizedIdNumber;
            });
        }

        // 2. Nếu không tìm thấy bằng CCCD thì so sánh:
        // Họ + Tên + Ngày sinh
        if (
            ! $match
            && ! empty($parsedData['last_name'])
            && ! empty($parsedData['first_name'])
            && ! empty($parsedData['birth_date'])
        ) {
            $match = Applicant::query()
                ->where('id', '!=', $current->id)
                ->whereRaw(
                    'LOWER(TRIM(last_name)) = ?',
                    [mb_strtolower(trim($parsedData['last_name']), 'UTF-8')]
                )
                ->whereRaw(
                    'LOWER(TRIM(first_name)) = ?',
                    [mb_strtolower(trim($parsedData['first_name']), 'UTF-8')]
                )
                ->whereDate('birth_date', $parsedData['birth_date'])
                ->first();
        }

        // Không tìm thấy hồ sơ trùng
        if (! $match) {
            return null;
        }

        // Chuẩn hóa CCCD của hồ sơ hiện tại và hồ sơ tìm được
        $currentIdNumber = $this->normalizeIdNumber($current->id_number);
        $matchIdNumber = $this->normalizeIdNumber($match->id_number);

        // Kiểm tra xem hồ sơ hiện tại có thông tin định danh
        // mâu thuẫn với hồ sơ trùng hay không
        $currentHasConflictingIdentity =
            (
                ! empty($currentIdNumber)
                && ! empty($matchIdNumber)
                && $currentIdNumber !== $matchIdNumber
            )
            || (
                ! empty($current->last_name)
                && mb_strtolower(trim($current->last_name), 'UTF-8')
                    !== mb_strtolower(trim($match->last_name ?? ''), 'UTF-8')
            )
            || (
                ! empty($current->first_name)
                && mb_strtolower(trim($current->first_name), 'UTF-8')
                    !== mb_strtolower(trim($match->first_name ?? ''), 'UTF-8')
            );

        return $currentHasConflictingIdentity ? null : $match;
    }
    protected function normalizeIdNumber(?string $idNumber): ?string
    {
        if (empty($idNumber)) {
            return null;
        }

        // Chỉ giữ lại chữ số
        $normalized = preg_replace('/\D/', '', $idNumber);

        return $normalized !== '' ? $normalized : null;
    }

    public function update(Request $request, Applicant $applicant): RedirectResponse
    {
        $validated = $request->validate([
            // Thông tin hồ sơ
            'missing_documents' => 'nullable|string|max:255',

            // Thông tin cá nhân
            'last_name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:50',
            'birth_date' => 'nullable|string|max:20',
            'id_number' => 'nullable|string|max:20',
            'place_of_birth' => 'nullable|string|max:255',
            'ethnic' => 'nullable|string|max:100',

            // Địa chỉ
            'ward_name' => 'nullable|string|max:255',
            'province_name' => 'nullable|string|max:255',
            'permanent_address' => 'nullable|string|max:1000',

            // THPT
            'highschool_province_name' => 'nullable|string|max:255',
            'highschool_name' => 'nullable|string|max:255',
            'highschool_graduation_year' => 'nullable|integer|min:1900|max:2100',
            'highschool_academic_rank' => 'nullable|string|max:100',
            'highschool_conduct_rank' => 'nullable|string|max:100',

            // Trung cấp / Cao đẳng / Đại học
            'university_province_name' => 'nullable|string|max:255',
            'university_name' => 'nullable|string|max:255',
            'university_graduation_year' => 'nullable|integer|min:1900|max:2100',

            // Liên hệ
            'phone_1' => 'nullable|string|max:20',
            'phone_2' => 'nullable|string|max:20',

            // Ngành học
            'major_name' => 'nullable|string|max:255',

            // Ghi chú
            'note_1' => 'nullable|string|max:1000',
            'note_2' => 'nullable|string|max:1000',

            // Bằng tốt nghiệp
            'training_type' => 'nullable|string|max:255',
            'diploma_number' => 'nullable|string|max:100',
            'diploma_registry_number' => 'nullable|string|max:100',
        ]);

        // Chỉ lấy các cột được phép xuất/cập nhật
        $data = collect($validated)
            ->only(array_keys(Applicant::EXPORT_COLUMNS))
            ->toArray();

        // Chuẩn hóa các trường ngày tháng
        $dateColumns = [
            'birth_date',
        ];

        foreach ($dateColumns as $column) {
            if (!empty($data[$column])) {
                $data[$column] = $this->parseDateInput($data[$column]);
            }
        }

        $applicant->update($data);

        return back()->with('success', 'Đã lưu thông tin hồ sơ.');
    }
    protected function parseDateInput(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Định dạng YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = \DateTime::createFromFormat('Y-m-d', $value);

            if ($date && $date->format('Y-m-d') === $value) {
                return $value;
            }
        }

        // Định dạng DD/MM/YYYY
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
            $date = \DateTime::createFromFormat('d/m/Y', $value);

            if ($date && $date->format('d/m/Y') === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public function destroy(Applicant $applicant): RedirectResponse
    {
        // Lấy toàn bộ tài liệu thuộc hồ sơ
        $applicant->load('documents');

        // Xóa các file vật lý
        foreach ($applicant->documents as $document) {
            if (!empty($document->image_path)) {
                Storage::disk('public')->delete($document->image_path);
            }
        }

        // Xóa hồ sơ
        // Các bản ghi applicant_documents sẽ được xóa theo cascade
        $applicant->delete();

        return redirect()
            ->route('applicants.index')
            ->with('success', 'Đã xoá hồ sơ và toàn bộ tài liệu liên quan.');
    }

    /**
     * Gộp $applicant (hồ sơ trùng, không đủ an toàn để tự động gộp lúc
     * upload - vd có xung đột tên/CCCD do OCR đọc sai) vào $target (hồ sơ
     * gốc), theo xác nhận thủ công của admin qua nút "Gộp vào hồ sơ cũ".
     *
     * - Chuyển toàn bộ ApplicantDocument từ $applicant sang $target.
     * - Điền các field còn TRỐNG ở $target bằng dữ liệu từ $applicant
     *   (không ghi đè field $target đã có giá trị).
     * - Xoá $applicant sau khi gộp xong.
     */
    public function mergeInto(Applicant $applicant, Applicant $target): RedirectResponse
    {
        if ($applicant->id === $target->id) {
            return back()->with('error', 'Không thể gộp hồ sơ vào chính nó.');
        }

        DB::transaction(function () use ($applicant, $target) {
            ApplicantDocument::where('applicant_id', $applicant->id)
                ->update(['applicant_id' => $target->id]);

            $updates = [];
            foreach (array_keys(Applicant::EXPORT_COLUMNS) as $column) {
                if (! empty($applicant->{$column}) && empty($target->{$column})) {
                    $updates[$column] = $applicant->{$column};
                }
            }

            if (! empty($updates)) {
                $target->update($updates);
            }

            $applicant->delete();
        });

        return redirect()->route('applicants.workspace', $target->id)
            ->with('success', 'Đã gộp hồ sơ trùng vào hồ sơ này thành công.');
    }

    public function exportOne(Applicant $applicant)
    {
        return $this->buildExcel([$applicant], 'HoSo_' . ($applicant->id_number ?: $applicant->id) . '.xlsx');
    }

    public function exportBatch(Request $request)
    {
        $query = Applicant::query();

        if ($request->filled('ids')) {
            $ids = explode(',', $request->ids);
            $query->whereIn('id', $ids);
        }

        $applicants = $query->orderBy('id')->get();

        return $this->buildExcel($applicants, 'DanhSach_ThiSinh_' . now()->format('YmdHis') . '.xlsx');
    }

    protected function buildExcel(iterable $applicants, string $fileName)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $columns = array_keys(Applicant::EXPORT_COLUMNS);
        $headers = array_values(Applicant::EXPORT_COLUMNS);

        foreach ($headers as $i => $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter . '1', $header);
        }

        $rowIndex = 2;
        foreach ($applicants as $applicant) {
            foreach ($columns as $i => $column) {
                $value = $applicant->{$column};
                if ($value instanceof \Carbon\Carbon) {
                    $value = $value->format('d/m/Y');
                }
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue($colLetter . $rowIndex, $value);
            }
            $rowIndex++;
        }

        $path = storage_path('app/public/' . $fileName);
        (new Xlsx($spreadsheet))->save($path);

        return response()->download($path)->deleteFileAfterSend(true);
    }
}