<?php

namespace Database\Factories;

use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = $this->faker->uuid.'.pdf';

        return [
            'application_document_id' => null,
            'business_client_document_id' => null,
            'storage_path' => 'fleet-vehicles/'.$filename,
            'original_filename' => 'licence-'.$this->faker->bothify('???###?').'.pdf',
            'mime' => 'application/pdf',
            'size' => $this->faker->numberBetween(50000, 2000000),
            'sha256' => hash('sha256', $filename),
            'scan_status' => 'skipped',
            'inspection_status' => 'skipped',
            'uploaded_by' => null,
        ];
    }
}
