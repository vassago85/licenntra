<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\QuoteStatus;
use App\Models\Application;
use App\Models\Quote;
use App\Models\User;
use App\Services\NotificationDispatcher;

class SendQuote
{
    public function __construct(
        private RecordAudit $audit,
        private NotificationDispatcher $notifications,
    ) {}

    public function handle(Application $application, User $actor, Quote $quote): Application
    {
        $transition = app(TransitionApplication::class);

        if ($application->stage === ApplicationStage::DocumentReview) {
            $application = $transition->handle($application, ApplicationStage::QuoteRequired, $actor);
        }

        $application = $transition->handle($application, ApplicationStage::QuoteSent, $actor);
        $quote->status = QuoteStatus::Sent;
        $quote->save();

        $this->audit->handle($actor, $quote, 'quote.sent', 'Quote sent to the client.');

        $this->notifications->quoteSent($application, $quote);

        if ($application->clientAccount?->has_standing_agreement) {
            $application = $transition->handle($application, ApplicationStage::QuoteAccepted, null, isSystem: true);
            $quote->status = QuoteStatus::Accepted;
            $quote->save();

            $this->audit->handle(null, $quote, 'quote.accepted', 'Quote accepted under the client\'s standing agreement.', isSystem: true);
        }

        return $application->refresh();
    }
}
