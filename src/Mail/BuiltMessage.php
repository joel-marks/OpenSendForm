<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

/**
 * An outbound message assembled by the MessageBuilder.
 *
 * A plain value object carrying only the submitter-influenced parts of the
 * mail (subject, plain-text body, and an optional Reply-To). The recipient
 * (To) and the fixed From identity are supplied by the delivery layer from
 * trusted configuration, never from here.
 */
final class BuiltMessage
{
    private string $subject;
    private string $textBody;
    private ?string $replyTo;

    public function __construct(string $subject, string $textBody, ?string $replyTo)
    {
        $this->subject = $subject;
        $this->textBody = $textBody;
        $this->replyTo = $replyTo;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function textBody(): string
    {
        return $this->textBody;
    }

    /**
     * A validated Reply-To address, or null when the submission carried no
     * usable email field.
     */
    public function replyTo(): ?string
    {
        return $this->replyTo;
    }
}
