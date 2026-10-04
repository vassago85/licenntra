<?php

namespace App\Services\LicenceOcr;

interface LicenceOcrReader
{
    /**
     * Read a licence file from the given absolute path and return the three
     * candidate fields (expiry date, register number, VIN) that a reviewer
     * will confirm. Implementations MUST NOT persist anything.
     */
    public function read(string $absolutePath, string $mime): LicenceOcrResult;
}
