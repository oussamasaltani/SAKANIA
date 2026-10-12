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
use OpenApi\Attributes as OA;

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

    #[OA\Post(
        path: '/register',
        operationId: 'authRegister',
        tags: ['Authentication'],
        summary: 'Register a tenant or owner account',
        description: 'Creates an account and returns a Sanctum personal access token. Rate limited to 3 requests every 10 minutes per IP.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['full_name', 'email', 'password', 'password_confirmation', 'role_id'],
                properties: [
                    new OA\Property(property: 'full_name', type: 'string', maxLength: 255, example: 'Amina El Idrissi'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'amina@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'secret-password'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'secret-password'),
                    new OA\Property(property: 'role_id', type: 'integer', enum: [1, 2], description: '1 = tenant, 2 = owner.', example: 1),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Account created.', content: new OA\JsonContent(ref: '#/components/schemas/AuthResponse')),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ],
    )]
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

    #[OA\Post(
        path: '/login',
        operationId: 'authLogin',
        tags: ['Authentication'],
        summary: 'Sign in with email and password',
        description: 'Returns a Sanctum personal access token to send as `Authorization: Bearer <token>`. Rate limited to 5 requests per minute per IP.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'amina@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret-password'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated.', content: new OA\JsonContent(ref: '#/components/schemas/AuthResponse')),
            new OA\Response(response: 403, description: 'The account has been deactivated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 422, description: 'The credentials are incorrect.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
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

    #[OA\Post(
        path: '/logout',
        operationId: 'authLogout',
        tags: ['Authentication'],
        summary: 'Sign out',
        description: 'Revokes the access token that made the request.',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logged out.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    #[OA\Get(
        path: '/user',
        operationId: 'authUser',
        tags: ['Authentication'],
        summary: 'The authenticated user',
        description: 'Returns the signed-in account with its role flattened to a name and its avatar resolved to a URL.',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The signed-in user.', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
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
    #[OA\Post(
        path: '/auth/google/redirect',
        operationId: 'authGoogleRedirect',
        tags: ['Authentication'],
        summary: 'Start Google sign-in',
        description: 'Step 1. Returns the Google consent URL the client must send the browser to. Rate limited to 10 requests per minute.',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'role_id', type: 'integer', enum: [1, 2], description: 'Role chosen on the sign-up form.', nullable: true),
                    new OA\Property(property: 'return_to', type: 'string', enum: ['login', 'register'], nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The Google authorization URL.',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'url', type: 'string', format: 'uri')],
                ),
            ),
            new OA\Response(response: 503, description: 'Google sign-in is not configured.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
        ],
    )]
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
    #[OA\Get(
        path: '/auth/google/callback',
        operationId: 'authGoogleCallback',
        tags: ['Authentication'],
        summary: 'Google OAuth callback',
        description: 'Step 2. This endpoint is a browser navigation, not an API call: it always answers with a redirect, back to the client with a one-time `code` on success or back to the login/register page with a failure reason.',
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'error', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect back to the frontend application.'),
        ],
    )]
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
    #[OA\Post(
        path: '/auth/google/exchange',
        operationId: 'authGoogleExchange',
        tags: ['Authentication'],
        summary: 'Exchange the Google hand-off code for a token',
        description: 'Step 3. Swaps the one-time code produced by the callback for the same `{ user, token }` payload the password login returns.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [new OA\Property(property: 'code', type: 'string', maxLength: 200)],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated.', content: new OA\JsonContent(ref: '#/components/schemas/AuthResponse')),
            new OA\Response(response: 401, description: 'The code has expired.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 403, description: 'The account has been deactivated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
        ],
    )]
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
