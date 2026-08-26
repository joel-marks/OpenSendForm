<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use InvalidArgumentException;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Version;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin form-management screens: list, create, edit and enable/disable.
 *
 * All validation and normalisation is delegated to FormRepository — the
 * controller only marshals request input, decides the Turnstile pairing, and
 * re-renders inline on failure with the submitted values preserved. The
 * Turnstile secret is write-only: it is never echoed back; the edit screen
 * shows a set/not-set indicator instead.
 */
final class FormsController
{
    // --- List -------------------------------------------------------------

    public static function index(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return AdminView::renderPage($c, $response, 'forms_list', [
            'title' => 'Forms',
            'forms' => self::forms($c)->listForms(),
        ], 'forms');
    }

    // --- Create -----------------------------------------------------------

    public static function createForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return AdminView::renderPage($c, $response, 'form_edit', self::blankFormVars($c), 'forms');
    }

    public static function create(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderInvalid($c, $request, $response, null, $data, 'Your session expired. Please try again.', 400);
        }

        $input = self::readInput($data);

        try {
            $pair = self::resolveTurnstile($input['tsSitekey'], $input['tsSecret'], null);
            $form = self::forms($c)->createForm(
                $input['name'],
                $input['recipient'],
                $input['origins'],
                $input['storeContent'],
                $input['retentionDays'],
                $input['isActive']
            );
            self::forms($c)->setTurnstile((int) $form['id'], $pair[0], $pair[1]);
        } catch (InvalidArgumentException $e) {
            return self::renderInvalid($c, $request, $response, null, $data, $e->getMessage(), 422);
        }

        self::flash($c)->success('Form "' . $form['name'] . '" created.');

        return self::redirect($response, '/admin/forms');
    }

    // --- Edit -------------------------------------------------------------

    public static function editForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $form = self::forms($c)->findById((int) ($args['id'] ?? 0));
        if ($form === null) {
            self::flash($c)->error('That form no longer exists.');

            return self::redirect($response, '/admin/forms');
        }

        $vars = self::formVars($c, $form);
        $vars['installUrl'] = self::installUrl($request);
        $vars['embedVersion'] = Version::STRING;

        return AdminView::renderPage($c, $response, 'form_edit', $vars, 'forms');
    }

    public static function update(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $form = self::forms($c)->findById((int) ($args['id'] ?? 0));
        if ($form === null) {
            self::flash($c)->error('That form no longer exists.');

            return self::redirect($response, '/admin/forms');
        }

        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderInvalid($c, $request, $response, $form, $data, 'Your session expired. Please try again.', 400);
        }

        $input = self::readInput($data);

        try {
            $pair = self::resolveTurnstile($input['tsSitekey'], $input['tsSecret'], $form['turnstile_secret']);
            self::forms($c)->updateForm(
                (int) $form['id'],
                $input['name'],
                $input['recipient'],
                $input['origins'],
                $input['storeContent'],
                $input['retentionDays'],
                $input['isActive']
            );
            self::forms($c)->setTurnstile((int) $form['id'], $pair[0], $pair[1]);
        } catch (InvalidArgumentException $e) {
            return self::renderInvalid($c, $request, $response, $form, $data, $e->getMessage(), 422);
        }

        self::flash($c)->success('Form "' . $input['name'] . '" updated.');

        return self::redirect($response, '/admin/forms');
    }

    // --- Enable / disable -------------------------------------------------

    public static function enable(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        return self::setActive($c, $request, $response, $args, true);
    }

    public static function disable(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        return self::setActive($c, $request, $response, $args, false);
    }

    private static function setActive(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
        bool $active
    ): ResponseInterface {
        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirect($response, '/admin/forms');
        }

        $id = (int) ($args['id'] ?? 0);
        $updated = self::forms($c)->setActive($id, $active);

        if ($updated) {
            self::flash($c)->success('Form ' . ($active ? 'enabled.' : 'disabled.'));
        } else {
            self::flash($c)->error('That form no longer exists.');
        }

        return self::redirect($response, '/admin/forms');
    }

    // --- Input marshalling ------------------------------------------------

    /**
     * Pull and lightly shape the posted fields. Validation proper lives in
     * FormRepository; here we only split the origins textarea and coerce the
     * checkbox/number fields.
     *
     * @param array<string, mixed> $data
     * @return array{name: string, recipient: string, origins: array<int, string>,
     *               storeContent: bool, retentionDays: int, isActive: bool,
     *               tsSitekey: string, tsSecret: string}
     */
    private static function readInput(array $data): array
    {
        return [
            'name'          => (string) ($data['name'] ?? ''),
            'recipient'     => (string) ($data['recipient'] ?? ''),
            'origins'       => self::splitOrigins((string) ($data['origins'] ?? '')),
            'storeContent'  => isset($data['store_content']),
            'retentionDays' => (int) ($data['retention_days'] ?? 0),
            'isActive'      => isset($data['is_active']),
            'tsSitekey'     => (string) ($data['turnstile_sitekey'] ?? ''),
            'tsSecret'      => (string) ($data['turnstile_secret'] ?? ''),
        ];
    }

    /**
     * Split a one-per-line origins textarea into a trimmed, non-empty list.
     *
     * @return array<int, string>
     */
    private static function splitOrigins(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $origins = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $origins[] = $line;
            }
        }

        return $origins;
    }

    /**
     * Decide the Turnstile pair to store, enforcing both-or-neither with a
     * "keep existing secret" convenience: a site key with a blank secret keeps
     * the currently stored secret (so the write-only field need not be
     * re-entered on every edit). Clearing the site key disables Turnstile.
     *
     * @return array{0: ?string, 1: ?string} [sitekey, secret]
     *
     * @throws InvalidArgumentException when exactly one of the pair is supplied
     *         and there is no existing secret to fall back on.
     */
    private static function resolveTurnstile(string $sitekey, string $secret, ?string $existingSecret): array
    {
        $sitekey = trim($sitekey);
        $secret = trim($secret);

        if ($sitekey === '' && $secret === '') {
            return [null, null];
        }
        if ($sitekey !== '' && $secret !== '') {
            return [$sitekey, $secret];
        }
        if ($sitekey !== '' && $secret === '' && $existingSecret !== null && $existingSecret !== '') {
            // Keep the stored secret; the site key may still change.
            return [$sitekey, $existingSecret];
        }

        throw new InvalidArgumentException('Turnstile needs both a site key and a secret, or clear both to disable it.');
    }

    // --- View-var builders ------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function blankFormVars(ContainerInterface $c): array
    {
        return [
            'title'             => 'New form',
            'isNew'             => true,
            'formId'            => null,
            'formKey'           => null,
            'name'              => '',
            'recipient'         => '',
            'origins'           => '',
            'storeContent'      => false,
            'retentionDays'     => 30,
            'isActive'          => true,
            'turnstileSitekey'  => '',
            'turnstileSecretSet' => false,
            'error'             => '',
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function formVars(ContainerInterface $c, array $form): array
    {
        return [
            'title'             => 'Edit form',
            'isNew'             => false,
            'formId'            => (int) $form['id'],
            'formKey'           => (string) $form['form_key'],
            'name'              => (string) $form['name'],
            'recipient'         => (string) $form['recipient_email'],
            'origins'           => implode("\n", $form['allowed_origins']),
            'storeContent'      => (int) $form['store_content'] === 1,
            'retentionDays'     => (int) $form['retention_days'],
            'isActive'          => (int) $form['is_active'] === 1,
            'turnstileSitekey'  => (string) ($form['turnstile_sitekey'] ?? ''),
            'turnstileSecretSet' => ($form['turnstile_secret'] ?? null) !== null,
            'error'             => '',
        ];
    }

    /**
     * Re-render the edit form after a validation failure, preserving the
     * submitted values (never the secret) and showing the message inline.
     *
     * @param array<string, mixed>|null $form Existing form on edit, null on create.
     * @param array<string, mixed>      $data Raw posted data.
     */
    private static function renderInvalid(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $form,
        array $data,
        string $message,
        int $status
    ): ResponseInterface {
        $vars = [
            'title'              => $form === null ? 'New form' : 'Edit form',
            'isNew'              => $form === null,
            'formId'             => $form === null ? null : (int) $form['id'],
            'formKey'            => $form === null ? null : (string) $form['form_key'],
            'name'               => (string) ($data['name'] ?? ''),
            'recipient'          => (string) ($data['recipient'] ?? ''),
            'origins'            => (string) ($data['origins'] ?? ''),
            'storeContent'       => isset($data['store_content']),
            'retentionDays'      => (int) ($data['retention_days'] ?? 30),
            'isActive'           => isset($data['is_active']),
            'turnstileSitekey'   => (string) ($data['turnstile_sitekey'] ?? ''),
            // Never echo the secret; keep the existing set/not-set state.
            'turnstileSecretSet' => $form !== null && ($form['turnstile_secret'] ?? null) !== null,
            'error'              => $message,
            // The embed panel only renders for an existing form (it needs a key).
            'installUrl'         => $form === null ? '' : self::installUrl($request),
            'embedVersion'       => Version::STRING,
        ];

        return AdminView::renderPage($c, $response, 'form_edit', $vars, 'forms', $status);
    }

    /**
     * The installation's base URL (scheme://host[:port], no trailing path),
     * derived from the current request. Returned empty when the request carries
     * no host, so the embed panel degrades to a hint rather than a broken URL.
     */
    private static function installUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            return '';
        }

        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
        $authority = $host;
        $port = $uri->getPort();
        $isDefault = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        if ($port !== null && !$isDefault) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority;
    }

    // --- Container accessors & tiny helpers -------------------------------

    private static function forms(ContainerInterface $c): FormRepository
    {
        /** @var FormRepository $r */
        $r = $c->get(FormRepository::class);

        return $r;
    }

    private static function csrf(ContainerInterface $c): Csrf
    {
        /** @var Csrf $s */
        $s = $c->get(Csrf::class);

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
