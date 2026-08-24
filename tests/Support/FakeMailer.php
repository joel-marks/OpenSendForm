<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Mail\MailerInterface;
use RuntimeException;

/**
 * A mailer test double. Records every send() call and can be scripted to
 * fail, so delivery and retry behaviour is exercised without ever touching
 * a real SMTP server.
 */
final class FakeMailer implements MailerInterface
{
    /**
     * Every attempted send, in order. Each entry:
     * ['to','replyTo','subject','textBody'].
     *
     * @var array<int, array{to:string, replyTo:?string, subject:string, textBody:string}>
     */
    public array $calls = [];

    private bool $shouldFail = false;
    private string $failMessage = 'Simulated delivery failure';

    /** How many leading calls should fail before subsequent ones succeed. */
    private ?int $failFirst = null;

    /**
     * Make every send fail with the given message.
     */
    public function alwaysFail(string $message = 'Simulated delivery failure'): void
    {
        $this->shouldFail = true;
        $this->failFirst = null;
        $this->failMessage = $message;
    }

    /**
     * Make only the first N sends fail; the rest succeed. Useful for testing
     * a submission that recovers on a later retry.
     */
    public function failFirst(int $count, string $message = 'Simulated delivery failure'): void
    {
        $this->shouldFail = false;
        $this->failFirst = $count;
        $this->failMessage = $message;
    }

    public function send(string $to, ?string $replyTo, string $subject, string $textBody): void
    {
        $callIndex = count($this->calls);
        $this->calls[] = [
            'to'       => $to,
            'replyTo'  => $replyTo,
            'subject'  => $subject,
            'textBody' => $textBody,
        ];

        if ($this->shouldFail || ($this->failFirst !== null && $callIndex < $this->failFirst)) {
            throw new RuntimeException($this->failMessage);
        }
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * @return array{to:string, replyTo:?string, subject:string, textBody:string}|null
     */
    public function lastCall(): ?array
    {
        if ($this->calls === []) {
            return null;
        }

        return $this->calls[count($this->calls) - 1];
    }
}
