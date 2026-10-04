<?php

namespace App\Jobs;

use App\Models\DocumentVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

class InspectDocument implements ShouldQueue
{
    use Queueable;

    /**
     * How much of the extracted text we keep for the reviewer preview.
     */
    private const EXCERPT_LENGTH = 500;

    /**
     * Below this many non-whitespace characters we treat the PDF as image-only.
     */
    private const MIN_TEXT_CHARS = 40;

    public function __construct(public int $documentVersionId) {}

    public function handle(): void
    {
        $version = DocumentVersion::query()
            ->withoutGlobalScopes()
            ->find($this->documentVersionId);

        if ($version === null) {
            return;
        }

        if ($version->mime !== 'application/pdf') {
            $version->forceFill([
                'inspection_status' => 'skipped',
                'inspection_notes' => null,
            ])->save();

            return;
        }

        $contents = Storage::disk('documents')->get($version->storage_path);

        if (! is_string($contents) || $contents === '') {
            $version->forceFill([
                'inspection_status' => 'unreadable',
                'inspection_notes' => 'The stored file could not be read.',
            ])->save();

            return;
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseContent($contents);
            $pages = $pdf->getPages();
            $pageCount = count($pages);
            $text = trim((string) $pdf->getText());
            $textLength = mb_strlen(preg_replace('/\s+/u', '', $text) ?? '');
            $hasTextLayer = $textLength >= self::MIN_TEXT_CHARS;

            $version->forceFill([
                'page_count' => $pageCount,
                'has_text_layer' => $hasTextLayer,
                'inspection_status' => $hasTextLayer ? 'clean' : 'empty',
                'inspection_notes' => $hasTextLayer
                    ? null
                    : 'No searchable text layer. This looks like a scanned image — open and read the file.',
                'text_excerpt' => $hasTextLayer
                    ? mb_substr($text, 0, self::EXCERPT_LENGTH)
                    : null,
            ])->save();
        } catch (Throwable $exception) {
            $version->forceFill([
                'inspection_status' => 'unreadable',
                'inspection_notes' => 'The PDF could not be parsed: '.mb_substr($exception->getMessage(), 0, 180),
            ])->save();
        }
    }
}
