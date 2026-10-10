<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ROLE = 1;

    private const OWNER_ROLE = 2;

    private const ADMIN_ROLE = 3;

    private const OLD_PASSWORD = 'secret1234';

    private const NEW_PASSWORD = 'brand-new-secret';

    /** @var array<string, true> Avatar files that already existed before the test. */
    private array $preExistingAvatars = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The role names match DatabaseSeeder, since the client compares the
        // role string against 'tenant' / 'owner' / 'admin'.
        foreach (['tenant', 'owner', 'admin'] as $index => $name) {
            DB::table('roles')->insert(['id' => $index + 1, 'name' => $name]);
        }

        // Record the avatars already on disk: the uploads are real files under
        // public/uploads, so the test must only ever clean up its own.
        foreach (glob(public_path('uploads/profiles/*')) ?: [] as $file) {
            $this->preExistingAvatars[basename($file)] = true;
        }
    }

    private function makeUser(string $name, int $roleId): User
    {
        return User::create([
            'full_name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'password_hash' => bcrypt(self::OLD_PASSWORD),
            'role_id' => $roleId,
            'is_active' => true,
        ]);
    }

    /**
     * A real 1x1 PNG, sent the way the browser sends it: a data URI in the JSON
     * body, which is how every upload in this application travels.
     */
    private function pngDataUri(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $this->assertNotFalse($png);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    protected function tearDown(): void
    {
        // Remove only the avatars this test wrote. Anything that was already on
        // disk before the test (a real user's upload) is left untouched.
        foreach (glob(public_path('uploads/profiles/*')) ?: [] as $file) {
            if (! isset($this->preExistingAvatars[basename($file)])) {
                @unlink($file);
            }
        }

        $this->preExistingAvatars = [];

        parent::tearDown();
    }

    public function test_the_profile_endpoints_require_authentication(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
        $this->putJson('/api/profile', ['full_name' => 'Nobody'])->assertStatus(401);
        $this->putJson('/api/profile/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(401);
        $this->deleteJson('/api/profile/avatar')->assertStatus(401);
    }

    public function test_a_user_can_read_their_own_profile(): void
    {
        $tenant = $this->makeUser('Reader Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->getJson('/api/profile')
            ->assertStatus(200)
            ->assertJsonPath('id', $tenant->id)
            ->assertJsonPath('email', $tenant->email)
            ->assertJsonPath('role', 'tenant')
            // The hash must never travel with the profile.
            ->assertJsonMissing(['password_hash']);
    }

    public function test_a_user_can_change_their_name(): void
    {
        $tenant = $this->makeUser('Before Name', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile', ['full_name' => 'After Name'])
            ->assertStatus(200)
            ->assertJsonPath('user.full_name', 'After Name')
            ->assertJsonPath('message', 'Profile updated successfully.');

        $this->assertDatabaseHas('users', ['id' => $tenant->id, 'full_name' => 'After Name']);
    }

    public function test_saving_the_profile_keeps_every_field_that_was_not_submitted(): void
    {
        $owner = $this->makeUser('Kept Owner', self::OWNER_ROLE);
        $owner->forceFill([
            'phone' => '+212600000000',
            'company_name' => 'Sakania Real Estate',
            'avatar' => 'uploads/profiles/existing.png',
        ])->save();

        Sanctum::actingAs($owner);

        // Only the name is in the payload, as a plain save of the form does.
        $this->putJson('/api/profile', ['full_name' => 'Kept Owner Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('user.full_name', 'Kept Owner Renamed')
            ->assertJsonPath('user.phone', '+212600000000')
            ->assertJsonPath('user.company_name', 'Sakania Real Estate')
            ->assertJsonPath('user.avatar', 'uploads/profiles/existing.png');

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'phone' => '+212600000000',
            'company_name' => 'Sakania Real Estate',
            'avatar' => 'uploads/profiles/existing.png',
        ]);
    }

    public function test_an_empty_name_is_rejected(): void
    {
        $tenant = $this->makeUser('Named Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile', ['full_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('full_name');
    }

    public function test_a_name_longer_than_the_column_is_rejected(): void
    {
        $tenant = $this->makeUser('Long Name', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        // users.full_name is a 100 character column, so the rule has to stop at
        // the column width rather than let the database raise a 500.
        $this->putJson('/api/profile', ['full_name' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('full_name');
    }

    public function test_an_avatar_is_stored_and_referenced_by_a_public_path(): void
    {
        $tenant = $this->makeUser('Avatar Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $response = $this->putJson('/api/profile', ['avatar' => $this->pngDataUri()])
            ->assertStatus(200);

        $path = $response->json('user.avatar');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/profiles/', $path);
        $this->assertMatchesRegularExpression('/\.png$/', $path);
        $this->assertSame('/'.$path, $response->json('user.avatar_url'));
        $this->assertFileExists(public_path($path));

        // The response has to match what a later read of the profile returns,
        // which is what the client stores in its session.
        $this->getJson('/api/profile')
            ->assertJsonPath('avatar', $path)
            ->assertJsonPath('avatar_url', '/'.$path);
    }

    public function test_changing_the_avatar_replaces_the_previous_upload(): void
    {
        $tenant = $this->makeUser('Replace Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $first = $this->putJson('/api/profile', ['avatar' => $this->pngDataUri()])->assertStatus(200);
        $firstPath = $first->json('user.avatar');

        $second = $this->putJson('/api/profile', ['avatar' => $this->pngDataUri()])->assertStatus(200);
        $secondPath = $second->json('user.avatar');

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertFileExists(public_path($secondPath));
        // The replaced file is removed so the upload folder does not grow
        // without bound, and the old URL stops resolving.
        $this->assertFileDoesNotExist(public_path($firstPath));
    }

    public function test_saving_the_name_alone_does_not_delete_the_avatar(): void
    {
        $tenant = $this->makeUser('Keep Avatar', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $path = $this->putJson('/api/profile', ['avatar' => $this->pngDataUri()])->json('user.avatar');

        $this->putJson('/api/profile', ['full_name' => 'Keep Avatar Renamed'])->assertStatus(200);

        $this->assertFileExists(public_path($path));
        $this->assertDatabaseHas('users', ['id' => $tenant->id, 'avatar' => $path]);
    }

    public function test_a_user_can_delete_their_avatar(): void
    {
        $tenant = $this->makeUser('Delete Avatar', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $path = $this->putJson('/api/profile', ['avatar' => $this->pngDataUri()])->json('user.avatar');
        $this->assertFileExists(public_path($path));

        $this->deleteJson('/api/profile/avatar')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Profile picture removed successfully.')
            ->assertJsonPath('user.avatar', null)
            ->assertJsonPath('user.avatar_url', null);

        // Both the reference and the stored file are gone, so the client falls
        // back to the initials and the old URL stops resolving.
        $this->assertFileDoesNotExist(public_path($path));
        $this->assertDatabaseHas('users', ['id' => $tenant->id, 'avatar' => null]);

        // A later read agrees with what the deletion returned.
        $this->getJson('/api/profile')
            ->assertJsonPath('avatar', null)
            ->assertJsonPath('avatar_url', null);
    }

    public function test_deleting_an_avatar_only_touches_the_signed_in_account(): void
    {
        $tenant = $this->makeUser('Deleter Tenant', self::TENANT_ROLE);
        $other = $this->makeUser('Neighbour Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);
        $mine = $this->putJson('/api/profile', ['avatar' => $this->pngDataUri()])->json('user.avatar');

        Sanctum::actingAs($other);
        $theirs = $this->putJson('/api/profile', ['avatar' => $this->pngDataUri()])->json('user.avatar');

        Sanctum::actingAs($tenant);
        $this->deleteJson('/api/profile/avatar')->assertStatus(200);

        $this->assertFileDoesNotExist(public_path($mine));
        // The neighbour's picture stays on disk and keeps its reference: the
        // endpoint reads the path from the session row, never from a payload.
        $this->assertFileExists(public_path($theirs));
        $this->assertDatabaseHas('users', ['id' => $other->id, 'avatar' => $theirs]);
    }

    public function test_deleting_an_avatar_that_does_not_exist_is_rejected(): void
    {
        $tenant = $this->makeUser('No Avatar Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->deleteJson('/api/profile/avatar')
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');

        $this->assertDatabaseHas('users', ['id' => $tenant->id, 'avatar' => null]);
    }

    public function test_a_non_image_payload_is_rejected(): void
    {
        $tenant = $this->makeUser('Fake Avatar', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile', ['avatar' => 'data:image/png;base64,'.base64_encode('<?php echo 1;')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');

        $this->assertDatabaseHas('users', ['id' => $tenant->id, 'avatar' => null]);
    }

    public function test_a_photo_that_is_not_an_image_data_uri_is_rejected(): void
    {
        $tenant = $this->makeUser('Text Avatar', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile', ['avatar' => 'just a string'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_an_avatar_above_two_megabytes_is_rejected(): void
    {
        $tenant = $this->makeUser('Big Avatar', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        // Large enough to be over the 2MB ceiling once decoded.
        $payload = str_repeat('A', 4 * 1024 * 1024);

        $this->putJson('/api/profile', ['avatar' => 'data:image/png;base64,'.base64_encode($payload)])
            ->assertStatus(422);
    }

    public function test_an_owner_can_set_a_company_name_but_a_tenant_cannot(): void
    {
        $owner = $this->makeUser('Company Owner', self::OWNER_ROLE);

        Sanctum::actingAs($owner);
        $this->putJson('/api/profile', ['company_name' => 'Sakania'])
            ->assertStatus(200)
            ->assertJsonPath('user.company_name', 'Sakania');

        $tenant = $this->makeUser('Plain Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);
        $this->putJson('/api/profile', ['company_name' => 'Should Not Stick'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $tenant->id, 'company_name' => 'Should Not Stick']);
    }

    public function test_the_profile_endpoint_cannot_change_the_role_or_the_activation(): void
    {
        $tenant = $this->makeUser('Escalating Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile', [
            'full_name' => 'Escalating Tenant',
            'role_id' => self::ADMIN_ROLE,
            'is_active' => true,
            'email' => 'hijack@example.test',
        ])->assertStatus(200);

        // Only the name may move; privilege and credentials are not the user's
        // to set from their own profile.
        $this->assertDatabaseHas('users', [
            'id' => $tenant->id,
            'role_id' => self::TENANT_ROLE,
            'email' => $tenant->email,
        ]);
    }

    public function test_a_user_can_change_their_password(): void
    {
        $tenant = $this->makeUser('Password Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(200)
            ->assertJsonPath('message', 'Password changed successfully.');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $tenant->fresh()->password_hash));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $tenant->fresh()->password_hash));

        // The new password is the one that authenticates from now on.
        $this->postJson('/api/login', [
            'email' => $tenant->email,
            'password' => self::NEW_PASSWORD,
        ])->assertStatus(200);

        $this->postJson('/api/login', [
            'email' => $tenant->email,
            'password' => self::OLD_PASSWORD,
        ])->assertStatus(422);
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $tenant = $this->makeUser('Wrong Current', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile/password', [
            'current_password' => 'not-the-password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $tenant->fresh()->password_hash));
    }

    public function test_the_new_password_has_to_be_confirmed_and_long_enough(): void
    {
        $tenant = $this->makeUser('Weak Password', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->putJson('/api/profile/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $tenant->fresh()->password_hash));
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        $tenant = $this->makeUser('Same Password', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->putJson('/api/profile/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::OLD_PASSWORD,
            'password_confirmation' => self::OLD_PASSWORD,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_changing_the_password_ends_the_other_sessions_only(): void
    {
        $tenant = $this->makeUser('Multi Device', self::TENANT_ROLE);

        // Two real tokens, so currentAccessToken() is a persisted one and the
        // controller can tell the calling session from the others.
$phone = $tenant->createToken('phone');
        $laptop = $tenant->createToken('laptop');
        $phoneTokenId = $phone->accessToken->id;
        $laptopTokenId = $laptop->accessToken->id;
        $this->assertCount(2, $tenant->tokens);

        $this->withToken($laptop->plainTextToken)
            ->putJson('/api/profile/password', [
                'current_password' => self::OLD_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertStatus(200);

        // The session that made the change keeps working...
        $this->assertTrue($tenant->tokens()->whereKey($laptopTokenId)->exists());
        // ...and the other one is revoked, because it was opened with the old
        // password and can no longer be trusted.
        $this->assertFalse($tenant->tokens()->whereKey($phoneTokenId)->exists());
        $this->assertCount(1, $tenant->fresh()->tokens);

        $this->withToken($laptop->plainTextToken)
            ->getJson('/api/profile')
            ->assertStatus(200);
    }

    public function test_an_owner_can_still_read_the_role_scoped_profile_route(): void
    {
        $owner = $this->makeUser('Scoped Owner', self::OWNER_ROLE);

        Sanctum::actingAs($owner);

        // The tenant and owner paths are kept working for anything already
        // calling them; both now serve the shared controller.
        $this->getJson('/api/owner/profile')->assertStatus(200)->assertJsonPath('id', $owner->id);
        $this->putJson('/api/owner/profile', ['full_name' => 'Scoped Owner Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('user.full_name', 'Scoped Owner Renamed');
    }

    public function test_an_admin_has_a_profile_route_too(): void
    {
        $admin = $this->makeUser('Admin Account', self::ADMIN_ROLE);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/profile')->assertStatus(200)->assertJsonPath('role', 'admin');
        $this->putJson('/api/admin/profile', ['full_name' => 'Admin Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('user.full_name', 'Admin Renamed');
    }

    public function test_a_tenant_is_still_refused_the_owner_profile_route(): void
    {
        $tenant = $this->makeUser('Refused Tenant', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->getJson('/api/owner/profile')->assertStatus(403);
    }
}