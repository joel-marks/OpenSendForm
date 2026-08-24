<?php

declare(strict_types=1);

namespace OpenSendForm\Submit;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Mutable state threaded through the submission pipeline.
 *
 * Constructed once per request from the incoming ServerRequest; each stage
 * reads what earlier stages have populated and fills in its own results.
 */
final class SubmitContext
{
    /** Reserved field names carried alongside the user's form data. */
    public const FIELD_TOKEN = '_osf_token';
    public const FIELD_HONEYPOT = '_osf_hp';

    public ServerRequestInterface $request;

    /** Raw request body, read once for size checks and parsing. */
    public string $rawBody = '';

    /** Lower-cased content-type without parameters (e.g. "application/json"). */
    public string $contentType = '';

    /**
     * All submitted fields keyed by name. The method/body stage populates
     * this with the raw parsed values (which may be non-scalar); the field
     * hygiene stage then narrows it to validated strings.
     *
     * @var array<string, mixed>
     */
    public array $fields = [];

    /**
     * User fields only — reserved _osf_* keys removed (set by the field
     * hygiene stage). This is what gets serialised and stored.
     *
     * @var array<string, string>
     */
    public array $userFields = [];

    /** The form key from the request URL (/v1/form/{form_key}/submit). */
    public ?string $formKey;

    public ?string $token = null;
    public ?string $honeypot = null;

    /**
     * The looked-up, active form (set by the form-lookup stage).
     *
     * @var array<string, mixed>|null
     */
    public ?array $form = null;

    /**
     * The request origin once validated against the form allowlist (set by
     * the origin stage). Drives CORS headers on the response.
     */
    public ?string $matchedOrigin = null;

    public string $remoteIp = '';
    public ?string $userAgent = null;
    public ?string $originHeader = null;
    public ?string $refererHeader = null;

    public function __construct(ServerRequestInterface $request, ?string $formKey = null)
    {
        $this->request = $request;
        $this->formKey = $formKey === '' ? null : $formKey;

        $server = $request->getServerParams();
        // REMOTE_ADDR only. Trusting X-Forwarded-For behind a proxy is a
        // deliberate future config concern, not a default.
        $this->remoteIp = (string) ($server['REMOTE_ADDR'] ?? '');

        $ua = $request->getHeaderLine('User-Agent');
        $this->userAgent = $ua === '' ? null : $ua;

        $origin = $request->getHeaderLine('Origin');
        $this->originHeader = $origin === '' ? null : $origin;

        $referer = $request->getHeaderLine('Referer');
        $this->refererHeader = $referer === '' ? null : $referer;
    }
}
