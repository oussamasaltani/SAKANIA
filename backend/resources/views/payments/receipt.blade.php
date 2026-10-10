@php
    use Carbon\Carbon;

    $fmt = fn ($value) => $value === null ? '—' : number_format((float) $value, 2) . ' MAD';
    $paidAt = $payment->payment_date ? Carbon::parse($payment->payment_date) : Carbon::now();
    $methodLabels = ['cash' => 'Cash', 'card' => 'Credit / Debit card', 'transfer' => 'Bank transfer'];
    $statusLabels = ['paid' => 'Paid in full', 'pending' => 'Partially paid', 'failed' => 'Failed', 'refunded' => 'Refunded'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Receipt {{ $reference }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            line-height: 1.5;
        }
        .header {
            border-bottom: 3px solid #ff7e21;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .header table { width: 100%; }
        .brand { font-size: 20px; font-weight: bold; color: #ff7e21; letter-spacing: 1px; }
        .brand-sub { font-size: 9px; color: #6b7280; text-transform: uppercase; letter-spacing: 2px; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: right; }
        .doc-ref { font-size: 10px; color: #6b7280; text-align: right; }
        h2 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #374151;
            border-left: 3px solid #ff7e21;
            padding-left: 8px;
            margin: 0 0 8px;
        }
        .section { margin-bottom: 16px; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: top;
        }
        .label {
            width: 32%;
            background: #f9fafb;
            font-weight: bold;
            color: #4b5563;
        }
        .parties { width: 100%; border-collapse: collapse; }
        .parties td { width: 50%; vertical-align: top; padding: 0 6px; }
        .party-box {
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px;
        }
        .party-role {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #ff7e21;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .party-name { font-size: 13px; font-weight: bold; margin-bottom: 3px; }
        .party-line { color: #4b5563; }
        .amounts { width: 100%; border-collapse: collapse; }
        .amounts td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; }
        .amounts td.amount { text-align: right; font-weight: bold; }
        .head-row td { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }
        .total-row td { border-top: 2px solid #374151; border-bottom: none; font-size: 12px; }
        .remaining { color: #b91c1c; }
        .paid { color: #047857; }
        .stamp {
            display: inline-block;
            border: 2px solid #047857;
            border-radius: 4px;
            color: #047857;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 4px 12px;
        }
        .note { color: #4b5563; font-size: 10px; }
        .footer { margin-top: 14px; border-top: 1px solid #e5e7eb; padding-top: 8px; font-size: 8.5px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="brand">SAKANIA</div>
                    <div class="brand-sub">Rental Management Platform</div>
                </td>
                <td>
                    <div class="doc-title">Payment Receipt</div>
                    <div class="doc-ref">Receipt No.: {{ $reference }}</div>
                    <div class="doc-ref">Issued: {{ $paidAt->format('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Receipt status</h2>
        <table>
            <tr>
                <td style="padding-left: 0;">
                    <span class="stamp">{{ $statusLabels[$payment->status] ?? ucfirst($payment->status) }}</span>
                </td>
                <td style="text-align: right; padding-right: 0; font-size: 10px; color: #6b7280;">
                    Paid on {{ $paidAt->format('d/m/Y H:i') }}
                    @if ($payment->transaction_ref)
                        &bull; Transaction {{ $payment->transaction_ref }}
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Parties</h2>
        <table class="parties">
            <tr>
                <td>
                    <div class="party-box">
                        <div class="party-role">Paid by — The Tenant</div>
                        <div class="party-name">{{ $tenant->full_name }}</div>
                        <div class="party-line">Email: {{ $tenant->email ?: '—' }}</div>
                        <div class="party-line">Phone: {{ $tenant->phone ?: '—' }}</div>
                    </div>
                </td>
                <td>
                    <div class="party-box">
                        <div class="party-role">Received by — The Owner</div>
                        <div class="party-name">{{ $owner->full_name }}</div>
                        <div class="party-line">Email: {{ $owner->email ?: '—' }}</div>
                        <div class="party-line">Phone: {{ $owner->phone ?: '—' }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Payment details</h2>
        <table class="grid">
            <tr>
                <td class="label">Property</td>
                <td>{{ $property->title }}</td>
            </tr>
            <tr>
                <td class="label">Address</td>
                <td>{{ $property->location ?: '—' }}</td>
            </tr>
            <tr>
                <td class="label">Reservation reference</td>
                <td>{{ $reservation->reference }}</td>
            </tr>
            @if ($contractReference)
                <tr>
                    <td class="label">Rental contract</td>
                    <td>{{ $contractReference }} (issued once, on the first payment)</td>
                </tr>
            @endif
            <tr>
                <td class="label">Rental period</td>
                <td>
                    {{ $reservation->start_date->format('d/m/Y') }} to {{ $reservation->end_date->format('d/m/Y') }}
                    ({{ $durationMonths }} month(s))
                </td>
            </tr>
            <tr>
                <td class="label">Monthly rent</td>
                <td>{{ $fmt($monthlyRate) }}</td>
            </tr>
            <tr>
                <td class="label">Payment method</td>
                <td>{{ $methodLabels[$payment->method] ?? ucfirst((string) $payment->method) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Amounts</h2>
        <table class="amounts">
            <tr class="head-row">
                <td>Description</td>
                <td class="amount">Amount</td>
            </tr>
            <tr>
                <td>Amount paid with this receipt</td>
                <td class="amount paid">{{ $fmt($installment) }}</td>
            </tr>
            <tr class="total-row">
                <td>Total rental amount</td>
                <td class="amount">{{ $fmt($totalAmount) }}</td>
            </tr>
            <tr>
                <td>Total paid to date</td>
                <td class="amount paid">{{ $fmt($totalPaid) }}</td>
            </tr>
            <tr>
                <td>Remaining balance</td>
                <td class="amount remaining">{{ $fmt($remainingBalance) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Note</h2>
        <p class="note">
            This receipt acknowledges the payment recorded above for the rental of
            "{{ $property->title }}" ({{ $reservation->reference }}). It does not replace the
            rental contract {{ $contractReference ?? '—' }}, which is issued once for the whole
            rental period and is not re-generated for later payments. The amounts shown reflect
            the platform records at the moment this receipt was issued.
        </p>
    </div>

    <div class="footer">
        SAKANIA — Rental Management Platform &bull; Receipt {{ $reference }} &bull; Generated on {{ $paidAt->format('d/m/Y H:i') }}
    </div>
</body>
</html>
