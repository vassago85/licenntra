<?php

namespace App\Enums;

/**
 * Which way documents move between the licensing company and its client.
 * A Collection is the licensing company collecting paperwork from the
 * client (client → licensing company). A Delivery is the licensing
 * company returning finished paperwork to the client (licensing
 * company → client). The licensing authority is never a party.
 */
enum HandoverDirection: string
{
    case Collection = 'collection';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Collection (client to licensing company)',
            self::Delivery => 'Delivery (licensing company to client)',
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
