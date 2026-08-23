<?php

declare(strict_types=1);

namespace OpenSendForm\Submission;

use OpenSendForm\Storage\Database;

/**
 * Data-access layer for submissions.
 *
 * Metadata is always stored; message content is persisted only when the
 * owning form's store_content toggle is enabled. All access goes through
 * prepared statements on the Database wrapper.
 */
final class SubmissionRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Record a submission for a form.
     *
     * The content column is written only when the owning form has
     * store_content enabled; otherwise it is stored as NULL regardless of
     * what the caller supplies.
     *
     * @return int The new submission id.
     */
    public function recordSubmission(
        int $formId,
        string $remoteIp,
        ?string $origin,
        ?string $userAgent,
        ?string $contentJson = null,
        string $status = 'received'
    ): int {
        $content = $this->formStoresContent($formId) ? $contentJson : null;

        $this->db->execute(
            'INSERT INTO submissions
                (form_id, created_at, remote_ip, origin, user_agent, status, content)
             VALUES
                (:form_id, :created_at, :remote_ip, :origin, :user_agent, :status, :content)',
            [
                'form_id'    => $formId,
                'created_at' => self::now(),
                'remote_ip'  => $remoteIp,
                'origin'     => $origin,
                'user_agent' => $userAgent,
                'status'     => $status,
                'content'    => $content,
            ]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Delete submissions older than each form's retention_days.
     *
     * Cutoffs are computed per form in PHP so the SQL stays portable
     * across sqlite and mysql (no dialect-specific date arithmetic).
     *
     * @return int Total rows deleted.
     */
    public function purgeExpired(): int
    {
        $forms = $this->db->fetchAll('SELECT id, retention_days FROM forms');

        $deleted = 0;
        foreach ($forms as $form) {
            $retentionDays = (int) $form['retention_days'];
            $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

            $statement = $this->db->execute(
                'DELETE FROM submissions WHERE form_id = :form_id AND created_at < :cutoff',
                [
                    'form_id' => (int) $form['id'],
                    'cutoff'  => $cutoff,
                ]
            );

            $deleted += $statement->rowCount();
        }

        return $deleted;
    }

    /**
     * Find a submission by id, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM submissions WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Whether the owning form persists message content.
     */
    private function formStoresContent(int $formId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT store_content FROM forms WHERE id = :id',
            ['id' => $formId]
        );

        return $row !== null && (int) $row['store_content'] === 1;
    }

    /**
     * Current UTC timestamp in a portable, lexicographically-sortable form.
     */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
