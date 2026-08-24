<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Mail;

use OpenSendForm\Mail\MessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Direct, hostile-input coverage of the message builder — the last line of
 * defence for the mail security policy. Every case here targets a way a
 * submitter might try to reach a header or bloat the message.
 */
final class MessageBuilderTest extends TestCase
{
    private MessageBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MessageBuilder();
    }

    // --- Subject ----------------------------------------------------------

    public function testSubjectIsBuiltFromTheFormName(): void
    {
        self::assertSame(
            'New form submission: Contact form',
            $this->builder->buildSubject('Contact form')
        );
    }

    public function testSubjectStripsNewlinesAndControlChars(): void
    {
        $subject = $this->builder->buildSubject("Contact\r\nBcc: evil@example.com\x00\tX");

        self::assertStringNotContainsString("\r", $subject);
        self::assertStringNotContainsString("\n", $subject);
        self::assertStringNotContainsString("\x00", $subject);
        self::assertStringNotContainsString("\t", $subject);
        // Collapsed to a single harmless line.
        self::assertSame('New form submission: Contact Bcc: evil@example.com X', $subject);
    }

    public function testSubjectFallsBackWhenFormNameIsEmpty(): void
    {
        self::assertSame('New form submission', $this->builder->buildSubject("  \r\n  "));
    }

    public function testSubjectIsCapped(): void
    {
        $subject = $this->builder->buildSubject(str_repeat('A', 5000));

        self::assertLessThanOrEqual(MessageBuilder::NAME_CAP + 32 + 16, mb_strlen($subject));
        self::assertStringContainsString('[truncated]', $subject);
    }

    // --- Reply-To ---------------------------------------------------------

    public function testValidEmailFieldBecomesReplyTo(): void
    {
        self::assertSame(
            'ada@example.com',
            $this->builder->extractReplyTo(['email' => '  ada@example.com  '])
        );
    }

    public function testHeaderInjectionInEmailFieldYieldsNoReplyTo(): void
    {
        $hostile = "ada@example.com\r\nBcc: victim@example.com";

        self::assertNull($this->builder->extractReplyTo(['email' => $hostile]));
    }

    public function testMissingOrInvalidEmailYieldsNoReplyTo(): void
    {
        self::assertNull($this->builder->extractReplyTo([]));
        self::assertNull($this->builder->extractReplyTo(['email' => 'not-an-email']));
        self::assertNull($this->builder->extractReplyTo(['email' => ['array' => 'value']]));
    }

    // --- Body -------------------------------------------------------------

    public function testBodyListsFieldsAsPlainText(): void
    {
        $body = $this->builder->buildBody('Contact', [
            'name'    => 'Ada',
            'message' => 'Hello there',
        ]);

        self::assertStringContainsString('Form: Contact', $body);
        self::assertStringContainsString('name: Ada', $body);
        self::assertStringContainsString('message: Hello there', $body);
    }

    public function testBodyFlattensNewlinesInFieldNames(): void
    {
        $body = $this->builder->buildBody('Contact', [
            "na\r\nme: injected" => 'value',
        ]);

        // The field name must not introduce its own line into the listing.
        self::assertStringContainsString('na me: injected: value', $body);
        self::assertStringNotContainsString("na\r\nme", $body);
        self::assertStringNotContainsString("na\nme", $body);
    }

    public function testBodyStripsControlCharsFromValuesButKeepsNewlines(): void
    {
        $body = $this->builder->buildBody('Contact', [
            'message' => "line one\r\nline two\x00\x07 end",
        ]);

        // CRLF normalised to LF, other control chars (NUL, BEL) removed.
        self::assertStringContainsString("message: line one\nline two end", $body);
        self::assertStringNotContainsString("\x00", $body);
        self::assertStringNotContainsString("\x07", $body);
        self::assertStringNotContainsString("\r", $body);
    }

    public function testBodyTruncatesVeryLongValues(): void
    {
        $huge = str_repeat('x', 20000);
        $body = $this->builder->buildBody('Contact', ['message' => $huge]);

        self::assertStringContainsString('[truncated]', $body);
        // The kept value cannot exceed the cap plus the marker.
        self::assertLessThan(20000, mb_strlen($body));
        self::assertGreaterThanOrEqual(MessageBuilder::VALUE_CAP, mb_strlen($body));
    }

    public function testBodyHandlesNoFields(): void
    {
        $body = $this->builder->buildBody('Contact', []);

        self::assertStringContainsString('(no fields were submitted)', $body);
    }

    public function testBlankFieldNameIsLabelledUnnamed(): void
    {
        $body = $this->builder->buildBody('Contact', ['' => 'orphan value']);

        self::assertStringContainsString('(unnamed): orphan value', $body);
    }

    // --- build() aggregate ------------------------------------------------

    public function testBuildAssemblesAllParts(): void
    {
        $message = $this->builder->build(
            ['name' => 'Contact'],
            ['email' => 'ada@example.com', 'message' => 'Hi']
        );

        self::assertSame('New form submission: Contact', $message->subject());
        self::assertSame('ada@example.com', $message->replyTo());
        self::assertStringContainsString('message: Hi', $message->textBody());
    }
}
