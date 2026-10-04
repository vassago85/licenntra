<?php

namespace App\Enums;

enum DatafixStatus: string
{
    use LabelsEnum;

    case NotRequired = 'not_required';
    case AwaitingDocuments = 'awaiting_documents';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Queried = 'queried';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Not required',
            self::AwaitingDocuments => 'Awaiting documents',
            self::Ready => 'Ready',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Queried => 'Queried',
        };
    }
}
