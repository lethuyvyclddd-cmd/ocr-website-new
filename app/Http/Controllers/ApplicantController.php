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

    public function uploadDocument(
    Request $request,
    Applicant $applicant
): RedirectResponse {

    set_time_limit(300);
    ini_set('max_execution_time', '300');

    $request->validate([
        'image' => 'required|file|mimes:jpg,jpeg,png,bmp,webp,pdf,docx|max:20480',
        'document_type' => 'required|in:' . implode(',', array_keys(ApplicantDocument::TYPES)),
    ]);

    $file = $request->file('image');

    $extension = strtolower(
        $file->getClientOriginalExtension()
    );

    $fileName =
        'applicant_' .
        $applicant->id .
        '_' .
        $request->document_type .
        '_' .
        time() .
        '.' .
        $extension;

    $destinationPath =
        storage_path('app/public/applicants');

    $file->move(
        $destinationPath,
        $fileName
    );

    $filePath =
        $destinationPath .
        DIRECTORY_SEPARATOR .
        $fileName;

    /*
    |--------------------------------------------------------------------------
    | 1. ĐỌC OCR
    |--------------------------------------------------------------------------
    */

    $rawText = $this->fileExtractor->extract(
        $filePath,
        $extension
    );

    /*
    |--------------------------------------------------------------------------
    | 2. EXTRACT DỮ LIỆU
    |--------------------------------------------------------------------------
    */

    $extractor = ExtractorFactory::make(
        $request->document_type
    );

    $parsedData = $extractor->extract(
        $rawText
    );

    /*
    |--------------------------------------------------------------------------
    | 3. LƯU DOCUMENT + CẬP NHẬT APPLICANT
    |--------------------------------------------------------------------------
    */

    DB::transaction(function () use (
        $applicant,
        $request,
        $fileName,
        $rawText,
        $parsedData
    ) {

        /*
        |--------------------------------------------------------------------------
        | Lưu tài liệu OCR
        |--------------------------------------------------------------------------
        */

        ApplicantDocument::create([
            'applicant_id' => $applicant->id,
            'document_type' => $request->document_type,
            'image_path' => 'applicants/' . $fileName,
            'raw_text' => $rawText,
            'parsed_data' => $parsedData,
            'uploaded_by' => $request->user()->id,
        ]);


        /*
        |--------------------------------------------------------------------------
        | CÁC FIELD CỦA BẰNG TỐT NGHIỆP
        |--------------------------------------------------------------------------
        |
        | Phải ghi đè theo bằng mới.
        |
        */

        $diplomaFields = [
            'university_province_name',
            'university_name',
            'university_graduation_year',
            'note_2',
            'training_type',
            'diploma_number',
            'diploma_registry_number',
        ];


        /*
        |--------------------------------------------------------------------------
        | CÁC FIELD PHIẾU ĐKXT ĐƯỢC PHÉP GHI ĐÈ
        |--------------------------------------------------------------------------
        */

        $overridableFields = [
            'last_name',
            'first_name',
            'gender',
            'birth_date',
            'place_of_birth',
            'ethnic',
            'ward_name',
            'province_name',
        ];


        /*
        |--------------------------------------------------------------------------
        | TẠO MẢNG UPDATE
        |--------------------------------------------------------------------------
        */

        $updates = [];


        /*
        |--------------------------------------------------------------------------
        | BẰNG TỐT NGHIỆP
        |--------------------------------------------------------------------------
        |
        | QUAN TRỌNG:
        |
        | Không được dùng:
        |
        |     if (empty($applicant->{$column}))
        |
        | vì như vậy dữ liệu cũ sẽ không bị ghi đè.
        |
        */

        if (
            $request->document_type === 'diploma'
            || $request->document_type === 'diploma_transcript'
            || $request->document_type === 'graduation_diploma'
        ) {

            /*
             * Xóa dữ liệu bằng cũ trước.
             *
             * Nếu bằng mới không có "Hình thức đào tạo"
             * thì field đó phải trở thành NULL,
             * chứ KHÔNG được giữ "Chính quy" từ bằng cũ.
             */

            foreach ($diplomaFields as $field) {
                $updates[$field] = null;
            }


            /*
             * Ghi dữ liệu OCR mới vào.
             */

            foreach ($parsedData as $column => $value) {

                if (
                    !in_array(
                        $column,
                        $diplomaFields,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    $value === null ||
                    trim((string) $value) === ''
                ) {
                    continue;
                }

                $updates[$column] = trim(
                    (string) $value
                );
            }


            /*
             * Không cho phép OCR ghi "Đồng Tháp"
             * vào university_province_name chỉ vì
             * ProvinceMergeMapper.
             *
             * Giá trị lấy nguyên từ extractor.
             */


        } else {

            /*
            |--------------------------------------------------------------------------
            | CÁC GIẤY TỜ KHÁC
            |--------------------------------------------------------------------------
            */

            foreach ($parsedData as $column => $value) {

                if (
                    $value === null ||
                    trim((string) $value) === ''
                ) {
                    continue;
                }


                /*
                 * Phiếu ĐKXT được phép ghi đè
                 * một số field.
                 */

                if (
                    in_array(
                        $column,
                        $overridableFields,
                        true
                    )
                ) {
                    $updates[$column] = $value;
                    continue;
                }


                /*
                 * Các field còn lại:
                 * chỉ ghi nếu DB đang trống.
                 */

                if (
                    empty($applicant->{$column})
                ) {
                    $updates[$column] = $value;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */

        if (!empty($updates)) {
            $applicant->update($updates);
        }
    });


    return back()->with(
        'success',
        'Đã đọc và điền dữ liệu từ ' .
        ApplicantDocument::TYPES[
            $request->document_type
        ]
    );
}

    public function destroyDocument(Applicant $applicant, ApplicantDocument $document): RedirectResponse
    {
        // Đảm bảo tài liệu này đúng là của applicant đang xem, tránh xoá nhầm hồ sơ khác
        if ($document->applicant_id !== $applicant->id) {
            abort(404);
        }

        if ($document->image_path && \Storage::disk('public')->exists($document->image_path)) {
            \Storage::disk('public')->delete($document->image_path);
        }

        $document->delete();

        return back()->with('success', 'Đã xoá ảnh/giấy tờ.');
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