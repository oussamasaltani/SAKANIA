@php
    use Carbon\Carbon;

    $fmt = fn ($value) => $value === null ? '—' : number_format((float) $value, 2) . ' MAD';
    $contractDate = $contract->signed_at ? Carbon::parse($contract->signed_at) : Carbon::now();
    $methodLabels = ['cash' => 'Cash', 'card' => 'Credit / Debit card', 'transfer' => 'Bank transfer'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rental Contract {{ $reference }}</title>
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
        .total-row td { border-top: 2px solid #374151; border-bottom: none; font-size: 12px; }
        .remaining { color: #b91c1c; }
        .paid { color: #047857; }
        .terms { color: #4b5563; }
        .terms li { margin-bottom: 3px; }
        .signatures { width: 100%; border-collapse: separate; border-spacing: 24px 0; margin-top: 8px; }
        .signatures td { width: 50%; text-align: center; vertical-align: bottom; }
        .sign-line { border-top: 1px solid #9ca3af; margin-top: 46px; padding-top: 4px; font-size: 9px; color: #6b7280; }
        .footer { margin-top: 14px; border-top: 1px solid #e5e7eb; padding-top: 8px; font-size: 8.5px; color: #9ca3af; text-align: center; }
        .muted { color: #6b7280; }
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
                    <div class="doc-title">Residential Rental Agreement</div>
                    <div class="doc-ref">Reference: {{ $reference }}</div>
                    <div class="doc-ref">Issued: {{ $contractDate->format('d/m/Y') }}</div>
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
                        <div class="party-role">The Owner (Lessor)</div>
                        <div class="party-name">{{ $owner->full_name }}</div>
                        <div class="party-line">Email: {{ $owner->email ?: '—' }}</div>
                        <div class="party-line">Phone: {{ $owner->phone ?: '—' }}</div>
                        @if ($owner->company_name)
                            <div class="party-line">Company: {{ $owner->company_name }}</div>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="party-box">
                        <div class="party-role">The Tenant (Lessee)</div>
                        <div class="party-name">{{ $tenant->full_name }}</div>
                        <div class="party-line">Email: {{ $tenant->email ?: '—' }}</div>
                        <div class="party-line">Phone: {{ $tenant->phone ?: '—' }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Property</h2>
        <table class="grid">
            <tr>
                <td class="label">Title</td>
                <td>{{ $property->title }}</td>
            </tr>
            <tr>
                <td class="label">Address</td>
                <td>{{ $property->location ?: '—' }}</td>
            </tr>
            <tr>
                <td class="label">Type</td>
                <td>{{ ucfirst((string) $property->type) ?: '—' }}</td>
            </tr>
            <tr>
                <td class="label">Surface area</td>
                <td>{{ $property->area ? $property->area . ' m²' : '—' }}</td>
            </tr>
            <tr>
                <td class="label">Layout</td>
                <td>
                    {{ $property->rooms }} room(s) &bull; {{ $property->bathrooms }} bathroom(s)
                    @if ($property->floor) &bull; floor {{ $property->floor }} @endif
                    @if ($property->capacity) &bull; capacity {{ $property->capacity }} person(s) @endif
                </td>
            </tr>
            @if (! empty($property->amenities))
                <tr>
                    <td class="label">Amenities</td>
                    <td>{{ implode(', ', array_map('ucfirst', (array) $property->amenities)) }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="section">
        <h2>Reservation</h2>
        <table class="grid">
            <tr>
                <td class="label">Reservation reference</td>
                <td>{{ $reference }}</td>
            </tr>
            <tr>
                <td class="label">Rental start date</td>
                <td>{{ $reservation->start_date->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td class="label">Rental end date</td>
                <td>{{ $reservation->end_date->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td class="label">Duration</td>
                <td>{{ $durationMonths }} month(s)</td>
            </tr>
            <tr>
                <td class="label">Reservation status</td>
                <td>{{ ucfirst($reservation->status) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Financial terms</h2>
        <table class="amounts">
            <tr>
                <td>Monthly rent</td>
                <td class="amount">{{ $fmt($monthlyRate) }}</td>
            </tr>
            <tr>
                <td>Duration</td>
                <td class="amount">{{ $durationMonths }} month(s)</td>
            </tr>
            <tr class="total-row">
                <td>Total rental amount</td>
                <td class="amount">{{ $fmt($totalAmount) }}</td>
            </tr>
            <tr>
                <td>Amount paid</td>
                <td class="amount paid">{{ $fmt($paidAmount) }}</td>
            </tr>
            <tr>
                <td>Remaining balance</td>
                <td class="amount remaining">{{ $fmt($remainingBalance) }}</td>
            </tr>
        </table>

        <table class="grid" style="margin-top: 10px;">
            <tr>
                <td class="label">Payment status</td>
                <td>{{ $payment ? ucfirst($payment->status) : 'Pending' }}</td>
            </tr>
            <tr>
                <td class="label">Payment method</td>
                <td>{{ $payment ? ($methodLabels[$payment->method] ?? ucfirst($payment->method)) : '—' }}</td>
            </tr>
            <tr>
                <td class="label">Payment date</td>
                <td>{{ $payment && $payment->payment_date ? \Carbon\Carbon::parse($payment->payment_date)->format('d/m/Y') : '—' }}</td>
            </tr>
            <tr>
                <td class="label">Transaction reference</td>
                <td>{{ $payment->transaction_ref ?? '—' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Terms &amp; conditions</h2>
        <ol class="terms">
            <li>The tenant rents the property described above for the period stated in this agreement, from {{ $reservation->start_date->format('d/m/Y') }} to {{ $reservation->end_date->format('d/m/Y') }} ({{ $durationMonths }} month(s)).</li>
            <li>The total rental amount is the monthly rent multiplied by the duration recorded above.</li>
            <li>The payment details shown above reflect the payments registered for this reservation on the platform. The remaining balance is the amount still outstanding when this document was issued.</li>
            <li>Any matter not covered by the records above &mdash; such as deposit, utilities, maintenance, notice period or penalties &mdash; is not defined by this document and has to be agreed separately between the two parties.</li>
            <li>Both parties confirm that the identity, property, dates and amounts recorded above are the ones held on the platform for this agreement.</li>
        </ol>
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="sign-line">The Owner — {{ $owner->full_name }} — Date: ____ / ____ / ______</div>
            </td>
            <td>
                <div class="sign-line">The Tenant — {{ $tenant->full_name }} — Date: ____ / ____ / ______</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        SAKANIA — Rental Management Platform &bull; Contract {{ $reference }} &bull; Generated on {{ $contractDate->format('d/m/Y H:i') }}
    </div>
</body>
</html>
