<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use InvalidArgumentException;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Auth\PasswordHasher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin user-management screen.
 *
 * OpenSendForm is single-tenant: every admin is a co-operator of the one
 * installation and sees all forms and submissions. This screen lists the
 * roster, lets any admin add another, deactivates or reactivates them, and
 * can permanently delete one.
 *
 * Two hard guards protect availability, enforced server-side regardless of
 * what the UI offered (a forged POST cannot bypass them):
 *  - the last remaining ACTIVE admin can never be deactivated OR deleted
 *    (deleting an INACTIVE admin is always allowed, even if they were once
 *    the only admin);
 *  - an admin can never deactivate or delete their OWN account.
 * Deletion additionally re-authenticates: the acting admin must re-enter
 * their own CURRENT password on the confirmation step, and the confirmation
 * screen states plainly that deletion is permanent. Deactivation remains the
 * reversible alternative for retiring an admin without erasing the account.
 */
final class AdminsController
{
    private const MIN_PASSWORD_LENGTH = 12;

    // --- List -------------------------------------------------------------

    public static function index(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $status = 200,
        array $formVars = []
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $admins = self::admins($c);

        return AdminView::renderPage($c, $response, 'admins', $formVars + [
            'title'             => 'Admins',
            'admins'            => $admins->listAll(),
            'currentAdminId'    => (int) $admin['id'],
            'activeCount'       => $admins->countActive(),
            'minPasswordLength' => self::MIN_PASSWORD_LENGTH,
            // Preserve create-form input on a re-render; blank on first load.
            'newEmail'          => (string) ($formVars['newEmail'] ?? ''),
            'newName'           => (string) ($formVars['newName'] ?? ''),
            'error'             => (string) ($formVars['error'] ?? ''),
        ], 'admins', $status);
    }

    // --- Create -----------------------------------------------------------

    public static function create(
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
            return self::index($c, $request, $response, 400, [
                'error' => 'Your session expired. Please try again.',
            ]);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return self::index($c, $request, $response, 422, [
                'error'    => 'Initial password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
                'newEmail' => $email,
                'newName'  => $name,
            ]);
        }

        if (self::admins($c)->findByEmail($email) !== null) {
            return self::index($c, $request, $response, 422, [
                'error'    => 'An admin with that email already exists.',
                'newEmail' => $email,
                'newName'  => $name,
            ]);
        }

        try {
            $created = self::admins($c)->createAdmin($email, $name, $password);
        } catch (InvalidArgumentException $e) {
            return self::index($c, $request, $response, 422, [
                'error'    => $e->getMessage(),
                'newEmail' => $email,
                'newName'  => $name,
            ]);
        }

        self::flash($c)->success('Admin "' . $created['email'] . '" created. Ask them to change their password after first sign-in.');

        return self::redirect($response, '/admin/admins');
    }

    // --- Deactivate / reactivate -----------------------------------------

    public static function deactivate(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/admins');
        }

        $admins = self::admins($c);
        $id = (int) ($args['id'] ?? 0);
        $target = $admins->findById($id);

        if ($target === null) {
            self::flash($c)->error('That admin no longer exists.');

            return self::redirect($response, '/admin/admins');
        }

        // Availability guard: never deactivate the last remaining active admin
        // (whether that is yourself or someone else). Enforced here regardless
        // of whether the UI offered the button.
        if ((int) $target['is_active'] === 1 && $admins->countActive() <= 1) {
            self::flash($c)->error('You cannot deactivate the last active admin.');

            return self::redirect($response, '/admin/admins');
        }

        $admins->setActive($id, false);
        $who = (int) $target['id'] === (int) $admin['id'] ? 'your own account' : ('"' . $target['email'] . '"');
        self::flash($c)->success('Deactivated ' . $who . '.');

        return self::redirect($response, '/admin/admins');
    }

    public static function reactivate(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/admins');
        }

        $id = (int) ($args['id'] ?? 0);
        $target = self::admins($c)->findById($id);
        if ($target === null) {
            self::flash($c)->error('That admin no longer exists.');

            return self::redirect($response, '/admin/admins');
        }

        self::admins($c)->setActive($id, true);
        self::flash($c)->success('Reactivated "' . $target['email'] . '".');

        return self::redirect($response, '/admin/admins');
    }

    // --- Delete -------------------------------------------------------------

    /**
     * Confirmation step: shows the target's email, states that deletion is
     * permanent, and asks the acting admin to re-enter their own current
     * password. Guarded up front so a forged link to this screen for an
     * ineligible target simply bounces back with an explanation instead of
     * rendering a form that could never succeed.
     */
    public static function deleteConfirm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $id = (int) ($args['id'] ?? 0);
        $target = self::admins($c)->findById($id);
        $guardError = self::deletionGuardError($c, $admin, $target, $id);
        if ($guardError !== null) {
            self::flash($c)->error($guardError);

            return self::redirect($response, '/admin/admins');
        }

        return self::renderDeleteConfirm($c, $response, $target);
    }

    /**
     * Performs the delete. Every guard is re-checked here regardless of what
     * the confirmation screen showed, so a forged POST (skipping the GET
     * step, or targeting an id the UI never offered) cannot bypass them.
     */
    public static function delete(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/admins');
        }

        $id = (int) ($args['id'] ?? 0);
        $target = self::admins($c)->findById($id);
        $guardError = self::deletionGuardError($c, $admin, $target, $id);
        if ($guardError !== null) {
            self::flash($c)->error($guardError);

            return self::redirect($response, '/admin/admins');
        }

        $password = (string) ($data['current_password'] ?? '');
        if (!self::hasher($c)->verify($password, (string) $admin['password_hash'])) {
            return self::renderDeleteConfirm($c, $response, $target, 'That is not your current password.', 401);
        }

        self::admins($c)->deleteAdmin($id);
        self::flash($c)->success('Deleted "' . $target['email'] . '". This cannot be undone.');

        return self::redirect($response, '/admin/admins');
    }

    /**
     * Shared guard logic for both the confirmation screen and the delete
     * action itself: target must exist, must not be the last remaining
     * active admin (deleting an inactive one is always allowed, whatever the
     * active count — checked first, matching the deactivate guard), and must
     * not be the acting admin themselves.
     *
     * @param array<string, mixed>      $admin  The acting (signed-in) admin.
     * @param array<string, mixed>|null $target The admin being deleted.
     */
    private static function deletionGuardError(ContainerInterface $c, array $admin, ?array $target, int $id): ?string
    {
        if ($target === null) {
            return 'That admin no longer exists.';
        }
        if ((int) $target['is_active'] === 1 && self::admins($c)->countActive() <= 1) {
            return 'You cannot delete the last active admin.';
        }
        if ($id === (int) $admin['id']) {
            return 'You cannot delete your own account.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function renderDeleteConfirm(
        ContainerInterface $c,
        ResponseInterface $response,
        array $target,
        string $error = '',
        int $status = 200
    ): ResponseInterface {
        return AdminView::renderPage($c, $response, 'admin_delete_confirm', [
            'title'       => 'Delete admin',
            'targetId'    => (int) $target['id'],
            'targetEmail' => (string) $target['email'],
            'error'       => $error,
        ], 'admins', $status);
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
