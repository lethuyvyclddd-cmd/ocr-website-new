<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Services\Extractors\ExtractorFactory;
use App\Services\FileTextExtractorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('student_code', 'like', "%{$keyword}%");
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
            'entry_date' => now()->toDateString(),
            'entered_by' => $request->user()->name,
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

    public function uploadDocument(Request $request, Applicant $applicant): RedirectResponse
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

        $rawText = $this->fileExtractor->extract($filePath, $extension);

        $extractor = ExtractorFactory::make($request->document_type);
        $parsedData = $extractor->extract($rawText);

        // Tìm hồ sơ KHÁC đã tồn tại trùng với người trong tài liệu vừa OCR
        // (theo CCCD, hoặc theo họ+tên+ngày sinh nếu tài liệu chưa đọc được
        // CCCD) — để GỘP tài liệu + dữ liệu vào ĐÚNG 1 hồ sơ duy nhất thay
        // vì để 2 hồ sơ rời rạc tồn tại song song cho cùng 1 người (đây là
        // nguyên nhân trực tiếp gây ra tình trạng "2 hồ sơ 1 người").
        //
        // AN TOÀN: chỉ coi là trùng để TỰ ĐỘNG gộp khi hồ sơ đang mở
        // ($applicant) CHƯA có thông tin định danh xung đột (không có
        // CCCD/họ tên khác với hồ sơ tìm thấy) — nếu có xung đột, rất có
        // thể là 2 người trùng tên/OCR đọc sai, không tự gộp mà giữ hành vi
        // cũ (cảnh báo, để admin tự xử lý) để tránh gộp nhầm dữ liệu.
        $duplicateApplicant = $this->findDuplicateApplicant($applicant, $parsedData);

        $targetApplicant = $duplicateApplicant ?? $applicant;

        DB::transaction(function () use ($targetApplicant, $request, $fileName, $rawText, $parsedData) {
            ApplicantDocument::create([
                'applicant_id' => $targetApplicant->id,
                'document_type' => $request->document_type,
                'image_path' => 'applicants/' . $fileName,
                'raw_text' => $rawText,
                'parsed_data' => $parsedData,
                'uploaded_by' => $request->user()->id,
            ]);

            // Các field mà Phiếu ĐKXT được ƯU TIÊN GHI ĐÈ, kể cả khi đã có dữ liệu từ CCCD/Bằng
            // (vì địa chỉ trên phiếu đăng ký mới hơn, sau khi Việt Nam sáp nhập tỉnh thì
            // địa chỉ in sẵn trên CCCD cũ không còn đúng nữa)
            $alwaysOverrideFields = ['permanent_address', 'ward_name', 'province_name'];

            // Họ tên/ngày sinh trên BẰNG TỐT NGHIỆP thường viết theo font
            // chữ thảo (cursive), VietOCR đọc kém chính xác hơn nhiều so
            // với CCCD (chữ in). Nếu bằng được upload TRƯỚC CCCD, các field
            // này sẽ bị điền sai và mặc định KHÔNG được ghi đè nữa (vì logic
            // gốc: chỉ điền khi đang trống). Cho phép CCCD luôn ghi đè lại
            // các field này, kể cả khi đã có giá trị (có thể sai) từ bằng
            // trước đó.
            $cccdPriorityFields = ['last_name', 'first_name', 'birth_date'];

            $updates = [];
            foreach ($parsedData as $column => $value) {
                if (empty($value)) {
                    continue;
                }

                $shouldOverride = ($request->document_type === 'admission_form'
                        && in_array($column, $alwaysOverrideFields, true))
                    || ($request->document_type === 'cccd_front'
                        && in_array($column, $cccdPriorityFields, true));

                if ($shouldOverride || empty($targetApplicant->{$column})) {
                    $updates[$column] = $value;
                }
            }
            if (! empty($updates)) {
                $targetApplicant->update($updates);
            }
        });

        if ($duplicateApplicant) {
            $currentIsEmpty = empty($applicant->id_number)
                && empty($applicant->last_name)
                && empty($applicant->first_name)
                && $applicant->documents()->count() === 0;

            return redirect()
                ->route('applicants.workspace', $duplicateApplicant->id)
                ->with('warning',
                    'Tài liệu này thuộc về người đã có hồ sơ #' . $duplicateApplicant->id
                    . ' (' . ($duplicateApplicant->full_name ?: 'chưa có tên') . '). '
                    . 'Đã tự động gộp dữ liệu + tài liệu vào hồ sơ đó thay vì tạo thêm ở hồ sơ #' . $applicant->id . '.'
                    . ($currentIsEmpty
                        ? ' Hồ sơ #' . $applicant->id . ' hiện đang trống, bạn có thể xoá nó.'
                        : ' LƯU Ý: hồ sơ #' . $applicant->id . ' đã có dữ liệu khác, vui lòng kiểm tra lại.')
                );
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
                return back()
                    ->with('duplicate_message',
                        'Đã đọc và điền dữ liệu từ ' . ApplicantDocument::TYPES[$request->document_type]
                        . '. LƯU Ý: số CCCD ' . $parsedData['id_number'] . ' đọc được đã tồn tại ở hồ sơ #'
                        . $conflicting->id . ' (' . ($conflicting->full_name ?: 'chưa có tên') . ') nhưng hồ sơ '
                        . 'hiện tại đã có thông tin khác nên KHÔNG tự động gộp. Nếu chắc chắn đây là cùng 1 '
                        . 'người (do OCR đọc sai), bấm nút bên dưới để gộp thủ công.'
                    )
                    ->with('duplicate_target_id', $conflicting->id)
                    ->with('duplicate_target_name', $conflicting->full_name ?: ('Hồ sơ #' . $conflicting->id));
            }
        }

        return back()->with('success', 'Đã đọc và điền dữ liệu từ ' . ApplicantDocument::TYPES[$request->document_type]);
    }

    /**
     * Tìm hồ sơ (applicant) KHÁC $current đã tồn tại, trùng với người vừa
     * OCR được trong $parsedData — chỉ trả về khi đủ AN TOÀN để tự động
     * gộp (xem giải thích ở uploadDocument()).
     */
    protected function findDuplicateApplicant(Applicant $current, array $parsedData): ?Applicant
    {
        $match = null;

        if (! empty($parsedData['id_number'])) {
            $match = Applicant::where('id_number', $parsedData['id_number'])
                ->where('id', '!=', $current->id)
                ->first();
        }

        if (
            ! $match
            && ! empty($parsedData['last_name'])
            && ! empty($parsedData['first_name'])
            && ! empty($parsedData['birth_date'])
        ) {
            $match = Applicant::query()
                ->where('id', '!=', $current->id)
                ->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower(trim($parsedData['last_name']), 'UTF-8')])
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower(trim($parsedData['first_name']), 'UTF-8')])
                ->whereDate('birth_date', $parsedData['birth_date'])
                ->first();
        }

        if (! $match) {
            return null;
        }

        $currentHasConflictingIdentity =
            (! empty($current->id_number) && $current->id_number !== $match->id_number)
            || (! empty($current->last_name) && mb_strtolower(trim($current->last_name), 'UTF-8') !== mb_strtolower(trim($match->last_name ?? ''), 'UTF-8'))
            || (! empty($current->first_name) && mb_strtolower(trim($current->first_name), 'UTF-8') !== mb_strtolower(trim($match->first_name ?? ''), 'UTF-8'));

        return $currentHasConflictingIdentity ? null : $match;
    }

    public function update(Request $request, Applicant $applicant): RedirectResponse
    {
        $data = $request->only(array_keys(Applicant::EXPORT_COLUMNS));

        $dateColumns = ['birth_date', 'gb_date', 'entry_date'];
        foreach ($dateColumns as $column) {
            if (! empty($data[$column])) {
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

        // Đã đúng định dạng chuẩn Y-m-d rồi -> giữ nguyên
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        // Định dạng d/m/Y (hiển thị trên form) -> chuyển về Y-m-d
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // Không nhận dạng được -> bỏ qua, không lưu để tránh lỗi
        return null;
    }

    public function destroy(Applicant $applicant): RedirectResponse
    {
        $applicant->delete();

        return redirect()->route('applicants.index')->with('success', 'Đã xoá hồ sơ.');
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