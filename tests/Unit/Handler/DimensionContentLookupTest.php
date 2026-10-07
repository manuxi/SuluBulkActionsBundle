<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\Handler;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Manuxi\SuluBulkActionsBundle\Handler\DimensionContentLookup;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\Entity\Item;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\Entity\ItemDimensionContent;
use PHPUnit\Framework\TestCase;

/**
 * Runs the queries against SQLite, with entities shaped like the content entities of Sulu 3.
 */
class DimensionContentLookupTest extends TestCase
{
    private const BOTH = '00000000-0000-0000-0000-000000000001';
    private const GERMAN_ONLY = '00000000-0000-0000-0000-000000000002';
    private const OLD_VERSION_ONLY = '00000000-0000-0000-0000-000000000003';
    private const UNKNOWN = '00000000-0000-0000-0000-000000000099';

    private EntityManager $entityManager;
    private DimensionContentLookup $lookup;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../Fixtures/Entity'], true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(Item::class),
            $this->entityManager->getClassMetadata(ItemDimensionContent::class),
        ]);

        $this->entityManager->persist((new Item(self::BOTH))
            ->addDimension(null, 'draft')
            ->addDimension('de', 'draft', 'Sommerfest')
            ->addDimension('en', 'draft', 'Summer party')
            ->addDimension('en', 'live', 'Summer party'));

        // the case of the bug report: the entry exists, but not in English
        $this->entityManager->persist((new Item(self::GERMAN_ONLY))
            ->addDimension(null, 'draft')
            ->addDimension('de', 'draft', 'Jahresbericht')
            ->addDimension('de', 'live', 'Jahresbericht'));

        // English only as an older version and live: no current draft to work on
        $this->entityManager->persist((new Item(self::OLD_VERSION_ONLY))
            ->addDimension('de', 'draft', 'Archiv')
            ->addDimension('en', 'draft', 'Archive', 3)
            ->addDimension('en', 'live', 'Archive'));

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->lookup = new DimensionContentLookup($this->entityManager, Item::class);
    }

    public function testFindsEntriesWithoutContentInTheLocale(): void
    {
        $missing = $this->lookup->findMissingInLocale(
            [self::BOTH, self::GERMAN_ONLY, self::OLD_VERSION_ONLY, self::UNKNOWN],
            'en',
        );

        $this->assertSame([self::GERMAN_ONLY, self::OLD_VERSION_ONLY, self::UNKNOWN], $missing);
    }

    public function testFindsNothingMissingIfAllEntriesHaveTheLocale(): void
    {
        $this->assertSame([], $this->lookup->findMissingInLocale([self::BOTH, self::GERMAN_ONLY], 'de'));
    }

    public function testDoesNotQueryWithoutIds(): void
    {
        $this->assertSame([], $this->lookup->findMissingInLocale([], 'en'));
        $this->assertSame([], $this->lookup->getTitles([], 'en'));
    }

    public function testTitlesPreferTheLocaleAndFallBackToAnotherOne(): void
    {
        $titles = $this->lookup->getTitles([self::BOTH, self::GERMAN_ONLY, self::UNKNOWN], 'en');

        $this->assertEquals([self::BOTH => 'Summer party', self::GERMAN_ONLY => 'Jahresbericht'], $titles);
    }
}
