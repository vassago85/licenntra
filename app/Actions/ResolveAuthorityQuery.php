<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Records how operations answered a department query. Only after this does
 * the application count as ready for a resubmission pack.
 */
class ResolveAuthorityQuery
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor, string $resolution): Application
    {
        if (! $actor->is_active || ! $actor->hasAnyRole(['reviewer', 'owner'])) {
            throw ValidationException::withMessages([
                'resolution' => 'Only operations can resolve a department query.',
            ]);
        }

        if ($application->stage !== ApplicationStage::AuthorityQuery) {
            throw ValidationException::withMessages([
                'resolution' => 'This application has no open department query.',
            ]);
        }

        $resolution = trim($resolution);

        if ($resolution === '') {
            throw ValidationException::withMessages([
                'resolution' => 'Describe how the query was resolved.',
            ]);
        }

        $application->forceFill([
            'authority_query_resolved_at' => now(),
            'authority_query_resolution' => $resolution,
        ])->save();

        $this->audit->handle(
            $actor,
            $application,
            'application.authority_query_resolved',
            'Department query resolved: '.$resolution,
            null,
            ['resolution' => $resolution],
        );

        return $application->refresh();
    }
}
