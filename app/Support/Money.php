<?php

namespace App\Support;

final class Money
{
    public static function rands(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $rands = intdiv($cents, 100);
        $centsPart = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').'R '.number_format($rands, 0, '.', ' ').'.'.$centsPart;
    }
}
