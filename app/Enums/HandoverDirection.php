<?php

namespace App\Enums;

/**
 * Who is handing documents to whom. A Collection is the licensing
 * authority representative collecting a pack from the dealership
 * (dealer → authority). A Delivery is the representative dropping
 * paperwork off at the dealership (authority → dealer).
 */
enum HandoverDirection: string
{
    case Collection = 'collection';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Collection (sent to authority)',
            self::Delivery => 'Delivery (received from authority)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Collection => 'Collection',
            self::Delivery => 'Delivery',
        };
    }

    public function documentTitle(): string
    {
        return match ($this) {
            self::Collection => 'Proof of collection (POC)',
            self::Delivery => 'Proof of delivery (POD)',
        };
    }

    public function dealerVerb(): string
    {
        return match ($this) {
            self::Collection => 'handed over by',
            self::Delivery => 'received by',
        };
    }
}
