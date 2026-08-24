<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

use RuntimeException;

/**
 * A transport that sends one already-built message.
 *
 * Implementations MUST throw on any failure to deliver (connection refused,
 * authentication failure, rejected recipient, timeout, …). The delivery
 * layer treats a thrown exception as a failed attempt and schedules a retry;
 * a normal return means the message was handed to the SMTP server.
 */
interface MailerInterface
{
    /**
     * @param string      $to       The recipient (the form's configured owner).
     * @param string|null $replyTo  A validated Reply-To, or null for none.
     * @param string      $subject  The sanitised subject line.
     * @param string      $textBody The plain-text body.
     *
     * @throws RuntimeException|\Throwable on any delivery failure.
     */
    public function send(string $to, ?string $replyTo, string $subject, string $textBody): void;
}
