<?php

namespace App\Services;

use App\Exceptions\GoogleAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as GoogleAccount;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Signing in with a Google account, on top of the email/password login that
 * already exists.
 *
 * The application is an API: the browser is not redirected through Laravel, it
 * is bounced straight to Google and comes back to a URL of this API. Socialite
 * would normally keep the `state` in the session, but this round trip arrives
 * from accounts.google.com, where no SAKANIA session cookie exists, so the state
 * is kept server side in the cache instead and checked by hand. Every call is
 * stateless for the same reason: nothing may depend on a cookie the browser
 * will not have.
 *
 * The flow is three steps, one endpoint each:
 *
 *   POST /api/auth/google/redirect  the SPA asks for the consent URL
 *   GET  /api/auth/google/callback  Google sends the browser back here
 *   POST /api/auth/google/exchange  the SPA swaps a one-time code for the very
 *                                   same { user, token } payload /api/login gives
 *
 * Nothing is written to the session and no cookie is set, so this works exactly
 * like the token based login it sits next to.
 */
class GoogleAuthService
{
    /** Roles a Google account may be created with. Admin is deliberately absent. */
    public const TENANT_ROLE = 1;

    public const OWNER_ROLE = 2;

    /** What somebody who never chose is, same as the default on the sign-up form. */
    public const DEFAULT_ROLE = self::TENANT_ROLE;

    /** A consent screen left open for longer than this is treated as abandoned. */
    private const STATE_TTL_MINUTES = 10;

    /** The window in which the client can still redeem the returned code. */
    private const HANDOFF_TTL_MINUTES = 5;

    private const PROVIDER = 'google';

    /**
     * Whether the credentials in .env are there. The redirect URI is part of the
     * answer: without it Google has nowhere to send the user back to. The client
     * uses this to hide the button instead of offering something that cannot work.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /**
     * The Google consent URL the browser has to be sent to, with the state of
     * this attempt remembered so the callback can prove it belongs to us.
     */
    public function authorizationUrl(?int $signUpRole, ?string $returnTo = null): string
    {
        if (! $this->isConfigured()) {
            throw new GoogleAuthException(
                GoogleAuthException::NOT_CONFIGURED,
                'Signing in with Google is not available on this server.'
            );
        }

        $state = $this->rememberState($signUpRole, $returnTo);

        // stateless() keeps Socialite away from the session; the state travels in
        // the query string, where Google hands it back to us unchanged.
        return Socialite::driver(self::PROVIDER)
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();
    }

