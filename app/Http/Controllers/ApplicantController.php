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

            $updates = [];
            foreach ($parsedData as $column => $value) {
                if (empty($value)) {
                    continue;
                }
                if (empty($applicant->{$column})) {
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
        $applicant->update($data);

        return back()->with('success', 'Đã lưu thông tin hồ sơ.');
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