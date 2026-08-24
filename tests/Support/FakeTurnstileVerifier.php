<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Turnstile\TurnstileResult;
use OpenSendForm\Turnstile\TurnstileVerifierInterface;

/**
 * A Turnstile verifier test double. Records every verify() call and returns a
 * scripted result (valid / invalid / outage) so pipeline behaviour is
 * exercised without ever touching the real Cloudflare API. Defaults to VALID.
 */
final class FakeTurnstileVerifier implements TurnstileVerifierInterface
{
    /**
     * Every verify() call, in order.
     *
     * @var array<int, array{secret:string, token:string, remoteIp:string}>
     */
    public array $calls = [];

    private TurnstileResult $result;

    public function __construct(TurnstileResult $result = TurnstileResult::VALID)
    {
        $this->result = $result;
    }

    /** Script the result returned by subsequent verify() calls. */
    public function returns(TurnstileResult $result): void
    {
        $this->result = $result;
    }

    public function verify(string $secret, string $token, string $remoteIp): TurnstileResult
    {
        $this->calls[] = [
            'secret'   => $secret,
            'token'    => $token,
            'remoteIp' => $remoteIp,
        ];

        return $this->result;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
