<?php

namespace App\Services\LicenceOcr;

/**
 * Pull the three fields we care about out of OCR text. Shared between the
 * Tesseract reader (feeds in Tesseract's output) and tests (feed in canned
 * text). Keeping this as a pure function makes it directly testable without
 * the Tesseract binary.
 */
class LicenceOcrTextParser
{
    public function parse(string $text): LicenceOcrResult
    {
        $normalised = $this->normalise($text);

        $expiry = $this->findExpiry($normalised);
        $register = $this->findRegister($normalised);
        $vin = $this->findVin($normalised);

        if ($expiry === null && $register === null && $vin === null) {
            return LicenceOcrResult::unreadable('No expiry, register number, or VIN could be read from the file.');
        }

        if ($expiry === null) {
            return new LicenceOcrResult(null, $register, $vin, 'unreadable', 'The expiry date could not be read. Type it in during review.');
        }

        return LicenceOcrResult::clean($expiry, $register, $vin);
    }

    private function normalise(string $text): string
    {
        return (string) preg_replace("/[ \t]+/u", ' ', $text);
    }

    private function findExpiry(string $text): ?string
    {
        // Prefer a date that sits on a line labelled "Date of expiry" or
        // "Vervaldatum". Fall back to the first ISO date we see so a licence
        // that was OCR'd with the label on a separate line still produces a
        // candidate.
        $lines = preg_split("/\r?\n/", $text) ?: [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/\b(date\s*of\s*expiry|vervaldatum)\b/i', $line)) {
                continue;
            }

            if (preg_match('/(20\d{2}-\d{2}-\d{2})/', $line, $m)) {
                return $m[1];
            }

            $next = $lines[$index + 1] ?? '';

            if (preg_match('/(20\d{2}-\d{2}-\d{2})/', $next, $m)) {
                return $m[1];
            }
        }

        if (preg_match('/(20\d{2}-\d{2}-\d{2})/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function findRegister(string $text): ?string
    {
        return $this->valueBelowLabel($text, '/(vehicle\s*register\s*number|voertuigregisternommer)/i', '/^[A-Z0-9]{4,10}$/');
    }

    private function findVin(string $text): ?string
    {
        return $this->valueBelowLabel($text, '/(vehicle\s*identification\s*number|voertuigidentifikasienommer|\(vin\))/i', '/^[A-Z0-9]{10,17}$/');
    }

    private function valueBelowLabel(string $text, string $labelPattern, string $tokenPattern): ?string
    {
        $lines = preg_split("/\r?\n/", $text) ?: [];

        foreach ($lines as $index => $line) {
            if (! preg_match($labelPattern, $line)) {
                continue;
            }

            foreach ($this->tokensOnLine($line) as $token) {
                if (preg_match($tokenPattern, $token)) {
                    return $token;
                }
            }

            for ($offset = 1; $offset <= 2; $offset++) {
                $neighbour = $lines[$index + $offset] ?? '';
                foreach ($this->tokensOnLine($neighbour) as $token) {
                    if (preg_match($tokenPattern, $token)) {
                        return $token;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tokensOnLine(string $line): array
    {
        $parts = preg_split('/\s+/u', trim($line)) ?: [];

        return array_values(array_filter($parts, fn (string $token): bool => $token !== ''));
    }
}
