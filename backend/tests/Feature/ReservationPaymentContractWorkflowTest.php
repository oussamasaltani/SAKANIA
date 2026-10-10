<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ContractService;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationPaymentContractWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ROLE = 2;

    private const TENANT_ROLE = 1;

    private const STRANGER_ROLE = 1;

    /** @var array<int, string> */
    private array $generatedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Keep every generated contract PDF inside a throwaway disk so the
        // suite never reads, overwrites or deletes a real private document.
        Storage::fake(ContractService::DISK);

        // The role identifiers are hard coded in the role middlewares
        // (tenant = 1, owner = 2, admin = 3).
        foreach (['Tenant', 'Owner', 'Admin'] as $index => $name) {
            \Illuminate\Support\Facades\DB::table('roles')->insert(['id' => $index + 1, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $file) {
            if (is_file($file)) {
                File::delete($file);
            }
        }

        $this->generatedFiles = [];

        parent::tearDown();
    }

    /**
     * Absolute path of a contract PDF in private storage.
     */
    private function contractFilePath(Contract $contract): string
    {
        return Storage::disk(ContractService::DISK)->path(
            app(ContractService::class)->storagePath($contract)
        );
    }

    private function makeUser(string $name, int $roleId): User
    {
        return User::create([
            'full_name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'password_hash' => bcrypt('secret1234'),
            'role_id' => $roleId,
            'is_active' => true,
        ]);
    }

    private function makeProperty(User $owner, float $price = 6000.00, string $status = 'approved'): Property
    {
        return Property::create([
            'owner_id' => $owner->id,
            'title' => 'Test Apartment '.$owner->id,
            'location' => 'Casablanca',
            'type' => 'apartment',
            'area' => 80,
            'price_per_month' => $price,
            'status' => $status,
            'rooms' => 2,
            'bathrooms' => 1,
        ]);
    }

    private function createReservation(User $tenant, Property $property, string $start, int $months = 6): Reservation
    {
        $startDate = \Illuminate\Support\Carbon::parse($start);

        return Reservation::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->copy()->addMonths($months)->toDateString(),
            'status' => 'pending',
        ]);
    }

    private function notificationTitles(int $userId): array
    {
        return Notification::where('user_id', $userId)->pluck('title')->all();
    }

    public function test_complete_workflow_from_request_to_downloadable_contract(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant One', self::TENANT_ROLE);
        $stranger = $this->makeUser('Stranger One', self::STRANGER_ROLE);
        $otherOwner = $this->makeUser('Owner One Extra', self::OWNER_ROLE);
        $property = $this->makeProperty($owner, 6000.00);
        $start = now()->addMonth()->startOfMonth()->toDateString();

        // 1. The tenant requests the property and the owner is notified.
        Sanctum::actingAs($tenant);
        $response = $this->postJson('/api/reservations', [
            'property_id' => $property->id,
            'start_date' => $start,
            'duration_months' => 6,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('reservation.status', 'pending')
            ->assertJsonPath('reservation.tenant_id', $tenant->id)
            ->assertJsonPath('reservation.property_id', $property->id);

        $reservation = Reservation::firstOrFail();
        $this->assertSame(6, $reservation->durationInMonths());
        $this->assertSame(36000.00, $reservation->totalAmount());
        $this->assertSame('RES-'.str_pad((string) $reservation->id, 5, '0', STR_PAD_LEFT), $reservation->reference);
        $this->assertContains('New Reservation Request', $this->notificationTitles($owner->id));
        $this->assertNotContains('New Reservation Request', $this->notificationTitles($tenant->id));

        // 2. Nobody can pay before the owner accepted.
        $this->getJson("/api/payments/checkout/{$reservation->id}")
            ->assertStatus(422);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 36000,
            'method' => 'card',
        ])->assertStatus(422);
        $this->assertDatabaseCount('payments', 0);

        // 3. Only the owner of the property can decide, and only once.
        Sanctum::actingAs($otherOwner);
        $this->putJson("/api/owner/reservations/{$reservation->id}/accept")->assertStatus(404);

        Sanctum::actingAs($stranger);
        $this->putJson("/api/owner/reservations/{$reservation->id}/accept")->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->putJson("/api/owner/reservations/{$reservation->id}/accept")
            ->assertStatus(200)
            ->assertJsonPath('reservation.status', 'accepted');

        $this->assertContains('Reservation Accepted', $this->notificationTitles($tenant->id));
        $this->assertContains('Payment Required', $this->notificationTitles($tenant->id));

        $this->putJson("/api/owner/reservations/{$reservation->id}/accept")->assertStatus(422);
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('title', 'Reservation Accepted')->count());

        // 4. The tenant pays, but not more than the server calculates.
        Sanctum::actingAs($tenant);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 36000.01,
            'method' => 'card',
        ])->assertStatus(422);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 0,
            'method' => 'card',
        ])->assertStatus(422);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 100,
            'method' => 'bitcoin',
        ])->assertStatus(422);
        $this->assertDatabaseCount('payments', 0);

        // 5. The first payment issues the single rental contract straight away.
        $response = $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 16000,
            'method' => 'card',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.paid_amount', '16000.00')
            ->assertJsonPath('payment.remaining_balance', '20000.00');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('contracts', 1);

        $contract = Contract::firstOrFail();
        $this->generatedFiles[] = $this->contractFilePath($contract);

        $this->assertSame('active', $contract->status);
        $this->assertNotNull($contract->file_url);
        $this->assertTrue($contract->isDownloadable());
        $this->assertStringStartsWith('%PDF', (string) File::get($this->contractFilePath($contract)));
        $this->assertSame($owner->id, (int) $contract->owner_id);
        $this->assertSame($tenant->id, (int) $contract->tenant_id);
        $this->assertSame($property->id, (int) $contract->property->id);
        $this->assertSame('pending', $contract->payment_status);

        // The contract already carries the amounts agreed when it was issued.
        $response->assertJsonPath('contract.id', $contract->id)
            ->assertJsonPath('contract.total_amount', '36000.00')
            ->assertJsonPath('contract.paid_amount', '16000.00')
            ->assertJsonPath('contract.remaining_balance', '20000.00');

        // And the payment is acknowledged by a receipt.
        $payment = Payment::firstOrFail();
        $response->assertJsonPath('receipt.paid_amount', '16000.00')
            ->assertJsonPath('receipt.remaining_balance', '20000.00')
            ->assertJsonPath('receipt.reference', 'RC-'.str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT));

        $contractFingerprint = md5_file($this->contractFilePath($contract));

        // 6. Settling the balance issues no second contract, only a receipt.
        $response = $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 20000,
            'method' => 'transfer',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('payment.status', 'paid')
            ->assertJsonPath('payment.paid_amount', '36000.00')
            ->assertJsonPath('payment.remaining_balance', '0.00')
            ->assertJsonPath('contract', null)
            ->assertJsonPath('receipt.paid_amount', '36000.00')
            ->assertJsonPath('receipt.remaining_balance', '0.00');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('contracts', 1);

        $payment = $payment->fresh();
        $this->assertSame('paid', $payment->status);
        $this->assertEquals(36000.0, (float) $payment->paid_amount);
        $this->assertEquals(0.0, (float) $payment->remaining_balance);
        $this->assertEquals(36000.0, (float) $payment->total_amount);
        $this->assertNotEmpty($payment->transaction_ref);

        // The contract issued on the first payment was never re-generated.
        $this->assertSame($contractFingerprint, md5_file($this->contractFilePath($contract->fresh())));

        // 7. Both parties are told, exactly once, and the payment is closed.
        $this->assertContains('Payment Received', $this->notificationTitles($owner->id));
        $this->assertContains('Payment Processed', $this->notificationTitles($tenant->id));
        $this->assertContains('Contract Ready for Download', $this->notificationTitles($owner->id));
        $this->assertContains('Contract Ready for Download', $this->notificationTitles($tenant->id));
        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('title', 'Contract Ready for Download')->count());
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('title', 'Contract Ready for Download')->count());

        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 100,
            'method' => 'card',
        ])->assertStatus(422);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('contracts', 1);

        // 8. The contract is serialisable (no circular relation recursion).
        $this->assertIsArray($contract->fresh()->toArray());
        $this->assertIsArray($reservation->fresh()->load('contract')->toArray());

        // 9. Only the two parties can download the PDF.
        Sanctum::actingAs($tenant);
        $this->get("/api/contracts/{$contract->id}/download")->assertStatus(200);

        Sanctum::actingAs($owner);
        $this->get("/api/contracts/{$contract->id}/download")->assertStatus(200);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/contracts/{$contract->id}/download")->assertStatus(403);

        // No token at all: the route is closed to anonymous callers.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/contracts/{$contract->id}/download")->assertStatus(401);
    }

    /**
     * The contract PDF is a private document: it must never live under the
     * public web root, and the guarded endpoint must stay the only way to
     * obtain it.
     */
    public function test_contract_pdf_is_stored_outside_the_public_web_root(): void
    {
        $owner = $this->makeUser('Owner Private', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Private', self::TENANT_ROLE);
        $stranger = $this->makeUser('Stranger Private', self::STRANGER_ROLE);
        $property = $this->makeProperty($owner, 3000.00);
        $reservation = $this->createReservation($tenant, $property, now()->addMonth()->startOfMonth()->toDateString(), 3);
        $reservation->update(['status' => 'accepted']);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 9000,
            'method' => 'card',
        ])->assertStatus(200);

        $contract = Contract::firstOrFail();
        $this->generatedFiles[] = $this->contractFilePath($contract);

        // The stored reference is a private path, not a public URL.
        $this->assertSame('contracts/contract-'.$reservation->id.'.pdf', $contract->file_url);
        $this->assertFalse(str_starts_with($contract->file_url, 'uploads/'));
        $this->assertFileDoesNotExist(public_path('uploads/contracts/'.basename($contract->file_url)));

        // The file itself resolves onto the private disk, outside public/.
        $storedPath = str_replace('\\', '/', $this->contractFilePath($contract));
        $this->assertFileExists($this->contractFilePath($contract));
        $this->assertStringContainsString('/contracts/', $storedPath);
        $this->assertStringNotContainsString('/public', $storedPath);
        $this->assertTrue($contract->isDownloadable());

        // The endpoint is the only way in, and it enforces authorization.
        $this->get("/api/contracts/{$contract->id}/download")->assertStatus(200);

        Sanctum::actingAs($owner);
        $this->get("/api/contracts/{$contract->id}/download")->assertStatus(200);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/contracts/{$contract->id}/download")->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/contracts/{$contract->id}/download")->assertStatus(401);
    }

    /**
     * A contract file sitting on the private disk must never be streamed to an
     * anonymous caller, whatever route happens to answer the path.
     */
    public function test_private_storage_route_refuses_unsigned_access(): void
    {
        $response = $this->get('/storage/contracts/contract-2.pdf');

        $this->assertNotSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
    }

    public function test_duplicate_and_overlapping_requests_are_refused(): void
    {
        $owner = $this->makeUser('Owner Two', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Two', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $start = now()->addMonth()->startOfMonth()->toDateString();

        Sanctum::actingAs($tenant);
        $this->postJson('/api/reservations', [
            'property_id' => $property->id,
            'start_date' => $start,
            'duration_months' => 6,
        ])->assertStatus(201);

        $payload = [
            'property_id' => $property->id,
            'start_date' => $start,
            'duration_months' => 6,
        ];

        $this->postJson('/api/reservations', $payload)->assertStatus(422);
        $this->postJson('/api/reservations', [
            'property_id' => $property->id,
            'start_date' => now()->addMonths(3)->startOfMonth()->toDateString(),
            'duration_months' => 6,
        ])->assertStatus(422);
        $this->assertDatabaseCount('reservations', 1);

        // Unapproved properties cannot be booked, and past dates are refused.
        $pending = $this->makeProperty($owner, 5000, 'pending');
        $this->postJson('/api/reservations', [
            'property_id' => $pending->id,
            'start_date' => now()->addYear()->toDateString(),
            'duration_months' => 3,
        ])->assertStatus(422);

        $this->postJson('/api/reservations', [
            'property_id' => $property->id,
            'start_date' => now()->subMonth()->toDateString(),
            'duration_months' => 3,
        ])->assertStatus(422);
    }

    public function test_second_overlapping_booking_cannot_be_accepted(): void
    {
        $owner = $this->makeUser('Owner Three', self::OWNER_ROLE);
        $first = $this->makeUser('Tenant Three', self::TENANT_ROLE);
        $second = $this->makeUser('Tenant Four', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $start = now()->addMonth()->startOfMonth()->toDateString();

        $accepted = $this->createReservation($first, $property, $start, 6);
        $overlapping = $this->createReservation($second, $property, $start, 3);

        Sanctum::actingAs($owner);
        $this->putJson("/api/owner/reservations/{$accepted->id}/accept")->assertStatus(200);
        $this->putJson("/api/owner/reservations/{$overlapping->id}/accept")->assertStatus(422);

        $this->assertSame('pending', $overlapping->fresh()->status);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_rejected_and_cancelled_reservations_never_reach_payment(): void
    {
        $owner = $this->makeUser('Owner Four', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Five', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $start = now()->addMonth()->startOfMonth()->toDateString();

        $rejected = $this->createReservation($tenant, $property, $start, 6);
        $cancelled = $this->createReservation($tenant, $property, \Illuminate\Support\Carbon::parse($start)->addMonths(8)->toDateString(), 3);

        Sanctum::actingAs($owner);
        $this->putJson("/api/owner/reservations/{$rejected->id}/reject")->assertStatus(200);
        $this->putJson("/api/owner/reservations/{$rejected->id}/reject")->assertStatus(422);
        $this->assertContains('Reservation Rejected', $this->notificationTitles($tenant->id));

        Sanctum::actingAs($tenant);
        $this->putJson("/api/reservations/{$cancelled->id}/cancel")->assertStatus(200);
        $this->putJson("/api/reservations/{$cancelled->id}/cancel")->assertStatus(422);

        foreach ([$rejected, $cancelled] as $reservation) {
            $this->getJson("/api/payments/checkout/{$reservation->id}")->assertStatus(422);
            $this->postJson('/api/payments/process', [
                'reservation_id' => $reservation->id,
                'amount' => 18000,
                'method' => 'card',
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_a_tenant_cannot_cancel_or_pay_for_someone_else(): void
    {
        $owner = $this->makeUser('Owner Five', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Six', self::TENANT_ROLE);
        $other = $this->makeUser('Tenant Seven', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $reservation = $this->createReservation($tenant, $property, now()->addMonth()->startOfMonth()->toDateString(), 6);

        Sanctum::actingAs($other);
        $this->putJson("/api/reservations/{$reservation->id}/cancel")->assertStatus(404);
        $this->getJson("/api/payments/checkout/{$reservation->id}")->assertStatus(404);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 100,
            'method' => 'card',
        ])->assertStatus(404);

        // Owners are not tenants, so the tenant-only routes stay closed to them.
        Sanctum::actingAs($owner);
        $this->putJson("/api/reservations/{$reservation->id}/cancel")->assertStatus(403);

        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_owner_and_tenant_cannot_resolve_their_own_properties(): void
    {
        $owner = $this->makeUser('Owner Six', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Eight', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $start = now()->addMonth()->startOfMonth()->toDateString();

        Sanctum::actingAs($tenant);
        $this->postJson('/api/reservations', [
            'property_id' => $property->id,
            'start_date' => $start,
            'duration_months' => 6,
        ])->assertStatus(201);
        $reservation = Reservation::firstOrFail();

        Sanctum::actingAs($owner);
        $this->postJson('/api/reservations', [
            'property_id' => $property->id,
            'start_date' => $start,
            'duration_months' => 6,
        ])->assertStatus(403);

        Sanctum::actingAs($tenant);
        $this->putJson("/api/owner/reservations/{$reservation->id}/accept")->assertStatus(403);
    }

    public function test_a_missing_contract_file_is_regenerated_on_download(): void
    {
        $owner = $this->makeUser('Owner Seven', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Nine', self::TENANT_ROLE);
        $property = $this->makeProperty($owner, 4000);
        $reservation = $this->createReservation($tenant, $property, now()->addMonth()->startOfMonth()->toDateString(), 3);
        $reservation->update(['status' => 'accepted']);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 12000,
            'method' => 'card',
        ])->assertStatus(200);

        $contract = Contract::firstOrFail();
        $this->generatedFiles[] = $this->contractFilePath($contract);
        $this->assertTrue($contract->isDownloadable());

        File::delete($this->contractFilePath($contract));
        $this->assertFalse($contract->fresh()->isDownloadable());

        $this->get("/api/contracts/{$contract->id}/download")->assertStatus(200);
        $this->assertTrue($contract->fresh()->isDownloadable());
        $this->assertStringStartsWith('%PDF', (string) File::get($this->contractFilePath($contract->fresh())));
    }

    /**
     * The whole rental: one contract on the first payment, then a receipt for
     * every following month and never a second contract.
     */
    public function test_the_contract_is_issued_once_then_only_receipts_follow(): void
    {
        $owner = $this->makeUser('Owner Ten', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Ten', self::TENANT_ROLE);
        $admin = $this->makeUser('Admin Ten', self::STRANGER_ROLE + 2);
        $strangerTenant = $this->makeUser('Tenant Eleven', self::STRANGER_ROLE);
        $strangerOwner = $this->makeUser('Owner Eleven', self::OWNER_ROLE);
        $property = $this->makeProperty($owner, 6000.00);
        $reservation = $this->createReservation($tenant, $property, now()->addMonth()->startOfMonth()->toDateString(), 6);
        $reservation->update(['status' => 'accepted']);

        $monthly = 6000.00;
        $total = 36000.00;
        $this->assertSame($total, $reservation->totalAmount());

        Sanctum::actingAs($tenant);

        $contract = null;
        $fingerprint = null;

        for ($month = 1; $month <= 6; $month++) {
            $expectedPaid = $monthly * $month;
            $expectedRemaining = $total - $expectedPaid;

            $response = $this->postJson('/api/payments/process', [
                'reservation_id' => $reservation->id,
                'amount' => $monthly,
                'method' => 'card',
            ]);

            $response->assertStatus(200)
                ->assertJsonPath('payment.amount', number_format($monthly, 2, '.', ''))
                ->assertJsonPath('payment.paid_amount', number_format($expectedPaid, 2, '.', ''))
                ->assertJsonPath('payment.remaining_balance', number_format($expectedRemaining, 2, '.', ''))
                ->assertJsonPath('payment.status', $month === 6 ? 'paid' : 'pending')
                ->assertJsonPath('receipt.amount', number_format($monthly, 2, '.', ''))
                ->assertJsonPath('receipt.paid_amount', number_format($expectedPaid, 2, '.', ''))
                ->assertJsonPath('receipt.remaining_balance', number_format($expectedRemaining, 2, '.', ''));

            // Exactly one contract for the reservation, whatever the month.
            $this->assertDatabaseCount('contracts', 1);
            $contract = Contract::firstOrFail();

            if ($month === 1) {
                // The first successful payment issues the contract.
                $response->assertJsonPath('contract.id', $contract->id)
                    ->assertJsonPath('contract.status', 'active')
                    ->assertJsonPath('contract.total_amount', number_format($total, 2, '.', ''))
                    ->assertJsonPath('contract.paid_amount', number_format($monthly, 2, '.', ''))
                    ->assertJsonPath('contract.remaining_balance', number_format($total - $monthly, 2, '.', ''));

                $this->generatedFiles[] = $this->contractFilePath($contract);
                $this->assertTrue($contract->isDownloadable());
                $fingerprint = md5_file($this->contractFilePath($contract));
            } else {
                // Every later month is acknowledged by a receipt, not a contract.
                $response->assertJsonPath('contract', null)
                    ->assertJsonPath('contract_error', null);
                $this->assertSame($fingerprint, md5_file($this->contractFilePath($contract)));
            }
        }

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('contracts', 1);

        $payment = Payment::firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertEquals(0.0, (float) $payment->remaining_balance);

        // A seventh payment has nothing left to settle.
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 6000,
            'method' => 'card',
        ])->assertStatus(422);
        $this->assertDatabaseCount('contracts', 1);

        // Both parties were told about the contract once, not once per month.
        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('title', 'Contract Ready for Download')->count());
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('title', 'Contract Ready for Download')->count());

        // The contract is still downloadable and still the very first one.
        $this->get("/api/contracts/{$contract->id}/download")->assertStatus(200);
        $this->assertSame($fingerprint, md5_file($this->contractFilePath($contract->fresh())));

        // The receipt is available to the payer, the landlord and the admin.
        foreach ([$tenant, $owner, $admin] as $allowed) {
            Sanctum::actingAs($allowed);
            $download = $this->get("/api/payments/{$payment->id}/receipt");

            $download->assertStatus(200);
            $download->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $download->getContent());
        }

        // The receipt download name follows the receipt reference.
        $this->assertSame(
            'sakania-receipt-RC-'.str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT).'.pdf',
            app(PaymentReceiptService::class)->downloadName($payment),
        );

        // Nobody else gets a look at the money of somebody else's rental.
        Sanctum::actingAs($strangerTenant);
        $this->getJson("/api/payments/{$payment->id}/receipt")->assertStatus(403);

        Sanctum::actingAs($strangerOwner);
        $this->getJson("/api/payments/{$payment->id}/receipt")->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/payments/{$payment->id}/receipt")->assertStatus(401);

        Sanctum::actingAs($owner);
        $this->getJson('/api/payments/99999/receipt')->assertStatus(404);
    }

    /**
     * The receipt ticket has to carry the payment facts the tenant needs.
     */
    public function test_the_receipt_ticket_carries_the_essential_payment_information(): void
    {
        $owner = $this->makeUser('Owner Twelve', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Twelve', self::TENANT_ROLE);
        $property = $this->makeProperty($owner, 4500.00);
        $reservation = $this->createReservation($tenant, $property, now()->addMonth()->startOfMonth()->toDateString(), 4);
        $reservation->update(['status' => 'accepted']);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 4500,
            'method' => 'transfer',
        ])->assertStatus(200);

        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 4500,
            'method' => 'transfer',
        ])->assertStatus(200);

        $payment = Payment::firstOrFail();
        $data = app(PaymentReceiptService::class)->viewData($payment);
        $html = View::make('payments.receipt', $data)->render();

        // Parties, property and reservation.
        $this->assertStringContainsString('Payment Receipt', $html);
        $this->assertStringContainsString('RC-'.str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT), $html);
        $this->assertStringContainsString('Tenant Twelve', $html);
        $this->assertStringContainsString('Owner Twelve', $html);
        $this->assertStringContainsString($property->title, $html);
        $this->assertStringContainsString($property->location, $html);
        $this->assertStringContainsString($reservation->reference, $html);
        $this->assertStringContainsString($contract = Contract::firstOrFail()->reference, $html);

        // Payment information and the running amounts.
        $this->assertStringContainsString('Bank transfer', $html);
        $this->assertStringContainsString($payment->transaction_ref, $html);
        $this->assertStringContainsString('Amount paid with this receipt', $html);
        $this->assertStringContainsString('4,500.00 MAD', $html);
        $this->assertStringContainsString('Total rental amount', $html);
        $this->assertStringContainsString('18,000.00 MAD', $html);
        $this->assertStringContainsString('Total paid to date', $html);
        $this->assertStringContainsString('9,000.00 MAD', $html);
        $this->assertStringContainsString('Remaining balance', $html);
        $this->assertStringContainsString('Partially paid', $html);

        // The receipt is a valid PDF on its own.
        $pdf = app(PaymentReceiptService::class)->pdf($payment);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }

    /**
     * A later payment never revives the contract: the single document stays
     * untouched, and only the download endpoint repairs a missing file.
     */
    public function test_a_later_payment_does_not_rebuild_the_single_contract(): void
    {
        $owner = $this->makeUser('Owner Thirteen', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Thirteen', self::TENANT_ROLE);
        $property = $this->makeProperty($owner, 3000.00);
        $reservation = $this->createReservation($tenant, $property, now()->addMonth()->startOfMonth()->toDateString(), 3);
        $reservation->update(['status' => 'accepted']);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 3000,
            'method' => 'cash',
        ])->assertStatus(200)
            ->assertJsonPath('contract.id', Contract::firstOrFail()->id);

        $contract = Contract::firstOrFail();
        $this->generatedFiles[] = $this->contractFilePath($contract);
        $path = $this->contractFilePath($contract);
        File::delete($path);

        $this->postJson('/api/payments/process', [
            'reservation_id' => $reservation->id,
            'amount' => 3000,
            'method' => 'cash',
        ])->assertStatus(200)
            ->assertJsonPath('contract', null)
            ->assertJsonPath('receipt.remaining_balance', '3000.00');

        $this->assertDatabaseCount('contracts', 1);
        $this->assertFileDoesNotExist($path);

        $this->get("/api/contracts/{$contract->id}/download")->assertStatus(200);
        $this->assertFileExists($path);
    }
}
