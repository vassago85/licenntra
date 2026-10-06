<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\User;
use App\Services\NatisFormBuilder;
use Illuminate\Validation\ValidationException;

/**
 * Records the ALV / RLV exactly as operations checked it. The printout
 * uses these values from now on, together with a fingerprint of the
 * application data they were checked against.
 */
class SaveNatisForm
{
    public function __construct(
        private NatisFormBuilder $builder,
        private RecordAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function handle(Application $application, User $actor, array $values): Application
    {
        if (! $actor->can('review', $application)) {
            throw ValidationException::withMessages([
                'natis_form' => 'Only operations can check the department form.',
            ]);
        }

        $type = $this->builder->formTypeFor($application);

        if ($type === null) {
            throw ValidationException::withMessages([
                'natis_form' => 'This request type is not lodged on an ALV or RLV.',
            ]);
        }

        $before = $this->builder->values($application);
        $clean = $this->builder->normalise($type, $values);
        $changedFields = $this->changedFields($before, $clean);

        $application->forceFill([
            'natis_form' => [
                'type' => $type->value,
                'values' => $clean,
                'source_hash' => $this->builder->sourceHash($application),
            ],
            'natis_form_checked_at' => now(),
            'natis_form_checked_by_id' => $actor->id,
        ])->save();

        $this->audit->handle(
            $actor,
            $application,
            'natis_form.checked',
            $type->code().' checked and saved for '.$application->reference.'.',
            null,
            ['form' => $type->value, 'changed_fields' => $changedFields],
        );

        return $application;
    }

    /**
     * Field keys ("section.field") whose value differs from what was on
     * record before this save. Values stay out of the audit log because
     * the form carries identity numbers.
     *
     * @param  array<string, array<string, string|bool>>  $before
     * @param  array<string, array<string, string|bool>>  $after
     * @return list<string>
     */
    private function changedFields(array $before, array $after): array
    {
        $changed = [];

        foreach ($after as $section => $fields) {
            foreach ($fields as $field => $value) {
                if (($before[$section][$field] ?? null) !== $value) {
                    $changed[] = $section.'.'.$field;
                }
            }
        }

        return $changed;
    }
}
