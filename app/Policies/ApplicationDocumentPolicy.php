<?php

namespace App\Policies;

use App\Enums\ApplicationStage;
use App\Models\ApplicationDocument;
use App\Models\User;

class ApplicationDocumentPolicy
{
    public function view(User $user, ApplicationDocument $document): bool
    {
        return $user->can('view', $document->application);
    }

    public function upload(User $user, ApplicationDocument $document): bool
    {
        $application = $document->application;

        if ($application === null || ! $user->can('view', $application)) {
            return false;
        }

        if ($user->isClient()) {
            return $user->can('update', $application);
        }

        return $user->can('review', $application) && ! $application->stage->isTerminal();
    }

    public function review(User $user, ApplicationDocument $document): bool
    {
        return $user->can('review', $document->application);
    }

    public function download(User $user, ApplicationDocument $document): bool
    {
        if (! $this->view($user, $document)) {
            return false;
        }

        if ($document->documentType?->is_identity_document) {
            return $user->can('documents.identity.download');
        }

        if ($user->isClient()) {
            return $user->client_account_id === $document->application?->client_account_id;
        }

        return $user->isLicensingStaff();
    }

    public function replace(User $user, ApplicationDocument $document): bool
    {
        if (! $this->upload($user, $document)) {
            return false;
        }

        if ($user->isClient() && $document->application?->stage === ApplicationStage::ChangesRequested) {
            return $document->status->value === 'rejected' || $document->required;
        }

        return true;
    }
}
