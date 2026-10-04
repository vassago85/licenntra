<?php

namespace App\Actions;

use App\Enums\DatafixStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class QueryDatafix
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor, string $note): Application
    {
        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'query_note' => 'A datafix query needs a note.',
            ]);
        }

        $record = $application->datafix()->firstOrCreate(
            ['application_id' => $application->id],
            ['status' => $application->datafix_status],
        );

        $record->status = DatafixStatus::Queried;
        $record->query_note = $note;
        $record->save();

        $application->datafix_status = DatafixStatus::Queried;
        $application->save();

        $this->audit->handle($actor, $application, 'datafix.queried', 'Datafix queried.', null, ['query_note' => $note]);

        return $application->refresh();
    }
}
