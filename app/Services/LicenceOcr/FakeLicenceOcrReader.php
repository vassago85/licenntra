<?php

namespace App\Services\LicenceOcr;

/**
 * In-memory stub used in tests and in local dev where the Tesseract binary is
 * not available. Lets a test queue up a specific result (or return a canned
 * clean result by default) without touching the filesystem or shelling out.
 */
class FakeLicenceOcrReader implements LicenceOcrReader
{
    /** @var array<string, LicenceOcrResult> */
    private array $results = [];

    private ?LicenceOcrResult $default = null;

    public function queue(string $absolutePath, LicenceOcrResult $result): void
    {
        $this->results[$absolutePath] = $result;
    }

    public function setDefault(LicenceOcrResult $result): void
    {
        $this->default = $result;
    }

    public function read(string $absolutePath, string $mime): LicenceOcrResult
    {
        if (array_key_exists($absolutePath, $this->results)) {
            return $this->results[$absolutePath];
        }

        if ($this->default !== null) {
            return $this->default;
        }

        return LicenceOcrResult::clean('2027-06-30', 'BNT370X', 'ACVRREHR8K4047295');
    }
}
