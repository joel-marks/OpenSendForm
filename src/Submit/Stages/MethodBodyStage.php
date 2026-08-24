<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Config;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (a): method and body gate.
 *
 * Rejects non-POST requests and over-large bodies, then reads and parses
 * the body into the context. Accepts url-encoded, multipart and JSON
 * bodies. Cheapest checks first: method, declared Content-Length, then the
 * actual body length.
 */
final class MethodBodyStage implements Stage
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $request = $context->request;

        if (strtoupper($request->getMethod()) !== 'POST') {
            return SubmitOutcome::error(405, 'method_not_allowed', 'Only POST is accepted here.');
        }

        $max = $this->config->maxBodyBytes();

        // A declared Content-Length over the cap is rejected before reading.
        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $max) {
            return $this->tooLarge();
        }

        $raw = (string) $request->getBody();
        if (strlen($raw) > $max) {
            return $this->tooLarge();
        }

        $context->rawBody = $raw;
        $context->contentType = $this->contentType($request->getHeaderLine('Content-Type'));
        $context->fields = $this->parseFields($context, $raw);

        return null;
    }

    private function tooLarge(): SubmitOutcome
    {
        return SubmitOutcome::error(413, 'payload_too_large', 'Request body is too large.');
    }

    private function contentType(string $header): string
    {
        $type = $header;
        $semicolon = strpos($type, ';');
        if ($semicolon !== false) {
            $type = substr($type, 0, $semicolon);
        }

        return strtolower(trim($type));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFields(SubmitContext $context, string $raw): array
    {
        switch ($context->contentType) {
            case 'application/json':
                $decoded = json_decode($raw, true);
                // A non-object JSON body (or malformed) yields no fields; the
                // missing form key then surfaces as unknown_form downstream.
                return is_array($decoded) ? $decoded : [];

            case 'multipart/form-data':
                // Multipart is parsed by PHP/Slim into the parsed body; we do
                // not re-parse the raw multipart stream here. Uploaded files
                // are out of scope for this endpoint.
                $parsed = $context->request->getParsedBody();
                return is_array($parsed) ? $parsed : [];

            default:
                // application/x-www-form-urlencoded and anything else that
                // looks form-encoded.
                $fields = [];
                parse_str($raw, $fields);
                if ($fields === []) {
                    $parsed = $context->request->getParsedBody();
                    if (is_array($parsed)) {
                        return $parsed;
                    }
                }
                return $fields;
        }
    }
}
