<?php

namespace App\Livewire\Portal;

use App\Actions\CalculateFees;
use App\Actions\CreateQuote;
use App\Actions\SendQuote;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.portal')]
class QuoteBuilder extends Component
{
    public Application $application;

    public string $expires_at = '';

    public string $client_notes = '';

    public string $internal_notes = '';

    /** @var list<array{description: string, client_rands: string, internal_rands: string}> */
    public array $lines = [
        ['description' => '', 'client_rands' => '', 'internal_rands' => ''],
    ];

    public function mount(Application $application): void
    {
        $this->authorize('review', $application);
        $this->application = $application;
        $this->expires_at = now()->addDays(14)->format('Y-m-d');
    }

    public function addLine(): void
    {
        $this->lines[] = ['description' => '', 'client_rands' => '', 'internal_rands' => ''];
    }

    public function send(): void
    {
        $this->authorize('review', $this->application);

        try {
            $quote = app(CreateQuote::class)->handle(
                $this->application,
                auth()->user(),
                $this->pricedLines(),
                $this->expires_at !== '' ? Carbon::parse($this->expires_at)->endOfDay() : null,
                $this->client_notes !== '' ? $this->client_notes : null,
                $this->internal_notes !== '' ? $this->internal_notes : null,
            );
            $this->application = app(SendQuote::class)->handle($this->application, auth()->user(), $quote);
        } catch (InvalidTransition|ValidationException $exception) {
            if ($exception instanceof ValidationException) {
                $this->setErrorBag($exception->validator->getMessageBag());

                return;
            }

            $this->addError('quote', $exception->getMessage());

            return;
        }

        $this->redirectRoute('review.show', $this->application);
    }

    public function render(): View
    {
        return view('livewire.portal.quote-builder', [
            'money' => Money::class,
            'snapshot' => $this->application->fee_snapshot ?? app(CalculateFees::class)->snapshot($this->application),
        ]);
    }

    /**
     * @return list<array{description: string, client_price_cents: int, internal_cost_cents: int}>
     */
    private function pricedLines(): array
    {
        return array_map(function (array $line): array {
            return [
                'description' => $line['description'],
                'client_price_cents' => $this->toCents($line['client_rands']),
                'internal_cost_cents' => $this->toCents($line['internal_rands']),
            ];
        }, $this->lines);
    }

    private function toCents(string $rands): int
    {
        $normalised = str_replace([' ', ','], ['', '.'], trim($rands));

        if ($normalised === '') {
            return 0;
        }

        return (int) round(((float) $normalised) * 100);
    }
}
