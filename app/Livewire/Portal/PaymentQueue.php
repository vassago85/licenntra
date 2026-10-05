<?php

namespace App\Livewire\Portal;

use App\Actions\VerifyPayment;
use App\Enums\ApplicationStage;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.portal')]
class PaymentQueue extends Component
{
    public string $method = 'eft';

    public string $reference = '';

    public string $amount = '';

    public string $override_reason = '';

    public ?int $selected = null;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->is_active || ! $user->hasAnyRole(['finance', 'customer_admin', 'super_admin'])) {
            abort(403);
        }

        $requested = request()->integer('application');

        if ($requested > 0) {
            $application = Application::query()->find($requested);

            if ($application !== null && $user->can('verifyPayment', $application)) {
                $this->selectApplication($application->id);
            }
        }
    }

    public function selectApplication(int $applicationId): void
    {
        $application = Application::query()->findOrFail($applicationId);
        $this->authorize('verifyPayment', $application);
        $this->selected = $application->id;
        $this->amount = number_format(((int) ($application->fee_snapshot['total_cents'] ?? 0)) / 100, 2, '.', '');
        $this->reference = '';
        $this->override_reason = '';
    }

    public function verify(): void
    {
        $application = Application::query()->findOrFail($this->selected);
        $this->authorize('verifyPayment', $application);

        try {
            app(VerifyPayment::class)->handle(
                $application,
                auth()->user(),
                $this->toCents($this->amount),
                $this->method,
                $this->reference,
                $this->override_reason !== '' ? $this->override_reason : null,
            );
        } catch (InvalidTransition $exception) {
            $this->addError('payment', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->selected = null;
        session()->flash('status', 'Payment verified.');
    }

    public function render(): View
    {
        $rows = Application::query()
            ->with(['clientAccount', 'vehicle'])
            ->where('stage', ApplicationStage::PaymentPending)
            ->latest('updated_at')
            ->get();

        return view('livewire.portal.payment-queue', [
            'rows' => $rows,
            'money' => Money::class,
            'stats' => $this->stats($rows),
        ]);
    }

    /**
     * @param  Collection<int, Application>  $rows
     * @return array<string, int>
     */
    private function stats(Collection $rows): array
    {
        $totalCents = $rows->sum(fn (Application $a) => (int) ($a->fee_snapshot['total_cents'] ?? 0));

        $oldestDays = $rows
            ->map(fn (Application $a) => $a->updated_at?->diffInDays(now()) ?? 0)
            ->max() ?? 0;

        $accounts = $rows
            ->pluck('client_account_id')
            ->filter()
            ->unique()
            ->count();

        return [
            'count' => $rows->count(),
            'total_cents' => (int) $totalCents,
            'oldest_days' => (int) $oldestDays,
            'accounts' => $accounts,
        ];
    }

    private function toCents(string $rands): int
    {
        $normalised = str_replace([' ', ','], ['', '.'], trim($rands));

        return (int) round(((float) $normalised) * 100);
    }
}
