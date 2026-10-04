<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\Note;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AddApplicationNote
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor, string $body, string $visibility): Note
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'note' => 'Write a note before saving it.',
            ]);
        }

        if (! in_array($visibility, ['client', 'internal'], true)) {
            throw ValidationException::withMessages([
                'visibility' => 'Choose client or internal.',
            ]);
        }

        if ($visibility === 'internal' && $actor->isClient()) {
            throw ValidationException::withMessages([
                'visibility' => 'Client users can add client notes only.',
            ]);
        }

        $note = $application->notes()->create([
            'body' => $body,
            'visibility' => $visibility,
            'user_id' => $actor->id,
        ]);

        $this->audit->handle($actor, $application, 'application.note_added', 'Note added.', null, [
            'visibility' => $visibility,
        ]);

        return $note;
    }
}
