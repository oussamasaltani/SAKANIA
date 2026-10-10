<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GoogleAuthException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly GoogleAuthService $google)
    {
    }

    /**
     * The client keeps this object in AuthContext and reads the avatar from it
     * everywhere, so the relative upload path is turned into a URL once, here,
     * rather than being re-derived at every display site.
     */
    private function presentUser(User $user): array
    {
        $user->loadMissing('role');

        $data = $user->toArray();
        $data['role'] = $user->role?->name;
        $data['avatar_url'] = $user->avatar ? '/'.ltrim($user->avatar, '/') : null;

        return $data;
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'required|integer|in:1,2',
        ]);

        $user = User::create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'password_hash' => $validated['password'],
            'role_id' => $validated['role_id'],
            'is_active' => true,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $this->presentUser($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->with('role')->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $this->presentUser($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function user(Request $request)
    {
        return response()->json($this->presentUser($request->user()));
    }

    /**
     * Step 1 of "Continue with Google": the client asks where to send the
     * browser, and gets the Google consent URL.
     *
     * role_id is only the answer to a question the sign-up form already asks, so
     * it accepts the same two roles that form offers. return_to is where the
     * user is put back if Google does not cooperate.
     */
    public function googleRedirect(Request $request)
    {
        $validated = $request->validate([
            'role_id' => 'sometimes|integer|in:1,2',
            'return_to' => 'sometimes|string|in:login,register',
        ]);

        try {
            $url = $this->google->authorizationUrl(
                $validated['role_id'] ?? null,
                $validated['return_to'] ?? null,
            );
        } catch (GoogleAuthException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Step 2: Google redirects the browser here with the code it issued.
     *
     * There is no JSON to answer with, the user is mid-navigation, so every
     * outcome ends in a redirect: to the client page that swaps the code for a
     * token when it worked, back to the page they started from when it did not.
     */
    public function googleCallback(Request $request)
    {
        // Read outside the try as well: a failure on the very first step still
        // has to know which page to send the user back to.
        $attempt = null;

        try {
            // Checked before anything else: without our own state there is no
            // proof that this callback belongs to a sign-in started here.
            $attempt = $this->google->consumeState($request->query('state'));

            if ($request->filled('error')) {
                throw new GoogleAuthException(
                    $request->query('error') === 'access_denied'
                        ? GoogleAuthException::DENIED
                        : GoogleAuthException::FAILED,
                    'Google did not complete the sign-in.'
                );
            }

            [$user, $created] = $this->google->resolve(
                $this->google->googleAccount(),
                $attempt['role_id'] ?? null,
            );

            $code = $this->google->storeHandoff($user, $created);
        } catch (GoogleAuthException $e) {
            return $this->googleFailure($e->reason, $attempt['return_to'] ?? 'login');
        }

        return redirect()->away(
            $this->frontendUrl('/auth/google/callback', ['code' => $code])
        );
    }

    /**
     * Step 3: the client exchanges the one-time code for the same
     * { user, token } payload the email/password login returns, so signing in
     * with Google and signing in with a password end up in the same session.
     */
    public function googleExchange(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:200',
        ]);

        $handoff = $this->google->takeHandoff($validated['code']);
        $user = $handoff ? User::find($handoff['user_id']) : null;

        if (! $user) {
            return response()->json([
                'message' => 'This Google sign-in link has expired. Please try again.',
            ], 401);
        }

        // The account can be deactivated between the consent screen and here.
        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        return response()->json([
            'user' => $this->presentUser($user),
            'token' => $user->createToken('auth-token')->plainTextToken,
            'created' => (bool) ($handoff['created'] ?? false),
        ]);
    }

    /**
     * Sends the browser back to the client, carrying nothing but the reason the
     * sign-in did not happen: no internal message, no user, no code.
     */
    private function googleFailure(string $reason, ?string $returnTo): RedirectResponse
    {
        return redirect()->away($this->frontendUrl(
            $returnTo === 'register' ? '/register' : '/login',
            ['google' => $reason],
        ));
    }

    /**
     * The client page, on the origin the browser is actually using.
     */
    private function frontendUrl(string $path, array $query = []): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