    /**
     * Stores one sign-in attempt and returns its state.
     *
     * Kept separate from authorizationUrl() so the role a new account would get
     * and the page the user came from are decided once, here, and read back
     * unchanged when Google returns.
     */
    public function rememberState(?int $signUpRole, ?string $returnTo = null): string
    {
        $state = Str::random(40);

        Cache::put($this->stateKey($state), [
            'role_id' => $this->signUpRole($signUpRole),
            'return_to' => $returnTo === 'register' ? 'register' : 'login',
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $state;
    }

    /**
     * Reads and forgets the attempt behind a state, so a callback can be
     * replayed at most once.
     *
     * @throws GoogleAuthException when the state is unknown or has expired
     */
    public function consumeState(?string $state): array
    {
        $payload = is_string($state) && $state !== ''
            ? Cache::pull($this->stateKey($state))
            : null;

        if (! is_array($payload)) {
            throw new GoogleAuthException(
                GoogleAuthException::INVALID_STATE,
                'This Google sign-in request has expired. Please try again.'
            );
        }

        return $payload;
    }

    /**
     * The Google account behind the code Google just handed out.
     *
     * @throws GoogleAuthException when the code is refused or Google is unreachable
     */
    public function googleAccount(): GoogleAccount
    {
        try {
            return Socialite::driver(self::PROVIDER)->stateless()->user();
        } catch (GoogleAuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            // A reused or expired code, a clock skew on the code, a revoked
            // client secret: all of them are Google's answer, not a crash.
            report($e);

            throw new GoogleAuthException(
                GoogleAuthException::FAILED,
                'Google could not confirm this sign-in. Please try again.'
            );
        }
    }

    /**
     * Maps a Google account onto a SAKANIA user, creating it when the person is
     * new, and never creating a second account for the same address.
     *
     * @return array{0: User, 1: bool} the user, and whether it was just created
     *
     * @throws GoogleAuthException when the identity cannot be trusted or used
     */
    public function resolve(GoogleAccount $google, ?int $signUpRole = null): array
    {
        $googleId = trim((string) $google->getId());
        $email = $this->verifiedEmail($google);

        if ($googleId === '') {
            throw new GoogleAuthException(
                GoogleAuthException::FAILED,
                'Google did not return an account identifier.'
            );
        }

        // Already known: the same Google account coming back, role untouched.
        $linked = User::where('google_id', $googleId)->first();

        if ($linked) {
            return [$this->assertUsable($linked, $email), false];
        }

        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing) {
            // Same person, signing in with Google for the first time: the account
            // keeps its role, its password and everything else about it.
            if ($existing->google_id !== null) {
                throw new GoogleAuthException(
                    GoogleAuthException::EMAIL_TAKEN,
                    'This email address is already linked to another Google account.'
                );
            }

            // Checked before anything is written, so a refused attempt leaves the
            // account exactly as it was.
            $this->assertUsable($existing, $email);

            $existing->forceFill([
                'google_id' => $googleId,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            return [$existing->fresh(), false];
        }

        return [$this->create($google, $email, $googleId, $signUpRole), true];
    }

    /**
     * Hands the signed-in user over to the client as a single use code.
     *
     * The browser cannot carry a Sanctum token through a redirect without
     * putting it in the URL, so the URL carries this opaque code instead and the
     * client exchanges it over the API for the usual token. It is useless
     * without this server, expires in minutes, and is deleted by the exchange.
     */
    public function storeHandoff(User $user, bool $created): string
    {
        $code = Str::random(64);

        Cache::put($this->handoffKey($code), [
            'user_id' => $user->id,
            'created' => $created,
        ], now()->addMinutes(self::HANDOFF_TTL_MINUTES));

        return $code;
    }

    /**
     * Reads and forgets a handoff code, so one code signs one browser in once.
     *
     * @return array{user_id: int, created: bool}|null
     */
    public function takeHandoff(?string $code): ?array
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        $payload = Cache::pull($this->handoffKey($code));

        return is_array($payload) ? $payload : null;
    }

    /**
     * A brand new account for somebody who has never been here before.
     *
     * The role comes from the page the user started on and is narrowed to
     * tenant or owner here, so no code path can hand out an admin account.
     * password_hash stays empty on purpose: there is no password to log in with
     * until this person sets one, and until then no password matches.
     */
    private function create(GoogleAccount $google, string $email, string $googleId, ?int $signUpRole): User
    {
        $name = trim((string) $google->getName());

        return User::create([
            'full_name' => Str::limit($name !== '' ? $name : Str::before($email, '@'), 100, ''),
            'email' => $email,
            'password_hash' => null,
            'role_id' => $this->signUpRole($signUpRole),
            'is_active' => true,
            'google_id' => $googleId,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * The email address this Google account has proven it owns.
     *
     * The whole "no duplicate accounts" rule rests on this being the address
     * Google verified, which is why an unverified one is refused outright rather
     * than trusted.
     *
     * @throws GoogleAuthException when Google shared no address, or an unverified one
     */
    private function verifiedEmail(GoogleAccount $google): string
    {
        $email = Str::lower(trim((string) $google->getEmail()));

        if ($email === '') {
            throw new GoogleAuthException(
                GoogleAuthException::UNVERIFIED_EMAIL,
                'Your Google account did not share an email address with SAKANIA.'
            );
        }

        $raw = method_exists($google, 'getRaw') ? (array) $google->getRaw() : [];
        $verified = $raw['email_verified'] ?? null;

        if (! in_array($verified, [true, 1, '1', 'true'], true)) {
            throw new GoogleAuthException(
                GoogleAuthException::UNVERIFIED_EMAIL,
                'Your Google email address is not verified, so it cannot be used to sign in.'
            );
        }

        return $email;
    }

    /**
     * An account is only good for signing in while it is active and still the
     * same address Google says it is.
     *
     * @throws GoogleAuthException
     */
    private function assertUsable(User $user, string $email): User
    {
        if (Str::lower((string) $user->email) !== $email) {
            throw new GoogleAuthException(
                GoogleAuthException::EMAIL_TAKEN,
                'This Google account uses a different email than the SAKANIA account it is linked to.'
            );
        }

        if (! $user->is_active) {
            throw new GoogleAuthException(
                GoogleAuthException::INACTIVE,
                'Your account has been deactivated.'
            );
        }

        return $user;
    }

    /**
     * The one and only place a role is chosen for a Google sign-up.
     *
     * Anything that is not owner becomes tenant, so the admin role is out of
     * reach even if a request for it is somehow replayed at this level.
     */
    private function signUpRole(?int $requested): int
    {
        return $requested === self::OWNER_ROLE ? self::OWNER_ROLE : self::DEFAULT_ROLE;
    }

    /**
     * Hashed, so a state or a code can never be steered at a key of its own
     * choosing, whatever the browser sends.
     */
    private function stateKey(string $state): string
    {
        return 'sakania:google:state:'.hash('sha256', $state);
    }

    private function handoffKey(string $code): string
    {
        return 'sakania:google:handoff:'.hash('sha256', $code);
    }
}
