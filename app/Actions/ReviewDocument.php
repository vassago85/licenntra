<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Enums\RejectionReason;
use App\Models\ApplicationDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReviewDocument
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(
        ApplicationDocument $document,
        User $actor,
        DocumentStatus $decision,
        ?RejectionReason $reason = null,
        ?string $comment = null,
        ?bool $dangerousGoodsStamped = null,
    ): ApplicationDocument {
        if (! in_array($decision, [DocumentStatus::Accepted, DocumentStatus::Rejected], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose accept or reject.',
            ]);
        }

        if ($decision === DocumentStatus::Rejected) {
            if ($reason === null) {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'Choose a rejection reason.',
                ]);
            }

            if ($reason->requiresComment() && blank($comment)) {
                throw ValidationException::withMessages([
                    'reviewer_comment' => 'Other needs a comment.',
                ]);
            }
        }

        $document->loadMissing(['documentType', 'application']);
        $requiresDangerousGoodsStamp = $document->requiresDangerousGoodsStamp();

        if ($decision === DocumentStatus::Accepted && $requiresDangerousGoodsStamp && $dangerousGoodsStamped !== true) {
            throw ValidationException::withMessages([
                'dangerous_goods_stamped' => 'The certificate of fitness must be stamped Dangerous goods.',
            ]);
        }

        $before = [
            'status' => $document->status->value,
            'rejection_reason' => $document->rejection_reason?->value,
            'dangerous_goods_stamped' => $document->dangerous_goods_stamped,
        ];

        $document->status = $decision;
        $document->rejection_reason = $decision === DocumentStatus::Rejected ? $reason : null;
        $document->reviewer_comment = $decision === DocumentStatus::Rejected ? $comment : null;
        $document->dangerous_goods_stamped = $decision === DocumentStatus::Accepted && $requiresDangerousGoodsStamp
            ? true
            : null;
        $document->save();

        app(SyncDatafixStatus::class)->handle($document->application);

        $this->audit->handle(
            $actor,
            $document,
            'document.reviewed',
            $decision === DocumentStatus::Accepted
                ? ($requiresDangerousGoodsStamp ? 'Certificate of fitness accepted and stamped Dangerous goods.' : 'Document accepted.')
                : 'Document rejected.',
            $before,
            [
                'status' => $document->status->value,
                'rejection_reason' => $document->rejection_reason?->value,
                'dangerous_goods_stamped' => $document->dangerous_goods_stamped,
            ],
        );

        return $document->refresh();
    }
}
