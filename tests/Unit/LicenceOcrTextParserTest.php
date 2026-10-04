<?php

namespace Tests\Unit;

use App\Services\LicenceOcr\LicenceOcrTextParser;
use PHPUnit\Framework\TestCase;

class LicenceOcrTextParserTest extends TestCase
{
    public function test_pulls_expiry_register_and_vin_from_mvl1_shape(): void
    {
        $text = <<<'TEXT'
MOTOR VEHICLE LICENCE AND LICENCE DISC
Vehicle register number        BNT370X        Voertuigregisternommer
Licence number                 JN41BMGP       Lisensienommer
Vehicle identification number (VIN)  ACVRREHR8K4047295  Voertuigidentifikasienommer
Make                           ISUZU
Series name                    D-MAX
Date of expiry                 2021-06-30     Vervaldatum
TEXT;

        $result = (new LicenceOcrTextParser)->parse($text);

        $this->assertSame('clean', $result->status);
        $this->assertSame('2021-06-30', $result->expiryDate);
        $this->assertSame('BNT370X', $result->registerNumber);
        $this->assertSame('ACVRREHR8K4047295', $result->vin);
    }

    public function test_prefers_date_on_vervaldatum_line_over_other_dates(): void
    {
        $text = <<<'TEXT'
Date 2020-07-04
Received by RM DHLAMINI
Date of expiry                 2021-06-30     Vervaldatum
TEXT;

        $result = (new LicenceOcrTextParser)->parse($text);

        $this->assertSame('2021-06-30', $result->expiryDate);
    }

    public function test_marks_result_unreadable_when_no_fields_are_found(): void
    {
        $result = (new LicenceOcrTextParser)->parse('nothing to see here');

        $this->assertSame('unreadable', $result->status);
        $this->assertNull($result->expiryDate);
        $this->assertNull($result->registerNumber);
        $this->assertNull($result->vin);
        $this->assertNotNull($result->notes);
    }

    public function test_still_returns_fields_when_expiry_is_missing_but_marks_unreadable(): void
    {
        $text = <<<'TEXT'
Vehicle register number        BNT370X        Voertuigregisternommer
Vehicle identification number (VIN)  ACVRREHR8K4047295
TEXT;

        $result = (new LicenceOcrTextParser)->parse($text);

        $this->assertSame('unreadable', $result->status);
        $this->assertNull($result->expiryDate);
        $this->assertSame('BNT370X', $result->registerNumber);
        $this->assertSame('ACVRREHR8K4047295', $result->vin);
    }

    public function test_label_on_separate_line_still_locates_the_value(): void
    {
        $text = <<<'TEXT'
Voertuigregisternommer
BZG369X
Date of expiry
2021-02-28
TEXT;

        $result = (new LicenceOcrTextParser)->parse($text);

        $this->assertSame('BZG369X', $result->registerNumber);
        $this->assertSame('2021-02-28', $result->expiryDate);
    }
}
