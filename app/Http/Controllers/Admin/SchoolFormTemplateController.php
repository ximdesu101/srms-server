<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\DocumentSubmission;
use App\Models\Admin\SchoolFormTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class SchoolFormTemplateController extends Controller
{
    private const ALLOWED_FORM_CODES = [
        'SF1',
        'SF2',
        'SF3',
        'SF4',
        'SF5',
        'SF6',
        'SF7',
        'SF8',
        'SF9',
        'SF10',
    ];

    private const ALLOWED_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
    ];

    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];


    /**
     * GET /admin/school-forms/summary
     * Returns per-form approved file counts and template metadata for the School Forms cards.
     * Uses the current (active) template only.
     */
    public function summary(): JsonResponse
    {
        $templates = SchoolFormTemplate::query()
            ->current()
            ->whereIn('form_code', self::ALLOWED_FORM_CODES)
            ->get()
            ->keyBy('form_code');

        $approvedCounts = DocumentSubmission::query()
            ->where('status', DocumentSubmission::STATUS_APPROVED)
            ->join('submission_requests', 'document_submissions.submission_request_id', '=', 'submission_requests.id')
            ->whereIn('submission_requests.document_code', self::ALLOWED_FORM_CODES)
            ->selectRaw('submission_requests.document_code as document_code, COUNT(document_submissions.id) as total')
            ->groupBy('submission_requests.document_code')
            ->pluck('total', 'document_code');

        $forms = [];
        foreach (self::ALLOWED_FORM_CODES as $code) {
            $template = $templates->get($code);
            $forms[] = [
                'form_code' => $code,
                'approved_count' => (int) ($approvedCounts[$code] ?? 0),
                'template_count' => $template ? 1 : 0,
                'has_template' => (bool) $template,
                'last_updated' => $template?->updated_at?->format('M. j, Y'),
                'last_updated_iso' => $template?->updated_at?->toIso8601String(),
            ];
        }

        return response()->json(['forms' => $forms]);
    }

    /**
     * GET /admin/school-forms/{formCode}/templates
     * List all templates (current + archived) for a form, newest first.
     */
    public function index(string $formCode): JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (!in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $templates = SchoolFormTemplate::query()
            ->where('form_code', $formCode)
            ->orderByRaw("CASE WHEN status = 'current' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolFormTemplate $t) => $this->formatTemplate($t));

        return response()->json([
            'templates' => $templates,
        ]);
    }

    /**
     * GET /admin/school-forms/{formCode}/template
     * Returns the current active template metadata.
     */
    public function show(string $formCode): JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (!in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $template = SchoolFormTemplate::where('form_code', $formCode)->current()->first();

        if (!$template) {
            return response()->json([
                'message' => 'No template uploaded for this form.',
                'template' => null,
            ], 404);
        }

        return response()->json([
            'template' => $this->formatTemplate($template),
        ]);
    }

    /**
     * POST /admin/school-forms/{formCode}/template  (upload new as current)
     * Archives any previously current template for this form_code.
     */
    public function store(Request $request, string $formCode): JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (!in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:10240', // 10 MB
                function ($attribute, $value, $fail) {
                    if (!$value) {
                        return;
                    }
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                        $fail('The file must be an Excel spreadsheet (.xlsx or .xls).');
                    }
                    $mime = $value->getMimeType();
                    if ($mime && !in_array($mime, self::ALLOWED_MIMES, true) && $mime !== 'application/octet-stream') {
                        $fail('The file must be a valid Excel spreadsheet.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = $formCode . '_template_' . Str::uuid() . '.' . $extension;
        $directory = 'school-form-templates/' . $formCode;
        $path = $file->storeAs($directory, $storedName, 'public');

        $template = DB::transaction(function () use ($formCode, $file, $storedName, $path) {
            // Lock existing rows for this form to prevent concurrent "current" templates
            SchoolFormTemplate::where('form_code', $formCode)
                ->lockForUpdate()
                ->get();

            SchoolFormTemplate::where('form_code', $formCode)
                ->where('status', SchoolFormTemplate::STATUS_CURRENT)
                ->update(['status' => SchoolFormTemplate::STATUS_ARCHIVED]);

            return SchoolFormTemplate::create([
                'form_code' => $formCode,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'status' => SchoolFormTemplate::STATUS_CURRENT,
            ]);
        });

        return response()->json([
            'message' => 'Template uploaded successfully.',
            'template' => $this->formatTemplate($template),
        ], 201);
    }

    /**
     * POST /admin/school-forms/{formCode}/templates/{id}/activate
     * Make an archived template the current one; archive the previous current.
     */
    public function activate(string $formCode, int $id): JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (!in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $template = DB::transaction(function () use ($formCode, $id) {
            SchoolFormTemplate::where('form_code', $formCode)
                ->lockForUpdate()
                ->get();

            $target = SchoolFormTemplate::where('form_code', $formCode)
                ->where('id', $id)
                ->first();

            if (!$target) {
                return null;
            }

            if ($target->status === SchoolFormTemplate::STATUS_CURRENT) {
                return $target; // already current
            }

            SchoolFormTemplate::where('form_code', $formCode)
                ->where('status', SchoolFormTemplate::STATUS_CURRENT)
                ->update(['status' => SchoolFormTemplate::STATUS_ARCHIVED]);

            $target->update(['status' => SchoolFormTemplate::STATUS_CURRENT]);

            return $target->fresh();
        });

        if (!$template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        return response()->json([
            'message' => 'Template activated successfully.',
            'template' => $this->formatTemplate($template),
        ]);
    }

    /**
     * GET /admin/school-forms/{formCode}/template/download
     */
    public function download(string $formCode): BinaryFileResponse|JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (!in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $template = SchoolFormTemplate::where('form_code', $formCode)->current()->first();

        $filePath = $template?->getAttribute('file_path');

        if (!$template || !is_string($filePath) || !Storage::disk('public')->exists($filePath)) {
            return response()->json(['message' => 'Template file not found.'], 404);
        }

        return response()->download(
            storage_path('app/public/' . ltrim($filePath, '/')),
            (string) $template->getAttribute('original_name')
        );
    }

    private function formatTemplate(SchoolFormTemplate $template): array
    {
        return [
            'id' => $template->id,
            'form_code' => $template->form_code,
            'original_name' => $template->original_name,
            'file_size' => $template->file_size,
            'file_size_formatted' => $template->formatted_size,
            'mime_type' => $template->mime_type,
            'status' => $template->status,
            'status_label' => $template->status === SchoolFormTemplate::STATUS_CURRENT ? 'Current' : 'Archived',
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'last_updated' => $template->updated_at?->format('F j, Y'),
            'upload_date' => $template->created_at?->format('M. j, Y'),
            'download_url' => url('/api/admin/school-forms/' . $template->form_code . '/template/download'),
        ];
    }

    /**
     * GET /admin/school-forms/{formCode}/template/preview
     */
    public function preview(string $formCode): JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (!in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $template = SchoolFormTemplate::where('form_code', $formCode)->current()->first();

        if (!$template || !Storage::disk('public')->exists($template->file_path)) {
            return response()->json(['message' => 'Template file not found.'], 404);
        }

        $path = Storage::disk('public')->path($template->file_path);

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Could not read the template file. It may be corrupted.'], 422);
        }

        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $mergeMap = $this->buildMergeMap($sheet->getMergeCells());

        $columnWidths = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $width = $sheet->getColumnDimension($colLetter)->getWidth();
            $columnWidths[] = $width > 0 ? round($width * 7) : 100;
        }

        $rows = [];
        for ($rowIdx = 1; $rowIdx <= $highestRow; $rowIdx++) {
            $rowData = [];
            $rowHeight = $sheet->getRowDimension($rowIdx)->getRowHeight();

            for ($colIdx = 1; $colIdx <= $highestColumnIndex; $colIdx++) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);
                $coordinate = "{$colLetter}{$rowIdx}";

                if (isset($mergeMap[$coordinate]) && !$mergeMap[$coordinate]['isAnchor']) {
                    continue;
                }

                $cell = $sheet->getCell($coordinate);
                $style = $sheet->getStyle($coordinate);

                $rowData[] = [
                    'value' => $this->getFormattedValue($cell),
                    'colSpan' => $mergeMap[$coordinate]['colSpan'] ?? 1,
                    'rowSpan' => $mergeMap[$coordinate]['rowSpan'] ?? 1,
                    'bgColor' => $this->hexColor($style->getFill()->getStartColor()->getRGB()),
                    'fontColor' => $this->hexColor($style->getFont()->getColor()->getRGB()),
                    'bold' => $style->getFont()->getBold(),
                    'italic' => $style->getFont()->getItalic(),
                    'fontSize' => $style->getFont()->getSize(),
                    'fontFamily' => $style->getFont()->getName(),
                    'align' => $this->mapAlign($style->getAlignment()->getHorizontal()),
                    'valign' => $this->mapVAlign($style->getAlignment()->getVertical()),
                    'wrapText' => $style->getAlignment()->getWrapText(),
                    'borders' => $this->getBorders($style),
                ];
            }

            $rows[] = [
                'height' => $rowHeight > 0 ? round($rowHeight * 1.33) : 24,
                'cells' => $rowData,
            ];
        }

        return response()->json([
            'sheetName' => $spreadsheet->getSheetNames()[0],
            'columnWidths' => $columnWidths,
            'rows' => $rows,
        ]);
    }

    private function buildMergeMap(array $mergedRanges): array
    {
        $map = [];
        foreach ($mergedRanges as $range) {
            [$start, $end] = explode(':', $range);
            $startCoords = Coordinate::coordinateFromString($start);
            $endCoords = Coordinate::coordinateFromString($end);

            $startColIdx = Coordinate::columnIndexFromString($startCoords[0]);
            $endColIdx = Coordinate::columnIndexFromString($endCoords[0]);
            $startRow = (int) $startCoords[1];
            $endRow = (int) $endCoords[1];

            $map[$start] = [
                'isAnchor' => true,
                'colSpan' => $endColIdx - $startColIdx + 1,
                'rowSpan' => $endRow - $startRow + 1,
            ];

            for ($r = $startRow; $r <= $endRow; $r++) {
                for ($c = $startColIdx; $c <= $endColIdx; $c++) {
                    $coord = Coordinate::stringFromColumnIndex($c) . $r;
                    if ($coord !== $start) {
                        $map[$coord] = ['isAnchor' => false];
                    }
                }
            }
        }
        return $map;
    }

    private function getFormattedValue($cell): string
    {
        if ($cell->getValue() === null) {
            return '';
        }
        $formatCode = $cell->getStyle()->getNumberFormat()->getFormatCode();
        try {
            return (string) NumberFormat::toFormattedString($cell->getCalculatedValue(), $formatCode);
        } catch (\Exception $e) {
            return (string) $cell->getValue();
        }
    }

    private function hexColor(?string $argb): ?string
    {
        if (!$argb || strtoupper($argb) === '00000000') {
            return null;
        }
        $rgb = substr($argb, 2);
        return $rgb === '000000' ? null : "#{$rgb}";
    }

    private function mapAlign(?string $align): string
    {
        return match ($align) {
            Alignment::HORIZONTAL_CENTER => 'center',
            Alignment::HORIZONTAL_RIGHT => 'right',
            default => 'left',
        };
    }

    private function mapVAlign(?string $align): string
    {
        return match ($align) {
            Alignment::VERTICAL_TOP => 'top',
            Alignment::VERTICAL_BOTTOM => 'bottom',
            default => 'middle',
        };
    }

    private function getBorders($style): array
    {
        $borders = $style->getBorders();
        $has = fn($side) => $side->getBorderStyle() !== Border::BORDER_NONE;

        return [
            'top' => $has($borders->getTop()),
            'right' => $has($borders->getRight()),
            'bottom' => $has($borders->getBottom()),
            'left' => $has($borders->getLeft()),
        ];
    }
}