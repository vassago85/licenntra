<?php

namespace App\Jobs;

use App\Actions\RecordAudit;
use App\Models\ApplicationDocument;
use App\Models\BusinessClient;
use App\Models\DocumentVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SecureDeleteExpiredBusinessClients implements ShouldQueue
{
    use Queueable;

    public function handle(RecordAudit $audit): void
    {
        BusinessClient::query()
            ->where('legal_hold', false)
            ->whereNotNull('retention_expires_at')
            ->where('retention_expires_at', '<', now())
            ->orderBy('id')
            ->each(function (BusinessClient $client) use ($audit): void {
                if ($client->legal_hold) {
                    return;
                }

                DB::transaction(function () use ($client, $audit): void {
                    $documentIds = $client->documents()->pluck('id');

                    DocumentVersion::query()
                        ->whereIn('business_client_document_id', $documentIds)
                        ->orderBy('id')
                        ->each(function (DocumentVersion $version): void {
                            $this->wipe($version->storage_path);
                            $version->delete();
                        });

                    ApplicationDocument::query()
                        ->whereIn('business_client_document_id', $documentIds)
                        ->update(['business_client_document_id' => null]);

                    $client->documents()->delete();

                    $audit->handle(
                        null,
                        $client,
                        'business_client.secure_deleted',
                        'Expired business client securely deleted.',
                        ['business_name' => $client->business_name],
                        null,
                        true,
                    );

                    $client->delete();
                });
            });
    }

    private function wipe(string $path): void
    {
        $disk = Storage::disk('documents');

        if (! $disk->exists($path)) {
            return;
        }

        $disk->put($path, random_bytes(64));
        $disk->delete($path);
    }
}
