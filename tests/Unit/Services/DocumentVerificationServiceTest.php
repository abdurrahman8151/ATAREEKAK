<?php

namespace Tests\Unit\Services;

use App\Services\Verification\DocumentVerificationService;
use PHPUnit\Framework\TestCase;

class DocumentVerificationServiceTest extends TestCase
{
    private DocumentVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentVerificationService;
    }

    public function test_required_driver_document_types(): void
    {
        $types = $this->service->getRequiredDriverDocumentTypes();
        $this->assertContains('face_id', $types);
        $this->assertContains('back_id', $types);
        $this->assertContains('license', $types);
        $this->assertContains('mechanic_card', $types);
    }

    public function test_required_passenger_document_types(): void
    {
        $types = $this->service->getRequiredPassengerDocumentTypes();
        $this->assertContains('face_id', $types);
        $this->assertContains('back_id', $types);
        $this->assertCount(2, $types);
    }
}
