<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Admin\SchoolFormTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only template access for teacher accounts.
 *
 * Teachers may:
 *   - List the active (current) template for any allowed form code
 *   - Download the active template file
 *
 * Teachers may NOT:
 *   - Upload, replace, activate, or delete templates
 *   - List archived templates
 */
class TemplateController extends Controller
{
    private const SCHOOL_FORM_CODES = [
        'SF1', 'SF2', 'SF3', 'SF4', 'SF5',
        'SF6', 'SF7', 'SF8', 'SF9', 'SF10',
    ];

    private const ADMIN_FORM_CODES = [
        'DTR', 'ACR', 'DPDS', 'TO', 'LF',
        'SO', 'SNSED', 'TRANSMITTAL',
    ];

    private const ALLOWED_FORM_CODES = [
        'SF1', 'SF2', 'SF3', 'SF4', 'SF5',
        'SF6', 'SF7', 'SF8', 'SF9', 'SF10',
        'DTR', 'ACR', 'DPDS', 'TO', 'LF',
        'SO', 'SNSED', 'TRANSMITTAL',
    ];

    /**
     * GET /teacher/templates/school-forms
     * Returns current-template info for each School Form (SF1–SF10).
     */
    public function schoolFormsSummary(): JsonResponse
    {
        return response()->json([
            'forms' => $this->buildSummary(self::SCHOOL_FORM_CODES),
        ]);
    }

    /**
     * GET /teacher/templates/admin-forms
     * Returns current-template info for each Administrative Form.
     */
    public function adminFormsSummary(): JsonResponse
    {
        return response()->json([
            'forms' => $this->buildSummary(self::ADMIN_FORM_CODES),
        ]);
    }

    /**
     * GET /teacher/templates/{formCode}
     * Returns the current template metadata for a single form code.
     * Returns 404 if none has been uploaded yet.
     */
    public function show(string $formCode): JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (! in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $template = SchoolFormTemplate::where('form_code', $formCode)->current()->first();

        if (! $template) {
            return response()->json([
                'message' => 'No template available for this form.',
                'template' => null,
            ], 404);
        }

        return response()->json([
            'template' => $this->formatTemplate($template),
        ]);
    }

    /**
     * GET /teacher/templates/{formCode}/download
     * Streams the current template file for download.
     */
    public function download(string $formCode): BinaryFileResponse|JsonResponse
    {
        $formCode = strtoupper($formCode);

        if (! in_array($formCode, self::ALLOWED_FORM_CODES, true)) {
            return response()->json(['message' => 'Invalid form code.'], 422);
        }

        $template = SchoolFormTemplate::where('form_code', $formCode)->current()->first();

        $filePath = $template?->getAttribute('file_path');

        if (! $template || ! is_string($filePath) || ! Storage::disk('public')->exists($filePath)) {
            return response()->json(['message' => 'Template file not found.'], 404);
        }

        return response()->download(
            storage_path('app/public/' . ltrim($filePath, '/')),
            (string) $template->getAttribute('original_name')
        );
    }

    // ─── private ────────────────────────────────────────────────────────────

    private function buildSummary(array $formCodes): array
    {
        $templates = SchoolFormTemplate::query()
            ->current()
            ->whereIn('form_code', $formCodes)
            ->get()
            ->keyBy('form_code');

        return array_map(function (string $code) use ($templates) {
            $t = $templates->get($code);
            return [
                'form_code'    => $code,
                'has_template' => $t !== null,
                'template'     => $t ? $this->formatTemplate($t) : null,
            ];
        }, $formCodes);
    }

    private function formatTemplate(SchoolFormTemplate $t): array
    {
        return [
            'id'                  => $t->id,
            'form_code'           => $t->form_code,
            'original_name'       => $t->original_name,
            'file_size_formatted' => $t->formatted_size,
            'last_updated'        => $t->updated_at?->format('F j, Y'),
        ];
    }
}
