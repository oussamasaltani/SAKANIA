<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\ContractService;
use App\Services\PaymentReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly PaymentReceiptService $receipts,
    ) {
    }

    /**
     * Checkout summary for an accepted reservation. The amount due is always
     * recomputed on the server from the stored property price and reservation.
     */
    public function checkout(Request $request, $id)
    {
        $reservation = Reservation::with(['property', 'property.images', 'payments'])
            ->where('tenant_id', $request->user()->id)
            ->find($id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        if ($reservation->status !== 'accepted') {
            return response()->json(['message' => 'Reservation must be accepted before payment.'], 422);
        }

        $totalAmount = $reservation->totalAmount();
        $paidAmount = (float) ($reservation->payments->last()?->paid_amount ?? 0);
        $amountDue = round(max(0, $totalAmount - $paidAmount), 2);

        if ($amountDue <= 0) {
            return response()->json(['message' => 'Payment has already been completed for this reservation.'], 422);
        }

        return response()->json([
            'reservation' => $reservation,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'amount_due' => $amountDue,
            'monthly_rate' => (float) $reservation->property->price_per_month,
            'duration_months' => $reservation->durationInMonths(),
            'contract' => Contract::where('reservation_id', $reservation->id)->first(),
        ]);
    }

    /**
     * Records a payment for an accepted reservation. Every figure is verified
     * and recalculated on the server before any record is written.
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|in:cash,card,transfer',
        ]);

        $user = $request->user();
        $amount = round((float) $validated['amount'], 2);

        try {
            $result = DB::transaction(function () use ($user, $validated, $amount) {
                $reservation = Reservation::with('property')
                    ->where('tenant_id', $user->id)
                    ->lockForUpdate()
                    ->find($validated['reservation_id']);

                if (!$reservation) {
                    return ['error' => 'Reservation not found.', 'code' => 404];
                }

                if ($reservation->status !== 'accepted') {
                    return ['error' => 'The reservation must be accepted before payment.', 'code' => 422];
                }

                $totalAmount = $reservation->totalAmount();

                if ($totalAmount <= 0) {
                    return ['error' => 'This reservation has no payable amount.', 'code' => 422];
                }

                $payment = Payment::where('reservation_id', $reservation->id)
                    ->lockForUpdate()
                    ->first();

                $isFirstPayment = $payment === null;
                $alreadyPaid = (float) ($payment->paid_amount ?? 0);
                $remainingDue = round($totalAmount - $alreadyPaid, 2);

                if ($remainingDue <= 0) {
                    return ['error' => 'Payment has already been completed for this reservation.', 'code' => 422];
                }

                if ($amount > $remainingDue) {
                    return [
                        'error' => 'The amount exceeds the remaining balance of ' . number_format($remainingDue, 2) . ' MAD.',
                        'code' => 422,
                    ];
                }

                $paidAmount = round($alreadyPaid + $amount, 2);
                $remainingBalance = round(max(0, $totalAmount - $paidAmount), 2);
                $status = $remainingBalance <= 0 ? 'paid' : 'pending';

                $values = [
                    'total_amount' => $totalAmount,
                    'amount' => $amount,
                    'paid_amount' => $paidAmount,
                    'remaining_balance' => $remainingBalance,
                    'method' => $validated['method'],
                    'status' => $status,
                    'transaction_ref' => $payment?->transaction_ref ?: 'TXN-' . strtoupper(Str::random(12)),
                    'payment_date' => now(),
                ];

                if ($payment) {
                    $payment->update($values);
                } else {
                    $payment = Payment::create(array_merge($values, [
                        'reservation_id' => $reservation->id,
                        'tenant_id' => $user->id,
                    ]));
                }

                return [
                    'payment' => $payment->fresh(),
                    'reservation' => $reservation->load('property'),
                    'first_payment' => $isFirstPayment,
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The payment could not be processed. No changes were recorded.',
            ], 500);
        }

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }

        $payment = $result['payment'];
        $reservation = $result['reservation'];
        $ownerId = $reservation->property->owner_id;

        // The payment is already committed at this point: a notification
        // failure must never be reported as a failed payment.
        $notificationError = null;

        try {
            $this->notifyPaymentReceived($payment, $reservation, $ownerId, $amount);
        } catch (\Throwable $e) {
            report($e);
            $notificationError = 'The payment was recorded but the notification could not be sent.';
        }

        $contract = null;
        $contractError = null;

        // The rental contract is issued once, right after the first successful
        // payment, and is never re-generated afterwards: the following monthly
        // payments are acknowledged by a payment receipt instead.
        if ($result['first_payment']) {
            try {
                $contract = $this->contracts->ensureContract($reservation);
                $this->contracts->notifyAvailability($contract);
            } catch (\Throwable $e) {
                report($e);
                $contractError = 'The payment was recorded but the contract could not be generated yet.';
            }
        }

        return response()->json([
            'message' => 'Payment processed successfully.',
            'payment' => $payment->load(['reservation', 'reservation.property']),
            'contract' => $contract,
            'receipt' => $this->receiptPayload($payment),
            'contract_error' => $contractError,
            'notification_error' => $notificationError,
        ]);
    }

    public function success(Request $request, $id)
    {
        $payment = Payment::with(['reservation', 'reservation.property', 'reservation.contract'])
            ->where('tenant_id', $request->user()->id)
            ->find($id);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return response()->json([
            'payment' => $payment,
            'contract' => Contract::where('reservation_id', $payment->reservation_id)->first(),
            'receipt' => $this->receiptPayload($payment),
        ]);
    }

    /**
     * Payment receipt ticket for a recorded payment. The rental contract is not
     * touched here: the receipt is a standalone proof of the payment made.
     */
    public function receipt(Request $request, $id)
    {
        $payment = Payment::with(['reservation', 'reservation.property', 'tenant'])->find($id);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $user = $request->user();
        $isTenant = (int) $payment->tenant_id === (int) $user->id;
        $isOwner = (int) ($payment->reservation?->property?->owner_id) === (int) $user->id;
        $isAdmin = (int) $user->role_id === 3;

        if (!$isTenant && !$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Unauthorized to access this receipt.'], 403);
        }

        try {
            $contents = $this->receipts->pdf($payment);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Receipt file not available.'], 404);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->receipts->downloadName($payment) . '"',
        ]);
    }

    /**
     * Descriptor of the receipt generated for a payment, so the client can
     * offer the PDF without knowing anything about the server side rendering.
     */
    private function receiptPayload(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'reference' => $this->receipts->reference($payment),
            'amount' => (string) $payment->amount,
            'paid_amount' => (string) $payment->paid_amount,
            'remaining_balance' => (string) $payment->remaining_balance,
            'download_url' => '/api/payments/' . $payment->id . '/receipt',
        ];
    }

    /**
     * Confirms the payment to the tenant and informs the property owner.
     */
    private function notifyPaymentReceived(Payment $payment, Reservation $reservation, int $ownerId, float $amount): void
    {
        $reservation->loadMissing('tenant');
        $propertyTitle = $reservation->property->title;
        $tenantName = $reservation->tenant->full_name ?? 'The tenant';

        $recipientNotifications = [
            [
                'user_id' => $ownerId,
                'title' => 'Payment Received',
                'message' => $tenantName . ' completed a payment of ' . number_format($amount, 2)
                    . ' MAD for "' . $propertyTitle . '".',
            ],
            [
                'user_id' => $payment->tenant_id,
                'title' => 'Payment Processed',
                'message' => 'Your payment of ' . number_format($amount, 2) . ' MAD has been processed successfully.',
            ],
        ];

        foreach ($recipientNotifications as $notification) {
            Notification::create($notification + [
                'type' => 'payment',
                'related_type' => Payment::class,
                'related_id' => $payment->id,
                'is_read' => false,
            ]);
        }
    }
}
