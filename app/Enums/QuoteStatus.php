<?php

namespace App\Enums;

enum QuoteStatus: string
{
    use LabelsEnum;

    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
