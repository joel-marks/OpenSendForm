<?php

declare(strict_types=1);

namespace OpenSendForm\Http;

use OpenSendForm\Submit\SubmitOutcome;
use Psr\Http\Message\ResponseInterface;

/**
 * The no-JavaScript fallback pages for the submission endpoint.
 *
 * Progressive enhancement is absolute: a plain <form> whose action is the
 * submit URL still works with JS absent or failed. Such a request is a
 * top-level browser navigation that prefers text/html, so instead of the JSON
 * contract the endpoint returns one of these minimal, self-contained pages —
 * inline styles only, no assets, no scripts — echoing success or the failure
 * message with a link back to the page the submission came from.
 *
 * The JSON contract is unchanged; content negotiation in Routes::submit picks
 * this renderer only when the client explicitly prefers HTML.
 */
final class SubmitHtmlPage
{
    /**
     * Render the outcome as an HTML page. Success mirrors the 200 of the JSON
     * contract; a failure keeps the outcome's own HTTP status.
     *
     * @param string|null $backUrl A validated http(s) URL to link back to, or null.
     */
    public static function render(
        ResponseInterface $response,
        SubmitOutcome $outcome,
        ?string $backUrl
    ): ResponseInterface {
        if ($outcome->isSuccess()) {
            $body = self::page(
                'Message sent',
                'Message sent',
                'Thanks — your message has been sent.',
                $backUrl
            );
            $status = 200;
        } else {
            $body = self::page(
                'Something went wrong',
                'Something went wrong',
                $outcome->message(),
                $backUrl
            );
            $status = $outcome->status();
        }

        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    private static function page(string $title, string $heading, string $message, ?string $backUrl): string
    {
        $t = self::esc($title);
        $h = self::esc($heading);
        $m = self::esc($message);

        $back = '';
        if ($backUrl !== null) {
            $back = '<p class="back"><a href="' . self::esc($backUrl) . '">&larr; Back to the form</a></p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$t}</title>
<style>
:root{color-scheme:light dark}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.5;margin:0;
min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;background:#f4f5f7;color:#1c1e21}
main{max-width:32rem;width:100%;background:#fff;border-radius:.5rem;padding:2rem;
box-shadow:0 4px 24px rgba(0,0,0,.08);text-align:center}
h1{margin:0 0 .5rem;font-size:1.5rem}
p{margin:0 0 1rem}
.back a{color:#2563eb}
@media(prefers-color-scheme:dark){body{background:#16181c;color:#e6e7e9}main{background:#22252a;box-shadow:none}.back a{color:#8ab4ff}}
</style>
</head>
<body>
<main>
<h1>{$h}</h1>
<p>{$m}</p>
{$back}
</main>
</body>
</html>
HTML;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
