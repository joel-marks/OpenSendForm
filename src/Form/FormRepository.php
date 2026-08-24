<?php

declare(strict_types=1);

namespace OpenSendForm\Form;

use InvalidArgumentException;
use OpenSendForm\Storage\Database;

/**
 * Data-access layer for forms.
 *
 * A form is the unit of configuration: one record per embedded form,
 * carrying its own public key, recipient, allowed origins and toggles.
 * All access goes through prepared statements on the Database wrapper.
 */
final class FormRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Create a form, generating its public key.
     *
     * @param array<int, string> $allowedOrigins Origins as supplied by the
     *        caller; each is normalised and validated.
     * @return array<string, mixed> The created form (hydrated).
     *
     * @throws InvalidArgumentException on a bad recipient email or origin,
     *         or when no origins are supplied.
     */
    public function createForm(string $name, string $recipientEmail, array $allowedOrigins): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Form name must not be empty.');
        }

        $recipientEmail = trim($recipientEmail);
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid recipient email: {$recipientEmail}");
        }

        $origins = $this->normaliseOrigins($allowedOrigins);

        $key = FormKey::generate();
        $now = self::now();

        $this->db->execute(
            'INSERT INTO forms
                (form_key, name, recipient_email, allowed_origins,
                 store_content, retention_days, is_active, created_at, updated_at)
             VALUES
                (:form_key, :name, :recipient_email, :allowed_origins,
                 :store_content, :retention_days, :is_active, :created_at, :updated_at)',
            [
                'form_key'        => $key,
                'name'            => $name,
                'recipient_email' => $recipientEmail,
                'allowed_origins' => json_encode($origins, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'store_content'   => 0,
                'retention_days'  => 30,
                'is_active'       => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );

        $id = (int) $this->db->pdo()->lastInsertId();

        $form = $this->findById($id);
        if ($form === null) {
            // Should be unreachable: we just inserted the row.
            throw new \RuntimeException('Failed to load form after creation.');
        }

        return $form;
    }

    /**
     * Find an ACTIVE form by its public key, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByKey(string $formKey): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM forms WHERE form_key = :form_key AND is_active = 1',
            ['form_key' => $formKey]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Find a form by id regardless of active state, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM forms WHERE id = :id',
            ['id' => $id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * List all forms, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForms(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM forms ORDER BY id DESC');

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Whether a normalised origin is allowlisted by any active form.
     *
     * Used by the /v1/submit CORS preflight, which cannot know the target
     * form (its key travels in the body, not the preflight). The actual
     * per-form authorisation still happens on the POST itself.
     */
    public function isOriginAllowedByAnyActiveForm(string $normalisedOrigin): bool
    {
        $rows = $this->db->fetchAll(
            'SELECT allowed_origins FROM forms WHERE is_active = 1'
        );

        foreach ($rows as $row) {
            $origins = json_decode((string) $row['allowed_origins'], true);
            if (is_array($origins) && in_array($normalisedOrigin, $origins, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enable or disable a form.
     *
     * @return bool True if a row was updated.
     */
    public function setActive(int $id, bool $active): bool
    {
        $statement = $this->db->execute(
            'UPDATE forms SET is_active = :active, updated_at = :now WHERE id = :id',
            [
                'active' => $active ? 1 : 0,
                'now'    => self::now(),
                'id'     => $id,
            ]
        );

        return $statement->rowCount() > 0;
    }

    /**
     * Normalise and validate a list of origins.
     *
     * Each origin is reduced to scheme + host + optional port, with no
     * path, query, fragment or trailing slash. Scheme and host are
     * lower-cased. Duplicates are removed while preserving order.
     *
     * @param array<int, string> $origins
     * @return array<int, string>
     *
     * @throws InvalidArgumentException on an empty list or invalid origin.
     */
    public function normaliseOrigins(array $origins): array
    {
        $normalised = [];
        foreach ($origins as $origin) {
            $clean = $this->normaliseOrigin($origin);
            if (!in_array($clean, $normalised, true)) {
                $normalised[] = $clean;
            }
        }

        if ($normalised === []) {
            throw new InvalidArgumentException('At least one allowed origin is required.');
        }

        return $normalised;
    }

    /**
     * Normalise a single origin string.
     *
     * @throws InvalidArgumentException if it is not a valid origin.
     */
    private function normaliseOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === '') {
            throw new InvalidArgumentException('Origin must not be empty.');
        }

        $parts = parse_url($origin);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("Invalid origin (need scheme and host): {$origin}");
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException("Invalid origin scheme (http/https only): {$origin}");
        }

        // An origin carries no path, query or fragment. A bare trailing
        // slash is tolerated (and stripped); anything more is rejected.
        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException("Origin must not contain a path: {$origin}");
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException("Origin must not contain a query or fragment: {$origin}");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException("Origin must not contain credentials: {$origin}");
        }

        $host = strtolower($parts['host']);
        $result = $scheme . '://' . $host;

        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }

        return $result;
    }

    /**
     * Cast a raw row into typed PHP values.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $origins = json_decode((string) $row['allowed_origins'], true);

        return [
            'id'              => (int) $row['id'],
            'form_key'        => (string) $row['form_key'],
            'name'            => (string) $row['name'],
            'recipient_email' => (string) $row['recipient_email'],
            'allowed_origins' => is_array($origins) ? $origins : [],
            'store_content'   => (int) $row['store_content'],
            'retention_days'  => (int) $row['retention_days'],
            'is_active'       => (int) $row['is_active'],
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
        ];
    }

    /**
     * Current UTC timestamp in a portable, lexicographically-sortable form.
     */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
