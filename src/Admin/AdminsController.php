<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use InvalidArgumentException;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\Csrf;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin user-management screen.
 *
 * OpenSendForm is single-tenant: every admin is a co-operator of the one
 * installation and sees all forms and submissions. This screen lists the
 * roster, lets any admin add another, and deactivates or reactivates them.
 *
 * There is no delete action (deferred by design) — retirement is deactivation.
 * A hard guard protects availability: the last remaining ACTIVE admin can
 * never be deactivated (including deactivating yourself when you are the last
 * one), so an installation can never be locked out of its own admin area. The
 * guard is enforced server-side; the list also hides the button in that case.
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
