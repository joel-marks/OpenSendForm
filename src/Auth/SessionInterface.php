<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * The narrow session surface the admin stack depends on.
 *
 * All session access goes through this interface so $_SESSION is never
 * touched directly outside NativeSession, and tests can drive the whole
 * auth flow against an in-memory fake. regenerate() rotates the session id
 * (called on any privilege change) and destroy() clears it entirely.
 */
interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Rotate the underlying session id, preserving the stored data. Called
     * on every privilege change (login, TOTP pass) to prevent fixation.
     */
    public function regenerate(): void;

    /**
     * Discard all session data and invalidate the session entirely.
     */
    public function destroy(): void;
}
