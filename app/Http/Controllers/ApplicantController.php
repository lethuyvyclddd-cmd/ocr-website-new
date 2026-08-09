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

        DB::transaction(function () use ($applicant, $request, $fileName, $rawText, $parsedData) {
            ApplicantDocument::create([
                'applicant_id' => $applicant->id,
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

            $updates = [];
            foreach ($parsedData as $column => $value) {
                if (empty($value)) {
                    continue;
                }

                $shouldOverride = $request->document_type === 'admission_form'
                    && in_array($column, $alwaysOverrideFields, true);

                if ($shouldOverride || empty($applicant->{$column})) {
                    $updates[$column] = $value;
                }
            }
            if (! empty($updates)) {
                $applicant->update($updates);
            }
        });

        return back()->with('success', 'Đã đọc và điền dữ liệu từ ' . ApplicantDocument::TYPES[$request->document_type]);
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