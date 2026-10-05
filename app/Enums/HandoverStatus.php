<?php

namespace App\Enums;

/**
 * Lifecycle of a batched hand-over record. "Pending" means the dealer
 * has built the list in advance (either expecting a delivery or
 * preparing paperwork for an upcoming collection); "Completed" means
 * both parties have met and the hand-over has been confirmed.
 */
enum HandoverStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
        };
    }
}
