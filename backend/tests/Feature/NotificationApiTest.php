<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ROLE = 2;

    private const TENANT_ROLE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Tenant', 'Owner', 'Admin'] as $index => $name) {
            \Illuminate\Support\Facades\DB::table('roles')->insert(['id' => $index + 1, 'name' => $name]);
        }
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

    private function makeProperty(User $owner, float $price = 6000.00): Property
    {
        return Property::create([
            'owner_id' => $owner->id,
            'title' => 'Test Apartment '.$owner->id,
            'location' => 'Casablanca',
            'type' => 'apartment',
            'area' => 80,
            'price_per_month' => $price,
            'status' => 'approved',
            'rooms' => 2,
            'bathrooms' => 1,
        ]);
    }

    private function notify(int $userId, string $title, string $type = 'reservation'): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $title.' body.',
            'type' => $type,
            'related_type' => Reservation::class,
            'related_id' => null,
            'is_read' => false,
        ]);
    }

    public function test_the_notification_endpoints_require_authentication(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications/unread/count')->assertStatus(401);
        $this->putJson('/api/notifications/1/read')->assertStatus(401);
        $this->putJson('/api/notifications/read-all')->assertStatus(401);
    }

    public function test_an_owner_and_a_tenant_only_ever_receive_their_own_notifications(): void
    {
        $owner = $this->makeUser('Owner Api', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Api', self::TENANT_ROLE);
        $stranger = $this->makeUser('Stranger Api', self::TENANT_ROLE);

        $this->notify($owner->id, 'New Reservation Request');
        $this->notify($owner->id, 'Payment Received', 'payment');
        $this->notify($tenant->id, 'Reservation Accepted');
        $this->notify($stranger->id, 'Contract Ready for Download', 'contract');

        Sanctum::actingAs($owner);
        $ownerList = $this->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonPath('total', 2);
        // Newest first.
        $this->assertSame(
            ['Payment Received', 'New Reservation Request'],
            array_column($ownerList->json('data'), 'title')
        );
        $this->getJson('/api/notifications/unread/count')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 2);

        Sanctum::actingAs($tenant);
        $this->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Reservation Accepted');
        $this->getJson('/api/notifications/unread/count')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 1);
    }

    public function test_notifications_are_newest_first_even_when_they_share_a_timestamp(): void
    {
        $owner = $this->makeUser('Owner Order', self::OWNER_ROLE);

        // Accepting a reservation writes two notifications inside the same
        // second, which is exactly the tie this ordering has to break.
        $first = $this->notify($owner->id, 'Reservation Accepted');
        $second = $this->notify($owner->id, 'Payment Required', 'payment');

        // created_at is a database default, so it has to be read back before
        // it can be copied onto the sibling row.
        $sharedTimestamp = $first->fresh()->created_at;
        $this->assertNotNull($sharedTimestamp);

        \Illuminate\Support\Facades\DB::table('notifications')
            ->where('id', $second->id)
            ->update(['created_at' => $sharedTimestamp]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Payment Required')
            ->assertJsonPath('data.1.title', 'Reservation Accepted');
    }

    public function test_a_user_cannot_read_or_clear_someone_elses_notifications(): void
    {
        $owner = $this->makeUser('Owner Guard', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Guard', self::TENANT_ROLE);

        $notification = $this->notify($owner->id, 'New Reservation Request');

        Sanctum::actingAs($tenant);
        $this->putJson("/api/notifications/{$notification->id}/read")->assertStatus(404);
        $this->putJson('/api/notifications/read-all')->assertStatus(200);

        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_marking_a_notification_as_read_lowers_the_unread_counter(): void
    {
        $tenant = $this->makeUser('Tenant Read', self::TENANT_ROLE);

        $first = $this->notify($tenant->id, 'Reservation Accepted');
        $this->notify($tenant->id, 'Payment Processed', 'payment');

        Sanctum::actingAs($tenant);
        $this->putJson("/api/notifications/{$first->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Notification marked as read.');
        $this->getJson('/api/notifications/unread/count')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 1);

        $this->putJson('/api/notifications/read-all')
            ->assertStatus(200)
            ->assertJsonPath('message', 'All notifications marked as read.');
        $this->getJson('/api/notifications/unread/count')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 0);
        $this->assertSame(
            [true, true],
            Notification::where('user_id', $tenant->id)->pluck('is_read')->map(fn ($v) => (bool) $v)->sort()->values()->all()
        );
    }

    public function test_a_tenant_cancelling_informs_the_property_owner(): void
    {
        $owner = $this->makeUser('Owner Cancel', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Cancel', self::TENANT_ROLE);
        $otherOwner = $this->makeUser('Owner Unrelated', self::OWNER_ROLE);
        $property = $this->makeProperty($owner);
        $start = now()->addMonth()->startOfMonth()->toDateString();

        $reservation = Reservation::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => $start,
            'end_date' => \Illuminate\Support\Carbon::parse($start)->addMonths(6)->toDateString(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($tenant);
        $this->putJson("/api/reservations/{$reservation->id}/cancel")->assertStatus(200);

        $this->assertSame(
            ['Reservation Cancelled'],
            Notification::where('user_id', $owner->id)->pluck('title')->all()
        );
        $this->assertDatabaseCount('notifications', 1);

        // Cancelling twice must not warn the owner twice.
        $this->putJson("/api/reservations/{$reservation->id}/cancel")->assertStatus(422);
        $this->assertDatabaseCount('notifications', 1);

        Sanctum::actingAs($otherOwner);
        $this->getJson('/api/notifications')->assertStatus(200)->assertJsonPath('total', 0);
    }
}