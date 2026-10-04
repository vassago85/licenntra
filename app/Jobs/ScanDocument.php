<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\RejectionReason;
use App\Models\DocumentVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ScanDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $documentVersionId) {}

    public function handle(): void
    {
        $version = DocumentVersion::query()->find($this->documentVersionId);

        if ($version === null) {
            return;
        }

        $result = config('licentra.clamav_enabled') ? $this->scan($version) : 'clean';
        $version->scan_status = $result;
        $version->save();

        if ($result === 'clean') {
            $this->markAwaitingReview($version);

            return;
        }

        if ($result !== 'infected') {
            return;
        }

        $document = $version->applicationDocument;

        if ($document !== null && (int) $document->linked_version_id === (int) $version->id) {
            $document->status = DocumentStatus::Rejected;
            $document->rejection_reason = RejectionReason::Other;
            $document->reviewer_comment = 'The file failed the virus scan.';
            $document->save();
        }
    }

    private function markAwaitingReview(DocumentVersion $version): void
    {
        $document = $version->applicationDocument;

        if ($document === null || (int) $document->linked_version_id !== (int) $version->id) {
            return;
        }

        if ($document->status !== DocumentStatus::Scanning) {
            return;
        }

        $document->status = DocumentStatus::AwaitingReview;
        $document->save();
    }

    private function scan(DocumentVersion $version): string
    {
        $contents = Storage::disk('documents')->get($version->storage_path);

        if (! is_string($contents)) {
            return 'error';
        }

        $socket = @fsockopen(
            (string) config('licentra.clamav_host'),
            (int) config('licentra.clamav_port'),
            $errorNumber,
            $errorString,
            5,
        );

        if ($socket === false) {
            return 'error';
        }

        fwrite($socket, "zINSTREAM\0");

        foreach (str_split($contents, 2048) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }

        fwrite($socket, pack('N', 0));
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        if (str_contains($response, 'FOUND')) {
            return 'infected';
        }

        if (str_contains($response, 'OK')) {
            return 'clean';
        }

        return 'error';
    }
}
