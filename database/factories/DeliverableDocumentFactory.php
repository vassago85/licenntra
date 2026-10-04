<?php

namespace Database\Factories;

use App\Enums\DeliverableKind;
use App\Models\Application;
use App\Models\DeliverableDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliverableDocument>
 */
class DeliverableDocumentFactory extends Factory
{
    protected $model = DeliverableDocument::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'kind' => DeliverableKind::NatisCertificate,
            'label' => null,
            'storage_path' => 'deliverables/0/'.$this->faker->uuid().'.pdf',
            'original_filename' => 'natis-'.$this->faker->randomNumber(5).'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(50_000, 500_000),
            'sha256' => hash('sha256', $this->faker->sentence()),
            'uploaded_by_id' => User::factory(),
            'uploaded_at' => now(),
            'handover_notes' => null,
        ];
    }
}
