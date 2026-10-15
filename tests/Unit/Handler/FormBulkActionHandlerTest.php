<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\Handler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\FormBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\LocaleCopyHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeFormManager;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\FormBundle\Manager\FormManager;

/**
 * The lookups run against SQLite with the table of the SuluFormBundle; the form manager is a stand-in.
 */
class FormBulkActionHandlerTest extends TestCase
{
    private FakeFormManager $formManager;
    private FormBulkActionHandler $handler;

    public static function setUpBeforeClass(): void
    {
        // the SuluFormBundle is not installed here
        if (!class_exists(FormManager::class)) {
            class_alias(FakeFormManager::class, FormManager::class);
        }
    }

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE fo_form_translations (id INTEGER PRIMARY KEY, idForms INTEGER, locale VARCHAR(5), title VARCHAR(255))');
        $this->insertTranslation($connection, 1, 'de', 'Kontakt');
        $this->insertTranslation($connection, 1, 'en', 'Contact');
        $this->insertTranslation($connection, 2, 'de', 'Newsletter');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $this->formManager = new FakeFormManager();
        $this->handler = new FormBulkActionHandler($this->formManager, $entityManager);
    }

    public function testSupportsCopyLocaleOfFormsOnly(): void
    {
        $this->assertTrue($this->handler->supports('forms', LocaleCopyHandlerInterface::ACTION));
        $this->assertFalse($this->handler->supports('forms', 'publish'));
        $this->assertFalse($this->handler->supports('forms', 'delete'));
        $this->assertFalse($this->handler->supports('articles', LocaleCopyHandlerInterface::ACTION));
    }

    public function testEveryFormIsCheckedInTheContextOfTheForms(): void
    {
        $this->assertSame('sulu.form.forms', $this->handler->getSecurityContext('1', 'en'));
        $this->assertSame('sulu.form.forms', $this->handler->getListSecurityContext('sulu_form.list'));
    }

    public function testFindsTheFormsWithoutTextsInTheLocale(): void
    {
        $this->assertSame(['2', '99'], $this->handler->findMissingInLocale(['1', '2', '99'], 'en'));
        $this->assertSame([], $this->handler->findMissingInLocale(['1', '2'], 'de'));
        $this->assertSame(['1'], $this->handler->findMissingInLocale(['1'], 'nl'));
        $this->assertSame([], $this->handler->findMissingInLocale([], 'en'));
    }

    public function testTitlesComeFromTheLocaleOrElseFromAnotherOne(): void
    {
        $this->assertSame(['1' => 'Contact', '2' => 'Newsletter'], $this->handler->getTitles(['1', '2', '99'], 'en'));
        $this->assertSame(['1' => 'Kontakt', '2' => 'Newsletter'], $this->handler->getTitles(['1', '2'], 'de'));
        $this->assertSame([], $this->handler->getTitles([], 'de'));
    }

    public function testCopiesEveryFormAndReportsTheOnesThatFail(): void
    {
        $this->formManager->failingIds = [2];

        $result = $this->handler->copyLocale(['1', '2', '3'], 'de', 'en');

        $this->assertSame(['1', '3'], $result['done']);
        $this->assertSame(['2' => 'Form 2 cannot be copied.'], $result['failed']);
        $this->assertSame([[1, 'de', ['en']], [3, 'de', ['en']]], $this->formManager->copies);
    }

    public function testOtherActionsAreNotHandled(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handler->handle('publish', ['1'], 'de');
    }

    private function insertTranslation(Connection $connection, int $formId, string $locale, string $title): void
    {
        $connection->insert('fo_form_translations', ['idForms' => $formId, 'locale' => $locale, 'title' => $title]);
    }
}
