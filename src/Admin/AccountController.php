<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use InvalidArgumentException;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\SessionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The signed-in admin's own account screen: display name, email and password.
 *
 * Every sensitive change is re-authenticated: changing the email or the
 * password requires the CURRENT password (proven against the stored hash),
 * so a walk-up to an unlocked session cannot silently take the account over.
 * The display name is not sensitive and changes with only CSRF. On a
 * successful password change the session id is regenerated, matching the
 * privilege-change rotation the login flow performs.
 */
final class AccountController
{
    private const MIN_PASSWORD_LENGTH = 12;

    // --- Screen -----------------------------------------------------------

    public static function index(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        return self::renderAccount($c, $response, $admin);
    }

    // --- Change display name ---------------------------------------------

    public static function updateName(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/account');
        }

        try {
            self::admins($c)->updateDisplayName($admin['id'], (string) ($data['display_name'] ?? ''));
        } catch (InvalidArgumentException $e) {
            self::flash($c)->error($e->getMessage());

            return self::redirect($response, '/admin/account');
        }

        self::flash($c)->success('Display name updated.');

        return self::redirect($response, '/admin/account');
    }

    // --- Change email -----------------------------------------------------

    public static function updateEmail(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/account');
        }

        $currentPassword = (string) ($data['current_password'] ?? '');
        if (!self::hasher($c)->verify($currentPassword, (string) $admin['password_hash'])) {
            self::flash($c)->error('That is not your current password.');

            return self::redirect($response, '/admin/account');
        }

        $newEmail = strtolower(trim((string) ($data['email'] ?? '')));

        // Uniqueness: reject when another admin already owns the email. The
        // no-op case (same email) is allowed and simply re-saves.
        $existing = self::admins($c)->findByEmail($newEmail);
        if ($existing !== null && (int) $existing['id'] !== (int) $admin['id']) {
            self::flash($c)->error('That email is already in use by another admin.');

            return self::redirect($response, '/admin/account');
        }

        try {
            self::admins($c)->updateEmail($admin['id'], $newEmail);
        } catch (InvalidArgumentException $e) {
            self::flash($c)->error('Please enter a valid email address.');

            return self::redirect($response, '/admin/account');
        }

        self::flash($c)->success('Email updated.');

        return self::redirect($response, '/admin/account');
    }

    // --- Change password --------------------------------------------------

    public static function updatePassword(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/account');
        }

        $currentPassword = (string) ($data['current_password'] ?? '');
        if (!self::hasher($c)->verify($currentPassword, (string) $admin['password_hash'])) {
            self::flash($c)->error('That is not your current password.');

            return self::redirect($response, '/admin/account');
        }

        $new = (string) ($data['new_password'] ?? '');
        $confirm = (string) ($data['confirm_password'] ?? '');

        if ($new !== $confirm) {
            self::flash($c)->error('The new passwords do not match.');

            return self::redirect($response, '/admin/account');
        }
        if (strlen($new) < self::MIN_PASSWORD_LENGTH) {
            self::flash($c)->error('New password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');

            return self::redirect($response, '/admin/account');
        }

        self::admins($c)->updatePassword($admin['id'], $new);

        // Rotate the session id on this privilege change, mirroring login.
        self::session($c)->regenerate();

        self::flash($c)->success('Password updated.');

        return self::redirect($response, '/admin/account');
    }

    // --- Rendering --------------------------------------------------------

    /**
     * @param array<string, mixed> $admin
     */
    private static function renderAccount(
        ContainerInterface $c,
        ResponseInterface $response,
        array $admin,
        int $status = 200
    ): ResponseInterface {
        return AdminView::renderPage($c, $response, 'account', [
            'title'            => 'Your account',
            'email'            => (string) $admin['email'],
            'displayName'      => (string) $admin['display_name'],
            'totpEnabled'      => (int) $admin['totp_enabled'] === 1,
            'minPasswordLength' => self::MIN_PASSWORD_LENGTH,
        ], 'account', $status);
    }

    // --- Container accessors & helpers ------------------------------------

    private static function auth(ContainerInterface $c): AuthService
    {
        /** @var AuthService $s */
        $s = $c->get(AuthService::class);

        return $s;
    }

    private static function csrf(ContainerInterface $c): Csrf
    {
        /** @var Csrf $s */
        $s = $c->get(Csrf::class);

        return $s;
    }

    private static function admins(ContainerInterface $c): AdminRepository
    {
        /** @var AdminRepository $s */
        $s = $c->get(AdminRepository::class);

        return $s;
    }

    private static function hasher(ContainerInterface $c): PasswordHasher
    {
        /** @var PasswordHasher $s */
        $s = $c->get(PasswordHasher::class);

        return $s;
    }

    private static function session(ContainerInterface $c): SessionInterface
    {
        /** @var SessionInterface $s */
        $s = $c->get(SessionInterface::class);

        return $s;
    }

    private static function flash(ContainerInterface $c): Flash
    {
        /** @var Flash $f */
        $f = $c->get(Flash::class);

        return $f;
    }

    /**
     * @return array<string, mixed>
     */
    private static function formData(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        parse_str((string) $request->getBody(), $data);

        return $data;
    }

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
