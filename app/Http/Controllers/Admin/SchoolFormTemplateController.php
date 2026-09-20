<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\SchoolFormTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * GET /admin/school-forms/{formCode}/template
     */
    public function show(string $formCode): JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (!in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $template = SchoolFormTemplate::where('form_code', $formCode)->first();

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
     * POST /admin/school-forms/{formCode}/template  (upload or replace)
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
                        $fail('Only Excel files (.xlsx, .xls) are allowed.');
                    }
                    $mime = $value->getMimeType();
                    // Some browsers send application/octet-stream; extension check is primary.
                    if ($mime && !in_array($mime, self::ALLOWED_MIMES, true) && $mime !== 'application/octet-stream') {
                        $fail('Only Excel files (.xlsx, .xls) are allowed.');
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

        $existing = SchoolFormTemplate::where('form_code', $formCode)->first();

        if ($existing) {
            $existing->deleteFile();
            $existing->update([
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
            $template = $existing->fresh();
            $message = 'Template updated successfully.';
            $status = 200;
        } else {
            $template = SchoolFormTemplate::create([
                'form_code' => $formCode,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
            $message = 'Template uploaded successfully.';
            $status = 201;
        }

        return response()->json([
            'message' => $message,
            'template' => $this->formatTemplate($template),
        ], $status);
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

        $template = SchoolFormTemplate::where('form_code', $formCode)->first();

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
            'updated_at' => $template->updated_at?->toIso8601String(),
            'last_updated' => $template->updated_at?->format('F j, Y'),
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

        $template = SchoolFormTemplate::where('form_code', $formCode)->first();

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