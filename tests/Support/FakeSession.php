<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Auth\SessionInterface;

/**
 * In-memory session for tests.
 *
 * Behaves like a native session for get/set/remove but keeps everything in
 * an array so the full auth flow can be driven without PHP's global session
 * machinery. Adds observability the interface does not expose: how many
 * times the id was regenerated (to assert fixation defence on login) and
 * whether the session has been destroyed.
 */
final class FakeSession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    private int $regenerateCount = 0;
    private int $destroyCount = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        // A real regenerate rotates the id but preserves data — mirror that.
        $this->regenerateCount++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyCount++;
    }

    // --- Test observability ----------------------------------------------

    public function regenerateCount(): int
    {
        return $this->regenerateCount;
    }

    public function destroyCount(): int
    {
        return $this->destroyCount;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
