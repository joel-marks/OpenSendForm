<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\SessionInterface;

/**
 * One-time post-action notices ("flash" messages).
 *
 * Messages are stashed in the session by a POST handler and drained by the
 * next rendered page, so they survive the redirect of the POST/redirect/GET
 * pattern and appear exactly once. Backed by the same SessionInterface seam
 * as the rest of the admin stack, so tests drive it through FakeSession.
 */
final class Flash
{
    private const SESSION_KEY = 'admin.flash';

    private SessionInterface $session;

    public function __construct(SessionInterface $session)
    {
        $this->session = $session;
    }

    /**
     * Queue a message. Type is one of success|error|info (anything else is
     * treated as info by the renderer).
     */
    public function add(string $type, string $message): void
    {
        $queue = $this->session->get(self::SESSION_KEY);
        $queue = is_array($queue) ? $queue : [];
        $queue[] = ['type' => $type, 'message' => $message];
        $this->session->set(self::SESSION_KEY, $queue);
    }

    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    public function error(string $message): void
    {
        $this->add('error', $message);
    }

    public function info(string $message): void
    {
        $this->add('info', $message);
    }

    /**
     * Return and clear all queued messages.
     *
     * @return array<int, array{type: string, message: string}>
     */
    public function drain(): array
    {
        $queue = $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);

        return is_array($queue) ? $queue : [];
    }
}
