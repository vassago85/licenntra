<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentRule;
use Illuminate\Support\Collection;

class ResolveRequiredDocuments
{
    public function handle(Application $application): void
    {
        $rules = DocumentRule::query()
            ->where('active', true)
            ->with('documentType')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (DocumentRule $rule): bool => $this->matches($rule, $application))
            ->groupBy(fn (DocumentRule $rule): string => $rule->document_type_id.'|'.$rule->party_role);

        $kept = [];

        foreach ($rules as $key => $group) {
            /** @var Collection<int, DocumentRule> $group */
            $required = $group->contains(fn (DocumentRule $rule): bool => $rule->requirement === 'required');
            $rule = $group->first();

            $document = ApplicationDocument::query()->firstOrNew([
                'application_id' => $application->id,
                'document_type_id' => $rule->document_type_id,
                'party_role' => $rule->party_role,
            ]);

            $document->required = $required;

            if (! $document->exists) {
                $document->status = $required ? DocumentStatus::Missing : DocumentStatus::NotApplicable;
            } elseif (! $required && $document->status === DocumentStatus::Missing) {
                $document->status = DocumentStatus::NotApplicable;
            }

            $document->save();
            $kept[] = $document->id;
        }

        $application->documents()->whereNotIn('id', $kept)->each(function (ApplicationDocument $document): void {
            $document->required = false;

            if (in_array($document->status, [DocumentStatus::Missing, DocumentStatus::NotApplicable], true)) {
                $document->status = DocumentStatus::NotApplicable;
            }

            $document->save();
        });

        app(SyncDatafixStatus::class)->handle($application->refresh());
    }

    private function matches(DocumentRule $rule, Application $application): bool
    {
        return $this->same($rule->request_type?->value, $application->request_type?->value)
            && $this->same($rule->vehicle_category?->value, $application->vehicle_category?->value)
            && $this->same($rule->owner_type?->value, $application->owner_type?->value)
            && $this->same($rule->province?->value, $application->province?->value)
            && ($rule->is_financed === null || (bool) $rule->is_financed === $application->is_financed)
            && ($rule->is_dealer_stock === null || (bool) $rule->is_dealer_stock === $application->is_dealer_stock);
    }

    private function same(?string $ruleValue, ?string $applicationValue): bool
    {
        return $ruleValue === null || $ruleValue === $applicationValue;
    }
}
