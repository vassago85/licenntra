<?php

namespace App\Actions;

use App\Enums\DatafixStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CompleteDatafix
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor, ?string $authorityReference = null): Application
    {
        $record = $application->datafix;

        if ($record === null || $record->confirmed_at === null) {
            throw ValidationException::withMessages([
                'datafix' => 'Confirm tare, body type, and GVM before completing the datafix.',
            ]);
        }

        $record->status = DatafixStatus::Completed;
        $record->completed_at = now();
        $record->authority_reference = $authorityReference;
        $record->save();

        $application->datafix_status = DatafixStatus::Completed;
        $application->save();

        $this->audit->handle(
            $actor,
            $application,
            'datafix.completed',
            'Datafix completed.',
            null,
            ['authority_reference' => $authorityReference],
        );

        return $application->refresh();
    }
}
