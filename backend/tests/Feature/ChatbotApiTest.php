<?php

namespace Tests\Feature;

use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\Contract;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Chatbot\DataProvider;
use App\Services\Chatbot\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The chatbot endpoint, with Gemini stubbed so the assertions are about the
 * SAKANIA behaviour: scope control, the data handed to the model, and what the
 * model is allowed to be told.
 */
class ChatbotApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const OWNER = 2;

    private const ADMIN = 3;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Tenant', 'Owner', 'Admin'] as $index => $name) {
            DB::table('roles')->insert(['id' => $index + 1, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
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

    private function makeProperty(User $owner, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'Tangier Flat',
            'location' => 'Tanger',
            'type' => 'apartment',
            'area' => 80,
            'price_per_month' => 3000,
            'status' => 'approved',
            'rooms' => 2,
            'bathrooms' => 1,
        ], $overrides));
    }

    private function makeReservation(User $tenant, Property $property, string $status = 'pending'): Reservation
    {
        $start = now()->addMonth()->startOfMonth()->toDateString();

        return Reservation::create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
            'start_date' => $start,
            'end_date' => Carbon::parse($start)->addMonths(3)->toDateString(),
            'status' => $status,
        ]);
    }

    /**
     * Replaces the Gemini wrapper with a canned intent / answer pair.
     *
     * @param  array<string, mixed>|null  $intentJson  What the classifier returns.
     * @param  string|null  $answer  What the composer returns.
     */
    private function fakeGemini(?array $intentJson = null, ?string $answer = 'Here is your answer.'): void
    {
        $gemini = Mockery::mock(GeminiService::class);

        $gemini->shouldReceive('generateJson')
            ->andReturn($intentJson ?? [
                'intent' => 'platform_workflow',
                'in_scope' => true,
                'needs_clarification' => false,
                'clarification' => null,
                'slots' => [],
            ]);

        if ($answer === null) {
            $gemini->shouldReceive('generate')->andReturn(null);
        } else {
            $gemini->shouldReceive('generate')->andReturn($answer);
        }

        $this->app->instance(GeminiService::class, $gemini);
    }

    public function test_the_gemini_wrapper_never_returns_a_failure_payload(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake([
            '*generativelanguage*' => Http::response([
                'error' => ['message' => 'API key not valid', 'code' => 400],
            ], 400),
        ]);

        $this->assertNull(app(GeminiService::class)->generate([['role' => 'user', 'text' => 'hi']]));
        $this->assertNull(app(GeminiService::class)->generateJson([['role' => 'user', 'text' => 'hi']], '{}'));
    }

    public function test_the_gemini_wrapper_returns_null_without_a_configured_key(): void
    {
        config(['services.gemini.key' => null]);

        Http::fake();

        $this->assertNull(app(GeminiService::class)->generate([['role' => 'user', 'text' => 'hi']]));
        Http::assertNothingSent();
    }

    public function test_the_gemini_wrapper_survives_a_network_failure(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(fn () => throw new ConnectionException('unreachable'));

        $this->assertNull(app(GeminiService::class)->generate([['role' => 'user', 'text' => 'hi']]));
    }

    public function test_the_gemini_wrapper_strips_markdown_fences_from_json(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake([
            '*generativelanguage*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => "```json\n{\"intent\": \"payment_status\", \"slots\": {\"max_price\": \"3500\"}}\n```",
                    ]]],
                ]],
            ]),
        ]);

        $decoded = app(GeminiService::class)->generateJson([['role' => 'user', 'text' => 'hi']], '{}');

        $this->assertSame('payment_status', $decoded['intent']);
    }

    public function test_a_system_instruction_turn_is_sent_separately_from_the_dialogue(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake([
            '*generativelanguage*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
            ]),
        ]);

        app(GeminiService::class)->generate([
            GeminiService::instruction('be helpful'),
            ['role' => 'user', 'text' => 'hello'],
            ['role' => 'model', 'text' => 'hi'],
            ['role' => 'user', 'text' => 'again'],
        ]);

        $sent = Http::recorded()[0][0]->data();

        $this->assertSame('be helpful', $sent['systemInstruction']['parts'][0]['text']);
        $this->assertCount(3, $sent['contents']);
        $this->assertSame('user', $sent['contents'][0]['role']);
        $this->assertSame('model', $sent['contents'][1]['role']);
        $this->assertSame('again', $sent['contents'][2]['parts'][0]['text']);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/chatbot/send', ['message' => 'hello'])->assertStatus(200);
    }

    public function test_it_persists_the_exchange_and_returns_a_conversation_id(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $this->fakeGemini();

        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'How does SAKANIA work?'])
            ->assertOk()
            ->assertJsonStructure(['reply', 'conversation_id', 'intent']);

        $conversationId = $response->json('conversation_id');

        $this->assertSame(2, ChatbotMessage::where('conversation_id', $conversationId)->count());
        $this->assertSame(
            ['user', 'bot'],
            ChatbotMessage::where('conversation_id', $conversationId)->orderBy('id')->pluck('sender_type')->all()
        );

        // The same conversation is reused on the next turn.
        $second = $this->postJson('/api/chatbot/send', [
            'message' => 'And what about contracts?',
            'conversation_id' => $conversationId,
        ])->assertOk();

        $this->assertSame($conversationId, $second->json('conversation_id'));
        $this->assertSame(1, ChatbotConversation::where('user_id', $tenant->id)->count());
    }

    public function test_a_conversation_belonging_to_another_user_is_never_continued(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $intruder = $this->makeUser('Tenant Two', self::TENANT);
        $this->fakeGemini();

        Sanctum::actingAs($tenant);
        $conversationId = $this->postJson('/api/chatbot/send', ['message' => 'Bghit appartement f Tanger'])
            ->json('conversation_id');

        Sanctum::actingAs($intruder);
        $response = $this->postJson('/api/chatbot/send', [
            'message' => 'Bghit appartement f Tanger',
            'conversation_id' => $conversationId,
        ])->assertOk();

        $this->assertNotSame($conversationId, $response->json('conversation_id'));
        $this->assertSame(1, $intruder->chatbotConversations()->count());
        $this->assertSame(1, $tenant->chatbotConversations()->count());
    }

    public function test_out_of_scope_questions_are_refused_without_querying_the_database(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        // The classifier must never be reached for an unrelated question.
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('generate');
        $gemini->shouldNotReceive('generateJson');
        $this->app->instance(GeminiService::class, $gemini);

        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'Write me a CV in Python.'])->assertOk();

        $this->assertSame('out_of_scope', $response->json('intent'));
        $this->assertStringContainsString('SAKANIA', $response->json('reply'));
        $this->assertStringNotContainsString('CV', $response->json('reply'));
    }

    public function test_a_clarification_question_runs_no_query_and_asks_for_the_missing_detail(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $this->fakeGemini([
            'intent' => 'property_search',
            'in_scope' => true,
            'needs_clarification' => true,
            'clarification' => 'Which city are you looking in?',
            'slots' => [],
        ]);

        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'Bghit appartement b 3000 DH'])->assertOk();

        $this->assertSame('Which city are you looking in?', $response->json('reply'));
    }

    public function test_a_property_search_reaches_the_model_with_real_property_rows_only(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $this->makeProperty($owner, ['title' => 'Tangier Sea View', 'price_per_month' => 2800]);
        $this->makeProperty($owner, ['title' => 'Tangier Luxury', 'price_per_month' => 9000]);
        $this->makeProperty($owner, ['title' => 'Tangier Pending', 'price_per_month' => 1500, 'status' => 'pending']);

        $captured = [];

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateJson')->andReturn([
            'intent' => 'property_search',
            'in_scope' => true,
            'needs_clarification' => false,
            'slots' => ['location' => 'Tanger', 'max_price' => 3000, 'property_type' => 'apartment'],
        ]);
        $gemini->shouldReceive('generate')->andReturnUsing(function (array $turns) use (&$captured) {
            $captured = $turns;

            return 'I found one matching property.';
        });
        $this->app->instance(GeminiService::class, $gemini);

        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'Appartement f Tanger b 3000 DH'])->assertOk();

        $this->assertSame('property_search', $response->json('intent'));

        // The verified-data turn itself, so the assertion is about the payload the
        // model actually receives rather than a re-encoding of it.
        $verifiedTurn = collect($captured)->last()['text'];

        $this->assertStringContainsString('Tangier Sea View', $verifiedTurn);
        $this->assertStringContainsString('2800', $verifiedTurn);
        $this->assertStringNotContainsString('Tangier Luxury', $verifiedTurn);
        $this->assertStringNotContainsString('Tangier Pending', $verifiedTurn);
        $this->assertStringContainsString(
            '/properties/'.Property::where('title', 'Tangier Sea View')->value('id'),
            $verifiedTurn
        );
    }

    public function test_the_property_query_honours_the_extracted_criteria(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $this->makeProperty($owner, ['title' => 'Tangier Sea View', 'price_per_month' => 2800]);
        $this->makeProperty($owner, ['title' => 'Tangier Luxury', 'price_per_month' => 9000]);
        $this->makeProperty($owner, ['title' => 'Casablanca Cheap', 'location' => 'Casablanca', 'price_per_month' => 1500]);
        $this->makeProperty($owner, ['title' => 'Tangier Hidden', 'price_per_month' => 2000, 'status' => 'pending']);

        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $provider = app(DataProvider::class);

        $result = $provider->searchProperties(['location' => 'Tanger', 'max_price' => 3000], $tenant);

        $this->assertSame('exact', $result['match_type']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('Tangier Sea View', $result['properties'][0]['title']);
        $this->assertSame(2800.0, $result['properties'][0]['price_per_month_mad']);
    }

    public function test_a_search_without_an_exact_match_reports_the_relaxed_criterion(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $this->makeProperty($owner, ['title' => 'Tangier Sea View', 'price_per_month' => 2800]);

        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $result = app(DataProvider::class)->searchProperties(
            ['location' => 'Tanger', 'max_price' => 1000],
            $tenant
        );

        $this->assertSame('relaxed', $result['match_type']);
        $this->assertContains('budget', $result['relaxed_criteria']);
        $this->assertSame('Tangier Sea View', $result['properties'][0]['title']);
    }

    public function test_a_search_with_no_match_at_all_returns_no_properties(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $this->makeProperty($owner, ['title' => 'Tangier Sea View', 'location' => 'Tanger']);

        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $result = app(DataProvider::class)->searchProperties(['location' => 'Paris'], $tenant);

        $this->assertSame('none', $result['match_type']);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['properties']);
    }

    public function test_a_property_already_reserved_for_the_period_is_excluded(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $taken = $this->makeProperty($owner, ['title' => 'Tangier Taken']);
        $free = $this->makeProperty($owner, ['title' => 'Tangier Free']);

        $start = now()->addMonth()->startOfMonth();

        foreach ([$taken, $free] as $property) {
            Reservation::create([
                'property_id' => $property->id,
                'tenant_id' => $tenant->id,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addYear()->toDateString(),
                'status' => 'accepted',
            ]);
        }

        $other = $this->makeUser('Tenant Two', self::TENANT);
        $otherProperty = $this->makeProperty($owner, ['title' => 'Tangier Other']);

        $result = app(DataProvider::class)->searchProperties([
            'location' => 'Tanger',
            'duration_months' => 12,
            'start_date' => $start->toDateString(),
        ], $other);

        $titles = array_column($result['properties'], 'title');
        $this->assertNotContains('Tangier Taken', $titles);
        $this->assertNotContains('Tangier Free', $titles);
        $this->assertContains('Tangier Other', $titles);
        $this->assertNotNull($otherProperty);
    }

    public function test_a_tenant_cannot_read_another_tenants_reservation(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $intruder = $this->makeUser('Tenant Two', self::TENANT);

        $property = $this->makeProperty($owner);
        $reservation = $this->makeReservation($tenant, $property, 'accepted');

        $provider = app(DataProvider::class);

        $this->assertNull($provider->findReservation($reservation->id, $intruder));
        $this->assertNotNull($provider->findReservation($reservation->id, $tenant));

        // Pointing the chatbot at somebody else's reservation must not leak it.
        $this->fakeGemini([
            'intent' => 'reservation_status',
            'in_scope' => true,
            'needs_clarification' => false,
            'slots' => ['reservation_id' => $reservation->id],
        ]);

        Sanctum::actingAs($intruder);
        $response = $this->postJson('/api/chatbot/send', [
            'message' => 'Fin kayn contract dyali? reservation '.$reservation->id,
        ])->assertOk();

        $this->assertSame('reservation_status', $response->json('intent'));
    }

    public function test_a_tenant_is_never_shown_an_unapproved_property(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $pending = $this->makeProperty($owner, ['status' => 'pending']);
        $approved = $this->makeProperty($owner, ['title' => 'Tangier Approved']);

        $provider = app(DataProvider::class);

        $this->assertSame(false, $provider->propertyDetails(['property_id' => $pending->id], $tenant)['found']);
        $this->assertSame(true, $provider->propertyDetails(['property_id' => $approved->id], $tenant)['found']);

        // The owner may still inspect their own pending property.
        $this->assertSame(true, $provider->propertyDetails(['property_id' => $pending->id], $owner)['found']);
    }

    public function test_the_tenant_payment_facts_come_from_the_stored_payment_row(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $property = $this->makeProperty($owner, ['price_per_month' => 3000]);
        $reservation = $this->makeReservation($tenant, $property, 'accepted');

        Payment::create([
            'reservation_id' => $reservation->id,
            'tenant_id' => $tenant->id,
            'total_amount' => 9000,
            'amount' => 3000,
            'paid_amount' => 3000,
            'remaining_balance' => 6000,
            'method' => 'card',
            'status' => 'pending',
            'transaction_ref' => 'TXN-ABC123',
            'payment_date' => now(),
        ]);

        $facts = app(DataProvider::class)->paymentFor($reservation->fresh(), $tenant);

        $this->assertTrue($facts['has_payment_record']);
        $this->assertSame(3000.0, $facts['paid_amount_mad']);
        $this->assertSame(6000.0, $facts['remaining_balance_mad']);
        $this->assertSame('TXN-ABC123', $facts['transaction_reference']);
        $this->assertArrayNotHasKey('tenant_name', $facts);

        // The owner of the property may see the tenant name on the same payment.
        $ownerFacts = app(DataProvider::class)->paymentFor($reservation->fresh(), $owner);
        $this->assertSame('Tenant One', $ownerFacts['tenant_name']);
    }

    public function test_a_reservation_with_no_payment_reports_no_payment_row(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $property = $this->makeProperty($owner);
        $reservation = $this->makeReservation($tenant, $property, 'pending');

        $facts = app(DataProvider::class)->paymentFor($reservation, $tenant);

        $this->assertFalse($facts['has_payment_record']);
        $this->assertArrayNotHasKey('paid_amount_mad', $facts);
        $this->assertSame(9000.0, $facts['total_amount_mad']);
    }

    public function test_contract_state_reports_generation_and_downloadability(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $property = $this->makeProperty($owner);
        $reservation = $this->makeReservation($tenant, $property, 'accepted');

        $missing = app(DataProvider::class)->contractFor($reservation);
        $this->assertFalse($missing['exists']);
        $this->assertFalse($missing['contract_generated']);

        Contract::create([
            'reservation_id' => $reservation->id,
            'owner_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'file_url' => 'uploads/contracts/contract-'.$reservation->id.'.pdf',
        ]);

        $present = app(DataProvider::class)->contractFor($reservation->fresh());
        $this->assertTrue($present['exists']);
        $this->assertSame('active', $present['status']);
        // The file is not on disk in the test environment.
        $this->assertFalse($present['file_downloadable']);
    }

    public function test_notifications_are_read_only_for_their_own_user(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $other = $this->makeUser('Tenant Two', self::TENANT);

        Notification::create([
            'user_id' => $tenant->id,
            'title' => 'Reservation Accepted',
            'message' => 'Accepted',
            'type' => 'reservation',
            'is_read' => false,
        ]);

        $facts = app(DataProvider::class)->notificationsFor($other);

        $this->assertSame(0, $facts['total']);
        $this->assertSame(0, $facts['unread_count']);
        $this->assertSame([], $facts['latest']);

        $own = app(DataProvider::class)->notificationsFor($tenant);
        $this->assertSame(1, $own['unread_count']);
        $this->assertSame('Reservation Accepted', $own['latest'][0]['title']);
    }

    public function test_the_profile_payload_never_carries_credentials(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $facts = app(DataProvider::class)->profileFor($tenant);

        // The role name comes straight from the roles table, as elsewhere.
        $this->assertSame('Tenant', $facts['role']);
        $this->assertArrayNotHasKey('password_hash', $facts);
        $this->assertArrayNotHasKey('remember_token', $facts);
        $this->assertSame(['full_name', 'phone', 'avatar'], $facts['editable_fields']);
    }

    public function test_owner_and_tenant_overviews_are_kept_to_their_own_role(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $this->makeProperty($owner, ['status' => 'approved']);
        $this->makeProperty($owner, ['status' => 'pending']);
        $reservation = $this->makeReservation($tenant, $this->makeProperty($owner), 'accepted');

        $overview = app(DataProvider::class)->ownerOverview($owner);
        $this->assertSame(3, $overview['properties']['total']);
        $this->assertSame(2, $overview['properties']['approved']);
        $this->assertSame(1, $overview['properties']['pending_approval']);

        // The tenant is not told the owner revenue through the tenant overview.
        $tenantFacts = app(DataProvider::class)->tenantOverview($tenant);
        $this->assertSame(1, $tenantFacts['reservations']['accepted']);
        $this->assertArrayNotHasKey('revenue_paid_mad', $tenantFacts);
        $this->assertNotNull($reservation);
    }

    public function test_the_answer_never_receives_unverified_figures_when_gemini_is_down(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $property = $this->makeProperty($owner, ['price_per_month' => 3000]);
        $reservation = $this->makeReservation($tenant, $property, 'accepted');

        Payment::create([
            'reservation_id' => $reservation->id,
            'tenant_id' => $tenant->id,
            'total_amount' => 9000,
            'amount' => 3000,
            'paid_amount' => 3000,
            'remaining_balance' => 6000,
            'method' => 'card',
            'status' => 'pending',
            'payment_date' => now(),
        ]);

        $this->fakeGemini([
            'intent' => 'payment_status',
            'in_scope' => true,
            'needs_clarification' => false,
            'slots' => [],
        ], null);

        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'Ch7al ba9i 3lia nkhless?'])->assertOk();

        $reply = $response->json('reply');
        $this->assertStringContainsString('6,000.00', $reply);
        $this->assertStringContainsString('3,000.00', $reply);
        $this->assertStringNotContainsString('AIza', $reply);
    }

    public function test_the_prompt_sent_to_gemini_carries_no_other_users_data(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);
        $stranger = $this->makeUser('Stranger Nine', self::TENANT);

        $property = $this->makeProperty($owner);
        $strangersReservation = $this->makeReservation($stranger, $property, 'accepted');

        $captured = [];
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateJson')->andReturn([
            'intent' => 'reservation_status',
            'in_scope' => true,
            'needs_clarification' => false,
            'slots' => ['reservation_id' => $strangersReservation->id],
        ]);
        $gemini->shouldReceive('generate')->andReturnUsing(function (array $turns) use (&$captured) {
            $captured = $turns;

            return 'Understood.';
        });
        $this->app->instance(GeminiService::class, $gemini);

        Sanctum::actingAs($tenant);
        $this->postJson('/api/chatbot/send', [
            'message' => 'Reservation dyali mazal pending, 3lach? '.$strangersReservation->id,
        ])->assertOk();

        $payload = json_encode($captured);

        $this->assertStringContainsString('reservation_found', $payload);
        $this->assertStringNotContainsString('Stranger Nine', $payload);
        $this->assertStringNotContainsString($strangersReservation->reference, $payload);
        $this->assertStringNotContainsString(config('services.gemini.key') ?: 'unset-key', $payload);
    }

    public function test_the_user_message_is_still_recorded_when_the_provider_fails(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateJson')->andReturn([
            'intent' => 'payment_status',
            'in_scope' => true,
            'needs_clarification' => false,
            'slots' => [],
        ]);
        $gemini->shouldReceive('generate')->andThrow(new \RuntimeException('provider exploded'));
        $this->app->instance(GeminiService::class, $gemini);

        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'Payment dyali failed.'])->assertOk();

        $this->assertStringContainsString('SAKANIA Support', $response->json('reply'));
        $this->assertStringNotContainsString('provider exploded', $response->json('reply'));
        $this->assertSame(2, ChatbotMessage::where('conversation_id', $response->json('conversation_id'))->count());
    }

    public function test_a_database_failure_is_reported_as_unavailable_not_invented(): void
    {
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $this->fakeGemini([
            'intent' => 'payment_status',
            'in_scope' => true,
            'needs_clarification' => false,
            'slots' => [],
        ]);

        $this->partialMock(DataProvider::class, function ($mock) {
            $mock->shouldReceive('resolveTargetReservation')->andThrow(new \RuntimeException('db down'));
        });

        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'Ch7al ba9i 3lia nkhless?'])->assertOk();

        $this->assertStringContainsString('temporarily unavailable', $response->json('reply'));
        $this->assertStringNotContainsString('db down', $response->json('reply'));
    }

    public function test_the_viewed_property_context_is_only_used_when_the_user_may_see_it(): void
    {
        $owner = $this->makeUser('Owner One', self::OWNER);
        $tenant = $this->makeUser('Tenant One', self::TENANT);

        $pending = $this->makeProperty($owner, ['title' => 'Still Pending', 'status' => 'pending']);
        $approved = $this->makeProperty($owner, ['title' => 'Tangier Approved']);

        $this->fakeGemini();

        Sanctum::actingAs($tenant);

        $this->postJson('/api/chatbot/send', [
            'message' => 'How much is this property?',
            'property_id' => $approved->id,
        ])->assertOk()->assertJson(['intent' => 'platform_workflow']);

        // A tenant asking about an unapproved property is not an error: the
        // controller simply does not attach it as context.
        $this->postJson('/api/chatbot/send', [
            'message' => 'How much is this property?',
            'property_id' => $pending->id,
        ])->assertOk();
    }

    public function test_the_service_falls_back_to_the_playbook_when_the_classifier_is_unreachable(): void
    {
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateJson')->andReturn(null);
        $gemini->shouldReceive('generate')->andReturn(null);
        $this->app->instance(GeminiService::class, $gemini);

        $tenant = $this->makeUser('Tenant One', self::TENANT);
        Sanctum::actingAs($tenant);

        $response = $this->postJson('/api/chatbot/send', ['message' => 'Ma9dertch ndir reservation'])->assertOk();

        $this->assertSame('troubleshooting', $response->json('intent'));
        $this->assertStringContainsString('approved', $response->json('reply'));
    }
}
