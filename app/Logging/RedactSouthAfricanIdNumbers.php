<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class RedactSouthAfricanIdNumbers implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactValue($record->context),
            extra: $this->redactValue($record->extra),
        );
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = $this->redactValue($item);
        }

        return $redacted;
    }

    private function redactString(string $value): string
    {
        return (string) preg_replace('/\b\d{13}\b/', '[redacted]', $value);
    }
}
