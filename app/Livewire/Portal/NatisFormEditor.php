<?php

namespace App\Livewire\Portal;

use App\Actions\SaveNatisForm;
use App\Enums\NatisFormType;
use App\Models\Application;
use App\Services\NatisFormBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Operations check the pre-filled ALV / RLV here, correct anything the
 * application got wrong or left blank, and save it before printing.
 */
#[Layout('layouts.portal')]
class NatisFormEditor extends Component
{
    public Application $application;

    /** @var array<string, array<string, string|bool>> */
    public array $values = [];

    public ?string $statusMessage = null;

    public function mount(Application $application): void
    {
        $this->authorize('review', $application);
        abort_if($this->builder()->formTypeFor($application) === null, 404);

        $this->application = $application;
        $this->values = $this->builder()->values($application);
    }

    public function save(): void
    {
        if ($this->persist()) {
            $this->statusMessage = $this->formType()->code().' checked and saved. The pack prints these values.';
        }
    }

    public function saveAndPrint(): void
    {
        if ($this->persist()) {
            $this->redirectRoute('review.natis-form.print', $this->application);
        }
    }

    /**
     * Replaces the on-screen values with a fresh fill from the application.
     * Nothing is stored until staff save.
     */
    public function refill(): void
    {
        $this->authorize('review', $this->application);
        $this->resetErrorBag();
        $this->values = $this->builder()->derive($this->application->fresh());
        $this->statusMessage = 'Refilled from the application. Check the fields, then save to keep them.';
    }

    public function render(): View
    {
        $type = $this->formType();
        $builder = $this->builder();
        $this->application->loadMissing(['clientAccount', 'natisFormCheckedBy']);

        return view('livewire.portal.natis-form-editor', [
            'formType' => $type,
            'sections' => $builder->sections($type),
            'missing' => $builder->missingEssentials($type, $this->values),
            'isChecked' => $builder->isChecked($this->application),
            'isOutOfDate' => $builder->isOutOfDate($this->application),
            'textType' => NatisFormBuilder::TYPE_TEXT,
            'choiceType' => NatisFormBuilder::TYPE_CHOICE,
            'flagType' => NatisFormBuilder::TYPE_FLAG,
            'dateType' => NatisFormBuilder::TYPE_DATE,
        ]);
    }

    private function persist(): bool
    {
        $this->authorize('review', $this->application);
        $type = $this->formType();
        $this->validate($this->builder()->rules($type), [], $this->builder()->attributeLabels($type));

        try {
            app(SaveNatisForm::class)->handle($this->application, auth()->user(), $this->values);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return false;
        }

        $this->application->refresh();
        $this->values = $this->builder()->values($this->application);

        return true;
    }

    private function formType(): NatisFormType
    {
        $type = $this->builder()->formTypeFor($this->application);
        abort_if($type === null, 404);

        return $type;
    }

    private function builder(): NatisFormBuilder
    {
        return app(NatisFormBuilder::class);
    }
}
