<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * PHP-native session implementation — the ONLY place $_SESSION is touched.
 *
 * The session is started lazily on first access so the public API endpoints,
 * which never read or write session state, do not emit a session cookie. When
 * it does start, the cookie is hardened: HttpOnly, SameSite=Strict, Secure
 * whenever the request arrived over HTTPS, and path-scoped to the site root.
 */
final class NativeSession implements SessionInterface
{
    private bool $started = false;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->ensureStarted();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->ensureStarted();

        $_SESSION = [];

        // Expire the session cookie in the browser as well as dropping the
        // server-side data, so a stale id cannot be replayed.
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]
            );
        }

        session_destroy();
        $this->started = false;
    }

    /**
     * Start the session once, with hardened cookie parameters. A no-op if a
     * session is already active (e.g. started by another component).
     */
    private function ensureStarted(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => self::isHttps(),
        ]);

        // Refuse attacker-supplied session ids: without strict mode PHP will
        // happily adopt whatever id arrives in the cookie, enabling session
        // fixation.
        ini_set('session.use_strict_mode', '1');
        session_start();
        $this->started = true;
    }

    /**
     * Detect a direct HTTPS request. Trusting a proxy's forwarded proto is a
     * deliberate future configuration concern, consistent with how the rest
     * of the app treats REMOTE_ADDR.
     */
    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return $https !== '' && strtolower((string) $https) !== 'off';
    }
}
