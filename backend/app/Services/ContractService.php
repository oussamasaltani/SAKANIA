<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Notification;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;

class ContractService
{
    /**
     * Private disk that holds the generated contract PDFs. Its root is
     * storage/app/private, outside the public web root, so nginx can never
     * serve a rental contract straight off disk. Contracts leave the system
     * only through the authenticated download endpoint.
     */
    public const DISK = 'local';

    /**
     * Directory, relative to the private disk root, where the generated PDFs
     * live.
     */
    public const DIRECTORY = 'contracts';

    /**
     * Legacy public location. Kept only so contracts generated before the move
     * to private storage can still be located and migrated without losing a
     * single document.
     */
    private const LEGACY_DIRECTORY = 'uploads/contracts';

    /**
     * Returns the contract for a reservation, generating it when it does not
     * exist yet. The unique index on contracts.reservation_id guarantees a
     * single contract per reservation even under concurrent requests.
     */
    public function ensureContract(Reservation $reservation): Contract
    {
        $contract = $this->findOrCreateFor($reservation);

        if (!$this->exists($contract)) {
            $this->generatePdf($contract);
        }

        return $contract->fresh() ?? $contract;
    }

    /**
     * Creates the contract row, tolerating a concurrent creator for the same
     * reservation instead of failing on the unique reservation_id index.
     */
    private function findOrCreateFor(Reservation $reservation): Contract
    {
        try {
            return Contract::firstOrCreate(
                ['reservation_id' => $reservation->id],
                [
                    'owner_id' => $reservation->property->owner_id,
                    'tenant_id' => $reservation->tenant_id,
                    'status' => 'draft',
                ]
            );
        } catch (QueryException $e) {
            $contract = Contract::where('reservation_id', $reservation->id)->first();

            if (!$contract) {
                throw $e;
            }

            return $contract;
        }
    }

    /**
     * Renders the rental contract to a PDF and attaches it to the contract record.
     */
    public function generatePdf(Contract $contract): Contract
    {
        $contract->loadMissing(['reservation.property', 'reservation.tenant', 'owner', 'latestPayment']);

        $reservation = $contract->reservation;
        $property = $reservation?->property;
        $tenant = $reservation?->tenant;
        $owner = $contract->owner ?? $property?->owner;
        $payment = $contract->latestPayment;

        if (!$reservation || !$property || !$tenant || !$owner) {
            throw new RuntimeException('Contract data is incomplete, the PDF cannot be generated.');
        }

        $totalAmount = (float) ($payment?->total_amount ?? $reservation->totalAmount());
        $paidAmount = (float) ($payment?->paid_amount ?? 0);
        $remainingBalance = (float) ($payment?->remaining_balance ?? max(0, $totalAmount - $paidAmount));

        $html = View::make('contracts.rental', [
            'contract' => $contract,
            'reservation' => $reservation,
            'property' => $property,
            'owner' => $owner,
            'tenant' => $tenant,
            'payment' => $payment,
            'reference' => $contract->reference,
            'durationMonths' => $reservation->durationInMonths(),
            'monthlyRate' => (float) $property->price_per_month,
            'totalAmount' => $totalAmount,
            'paidAmount' => $paidAmount,
            'remainingBalance' => $remainingBalance,
        ])->render();

        $filename = 'contract-' . $reservation->id . '.pdf';
        $storagePath = self::DIRECTORY . '/' . $filename;
        $disk = Storage::disk(self::DISK);

        $pdf = app('dompdf.wrapper')->loadHTML($html)
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans');

        $disk->put($storagePath, $pdf->output());

        if (!$disk->exists($storagePath) || $disk->size($storagePath) === 0) {
            throw new RuntimeException('The contract PDF could not be written to private storage.');
        }

        $contract->update([
            'file_url' => $storagePath,
            'status' => 'active',
            'signed_at' => $contract->signed_at ?? now(),
        ]);

        return $contract;
    }

    /**
     * Relative path, on the private disk, of the PDF backing a contract.
     *
     * The file name is always derived from the reservation, which lets a
     * contract that still carries the legacy "uploads/contracts/..." value
     * resolve to the same private file as a freshly generated one.
     */
    public function storagePath(Contract $contract): string
    {
        $filename = $contract->file_url
            ? basename($contract->file_url)
            : 'contract-' . $contract->reservation_id . '.pdf';

        return self::DIRECTORY . '/' . $filename;
    }

    /**
     * Tells whether the PDF exists, on the private disk or, still un-migrated,
     * under the legacy public directory. This check is side effect free.
     */
    public function exists(Contract $contract): bool
    {
        $relative = $this->storagePath($contract);

        if (Storage::disk(self::DISK)->exists($relative)) {
            return true;
        }

        return File::exists($this->legacyPathFor($relative));
    }

    /**
     * Moves a contract that still lives under the old public uploads directory
     * onto the private disk. The bytes are written first and their size is
     * verified before the public copy is removed, so a failure never loses the
     * document.
     */
    public function migrateLegacyFile(Contract $contract): bool
    {
        $relative = $this->storagePath($contract);
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($relative)) {
            return false;
        }

        $legacy = $this->legacyPathFor($relative);

        if (!File::exists($legacy)) {
            return false;
        }

        $contents = File::get($legacy);

        if ($contents === '' || $contents === false) {
            return false;
        }

        if (!$disk->put($relative, $contents)) {
            return false;
        }

        if ($disk->size($relative) !== strlen($contents)) {
            return false;
        }

        File::delete($legacy);

        return true;
    }

    /**
     * Absolute path of the generated file, or null when it is missing. A
     * leftover public copy is transparently migrated to private storage first.
     */
    public function absolutePath(Contract $contract): ?string
    {
        $this->migrateLegacyFile($contract);

        $relative = $this->storagePath($contract);
        $disk = Storage::disk(self::DISK);

        return $disk->exists($relative) ? $disk->path($relative) : null;
    }

    private function legacyPathFor(string $relative): string
    {
        return public_path(self::LEGACY_DIRECTORY . '/' . basename($relative));
    }

    /**
     * Tells both parties that the contract is ready, without ever sending the
     * same notification twice for the same contract.
     */
    public function notifyAvailability(Contract $contract): void
    {
        $contract->loadMissing('reservation.property');

        $title = 'Contract Ready for Download';
        $message = 'The rental contract for "' . $contract->reservation->property->title . '" '
            . '(ref. ' . $contract->reference . ') is ready and available for download.';

        foreach (array_unique(array_filter([$contract->owner_id, $contract->tenant_id])) as $userId) {
            $alreadyNotified = Notification::where('user_id', $userId)
                ->where('type', 'contract')
                ->where('related_type', Contract::class)
                ->where('related_id', $contract->id)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'type' => 'contract',
                'related_type' => Contract::class,
                'related_id' => $contract->id,
                'is_read' => false,
            ]);
        }
    }

    public function downloadName(Contract $contract): string
    {
        return 'sakania-contract-' . $contract->reference . '.pdf';
    }
}
