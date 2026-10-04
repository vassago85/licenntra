<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\QuoteStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AcceptQuote
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor): Application
    {
        $quote = $application->quotes()->where('status', QuoteStatus::Sent)->latest('id')->first();

        if ($quote === null) {
            throw ValidationException::withMessages([
                'quote' => 'There is no quote waiting for acceptance.',
            ]);
        }

        $application = app(TransitionApplication::class)->handle($application, ApplicationStage::QuoteAccepted, $actor);
        $quote->status = QuoteStatus::Accepted;
        $quote->save();

        $this->audit->handle($actor, $quote, 'quote.accepted', 'Quote accepted.');

        return $application->refresh();
    }
}
