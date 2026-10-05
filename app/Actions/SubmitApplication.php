<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\DocumentStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitApplication
{
    public function handle(Application $application, User $actor): Application
    {
        $this->guardRequiredDocuments($application);

        return DB::transaction(function () use ($application, $actor): Application {
            $transition = app(TransitionApplication::class);

            if ($application->stage === ApplicationStage::ChangesRequested) {
                $application = $transition->handle($application, ApplicationStage::DocumentReview, $actor);
            } else {
                $application = $transition->handle($application, ApplicationStage::Submitted, $actor);
                $application = $transition->handle($application, ApplicationStage::DocumentReview, null, isSystem: true);
            }

            return app(AutoAssignPrimaryReviewer::class)->handle($application, $actor);
        });
    }

    /**
     * A dealer cannot submit (or re-submit on changes-requested) while any
     * required document is still Missing or was Rejected without a fresh
     * upload. This is defence in depth; the UI should present the same
     * list up-front, but we enforce here so a direct POST / Livewire call
     * can't bypass the Blade.
     *
     * @throws ValidationException
     */
    private function guardRequiredDocuments(Application $application): void
    {
        $blocking = $application->documents()
            ->with('documentType')
            ->where('required', true)
            ->whereIn('status', [DocumentStatus::Missing, DocumentStatus::Rejected])
            ->get();

        if ($blocking->isEmpty()) {
            return;
        }

        $messages = $blocking->mapWithKeys(function ($document) {
            $label = $document->label();
            $reason = $document->status === DocumentStatus::Rejected
                ? ' was rejected and needs a new file'
                : ' is missing';

            return ["documents.{$document->id}" => $label.$reason.'.'];
        })->all();

        throw ValidationException::withMessages(array_merge([
            'submit' => 'Fix the '.$blocking->count().' outstanding document'
                .($blocking->count() === 1 ? '' : 's').' before submitting.',
        ], $messages));
    }
}
