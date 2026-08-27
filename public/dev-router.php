<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server (`composer run serve`) ONLY. Never the
 * production entry point — production is public/index.php via Apache's
 * front-controller rewrite.
 *
 * The built-in server does not forward a request whose path maps to an
 * existing file to the router script the way Apache's rewrite does; it also
 * refuses to route requests with a "file-like" extension (e.g.
 * /embed/manual.html) to a script at all, serving 404 for anything that isn't
 * a real file. So: when the requested path IS an existing file under public/,
 * return false and let the built-in server serve those bytes natively;
 * otherwise hand the request to index.php, exactly like Apache's rewrite does
 * for a real front controller.
 */

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
