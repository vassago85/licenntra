<?php

namespace App\Services\LicenceOcr;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Shells out to the Tesseract binary installed in the app Docker image. PDFs
 * are first rasterised with poppler's pdftoppm, then every resulting page is
 * handed to Tesseract. The combined text is parsed by {@see LicenceOcrTextParser}.
 */
class TesseractLicenceOcrReader implements LicenceOcrReader
{
    public function __construct(
        private readonly LicenceOcrTextParser $parser,
        private readonly string $tesseractBinary = 'tesseract',
        private readonly string $pdfToPpmBinary = 'pdftoppm',
    ) {}

    public function read(string $absolutePath, string $mime): LicenceOcrResult
    {
        if (! is_file($absolutePath)) {
            return LicenceOcrResult::failed('The stored file could not be read.');
        }

        try {
            $imagePaths = $this->prepareImagePaths($absolutePath, $mime);
        } catch (Throwable $exception) {
            return LicenceOcrResult::failed('The file could not be rasterised: '.mb_substr($exception->getMessage(), 0, 180));
        }

        if ($imagePaths === []) {
            return LicenceOcrResult::failed('No pages were produced from the file.');
        }

        $text = '';

        try {
            foreach ($imagePaths as $imagePath) {
                $text .= $this->runTesseract($imagePath)."\n";
            }
        } catch (ProcessFailedException $exception) {
            return LicenceOcrResult::failed('Tesseract failed: '.mb_substr($exception->getMessage(), 0, 180));
        } finally {
            foreach ($imagePaths as $path) {
                if ($path !== $absolutePath && is_file($path)) {
                    @unlink($path);
                }
            }
        }

        return $this->parser->parse($text);
    }

    /**
     * @return list<string>
     */
    private function prepareImagePaths(string $absolutePath, string $mime): array
    {
        if ($mime !== 'application/pdf') {
            return [$absolutePath];
        }

        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'licentra-ocr-'.bin2hex(random_bytes(6));

        if (! mkdir($tempDir, 0700, true) && ! is_dir($tempDir)) {
            throw new \RuntimeException('Could not create a temporary directory for OCR.');
        }

        $prefix = $tempDir.DIRECTORY_SEPARATOR.'page';

        $process = new Process([
            $this->pdfToPpmBinary,
            '-r', '300',
            '-png',
            $absolutePath,
            $prefix,
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        $pages = glob($prefix.'-*.png') ?: [];
        sort($pages);

        return array_values(array_filter($pages, 'is_file'));
    }

    private function runTesseract(string $imagePath): string
    {
        $process = new Process([
            $this->tesseractBinary,
            $imagePath,
            '-', // write to stdout
            '-l', 'eng',
            '--psm', '6',
        ]);
        $process->setTimeout(60);
        $process->mustRun();

        return $process->getOutput();
    }
}
