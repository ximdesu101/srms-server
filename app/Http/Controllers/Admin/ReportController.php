<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\DocumentRequest;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports)
    {
    }

    public function analytics(Request $request): JsonResponse
    {
        try {
            [$from, $to] = $this->reports->resolveDateRange(
                $request->query('from'),
                $request->query('to'),
                $request->query('preset', 'all')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $groupBy = $request->query('group_by', 'month');
        if (!in_array($groupBy, ['day', 'week', 'month'], true)) {
            $groupBy = 'month';
        }

        return response()->json([
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'preset' => $request->query('preset', 'all'),
            ],
            'metrics' => $this->reports->getSummaryMetrics($from, $to),
            'charts' => [
                'status_distribution' => $this->reports->getRequestStatusDistribution($from, $to),
                'request_trends' => $this->reports->getRequestTrends($from, $to, $groupBy),
                'most_requested' => $this->reports->getMostRequestedDocuments($from, $to),
                'account_overview' => $this->reports->getAccountOverview(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function table(Request $request): JsonResponse
    {
        $type = $request->query('type', 'document_requests');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $filters = [
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'document_code' => $request->query('document_code'),
            'category' => $request->query('category'),
        ];

        $data = match ($type) {
            'teachers' => $this->reports->getTeacherAccountsReport($filters, $perPage),
            'staff' => $this->reports->getStaffAccountsReport($filters, $perPage),
            'school_records' => $this->reports->getSchoolRecordsReport($filters, $perPage),
            'document_requests' => $this->reports->getDocumentRequestsReport($filters, $perPage),
            'pending_unavailable' => $this->reports->getPendingUnavailableReport($filters, $perPage),
            'fulfillment' => $this->reports->getFulfillmentReport($filters, $perPage),
            'activity' => $this->reports->getActivityReport($filters, $perPage),
            default => $this->reports->getDocumentRequestsReport($filters, $perPage),
        };

        $data->getCollection()->transform(function ($item) use ($type) {
            return $this->formatRow($item, $type);
        });

        return response()->json($data);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'report_types' => [
                ['id' => 'summary', 'label' => 'Overall System Summary'],
                ['id' => 'teachers', 'label' => 'Teacher Accounts'],
                ['id' => 'staff', 'label' => 'Staff Accounts'],
                ['id' => 'school_records', 'label' => 'School Records'],
                ['id' => 'document_requests', 'label' => 'Document Requests'],
                ['id' => 'pending_unavailable', 'label' => 'Pending & Unavailable Requests'],
                ['id' => 'fulfillment', 'label' => 'Request Fulfillment'],
                ['id' => 'activity', 'label' => 'System Activity'],
            ],
            'document_statuses' => array_values(array_filter(
                DocumentRequest::STATUSES,
                fn ($s) => $s !== DocumentRequest::STATUS_DRAFT
            )),
            'document_types' => collect(DocumentRequest::DOCUMENT_MAP)
                ->map(fn ($name, $code) => ['code' => $code, 'name' => $name])
                ->values(),
            'presets' => [
                ['id' => 'all', 'label' => 'All Time'],
                ['id' => 'today', 'label' => 'Today'],
                ['id' => 'this_week', 'label' => 'This Week'],
                ['id' => 'this_month', 'label' => 'This Month'],
                ['id' => 'this_year', 'label' => 'This Year'],
                ['id' => 'last_30', 'label' => 'Last 30 Days'],
                ['id' => 'last_90', 'label' => 'Last 90 Days'],
                ['id' => 'custom', 'label' => 'Custom Range'],
            ],
            'export_formats' => [
                ['id' => 'pdf', 'label' => 'PDF', 'extension' => '.pdf', 'description' => 'Print-ready document'],
                ['id' => 'docx', 'label' => 'Word', 'extension' => '.docx', 'description' => 'Editable Microsoft Word document'],
                ['id' => 'xlsx', 'label' => 'Excel', 'extension' => '.xlsx', 'description' => 'Spreadsheet with filters'],
            ],
        ]);
    }

    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'report_type' => 'required|string|in:summary,teachers,staff,school_records,document_requests,pending_unavailable,fulfillment,activity',
            'format' => 'required|string|in:pdf,docx,xlsx',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'preset' => 'nullable|string',
            'status' => 'nullable|string',
            'search' => 'nullable|string|max:255',
            'document_code' => 'nullable|string',
            'include_summary' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid export parameters', 'errors' => $validator->errors()], 422);
        }

        $reportType = $request->input('report_type');
        $format = $request->input('format');
        $includeSummary = $request->boolean('include_summary', true);

        try {
            [$from, $to] = $this->reports->resolveDateRange(
                $request->input('from'),
                $request->input('to'),
                $request->input('preset', 'all')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $filters = [
            'status' => $request->input('status'),
            'search' => $request->input('search'),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'document_code' => $request->input('document_code'),
        ];

        $metrics = $includeSummary ? $this->reports->getSummaryMetrics($from, $to) : null;
        $rows = $this->reports->getExportData($reportType, $filters);
        $title = $this->reportTitle($reportType);
        $periodLabel = $this->periodLabel($from, $to, $request->input('preset', 'all'));
        $generatedAt = now()->format('F j, Y g:i A');
        $filename = 'Tagnao_' . str_replace(' ', '_', $title) . '_' . now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->exportPdf($title, $periodLabel, $generatedAt, $metrics, $rows, $reportType, $filename),
            'docx' => $this->exportDocx($title, $periodLabel, $generatedAt, $metrics, $rows, $reportType, $filename),
            'xlsx' => $this->exportXlsx($title, $periodLabel, $generatedAt, $metrics, $rows, $reportType, $filename),
            default => response()->json(['message' => 'Unsupported format'], 400),
        };
    }

    private function reportTitle(string $type): string
    {
        return match ($type) {
            'summary' => 'Overall System Summary',
            'teachers' => 'Teacher Accounts Report',
            'staff' => 'Staff Accounts Report',
            'school_records' => 'School Records Report',
            'document_requests' => 'Document Requests Report',
            'pending_unavailable' => 'Pending & Unavailable Requests Report',
            'fulfillment' => 'Request Fulfillment Report',
            'activity' => 'System Activity Report',
            default => 'System Report',
        };
    }

    private function periodLabel(?Carbon $from, ?Carbon $to, string $preset): string
    {
        if ($preset === 'all' || (!$from && !$to)) {
            return 'All Available Records';
        }
        if ($from && $to) {
            return $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
        }
        if ($from) {
            return 'From ' . $from->format('M j, Y');
        }
        return 'Until ' . $to->format('M j, Y');
    }

    private function formatRow($item, string $type): array
    {
        return match ($type) {
            'teachers', 'staff' => [
                'id' => $item->id,
                'employee_id' => $item->teacher_id ?? $item->staff_id ?? null,
                'full_name' => $item->full_name,
                'username' => $item->username,
                'position' => $item->position ?? null,
                'status' => $item->status,
                'activated_at' => $item->activated_at?->toDateTimeString(),
                'created_at' => $item->created_at?->toDateTimeString(),
            ],
            'school_records' => [
                'id' => $item->id,
                'form_code' => $item->form_code,
                'original_name' => $item->original_name,
                'status' => $item->status,
                'file_size' => $item->formatted_size ?? null,
                'created_at' => $item->created_at?->toDateTimeString(),
            ],
            'document_requests', 'pending_unavailable', 'fulfillment' => [
                'id' => $item->id,
                'request_code' => $item->request_code,
                'document_name' => $item->document_name,
                'document_code' => $item->document_code,
                'teacher_name' => $item->teacher?->full_name,
                'teacher_id' => $item->teacher?->teacher_id,
                'status' => $item->status,
                'purpose' => $item->purpose,
                'created_at' => $item->created_at?->toDateTimeString(),
                'acknowledged_at' => $item->acknowledged_at?->toDateTimeString(),
                'fulfilled_at' => $item->fulfilled_at?->toDateTimeString(),
                'duration_hours' => ($item->fulfilled_at && $item->created_at)
                    ? round($item->created_at->diffInMinutes($item->fulfilled_at) / 60, 1)
                    : null,
            ],
            'activity' => [
                'id' => $item->id,
                'actor_name' => $item->actor_name,
                'actor_role' => $item->actor_role,
                'action' => $item->action,
                'category' => $item->category,
                'description' => $item->description,
                'status' => $item->status,
                'created_at' => $item->created_at?->toDateTimeString(),
            ],
            default => (array) $item,
        };
    }

    private function exportPdf(string $title, string $period, string $generatedAt, ?array $metrics, array $rows, string $type, string $filename)
    {
        $html = view('reports.export-pdf', [
            'title' => $title,
            'period' => $period,
            'generatedAt' => $generatedAt,
            'metrics' => $metrics,
            'rows' => array_map(fn ($r) => is_object($r) ? $this->formatRow($r, $type) : $r, $rows),
            'type' => $type,
            'systemName' => 'Tagnao Offline School Record Management System',
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');
        return $pdf->download($filename . '.pdf');
    }

    private function exportDocx(string $title, string $period, string $generatedAt, ?array $metrics, array $rows, string $type, string $filename)
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'marginLeft' => 600,
            'marginRight' => 600,
            'marginTop' => 600,
            'marginBottom' => 600,
        ]);

        $section->addText('Tagnao Offline School Record Management System', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $section->addText($title, ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $section->addText('Reporting Period: ' . $period, ['size' => 9], ['alignment' => Jc::CENTER]);
        $section->addText('Generated: ' . $generatedAt, ['size' => 9], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        if ($metrics) {
            $section->addText('Summary Statistics', ['bold' => true, 'size' => 11]);
            $section->addText(
                sprintf(
                    'Teachers: %d (Active: %d) | Staff: %d | School Records: %d | Document Requests: %d (Pending: %d, Fulfilled: %d, Unavailable: %d)',
                    $metrics['teachers']['total'] ?? 0,
                    $metrics['teachers']['active'] ?? 0,
                    $metrics['staff']['total'] ?? 0,
                    $metrics['school_records']['total'] ?? 0,
                    $metrics['document_requests']['total'] ?? 0,
                    $metrics['document_requests']['pending'] ?? 0,
                    $metrics['document_requests']['fulfilled'] ?? 0,
                    $metrics['document_requests']['unavailable'] ?? 0
                ),
                ['size' => 9]
            );
            $section->addTextBreak(1);
        }

        $formatted = array_map(fn ($r) => is_object($r) ? $this->formatRow($r, $type) : $r, $rows);

        if (empty($formatted)) {
            $section->addText('No records match the selected criteria.', ['italic' => true]);
        } else {
            $headers = array_keys($formatted[0]);
            $table = $section->addTable([
                'borderSize' => 6,
                'borderColor' => '999999',
                'cellMargin' => 50,
            ]);
            $table->addRow();
            foreach ($headers as $h) {
                $table->addCell(1500)->addText(ucwords(str_replace('_', ' ', $h)), ['bold' => true, 'size' => 8]);
            }
            foreach ($formatted as $row) {
                $table->addRow();
                foreach ($headers as $h) {
                    $val = $row[$h] ?? '';
                    if (is_array($val)) {
                        $val = json_encode($val);
                    }
                    $table->addCell(1500)->addText((string) $val, ['size' => 8]);
                }
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'report_') . '.docx';
        $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmp);

        return response()->download($tmp, $filename . '.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    private function exportXlsx(string $title, string $period, string $generatedAt, ?array $metrics, array $rows, string $type, string $filename)
    {
        $spreadsheet = new Spreadsheet();

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Summary');
        $summary->setCellValue('A1', 'Tagnao Offline School Record Management System');
        $summary->setCellValue('A2', $title);
        $summary->setCellValue('A3', 'Reporting Period: ' . $period);
        $summary->setCellValue('A4', 'Generated: ' . $generatedAt);
        $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $summary->getStyle('A2')->getFont()->setBold(true)->setSize(12);

        $row = 6;
        if ($metrics) {
            $summary->setCellValue('A' . $row, 'Metric');
            $summary->setCellValue('B' . $row, 'Value');
            $summary->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
            $row++;
            $flat = [
                'Total Teachers' => $metrics['teachers']['total'] ?? 0,
                'Active Teachers' => $metrics['teachers']['active'] ?? 0,
                'Total Staff' => $metrics['staff']['total'] ?? 0,
                'Active Staff' => $metrics['staff']['active'] ?? 0,
                'School Records' => $metrics['school_records']['total'] ?? 0,
                'Document Requests (Total)' => $metrics['document_requests']['total'] ?? 0,
                'Pending Requests' => $metrics['document_requests']['pending'] ?? 0,
                'Fulfilled Requests' => $metrics['document_requests']['fulfilled'] ?? 0,
                'Unavailable Requests' => $metrics['document_requests']['unavailable'] ?? 0,
                'Submission Requests' => $metrics['submission_requests']['total'] ?? 0,
            ];
            foreach ($flat as $label => $val) {
                $summary->setCellValue('A' . $row, $label);
                $summary->setCellValue('B' . $row, $val);
                $row++;
            }
        }
        $summary->getColumnDimension('A')->setWidth(30);
        $summary->getColumnDimension('B')->setWidth(15);

        $formatted = array_map(fn ($r) => is_object($r) ? $this->formatRow($r, $type) : $r, $rows);
        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Detailed Records');

        if (empty($formatted)) {
            $detail->setCellValue('A1', 'No records match the selected criteria.');
        } else {
            $headers = array_keys($formatted[0]);
            $col = 1;
            foreach ($headers as $h) {
                $detail->setCellValueByColumnAndRow($col, 1, ucwords(str_replace('_', ' ', $h)));
                $col++;
            }
            $lastCol = $detail->getCellByColumnAndRow(count($headers), 1)->getCoordinate();
            $detail->getStyle('A1:' . $lastCol)->getFont()->setBold(true);
            $detail->getStyle('A1:' . $lastCol)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');

            $r = 2;
            foreach ($formatted as $rowData) {
                $c = 1;
                foreach ($headers as $h) {
                    $val = $rowData[$h] ?? '';
                    if (is_array($val)) {
                        $val = json_encode($val);
                    }
                    $detail->setCellValueByColumnAndRow($c, $r, $val);
                    $c++;
                }
                $r++;
            }

            foreach (range(1, count($headers)) as $c) {
                $detail->getColumnDimensionByColumn($c)->setAutoSize(true);
            }
            $detail->setAutoFilter($detail->calculateWorksheetDimension());
            $detail->freezePane('A2');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'report_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmp);

        return response()->download($tmp, $filename . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}