<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Submission;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use PHPUnit\Framework\TestCase;

final class SubmissionRepositoryTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    public function testContentAlwaysStoredAtRecordTimeRegardlessOfStoreContentToggle(): void
    {
        // store_content is OFF by default; content is still persisted as the
        // in-flight delivery payload — the toggle governs post-delivery
        // retention, not initial storage.
        $forms = new FormRepository($this->db);
        $form = $forms->createForm('Contact', 'owner@example.com', ['https://example.com']);

        $subs = new SubmissionRepository($this->db);
        $id = $subs->recordSubmission(
            $form['id'],
            '203.0.113.7',
            'https://example.com',
            'Mozilla/5.0',
            '{"message":"hello"}'
        );

        $row = $subs->findById($id);
        self::assertNotNull($row);
        self::assertSame('{"message":"hello"}', $row['content']);
        // Metadata is always kept.
        self::assertSame('203.0.113.7', $row['remote_ip']);
        self::assertSame('https://example.com', $row['origin']);
        self::assertSame('Mozilla/5.0', $row['user_agent']);
        self::assertSame('received', $row['status']);
    }

    public function testClearContentNullsTheColumn(): void
    {
        $forms = new FormRepository($this->db);
        $form = $forms->createForm('Contact', 'owner@example.com', ['https://example.com']);

        $subs = new SubmissionRepository($this->db);
        $id = $subs->recordSubmission(
            $form['id'],
            '203.0.113.7',
            'https://example.com',
            'Mozilla/5.0',
            '{"message":"hello"}'
        );

        $subs->clearContent($id);

        $row = $subs->findById($id);
        self::assertNotNull($row);
        self::assertNull($row['content']);
    }

    public function testPurgeExpiredHonoursPerFormRetention(): void
    {
        $forms = new FormRepository($this->db);
        $shortForm = $forms->createForm('Short', 'short@example.com', ['https://short.example.com']);
        $longForm = $forms->createForm('Long', 'long@example.com', ['https://long.example.com']);

        // Short form: 7-day retention. Long form: 90-day retention.
        $this->setRetention($shortForm['id'], 7);
        $this->setRetention($longForm['id'], 90);

        // For each form: one 30-day-old submission and one fresh one.
        $old = gmdate('Y-m-d H:i:s', time() - (30 * 86400));
        $fresh = gmdate('Y-m-d H:i:s');

        $this->insertSubmissionAt($shortForm['id'], $old);   // expired (>7d)
        $this->insertSubmissionAt($shortForm['id'], $fresh); // kept
        $this->insertSubmissionAt($longForm['id'], $old);    // kept (<90d)
        $this->insertSubmissionAt($longForm['id'], $fresh);  // kept

        $subs = new SubmissionRepository($this->db);
        $deleted = $subs->purgeExpired();

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->countFor($shortForm['id']));
        self::assertSame(2, $this->countFor($longForm['id']));
    }

    // --- Deletion ---------------------------------------------------------

    public function testDeleteByIdRemovesOnlyThatRow(): void
    {
        $forms = new FormRepository($this->db);
        $form = $forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $subs = new SubmissionRepository($this->db);

        $keep = $subs->recordSubmission($form['id'], '203.0.113.7', null, null);
        $drop = $subs->recordSubmission($form['id'], '203.0.113.7', null, null);

        self::assertSame(1, $subs->deleteById($drop));
        self::assertNull($subs->findById($drop));
        self::assertNotNull($subs->findById($keep));
        // Deleting a vanished row reports zero.
        self::assertSame(0, $subs->deleteById($drop));
    }

    public function testDeleteAllForFormRemovesOnlyThatFormsRows(): void
    {
        $forms = new FormRepository($this->db);
        $a = $forms->createForm('A', 'a@example.com', ['https://a.example.com']);
        $b = $forms->createForm('B', 'b@example.com', ['https://b.example.com']);
        $subs = new SubmissionRepository($this->db);

        $subs->recordSubmission($a['id'], '203.0.113.7', null, null);
        $subs->recordSubmission($a['id'], '203.0.113.7', null, null);
        $subs->recordSubmission($b['id'], '203.0.113.7', null, null);

        self::assertSame(2, $subs->deleteAllForForm($a['id']));
        self::assertSame(0, $this->countFor($a['id']));
        self::assertSame(1, $this->countFor($b['id']));
    }

    public function testDeleteAllWithoutStatusRemovesEverything(): void
    {
        $forms = new FormRepository($this->db);
        $form = $forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $subs = new SubmissionRepository($this->db);

        $subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'received');
        $subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'dead');

        self::assertSame(3, $subs->deleteAll());
        self::assertSame(0, $this->countFor($form['id']));
    }

    public function testDeleteAllWithStatusRemovesOnlyThatStatus(): void
    {
        $forms = new FormRepository($this->db);
        $form = $forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $subs = new SubmissionRepository($this->db);

        $subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'received');
        $subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');

        self::assertSame(2, $subs->deleteAll('failed'));
        self::assertSame(1, $this->countFor($form['id']));
        self::assertSame(0, $subs->countByStatus('failed'));
        self::assertSame(1, $subs->countByStatus('received'));
    }

    private function setRetention(int $formId, int $days): void
    {
        $this->db->execute(
            'UPDATE forms SET retention_days = :days WHERE id = :id',
            ['days' => $days, 'id' => $formId]
        );
    }

    private function insertSubmissionAt(int $formId, string $createdAt): void
    {
        $this->db->execute(
            'INSERT INTO submissions (form_id, created_at, remote_ip, status)
             VALUES (:form_id, :created_at, :remote_ip, :status)',
            [
                'form_id'    => $formId,
                'created_at' => $createdAt,
                'remote_ip'  => '203.0.113.7',
                'status'     => 'received',
            ]
        );
    }

    private function countFor(int $formId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE form_id = :id',
            ['id' => $formId]
        );

        return (int) $row['c'];
    }
}
