<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerPropertyApiTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ROLE = 2;

    private const TENANT_ROLE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // The role identifiers are hard coded in the role middlewares
        // (tenant = 1, owner = 2, admin = 3).
        foreach (['Tenant', 'Owner', 'Admin'] as $index => $name) {
            DB::table('roles')->insert(['id' => $index + 1, 'name' => $name]);
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

    /**
     * What the property form sends once every field it marks as required is
     * filled in: all values are strings, exactly as the inputs produce them.
     */
    private function completePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Sunny Studio near the beach',
            'description' => 'Bright studio, two steps from the shore.',
            'location' => 'Ain Diab, Casablanca',
            'type' => 'studio',
            'area' => '45.5',
            'price_per_month' => '3500',
            'rooms' => '1',
            'bathrooms' => '1',
            'floor' => '2',
            'capacity' => '2',
            'amenities' => ['wifi', 'parking'],
            'images' => [],
        ], $overrides);
    }

    private function makeProperty(User $owner): Property
    {
        return Property::create([
            'owner_id' => $owner->id,
            'title' => 'Existing Apartment',
            'description' => 'A furnished apartment.',
            'location' => 'Casablanca',
            'type' => 'apartment',
            'area' => 80,
            'price_per_month' => 6000.00,
            'status' => 'approved',
            'rooms' => 2,
            'bathrooms' => 1,
            'floor' => 1,
            'capacity' => 3,
        ]);
    }

    public function test_the_property_endpoints_require_authentication(): void
    {
        $this->postJson('/api/owner/properties', $this->completePayload())->assertStatus(401);
        $this->putJson('/api/owner/properties/1', $this->completePayload())->assertStatus(401);
        $this->deleteJson('/api/owner/properties/1')->assertStatus(401);
    }

    public function test_a_tenant_cannot_create_a_property(): void
    {
        Sanctum::actingAs($this->makeUser('Tenant Try', self::TENANT_ROLE));

        $this->postJson('/api/owner/properties', $this->completePayload())->assertStatus(403);
    }

    public function test_a_complete_property_form_is_created(): void
    {
        $owner = $this->makeUser('Form Owner', self::OWNER_ROLE);
        Sanctum::actingAs($owner);

        $this->postJson('/api/owner/properties', $this->completePayload())
            ->assertStatus(201)
            ->assertJsonPath('property.title', 'Sunny Studio near the beach')
            ->assertJsonPath('property.status', 'pending')
            ->assertJsonPath('rejected_images', 0);

        $this->assertDatabaseHas('properties', [
            'owner_id' => $owner->id,
            'title' => 'Sunny Studio near the beach',
            'type' => 'studio',
            'capacity' => 2,
        ]);
    }

    /**
     * The form used to check only title, location and price, so an untouched
     * form posted empty strings for the other seven required fields and the
     * API answered 422 with errors on exactly those keys.
     */
    public function test_the_payload_of_an_unvalidated_form_is_rejected_field_by_field(): void
    {
        $owner = $this->makeUser('Empty Fields Owner', self::OWNER_ROLE);
        Sanctum::actingAs($owner);

        $payload = $this->completePayload([
            'description' => '',
            'type' => '',
            'area' => '',
            'rooms' => '',
            'bathrooms' => '',
            'floor' => '',
            'capacity' => '',
        ]);

        $this->postJson('/api/owner/properties', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'description',
                'type',
                'area',
                'rooms',
                'bathrooms',
                'floor',
                'capacity',
            ])
            // The three fields the form did check are not what failed.
            ->assertJsonMissingValidationErrors(['title', 'location', 'price_per_month']);

        $this->assertDatabaseCount('properties', 0);
    }

    public function test_an_empty_title_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('No Title Owner', self::OWNER_ROLE));

        $this->postJson('/api/owner/properties', $this->completePayload(['title' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_an_unknown_property_type_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('Bad Type Owner', self::OWNER_ROLE));

        $this->postJson('/api/owner/properties', $this->completePayload(['type' => 'castle']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_a_capacity_below_one_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('Zero Capacity Owner', self::OWNER_ROLE));

        $this->postJson('/api/owner/properties', $this->completePayload(['capacity' => '0']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('capacity');
    }

    public function test_more_than_ten_images_are_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('Many Images Owner', self::OWNER_ROLE));

        $images = array_fill(0, 11, 'data:image/jpeg;base64,abc123');

        $this->postJson('/api/owner/properties', $this->completePayload(['images' => $images]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');
    }

    public function test_an_owner_can_update_their_property(): void
    {
        $owner = $this->makeUser('Editing Owner', self::OWNER_ROLE);
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($owner);

        $this->putJson('/api/owner/properties/'.$property->id, $this->completePayload([
            'title' => 'Renamed Apartment',
            'capacity' => '4',
        ]))
            ->assertStatus(200)
            ->assertJsonPath('property.title', 'Renamed Apartment');

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'title' => 'Renamed Apartment',
            'capacity' => 4,
        ]);
    }

    public function test_clearing_a_required_field_on_update_is_rejected(): void
    {
        $owner = $this->makeUser('Clearing Owner', self::OWNER_ROLE);
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($owner);

        $this->putJson('/api/owner/properties/'.$property->id, $this->completePayload([
            'description' => '',
            'area' => '',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description', 'area']);
    }

    public function test_an_owner_cannot_update_another_owners_property(): void
    {
        $owner = $this->makeUser('Real Owner', self::OWNER_ROLE);
        $stranger = $this->makeUser('Other Owner', self::OWNER_ROLE);
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($stranger);

        $this->putJson('/api/owner/properties/'.$property->id, $this->completePayload())
            ->assertStatus(404);
    }

    public function test_an_owner_can_delete_their_property(): void
    {
        $owner = $this->makeUser('Deleting Owner', self::OWNER_ROLE);
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/owner/properties/'.$property->id)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Property deleted successfully.');

        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
    }

    public function test_an_owner_cannot_delete_another_owners_property(): void
    {
        $owner = $this->makeUser('Sole Owner', self::OWNER_ROLE);
        $stranger = $this->makeUser('Foreign Owner', self::OWNER_ROLE);
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($stranger);

        $this->deleteJson('/api/owner/properties/'.$property->id)->assertStatus(404);

        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }
}
