<?php

namespace App\Enums;

enum ApplicationStage: string
{
    use LabelsEnum;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case DocumentReview = 'document_review';
    case ChangesRequested = 'changes_requested';
    case QuoteRequired = 'quote_required';
    case QuoteSent = 'quote_sent';
    case QuoteAccepted = 'quote_accepted';
    case PaymentPending = 'payment_pending';
    case PaymentVerified = 'payment_verified';
    case DatafixInProgress = 'datafix_in_progress';
    case SubmittedToAuthority = 'submitted_to_authority';
    case AuthorityQuery = 'authority_query';
    case Approved = 'approved';
    case ReadyForCollection = 'ready_for_collection';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DocumentReview => 'Document review',
            self::ChangesRequested => 'Changes requested',
            self::QuoteRequired => 'Quote required',
            self::QuoteSent => 'Quote sent',
            self::QuoteAccepted => 'Quote accepted',
            self::PaymentPending => 'Payment pending',
            self::PaymentVerified => 'Payment verified',
            self::DatafixInProgress => 'Datafix in progress',
            self::SubmittedToAuthority => 'At the authority',
            self::AuthorityQuery => 'Authority query',
            self::ReadyForCollection => 'Ready for collection',
            default => ucfirst($this->value),
        };
    }

    /**
     * @return list<self>
     */
    public function successors(): array
    {
        $next = match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::DocumentReview],
            self::DocumentReview => [self::ChangesRequested, self::QuoteRequired, self::PaymentPending],
            self::ChangesRequested => [self::DocumentReview],
            self::QuoteRequired => [self::QuoteSent],
            self::QuoteSent => [self::QuoteAccepted, self::Cancelled],
            self::QuoteAccepted => [self::PaymentPending],
            self::PaymentPending => [self::PaymentVerified],
            self::PaymentVerified => [self::DatafixInProgress, self::SubmittedToAuthority],
            self::DatafixInProgress => [self::SubmittedToAuthority],
            self::SubmittedToAuthority => [self::AuthorityQuery, self::Approved],
            self::AuthorityQuery => [self::SubmittedToAuthority],
            self::Approved => [self::ReadyForCollection],
            self::ReadyForCollection => [self::Completed],
            self::Completed, self::Cancelled => [self::Archived],
            self::Archived => [],
        };

        if ($this->isBeforeAuthority() && $this !== self::Cancelled) {
            $next[] = self::Cancelled;
        }

        return array_values(array_unique($next, SORT_REGULAR));
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->successors(), true);
    }

    public function isBeforeAuthority(): bool
    {
        return ! in_array($this, [
            self::SubmittedToAuthority,
            self::AuthorityQuery,
            self::Approved,
            self::ReadyForCollection,
            self::Completed,
            self::Cancelled,
            self::Archived,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Archived], true);
    }

    /**
     * UI tone for stage badges. Maps to a bg/fg colour pair in the view.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::ChangesRequested => 'danger',
            self::QuoteRequired, self::QuoteSent, self::PaymentPending => 'warning',
            self::Approved, self::ReadyForCollection, self::Completed, self::PaymentVerified, self::QuoteAccepted => 'success',
            self::Cancelled, self::Archived => 'neutral',
            default => 'info',
        };
    }

    /**
     * True when the next action sits with the client, not the licensing company.
     */
    public function needsClientAction(): bool
    {
        return in_array($this, [
            self::Draft,
            self::ChangesRequested,
            self::QuoteSent,
            self::PaymentPending,
        ], true);
    }
}
