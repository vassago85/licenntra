<?php

namespace App\Livewire\Portal;

use App\Actions\CaptureRetentionConsent;
use App\Models\BusinessClient;
use App\Models\SystemSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.portal')]
class BusinessClientShow extends Component
{
    public BusinessClient $businessClient;

    public bool $consent = false;

    public int $period_months = 12;

    public function mount(BusinessClient $businessClient): void
    {
        $this->authorize('view', $businessClient);
        $this->businessClient = $businessClient;
        $options = SystemSetting::current()->retention_period_options ?? [12];
        $this->period_months = (int) ($businessClient->retention_period_months ?: ($options[0] ?? 12));
    }

    public function saveConsent(): void
    {
        $this->authorize('update', $this->businessClient);

        try {
            $this->businessClient = app(CaptureRetentionConsent::class)->handle(
                $this->businessClient,
                auth()->user(),
                $this->period_months,
                $this->consent,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->consent = false;
        session()->flash('status', 'Retention consent saved.');
    }

    public function render(): View
    {
        $settings = SystemSetting::current();

        return view('livewire.portal.business-client-show', [
            'settings' => $settings,
            'options' => $settings->retention_period_options ?? [],
            'consents' => $this->businessClient->consents()->latest('id')->get(),
        ]);
    }
}
