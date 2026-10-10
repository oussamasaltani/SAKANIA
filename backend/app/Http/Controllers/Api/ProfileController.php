<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own account, for every role.
 *
 * This used to be duplicated in TenantController and OwnerController with two
 * nearly identical updateProfile() methods and no avatar or password support at
 * all. One controller now owns it and both role routes point here, so the rules
 * are identical whichever account is editing itself.
 *
 * Nothing here may change anything but the user's own row: the role, the
 * activation flag and the email are all outside what this endpoint accepts.
 */
class ProfileController extends Controller
{
    private const AVATAR_DIRECTORY = 'uploads/profiles';

    public function __construct(private readonly ImageUploadService $images)
    {
    }

    /**
     * The current account.
     *
     * `role` is flattened to its name here, matching /api/user and the login and
     * register responses, so the client can treat both shapes as the same user.
     */
    public function show(Request $request)
    {
        return response()->json($this->present($request->user()));
    }

    /**
     * Updates the personal information of the current account.
     *
     * Every field is optional and only the fields actually present are written,
     * so saving the form without touching the avatar leaves the stored avatar
     * exactly where it is instead of clearing it.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $rules = [
            'full_name' => 'sometimes|required|string|max:100',
            'phone' => 'sometimes|nullable|string|max:20',
            'avatar' => ['sometimes', 'nullable', 'string', 'max:' . (int) (ImageUploadService::PROFILE_MAX_BYTES * 4 / 3 + 1024)],
        ];

        // The company name belongs to an owner account only; a tenant sending one
        // is ignored rather than rejected, so the shared form stays usable.
        if ($user->role_id === 2) {
            $rules['company_name'] = 'sometimes|nullable|string|max:150';
        }

        $validated = $request->validate($rules);

        $attributes = [];

        if (array_key_exists('full_name', $validated)) {
            $attributes['full_name'] = trim($validated['full_name']);
        }

        if (array_key_exists('phone', $validated)) {
            $attributes['phone'] = $validated['phone'] !== null ? trim($validated['phone']) : null;
        }

        if (array_key_exists('company_name', $validated) && $user->role_id === 2) {
            $attributes['company_name'] = $validated['company_name'] !== null
                ? trim($validated['company_name'])
                : null;
        }

        if (array_key_exists('avatar', $validated) && $validated['avatar'] !== null && $validated['avatar'] !== '') {
            $stored = $this->images->storeDataUri(
                $validated['avatar'],
                self::AVATAR_DIRECTORY,
                ImageUploadService::PROFILE_MAX_BYTES
            );

            if ($stored === null) {
                throw ValidationException::withMessages([
                    'avatar' => ['The picture must be a JPEG, PNG, WEBP or GIF image smaller than 2MB.'],
                ]);
            }

            // Only replace the reference once the new file is on disk, and drop
            // the previous upload so repeated changes do not pile files up. The
            // old path is deleted only after the row has been saved.
            $previousAvatar = $user->avatar;
            $attributes['avatar'] = $stored;

            $user->fill($attributes)->save();

            if ($previousAvatar && $previousAvatar !== $stored) {
                $this->images->deleteStored($previousAvatar, self::AVATAR_DIRECTORY);
            }
        } else {
            // No picture in the payload: save whatever else changed and keep the
            // avatar that is already stored.
            $user->fill($attributes)->save();
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->present($user->fresh()),
        ]);
    }

    /**
     * Removes the current account's profile picture.
     *
     * The path always comes from the signed-in row ($request->user()), never
     * from the payload, so this endpoint cannot reach another account's file.
     * The reference is cleared before the file is unlinked: an unlink that
     * fails only leaves an orphaned file behind, never a URL that 404s.
     */
    public function destroyAvatar(Request $request)
    {
        $user = $request->user();

        if (! $user->avatar) {
            throw ValidationException::withMessages([
                'avatar' => ['You do not have a profile picture to remove.'],
            ]);
        }

        $previousAvatar = $user->avatar;

        $user->fill(['avatar' => null])->save();

        $this->images->deleteStored($previousAvatar, self::AVATAR_DIRECTORY);

        return response()->json([
            'message' => 'Profile picture removed successfully.',
            'user' => $this->present($user->fresh()),
        ]);
    }

    /**
     * Changes the password of the current account.
     *
     * The current password has to be supplied, which keeps a borrowed session
     * from locking the real owner out. Every other token is revoked afterwards,
     * so a password change ends any other session that was opened with the old
     * one; the token making this request stays valid.
     *
     * An account that only ever signs in with Google has no password at all, so
     * there is nothing to ask for and nothing to confirm: this is the one way it
     * gets its first password, after which the rule above applies to it like to
     * every other account.
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();
        $hasPassword = $user->hasPassword();

        $rules = ['password' => 'required|string|min:8|confirmed'];

        if ($hasPassword) {
            $rules['current_password'] = 'required|string';
        }

        $validated = $request->validate($rules);

        if ($hasPassword && ! Hash::check($validated['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if ($hasPassword && Hash::check($validated['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from the current one.'],
            ]);
        }

        $user->forceFill(['password_hash' => $validated['password']])->save();

        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * The user shape the client keeps in AuthContext, so a profile save can
     * replace the session user without a refetch.
     *
     * has_password tells the password form whether the current password has to be
     * asked for, which is the one thing it cannot work out from a hash it never
     * receives.
     */
    private function present(User $user): array
    {
        $user->loadMissing('role');

        $data = $user->toArray();
        $data['role'] = $user->role?->name;
        $data['avatar_url'] = $user->avatar ? '/'.ltrim($user->avatar, '/') : null;
        $data['has_password'] = $user->hasPassword();

        return $data;
    }
}