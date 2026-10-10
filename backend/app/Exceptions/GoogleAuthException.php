<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Google sign-in that cannot be completed, carrying a machine readable reason.
 *
 * The user is on the way back from Google when this is thrown, so there is no
 * longer a plain API request to answer with a JSON error body: the callback
 * turns the reason into a short flag on the redirect to the login page and the
 * client shows the message that belongs to it. The reason is always one of the
 * fixed strings below and never an internal message, so nothing about the server
 * is handed to the browser through the URL.
 */
class GoogleAuthException extends RuntimeException
{
    /** The Google credentials are missing from .env, so the button cannot work. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The user pressed "Cancel" on the Google consent screen. */
    public const DENIED = 'denied';

    /** The state is missing or expired: the request was not started here. */
    public const INVALID_STATE = 'invalid_state';

    /** Google returned no email address, or one it has not verified. */
    public const UNVERIFIED_EMAIL = 'unverified_email';

    /** That email already belongs to a SAKANIA account linked elsewhere. */
    public const EMAIL_TAKEN = 'email_taken';

    /** The matching account has been deactivated by an administrator. */
    public const INACTIVE = 'inactive';

    /** Anything unexpected: Google refused the code, or could not be reached. */
    public const FAILED = 'failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * The status the equivalent JSON endpoint answers with for this failure.
     */
    public function httpStatus(): int
    {
        return $this->reason === self::NOT_CONFIGURED ? 503 : 422;
    }
}
