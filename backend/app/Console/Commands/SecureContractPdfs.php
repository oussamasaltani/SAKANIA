<?php

namespace App\Console\Commands;

use App\Services\ContractService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Moves rental contract PDFs that were generated under the old
 * public/uploads/contracts directory onto the private disk.
 *
 * The move is non-destructive: the bytes are written to private storage and
 * their size is verified before the public copy is removed. A document is only
 * ever moved, never dropped.
 */
class SecureContractPdfs extends Command
{
    protected $signature = 'contracts:secure
                            {--dry-run : Report what would be moved without touching any file}';

    protected $description = 'Move contract PDFs from the public web root into private storage';

    public function handle(): int
    {
        $disk = Storage::disk(ContractService::DISK);
        $legacyDir = public_path('uploads/contracts');
        $dryRun = (bool) $this->option('dry-run');

        $files = File::isDirectory($legacyDir)
            ? File::glob($legacyDir . DIRECTORY_SEPARATOR . '*.pdf')
            : [];

        if ($files === []) {
            $this->info('No contract PDFs found under public/uploads/contracts. Nothing to migrate.');

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;

        foreach ($files as $source) {
            $filename = basename($source);
            $relative = ContractService::DIRECTORY . '/' . $filename;

            if ($disk->exists($relative)) {
                if ($disk->size($relative) === File::size($source)) {
                    if (!$dryRun) {
                        File::delete($source);
                    }
                    $this->line("Already private, public copy removed: {$filename}");
                    $moved++;

                    continue;
                }

                $this->warn("Private copy differs, public file kept for review: {$filename}");
                $skipped++;

                continue;
            }

            $size = File::size($source);

            if ($dryRun) {
                $this->line("Would move: {$filename} ({$size} bytes)");
                $moved++;

                continue;
            }

            $contents = File::get($source);

            if ($contents === '' || $contents === false || !$disk->put($relative, $contents)) {
                $this->error("Failed to migrate, public file kept: {$filename}");
                $skipped++;

                continue;
            }

            if ($disk->size($relative) !== $size) {
                $this->error("Size mismatch after copy, public file kept: {$filename}");
                $skipped++;

                continue;
            }

            File::delete($source);
            $this->info("Migrated to private storage: {$filename} ({$size} bytes)");
            $moved++;
        }

        $this->info("Done. Migrated: {$moved}, skipped: {$skipped}.");

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
