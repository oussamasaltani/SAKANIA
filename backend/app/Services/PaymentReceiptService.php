<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Renders the payment receipt ticket.
 *
 * The rental contract is issued once, on the first successful payment, and is
 * never re-generated. Every later monthly payment is instead acknowledged by a
 * receipt, which is built from the current state of the payment record: the
 * installment amount, the running total already paid and the balance left.
 *
 * Receipts are produced on demand and returned in memory, so no schema change
 * and no file accumulation on disk is required.
 */
class PaymentReceiptService
{
    /**
     * Data backing the receipt ticket, resolved from the stored reservation,
     * property, parties and payment records.
     */
    public function viewData(Payment $payment): array
    {
        $payment->loadMissing(['reservation.property', 'reservation.tenant', 'tenant']);

        $reservation = $payment->reservation;
        $property = $reservation?->property;

        if (!$reservation || !$property) {
            throw new RuntimeException('Receipt data is incomplete, the PDF cannot be generated.');
        }

        $owner = $property->owner;
        $tenant = $payment->tenant ?? $reservation->tenant;
        $contract = $reservation->contract()->getQuery()->first();

        return [
            'payment' => $payment,
            'reservation' => $reservation,
            'property' => $property,
            'owner' => $owner,
            'tenant' => $tenant,
            'contract' => $contract,
            'reference' => $this->reference($payment),
            'contractReference' => $contract?->reference,
            'durationMonths' => $reservation->durationInMonths(),
            'monthlyRate' => (float) $property->price_per_month,
            'totalAmount' => (float) $payment->total_amount,
            'installment' => (float) $payment->amount,
            'totalPaid' => (float) $payment->paid_amount,
            'remainingBalance' => (float) $payment->remaining_balance,
        ];
    }

    /**
     * Renders the receipt ticket for a payment and returns the raw PDF bytes.
     */
    public function pdf(Payment $payment): string
    {
        $html = View::make('payments.receipt', $this->viewData($payment))->render();

        $pdf = app('dompdf.wrapper')->loadHTML($html)
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans');

        $output = $pdf->output();

        if ($output === '') {
            throw new RuntimeException('The receipt PDF could not be generated.');
        }

        return $output;
    }

    /**
     * Stable, human readable receipt reference.
     */
    public function reference(Payment $payment): string
    {
        return 'RC-' . str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT);
    }

    public function downloadName(Payment $payment): string
    {
        return 'sakania-receipt-' . $this->reference($payment) . '.pdf';
    }
}
