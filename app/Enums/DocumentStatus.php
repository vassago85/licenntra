<?php

namespace App\Enums;

enum DocumentStatus: string
{
    use LabelsEnum;

    case Missing = 'missing';
    case Uploaded = 'uploaded';
    case Scanning = 'scanning';
    case AwaitingReview = 'awaiting_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Missing',
            self::Uploaded => 'Uploaded',
            self::Scanning => 'Scanning',
            self::AwaitingReview => 'Awaiting review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::NotApplicable => 'Not applicable',
        };
    }
}
