<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'invoice_number' => 'INV-'.$this->faker->unique()->numerify('########'),
            'storage_path' => 'invoices/0/'.$this->faker->uuid().'.pdf',
            'original_filename' => 'invoice-'.$this->faker->randomNumber(5).'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(50_000, 400_000),
            'sha256' => hash('sha256', $this->faker->sentence()),
            'uploaded_by_id' => User::factory(),
            'uploaded_at' => now(),
            'paid_at' => null,
            'paid_by_user_id' => null,
            'paid_reference' => null,
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (): array => [
            'paid_at' => now(),
            'paid_by_user_id' => User::factory(),
            'paid_reference' => 'EFT-'.$this->faker->numerify('######'),
        ]);
    }
}
