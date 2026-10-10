<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ROLE = 1;

    private const OWNER_ROLE = 2;

    private const ADMIN_ROLE = 3;

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

    private function makeProperty(User $owner, string $title = 'Casablanca Flat'): Property
    {
        return Property::create([
            'owner_id' => $owner->id,
            'title' => $title,
            'location' => 'Casablanca',
            'type' => 'apartment',
            'area' => 80,
            'price_per_month' => 6000.00,
            'status' => 'approved',
            'rooms' => 2,
            'bathrooms' => 1,
        ]);
    }

    private function makeReservation(User $tenant, Property $property): Reservation
    {
        $start = now()->addMonth()->startOfMonth()->toDateString();

        return Reservation::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => $start,
            'end_date' => \Illuminate\Support\Carbon::parse($start)->addMonths(6)->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function test_the_message_endpoints_require_authentication(): void
    {
        $this->getJson('/api/messages')->assertStatus(401);
        $this->getJson('/api/messages/unread/count')->assertStatus(401);
        $this->getJson('/api/messages/conversations/1')->assertStatus(401);
        $this->putJson('/api/messages/conversations/1/read')->assertStatus(401);
        $this->postJson('/api/messages', ['receiver_id' => 1, 'property_id' => 1, 'content' => 'hi'])->assertStatus(401);
    }

    public function test_a_tenant_opens_a_conversation_and_the_owner_replies(): void
    {
        $owner = $this->makeUser('Owner Thread', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Thread', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        // The first message creates the conversation, tied to the property.
        Sanctum::actingAs($tenant);
        $first = $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'Is the flat still available in March?',
        ])->assertStatus(201);

        $conversationId = $first->json('conversation_id');
        $this->assertNotNull($conversationId);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
            'property_id' => $property->id,
            'reservation_id' => null,
        ]);
        $this->assertFalse($first->json('message.is_read'));
        $this->assertSame($tenant->id, $first->json('message.sender_id'));
        $this->assertSame($owner->id, $first->json('message.receiver_id'));

        // The owner sees it in the thread list, with one unread message.
        Sanctum::actingAs($owner);
        $list = $this->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.counterpart.id', $tenant->id)
            ->assertJsonPath('data.0.counterpart.role', 'tenant')
            ->assertJsonPath('data.0.property.id', $property->id);
        $this->assertSame('Is the flat still available in March?', $list->json('data.0.last_message.content'));

        $this->getJson('/api/messages/unread/count')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 1);

        // The owner replies inside the same conversation.
        $this->postJson('/api/messages', [
            'conversation_id' => $conversationId,
            'content' => 'Yes it is, feel free to book it.',
        ])->assertStatus(201);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);

        // Both sides read the same thread, oldest first.
        Sanctum::actingAs($tenant);
        $thread = $this->getJson("/api/messages/conversations/{$conversationId}")
            ->assertStatus(200)
            ->assertJsonPath('conversation.id', $conversationId);
        $this->assertSame(
            ['Is the flat still available in March?', 'Yes it is, feel free to book it.'],
            array_column($thread->json('messages'), 'content')
        );
        $this->assertSame($owner->id, $thread->json('messages.0.receiver_id'));
        $this->assertSame($tenant->id, $thread->json('messages.1.receiver_id'));

        // Opening the thread marked the owner's message read on the tenant side.
        $this->assertTrue($thread->json('messages.1.is_read'));
        $this->assertNotNull($thread->json('messages.1.read_at'));
        // ...but the tenant's own message stays untouched.
        $this->assertFalse($thread->json('messages.0.is_read'));
        $this->getJson('/api/messages/unread/count')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 0);
    }

    public function test_a_second_message_reuses_the_conversation_and_only_one_thread_is_listed(): void
    {
        $owner = $this->makeUser('Owner Reuse', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Reuse', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'First question',
        ])->assertStatus(201);

        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'Second question',
        ])->assertStatus(201);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);

        // The sender has nothing unread in the thread they wrote into.
        $this->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unread_count', 0)
            ->assertJsonPath('data.0.last_message.content', 'Second question');

        // The owner has both messages waiting.
        Sanctum::actingAs($owner);
        $this->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unread_count', 2);
    }

    public function test_each_property_gets_its_own_conversation(): void
    {
        $owner = $this->makeUser('Owner Two Props', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Two Props', self::TENANT_ROLE);
        $first = $this->makeProperty($owner, 'First Flat');
        $second = $this->makeProperty($owner, 'Second Flat');

        Sanctum::actingAs($tenant);
        $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $first->id, 'content' => 'About the first'])->assertStatus(201);
        $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $second->id, 'content' => 'About the second'])->assertStatus(201);

        $this->assertDatabaseCount('conversations', 2);
        $this->getJson('/api/messages')->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_a_conversation_can_be_tied_to_a_reservation(): void
    {
        $owner = $this->makeUser('Owner Reserved', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Reserved', self::TENANT_ROLE);
        $other = $this->makeUser('Tenant Other', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $reservation = $this->makeReservation($tenant, $property);

        Sanctum::actingAs($tenant);
        $response = $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'content' => 'About my booking',
        ])->assertStatus(201);

        $conversationId = $response->json('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'reservation_id' => $reservation->id]);

        $this->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonPath('data.0.reservation.id', $reservation->id)
            ->assertJsonPath('data.0.reservation.reference', $reservation->reference);

        // A reservation belonging to somebody else cannot be attached.
        $foreignReservation = $this->makeReservation($other, $this->makeProperty($this->makeUser('Owner Foreign', self::OWNER_ROLE)));
        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'reservation_id' => $foreignReservation->id,
            'content' => 'Hijack',
        ])->assertStatus(404);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_messages_never_touch_reservation_or_contract_state(): void
    {
        $owner = $this->makeUser('Owner Neutral', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Neutral', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $reservation = $this->makeReservation($tenant, $property);

        $before = $reservation->fresh();

        Sanctum::actingAs($tenant);
        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'content' => 'Just a question',
        ])->assertStatus(201);

        $after = $reservation->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->start_date->toDateString(), $after->start_date->toDateString());

        // Messaging writes nothing into the notification system either.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_users_cannot_reach_a_conversation_that_is_not_theirs(): void
    {
        $owner = $this->makeUser('Owner Private', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Private', self::TENANT_ROLE);
        $intruder = $this->makeUser('Tenant Intruder', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);
        $conversationId = $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'Private note',
        ])->json('conversation_id');

        // The intruder is not a party: not in the list, and no read access.
        Sanctum::actingAs($intruder);
        $this->getJson('/api/messages')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson("/api/messages/conversations/{$conversationId}")->assertStatus(404);
        $this->putJson("/api/messages/conversations/{$conversationId}/read")->assertStatus(404);
        $this->postJson('/api/messages', [
            'conversation_id' => $conversationId,
            'content' => 'Let me in',
        ])->assertStatus(404);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_an_owner_only_sees_conversations_about_their_own_properties(): void
    {
        $owner = $this->makeUser('Owner Scoped', self::OWNER_ROLE);
        $rival = $this->makeUser('Owner Rival', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Scoped', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);
        $rivalProperty = $this->makeProperty($rival, 'Rival Flat');

        Sanctum::actingAs($tenant);
        $mine = $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $property->id, 'content' => 'Hi owner'])->json('conversation_id');
        $theirs = $this->postJson('/api/messages', ['receiver_id' => $rival->id, 'property_id' => $rivalProperty->id, 'content' => 'Hi rival'])->json('conversation_id');

        Sanctum::actingAs($owner);
        $this->getJson('/api/messages')->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->getJson("/api/messages/conversations/{$theirs}")->assertStatus(404);
        $this->getJson("/api/messages/conversations/{$mine}")->assertStatus(200);
    }

    public function test_a_tenant_cannot_message_someone_who_does_not_own_the_property(): void
    {
        $owner = $this->makeUser('Owner Legit', self::OWNER_ROLE);
        $stranger = $this->makeUser('Owner Stranger', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Guess', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);

        // The receiver has to be the owner of the property being asked about.
        $this->postJson('/api/messages', [
            'receiver_id' => $stranger->id,
            'property_id' => $property->id,
            'content' => 'Hello stranger',
        ])->assertStatus(403);

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_tenant_cannot_message_themselves_or_another_tenant(): void
    {
        $owner = $this->makeUser('Owner Guarded', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Guarded', self::TENANT_ROLE);
        $otherTenant = $this->makeUser('Tenant Peer', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);

        // Messaging another tenant is not a tenant-owner conversation.
        $this->postJson('/api/messages', [
            'receiver_id' => $otherTenant->id,
            'property_id' => $property->id,
            'content' => 'Peer to peer',
        ])->assertStatus(403);

        // Messaging yourself is refused even if you own the property.
        $ownProperty = $this->makeProperty($tenant, 'Tenant Owned');
        $this->postJson('/api/messages', [
            'receiver_id' => $tenant->id,
            'property_id' => $ownProperty->id,
            'content' => 'Note to self',
        ])->assertStatus(403);

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_an_owner_cannot_open_a_conversation_but_can_answer_one(): void
    {
        $owner = $this->makeUser('Owner Cold', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Cold', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($owner);
        $this->postJson('/api/messages', [
            'receiver_id' => $tenant->id,
            'property_id' => $property->id,
            'content' => 'Uninvited first move',
        ])->assertStatus(403);

        Sanctum::actingAs($tenant);
        $conversationId = $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'Hello',
        ])->json('conversation_id');

        Sanctum::actingAs($owner);
        $this->postJson('/api/messages', [
            'conversation_id' => $conversationId,
            'content' => 'Hello back',
        ])->assertStatus(201);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_an_inactive_recipient_cannot_be_messaged(): void
    {
        $owner = $this->makeUser('Owner Inactive', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Inactive', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        $owner->update(['is_active' => false]);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'Are you there?',
        ])->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_message_content_is_validated(): void
    {
        $owner = $this->makeUser('Owner Valid', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Valid', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);

        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => '',
        ])->assertStatus(422);

        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => '   ',
        ])->assertStatus(422);

        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => str_repeat('a', 5001),
        ])->assertStatus(422);

        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);

        // A message that is only whitespace around real text is trimmed, not lost.
        $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => '  Hello there  ',
        ])->assertStatus(201);

        $this->assertSame('Hello there', Message::first()->content);
    }

    public function test_marking_a_conversation_read_clears_only_that_thread(): void
    {
        $owner = $this->makeUser('Owner Mark', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Mark', self::TENANT_ROLE);
        $first = $this->makeProperty($owner, 'Mark One');
        $second = $this->makeProperty($owner, 'Mark Two');

        Sanctum::actingAs($tenant);
        $one = $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $first->id, 'content' => 'One'])->json('conversation_id');
        $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $second->id, 'content' => 'Two']);
        $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $first->id, 'content' => 'One again']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/messages/unread/count')->assertStatus(200)->assertJsonPath('unread_count', 3);

        $this->putJson("/api/messages/conversations/{$one}/read")->assertStatus(200);

        // Only the first thread was cleared, and its messages now carry read_at.
        $this->getJson('/api/messages/unread/count')->assertStatus(200)->assertJsonPath('unread_count', 1);
        $this->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonPath('data.0.unread_count', 1);

        $readMessages = Message::where('conversation_id', $one)->get();
        $this->assertCount(2, $readMessages);
        $this->assertTrue($readMessages->every(fn (Message $m) => (bool) $m->is_read));
        $this->assertTrue($readMessages->every(fn (Message $m) => $m->read_at !== null));
    }

    public function test_the_conversation_list_is_ordered_by_recent_activity(): void
    {
        $owner = $this->makeUser('Owner Order', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Order', self::TENANT_ROLE);
        $first = $this->makeProperty($owner, 'Order One');
        $second = $this->makeProperty($owner, 'Order Two');

        Sanctum::actingAs($tenant);
        $oldest = $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $first->id, 'content' => 'Older'])->json('conversation_id');
        $newest = $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $second->id, 'content' => 'Newer'])->json('conversation_id');

        $this->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $newest)
            ->assertJsonPath('data.1.id', $oldest);
    }

    public function test_the_messages_of_a_conversation_keep_their_order_within_a_single_second(): void
    {
        $owner = $this->makeUser('Owner SameSecond', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant SameSecond', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);
        $conversationId = $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'First',
        ])->json('conversation_id');

        $this->postJson('/api/messages', ['conversation_id' => $conversationId, 'content' => 'Second']);

        $contents = $this->getJson("/api/messages/conversations/{$conversationId}")
            ->assertStatus(200)
            ->json('messages');

        $this->assertSame(['First', 'Second'], array_column($contents, 'content'));
    }

    public function test_deleting_a_property_removes_its_conversations(): void
    {
        $owner = $this->makeUser('Owner Cleanup', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Cleanup', self::TENANT_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/messages', ['receiver_id' => $owner->id, 'property_id' => $property->id, 'content' => 'Hi'])->assertStatus(201);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);

        $property->delete();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_an_admin_is_not_a_conversation_participant(): void
    {
        $owner = $this->makeUser('Owner Admin', self::OWNER_ROLE);
        $tenant = $this->makeUser('Tenant Admin', self::TENANT_ROLE);
        $admin = $this->makeUser('Admin Reader', self::ADMIN_ROLE);
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($tenant);
        $conversationId = $this->postJson('/api/messages', [
            'receiver_id' => $owner->id,
            'property_id' => $property->id,
            'content' => 'Private',
        ])->json('conversation_id');

        Sanctum::actingAs($admin);
        $this->getJson('/api/messages')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson("/api/messages/conversations/{$conversationId}")->assertStatus(404);
    }
}