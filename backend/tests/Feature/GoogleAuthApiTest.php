<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleAccount;
use Mockery;
use Tests\TestCase;

/**
 * "Continue with Google", end to end with Google itself faked.
 *
 * The three steps are exercised the way a browser walks them: ask for the
 * consent URL, come back to the callback with the state and the code Google
 * issued, then redeem the one-time code the callback handed to the client. Only
 * the calls to accounts.google.com are replaced; the state handling, the account
 * lookup, the role rules and the token are the real thing.
 */
class GoogleAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ROLE = 1;

    private const OWNER_ROLE = 2;

    private const ADMIN_ROLE = 3;

    private const PASSWORD = 'secret1234';

    private const FRONTEND = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();

        // Ids and names match DatabaseSeeder, since the client compares the role
        // string against 'tenant' / 'owner' / 'admin'.
        foreach (['tenant', 'owner', 'admin'] as $index => $name) {
            DB::table('roles')->insert(['id' => $index + 1, 'name' => $name]);
        }
    }

    private function makeUser(string $name, int $roleId, array $attributes = []): User
    {
        return User::create(array_merge([
            'full_name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'password_hash' => bcrypt(self::PASSWORD),
            'role_id' => $roleId,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * An account that already exists, holding the very address the fake Google
     * account returns.
     */
    private function makeAccountWithThatEmail(int $roleId, array $attributes = []): User
    {
        return $this->makeUser('Existing Account', $roleId, array_merge([
            'email' => 'nour@example.test',
        ], $attributes));
    }

    /**
     * Stands in for the whole Google round trip.
     *
     * The fake provider behaves like the real one where it matters: it carries
     * the state that was handed to with() into the consent URL, so the tests
     * read the state back exactly as a browser would.
     */
    private function fakeGoogle(array $attributes = [])
    {
        $raw = array_merge([
            'sub' => 'google-subject-1',
            'email' => 'nour@example.test',
            'email_verified' => true,
            'name' => 'Nour El Idrissi',
        ], $attributes);

        $account = (new GoogleAccount)->setRaw($raw)->map([
            'id' => $raw['sub'],
            'nickname' => null,
            'name' => $raw['name'],
            'email' => $raw['email'],
            'avatar' => null,
        ]);

        $state = null;

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnUsing(
            function (array $parameters) use ($provider, &$state) {
                $state = $parameters['state'] ?? null;

                return $provider;
            }
        );
        $provider->shouldReceive('redirect')->andReturnUsing(function () use (&$state) {
            return new RedirectResponse('https://accounts.google.com/o/oauth2/auth?'.http_build_query([
                'client_id' => 'test-client-id',
                'redirect_uri' => self::FRONTEND.'/api/auth/google/callback',
                'scope' => 'openid profile email',
                'response_type' => 'code',
                'state' => $state,
            ]));
        });
        $provider->shouldReceive('user')->andReturn($account);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        return $account;
    }

    /**
     * Step 1, and the state Google will hand back, taken from the URL it returns.
     */
    private function consentUrl(array $payload = []): string
    {
        $this->fakeGoogle();

        $response = $this->postJson('/api/auth/google/redirect', $payload)->assertStatus(200);

        return $response->json('url');
    }

    /**
     * Steps 2 and 3: the callback hands back a one-time code, and that code
     * becomes a session. Returns the exchange response.
     */
    private function signInWithGoogle(array $payload = [])
    {
        parse_str((string) parse_url($this->consentUrl($payload), PHP_URL_QUERY), $consent);

        $callback = $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $consent['state'],
        ]));

        $callback->assertRedirect();

        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $handoff);

        return $this->postJson('/api/auth/google/exchange', ['code' => $handoff['code'] ?? '']);
    }

    public function test_the_consent_url_comes_from_google_and_carries_a_state(): void
    {
        $url = $this->consentUrl();

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query);
        $this->assertArrayHasKey('client_id', $query);
    }

    public function test_the_sign_up_form_cannot_ask_for_the_admin_role(): void
    {
        $this->fakeGoogle();

        $this->postJson('/api/auth/google/redirect', ['role_id' => self::ADMIN_ROLE])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
    }

    public function test_a_new_google_account_is_created_as_a_tenant_and_signed_in(): void
    {
        $response = $this->signInWithGoogle();

        $response->assertStatus(200)
            ->assertJsonPath('user.email', 'nour@example.test')
            ->assertJsonPath('user.full_name', 'Nour El Idrissi')
            ->assertJsonPath('user.role', 'tenant')
            ->assertJsonPath('created', true)
            ->assertJsonMissing(['password_hash']);

        $this->assertNotEmpty($response->json('token'));

        $user = User::where('email', 'nour@example.test')->firstOrFail();

        $this->assertSame(self::TENANT_ROLE, $user->role_id);
        $this->assertSame('google-subject-1', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        // No password was invented, so no password can match this account.
        $this->assertNull($user->password_hash);
        $this->assertFalse($user->hasPassword());
    }

    public function test_the_token_from_a_google_sign_in_works_on_the_api(): void
    {
        $token = $this->signInWithGoogle()->assertStatus(200)->json('token');

        $this->withToken($token)->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('email', 'nour@example.test')
            ->assertJsonPath('role', 'tenant');
    }

    public function test_a_new_account_takes_the_role_chosen_on_the_sign_up_form(): void
    {
        $this->signInWithGoogle(['role_id' => self::OWNER_ROLE])
            ->assertStatus(200)
            ->assertJsonPath('user.role', 'owner');

        $this->assertSame(self::OWNER_ROLE, User::where('email', 'nour@example.test')->firstOrFail()->role_id);
    }

    public function test_an_admin_role_survives_neither_the_endpoint_nor_the_state(): void
    {
        $this->fakeGoogle();

        // The endpoint refuses the request outright...
        $this->postJson('/api/auth/google/redirect', ['role_id' => self::ADMIN_ROLE])->assertStatus(422);

        // ...and even a state that somehow carried one still lands on a tenant.
        $state = app(GoogleAuthService::class)->rememberState(self::ADMIN_ROLE);

        $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $state,
        ]))->assertRedirect();

        $this->assertSame(self::TENANT_ROLE, User::where('email', 'nour@example.test')->firstOrFail()->role_id);
    }

    public function test_an_existing_account_is_linked_and_signed_in_without_a_second_row(): void
    {
        $existing = $this->makeAccountWithThatEmail(self::OWNER_ROLE);

        $response = $this->signInWithGoogle();

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $existing->id)
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('created', false);

        $this->assertSame(1, User::count());
        // The role an administrator or the person gave this account is untouched.
        $this->assertSame(self::OWNER_ROLE, $existing->fresh()->role_id);
        $this->assertSame('google-subject-1', $existing->fresh()->google_id);
        $this->assertNotNull($existing->fresh()->email_verified_at);
    }

    public function test_a_linked_account_keeps_signing_in_with_its_password(): void
    {
        $existing = $this->makeAccountWithThatEmail(self::TENANT_ROLE);

        $this->signInWithGoogle()->assertStatus(200);

        $this->assertNotNull($existing->fresh()->password_hash);

        $this->postJson('/api/login', [
            'email' => $existing->email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->assertJsonPath('user.id', $existing->id);
    }

    public function test_signing_in_again_never_creates_a_second_account(): void
    {
        $this->signInWithGoogle()->assertStatus(200);
        $this->signInWithGoogle()->assertStatus(200)->assertJsonPath('created', false);

        $this->assertSame(1, User::count());
    }

    public function test_the_email_match_does_not_depend_on_the_casing(): void
    {
        $existing = $this->makeAccountWithThatEmail(self::TENANT_ROLE);

        $this->fakeGoogle(['email' => 'NOUR@Example.test']);

        $state = app(GoogleAuthService::class)->rememberState(null);

        $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $state,
        ]))->assertRedirect();

        $this->assertSame(1, User::count());
        $this->assertSame('google-subject-1', $existing->fresh()->google_id);
    }

    public function test_an_unverified_google_email_is_refused(): void
    {
        $this->fakeGoogle(['email_verified' => false]);

        $state = app(GoogleAuthService::class)->rememberState(null);

        $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $state,
        ]))->assertRedirect(self::FRONTEND.'/login?google=unverified_email');

        $this->assertSame(0, User::count());
    }

    public function test_a_google_account_without_an_email_is_refused(): void
    {
        $this->fakeGoogle(['email' => null]);

        $state = app(GoogleAuthService::class)->rememberState(null);

        $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $state,
        ]))->assertRedirect(self::FRONTEND.'/login?google=unverified_email');

        $this->assertSame(0, User::count());
    }

    public function test_a_callback_without_a_known_state_is_refused(): void
    {
        $this->fakeGoogle();

        $this->get('/api/auth/google/callback?code=authorization-code-from-google&state=never-issued')
            ->assertRedirect(self::FRONTEND.'/login?google=invalid_state');

        $this->assertSame(0, User::count());
    }

    public function test_a_state_cannot_be_replayed(): void
    {
        $this->fakeGoogle();

        $state = app(GoogleAuthService::class)->rememberState(null);

        $query = http_build_query(['code' => 'authorization-code-from-google', 'state' => $state]);

        $this->get('/api/auth/google/callback?'.$query)->assertRedirect();

        $this->get('/api/auth/google/callback?'.$query)
            ->assertRedirect(self::FRONTEND.'/login?google=invalid_state');

        $this->assertSame(1, User::count());
    }

    public function test_cancelling_the_consent_screen_returns_to_the_page_it_started_from(): void
    {
        $this->fakeGoogle();

        $state = app(GoogleAuthService::class)->rememberState(null, 'register');

        $this->get('/api/auth/google/callback?'.http_build_query([
            'error' => 'access_denied',
            'state' => $state,
        ]))->assertRedirect(self::FRONTEND.'/register?google=denied');

        $this->assertSame(0, User::count());
    }

    public function test_a_deactivated_account_cannot_sign_in_with_google(): void
    {
        $deactivated = $this->makeAccountWithThatEmail(self::TENANT_ROLE, ['is_active' => false]);

        $this->fakeGoogle();

        $state = app(GoogleAuthService::class)->rememberState(null);

        $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $state,
        ]))->assertRedirect(self::FRONTEND.'/login?google=inactive');

        // A refused attempt leaves the account exactly as it was.
        $this->assertNull($deactivated->fresh()->google_id);
        $this->assertNull($deactivated->fresh()->email_verified_at);
    }

    public function test_an_email_already_linked_to_another_google_account_is_refused(): void
    {
        $this->makeAccountWithThatEmail(self::TENANT_ROLE, ['google_id' => 'somebody-elses-account']);

        $this->fakeGoogle();

        $state = app(GoogleAuthService::class)->rememberState(null);

        $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $state,
        ]))->assertRedirect(self::FRONTEND.'/login?google=email_taken');
    }

    public function test_a_linked_account_that_changed_its_google_email_is_refused(): void
    {
        $this->makeAccountWithThatEmail(self::TENANT_ROLE, ['google_id' => 'google-subject-1']);

        $this->fakeGoogle(['email' => 'previous.address@example.test']);

        $state = app(GoogleAuthService::class)->rememberState(null);

        $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $state,
        ]))->assertRedirect(self::FRONTEND.'/login?google=email_taken');
    }

    public function test_the_handoff_code_works_exactly_once(): void
    {
        parse_str((string) parse_url($this->consentUrl(), PHP_URL_QUERY), $consent);

        $callback = $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'authorization-code-from-google',
            'state' => $consent['state'],
        ]));

        $this->assertStringStartsWith(
            self::FRONTEND.'/auth/google/callback?code=',
            $callback->headers->get('Location')
        );

        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $handoff);

        $this->postJson('/api/auth/google/exchange', ['code' => $handoff['code']])
            ->assertStatus(200)
            ->assertJsonPath('user.email', 'nour@example.test');

        $this->postJson('/api/auth/google/exchange', ['code' => $handoff['code']])->assertStatus(401);
    }

    public function test_an_unknown_handoff_code_is_refused(): void
    {
        $this->postJson('/api/auth/google/exchange', ['code' => 'made-up-code'])->assertStatus(401);

        $this->postJson('/api/auth/google/exchange', [])->assertStatus(422);
    }

    public function test_no_password_can_sign_in_to_a_google_only_account(): void
    {
        $this->signInWithGoogle()->assertStatus(200);

        $this->postJson('/api/login', [
            'email' => 'nour@example.test',
            'password' => 'anything at all',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/login', [
            'email' => 'nour@example.test',
            'password' => '',
        ])->assertStatus(422);
    }

    public function test_a_google_only_account_can_set_a_password_and_use_it_afterwards(): void
    {
        $this->signInWithGoogle()->assertStatus(200);

        $user = User::where('email', 'nour@example.test')->firstOrFail();

        Sanctum::actingAs($user);

        // The profile says there is no password to confirm...
        $this->getJson('/api/profile')->assertStatus(200)->assertJsonPath('has_password', false);

        // ...so the first one can be set without the current password.
        $this->putJson('/api/profile/password', [
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertStatus(200)->assertJsonPath('message', 'Password changed successfully.');

        $this->assertTrue($user->fresh()->hasPassword());

        $this->postJson('/api/login', [
            'email' => 'nour@example.test',
            'password' => 'a-brand-new-secret',
        ])->assertStatus(200);
    }

    public function test_an_account_that_has_a_password_still_needs_it_to_change_it(): void
    {
        $tenant = $this->makeUser('Nour El Idrissi', self::TENANT_ROLE);

        Sanctum::actingAs($tenant);

        $this->getJson('/api/profile')->assertStatus(200)->assertJsonPath('has_password', true);

        $this->putJson('/api/profile/password', [
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertStatus(200);
    }

    public function test_the_google_subject_id_never_travels_with_the_user(): void
    {
        $this->signInWithGoogle()->assertStatus(200);

        $user = User::where('email', 'nour@example.test')->firstOrFail();

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonMissing(['google_id']);
    }

    public function test_the_email_password_login_is_untouched_by_google(): void
    {
        $tenant = $this->makeUser('Yassine Amrani', self::TENANT_ROLE);

        $this->postJson('/api/register', [
            'full_name' => 'Karim Bennis',
            'email' => 'karim@example.test',
            'password' => 'another-secret',
            'password_confirmation' => 'another-secret',
            'role_id' => self::OWNER_ROLE,
        ])->assertStatus(201)->assertJsonPath('user.role', 'owner');

        $this->postJson('/api/login', [
            'email' => $tenant->email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->assertJsonPath('user.role', 'tenant');

        $this->postJson('/api/login', [
            'email' => 'karim@example.test',
            'password' => 'another-secret',
        ])->assertStatus(200);

        $this->postJson('/api/login', [
            'email' => 'karim@example.test',
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }
}
