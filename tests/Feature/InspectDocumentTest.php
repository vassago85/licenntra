<?php

namespace Tests\Feature;

use App\Enums\ApplicationStage;
use App\Enums\DocumentStatus;
use App\Enums\ServiceType;
use App\Jobs\InspectDocument;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InspectDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    public function test_pdf_with_text_is_marked_clean_and_captures_excerpt(): void
    {
        $version = $this->seedPdfVersion(
            'srf.pdf',
            $this->makePdf('Vehicle registration SRF, make Isuzu NPR, VIN AHTFR22P007654321, body builder cert attached, title holder ABSA Bank.'),
        );

        InspectDocument::dispatchSync($version->id);
        $version->refresh();

        $this->assertSame(1, $version->page_count);
        $this->assertTrue($version->has_text_layer);
        $this->assertSame('clean', $version->inspection_status);
        $this->assertNull($version->inspection_notes);
        $this->assertStringContainsString('Isuzu NPR', (string) $version->text_excerpt);
    }

    public function test_image_only_pdf_is_flagged_as_empty(): void
    {
        $version = $this->seedPdfVersion('scan.pdf', $this->makePdf(''));

        InspectDocument::dispatchSync($version->id);
        $version->refresh();

        $this->assertSame(1, $version->page_count);
        $this->assertFalse($version->has_text_layer);
        $this->assertSame('empty', $version->inspection_status);
        $this->assertStringContainsString('scanned image', (string) $version->inspection_notes);
        $this->assertNull($version->text_excerpt);
    }

    public function test_corrupt_pdf_is_marked_unreadable(): void
    {
        $version = $this->seedPdfVersion('bad.pdf', "%PDF-1.4\nnot a real pdf\n%%EOF\n");

        InspectDocument::dispatchSync($version->id);
        $version->refresh();

        $this->assertSame('unreadable', $version->inspection_status);
        $this->assertNotNull($version->inspection_notes);
    }

    public function test_non_pdf_mime_is_skipped(): void
    {
        $version = $this->seedPdfVersion('photo.jpg', 'binary-image-bytes', mime: 'image/jpeg');

        InspectDocument::dispatchSync($version->id);
        $version->refresh();

        $this->assertSame('skipped', $version->inspection_status);
        $this->assertNull($version->page_count);
        $this->assertNull($version->has_text_layer);
    }

    private function seedPdfVersion(string $filename, string $bytes, string $mime = 'application/pdf'): DocumentVersion
    {
        $account = ClientAccount::query()->create([
            'name' => 'Fixture Dealer',
            'type' => 'dealer',
            'status' => 'active',
        ]);
        $application = Application::query()->create([
            'client_account_id' => $account->id,
            'reference' => 'FIX-'.uniqid(),
            'stage' => ApplicationStage::Draft,
            'service_type' => ServiceType::RegisterAndLicense,
            'request_type' => 'new_registration',
        ]);
        $type = DocumentType::query()->create([
            'code' => 'fixture_'.uniqid(),
            'name' => 'Fixture type',
        ]);
        $document = ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'required' => true,
            'status' => DocumentStatus::Scanning,
        ]);
        $path = 'applications/'.$application->id.'/'.$filename;
        Storage::disk('documents')->put($path, $bytes);

        return DocumentVersion::query()->create([
            'application_document_id' => $document->id,
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime' => $mime,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'scan_status' => 'clean',
        ]);
    }

    /**
     * Build a tiny but valid single-page PDF containing the provided text (or
     * an empty content stream, which mimics an image-only scan).
     */
    private function makePdf(string $text): string
    {
        $content = $text === ''
            ? "BT ET\n"
            : sprintf("BT\n/F1 12 Tf\n72 720 Td\n(%s) Tj\nET\n", $this->escapePdfString($text));

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                .'/Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($content), $content),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $output = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($output);
            $output .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xrefOffset = strlen($output);
        $output .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_keys($objects) as $id) {
            $output .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $output .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n"
            ."startxref\n".$xrefOffset."\n%%EOF\n";

        return $output;
    }

    private function escapePdfString(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
