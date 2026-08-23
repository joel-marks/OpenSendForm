<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Form;

use InvalidArgumentException;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class FormRepositoryTest extends TestCase
{
    private function repo(): FormRepository
    {
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        return new FormRepository($db);
    }

    public function testCreateFormReturnsHydratedFormWithGeneratedKey(): void
    {
        $repo = $this->repo();

        $form = $repo->createForm('Contact', 'owner@example.com', ['https://example.com']);

        self::assertIsInt($form['id']);
        self::assertMatchesRegularExpression('/^osf_[0-9a-f]{32}$/', $form['form_key']);
        self::assertSame('Contact', $form['name']);
        self::assertSame('owner@example.com', $form['recipient_email']);
        self::assertSame(['https://example.com'], $form['allowed_origins']);
        self::assertSame(0, $form['store_content']);
        self::assertSame(30, $form['retention_days']);
        self::assertSame(1, $form['is_active']);
        self::assertNotSame('', $form['created_at']);
        self::assertNotSame('', $form['updated_at']);
    }

    public function testFindByIdAndFindByKeyRoundTrip(): void
    {
        $repo = $this->repo();
        $form = $repo->createForm('Contact', 'owner@example.com', ['https://example.com']);

        $byId = $repo->findById($form['id']);
        $byKey = $repo->findByKey($form['form_key']);

        self::assertSame($form['form_key'], $byId['form_key']);
        self::assertSame($form['id'], $byKey['id']);
    }

    public function testFindByIdReturnsNullForMissing(): void
    {
        self::assertNull($this->repo()->findById(999));
    }

    public function testFindByKeyExcludesInactiveForms(): void
    {
        $repo = $this->repo();
        $form = $repo->createForm('Contact', 'owner@example.com', ['https://example.com']);

        // Active: findable by key.
        self::assertNotNull($repo->findByKey($form['form_key']));

        // Disabled: excluded from findByKey but still present via findById.
        self::assertTrue($repo->setActive($form['id'], false));
        self::assertNull($repo->findByKey($form['form_key']));
        self::assertSame(0, $repo->findById($form['id'])['is_active']);

        // Re-enabled: findable again.
        self::assertTrue($repo->setActive($form['id'], true));
        self::assertNotNull($repo->findByKey($form['form_key']));
    }

    public function testSetActiveReturnsFalseForMissingForm(): void
    {
        self::assertFalse($this->repo()->setActive(999, false));
    }

    public function testListFormsReturnsAllNewestFirst(): void
    {
        $repo = $this->repo();
        $a = $repo->createForm('A', 'a@example.com', ['https://a.example.com']);
        $b = $repo->createForm('B', 'b@example.com', ['https://b.example.com']);

        $forms = $repo->listForms();

        self::assertCount(2, $forms);
        self::assertSame($b['id'], $forms[0]['id']);
        self::assertSame($a['id'], $forms[1]['id']);
    }

    public function testRejectsInvalidRecipientEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->createForm('Contact', 'not-an-email', ['https://example.com']);
    }

    public function testNormalisesOriginsSchemeHostPort(): void
    {
        $repo = $this->repo();

        $form = $repo->createForm('Contact', 'owner@example.com', [
            'HTTPS://Example.COM/',            // upper-case + trailing slash
            'http://localhost:8080',           // explicit port kept
            'https://example.com',             // duplicate of the first once normalised
        ]);

        self::assertSame(
            ['https://example.com', 'http://localhost:8080'],
            $form['allowed_origins']
        );
    }

    public function testRejectsOriginWithPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->createForm('Contact', 'owner@example.com', ['https://example.com/contact']);
    }

    public function testRejectsOriginWithoutScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->createForm('Contact', 'owner@example.com', ['example.com']);
    }

    public function testRejectsOriginWithNonHttpScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->createForm('Contact', 'owner@example.com', ['ftp://example.com']);
    }

    public function testRejectsOriginWithQuery(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->createForm('Contact', 'owner@example.com', ['https://example.com?a=1']);
    }

    public function testRejectsEmptyOriginList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->createForm('Contact', 'owner@example.com', []);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->createForm('   ', 'owner@example.com', ['https://example.com']);
    }
}
