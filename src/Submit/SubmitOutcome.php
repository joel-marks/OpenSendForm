<?php

declare(strict_types=1);

namespace OpenSendForm\Submit;

/**
 * The terminal result of running the submission pipeline.
 *
 * Two shapes reach the client:
 *  - success: rendered as {"ok":true} with HTTP 200. Note that a genuine
 *    stored submission and a silently-discarded one (honeypot, bad token)
 *    both produce an identical success outcome — bots get no signal.
 *  - error: rendered as {"ok":false,"error":{code,message}} with the given
 *    HTTP status.
 */
final class SubmitOutcome
{
    private bool $success;
    private int $status;
    private string $code;
    private string $message;

    private function __construct(bool $success, int $status, string $code, string $message)
    {
        $this->success = $success;
        $this->status = $status;
        $this->code = $code;
        $this->message = $message;
    }

    public static function success(): self
    {
        return new self(true, 200, '', '');
    }

    public static function error(int $status, string $code, string $message): self
    {
        return new self(false, $status, $code, $message);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }
}
