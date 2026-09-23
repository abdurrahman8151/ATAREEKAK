<?php

namespace App\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Support\ArabicShaper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * AdminExportService
 *
 * Generates exportable reports (PDF, and later XLSX/CSV).
 *
 * Design:
 *   - Thin orchestrator: pulls data from AdminReportService,
 *     renders a Blade view, and converts it to the target format.
 *   - Follows the same final-class / constructor-injection pattern
 *     used across all other admin services.
 *   - Adding a new format means adding one method; nothing else changes.
 *
 * Routes (all [primary only]):
 *   GET /api/admin/export/pdf?start_date=&end_date=&sections[]=stats
 */
final class AdminExportService
{
    /** Sections the caller can request; default = all. */
    private const AVAILABLE_SECTIONS = [
        'stats',
        'financial',
        'growth',
        'cities',
        'recent',
    ];

    public function __construct(
        private readonly AdminReportService $reportService,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Build a PDF binary string for the dashboard report.
     *
     * @param  string|null  $startDate  Y-m-d
     * @param  string|null  $endDate    Y-m-d
     * @param  string[]     $sections   Subset of AVAILABLE_SECTIONS (empty = all)
     * @return string  Raw PDF bytes ready for streaming
     */
    public function exportDashboardPdf(
        ?string $startDate,
        ?string $endDate,
        array   $sections = [],
    ): string {
        $sections = $this->resolveSections($sections);

        $payload = $this->buildPayload($startDate, $endDate, $sections);

        $html = View::make('exports.dashboard-report', $payload)->render();

        // dompdf/DejaVu Sans don't shape Arabic themselves — connected letters
        // (names, routes, city names) render disconnected without this pass.
        $html = ArabicShaper::shapeHtml($html);

        $pdf = Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans');

        Log::info('Admin PDF export generated', [
            'sections'   => $sections,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]);

        return $pdf->output();
    }

    /**
     * Suggested filename for the Content-Disposition header.
     */
    public function buildFilename(?string $startDate, ?string $endDate): string
    {
        $from = $startDate ? Carbon::parse($startDate)->format('Ymd') : 'all';
        $to   = $endDate   ? Carbon::parse($endDate)->format('Ymd')   : 'all';

        return "syride-report_{$from}_{$to}_" . now()->format('His') . '.pdf';
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Normalise the requested sections list.
     * Empty list → all sections.
     */
    private function resolveSections(array $requested): array
    {
        if (empty($requested)) {
            return self::AVAILABLE_SECTIONS;
        }

        return array_values(
            array_intersect(self::AVAILABLE_SECTIONS, $requested)
        );
    }

    /**
     * Collect only the data blocks that were requested, keeping
     * the report lean when the caller only needs a subset.
     */
    private function buildPayload(
        ?string $startDate,
        ?string $endDate,
        array   $sections,
    ): array {
        $payload = [
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'dateRange'   => [
                'start' => $startDate ?? 'All time',
                'end'   => $endDate   ?? 'All time',
            ],
            'sections'    => $sections,

            // Always present (used in the header/footer)
            'stats'    => null,
            'financial'=> null,
            'growth'   => null,
            'cities'   => null,
            'recent'   => null,
        ];

        if (in_array('stats', $sections, true)) {
            $payload['stats'] = $this->reportService->getStats();
        }

        if (in_array('financial', $sections, true)) {
            $report = $this->reportService->generateReport($startDate, $endDate);
            $payload['financial'] = $report['financial_stats'] ?? null;
        }

        if (in_array('growth', $sections, true)) {
            $payload['growth'] = $this->reportService->getGrowthChart(6);
        }

        if (in_array('cities', $sections, true)) {
            $payload['cities'] = $this->reportService->getCityDistribution();
        }

        if (in_array('recent', $sections, true)) {
            $payload['recent'] = $this->reportService->getRecentActivities(25);
        }

        return $payload;
    }
}
