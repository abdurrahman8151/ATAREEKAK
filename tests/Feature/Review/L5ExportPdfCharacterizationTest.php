<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * L5 (modular-monolith slice 4): characterization test for GET /api/admin/export/pdf.
 *
 * No test called this endpoint before. It pins the current behaviour so that any later change to
 * `AdminDashboardController::exportPdf` or `AdminExportService` is caught:
 *   - a valid request returns a PDF with the expected headers
 *   - an invalid date range is refused with 422 and no PDF
 *   - a user without an admin token is refused
 *
 * This file changes no application code.
 */
class L5ExportPdfCharacterizationTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

    public function test_export_returns_a_pdf_with_inline_disposition_and_no_store_cache(): void
    {
        $response = $this->withToken($this->adminToken(null, 'system_admin'))
            ->get('/api/admin/export/pdf?start_date=2026-01-01&end_date=2026-01-31');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline; filename="', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_export_refuses_an_end_date_before_the_start_date(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->get('/api/admin/export/pdf?start_date=2026-02-10&end_date=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_export_refuses_an_unknown_section(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->get('/api/admin/export/pdf?sections[]=not_a_section')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sections.0']);
    }

    public function test_export_refuses_a_request_without_an_admin_token(): void
    {
        $this->get('/api/admin/export/pdf')
            ->assertStatus(401);
    }
}
