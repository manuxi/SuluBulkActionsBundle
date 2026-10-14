<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\Handler;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Manuxi\SuluBulkActionsBundle\Handler\ContentEntityBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\LocaleCopyHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeEntityDomainEvent;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeRemovedDomainEvent;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\TitledDimensionContent;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;
use Sulu\Bundle\TrashBundle\Application\TrashManager\TrashManagerInterface;
use Sulu\Bundle\TrashBundle\Domain\Model\TrashItemInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

class ContentEntityBulkActionHandlerTest extends TestCase
{
    private const FIRST = '00000000-0000-0000-0000-000000000001';
    private const SECOND = '00000000-0000-0000-0000-000000000002';

    /** @var array<string, ContentRichEntityInterface> */
    private array $entities = [];

    /** @var list<string> everything that happened, in order */
    private array $calls = [];

    /** @var list<DomainEvent> */
    private array $collected = [];

    private EntityManagerInterface $entityManager;
    private ContentWorkflowInterface $contentWorkflow;
    private ContentManagerInterface $contentManager;
    private DomainEventCollectorInterface $collector;
    private TrashManagerInterface $trashManager;

    protected function setUp(): void
    {
        $this->entities = [
            self::FIRST => $this->createEntity([['de', 'Sommerfest'], ['en', 'Summer party']]),
            self::SECOND => $this->createEntity([['de', 'Jahresbericht']]),
        ];

        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $repository = new class($this->entityManager, new ClassMetadata(\stdClass::class), $this->entities) extends EntityRepository {
            /** @param array<string, ContentRichEntityInterface> $entities */
            public function __construct(EntityManagerInterface $entityManager, ClassMetadata $class, private readonly array $entities)
            {
                parent::__construct($entityManager, $class);
            }

            public function findByUuid(string $uuid): ?ContentRichEntityInterface
            {
                return $this->entities[$uuid] ?? null;
            }
        };

        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->entityManager->method('remove')->willReturnCallback(function (): void {
            $this->calls[] = 'remove';
        });
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            $this->calls[] = 'flush';
        });

        $this->contentWorkflow = $this->createMock(ContentWorkflowInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);

        $this->collector = $this->createMock(DomainEventCollectorInterface::class);
        $this->collector->method('collect')->willReturnCallback(function (DomainEvent $event): void {
            $this->calls[] = 'collect';
            $this->collected[] = $event;
        });
        $this->collector->method('clear')->willReturnCallback(function (): void {
            $this->calls[] = 'clear';
            $this->collected = [];
        });

        $this->trashManager = $this->createMock(TrashManagerInterface::class);
    }

    public function testSupportsEveryActionForItsResourceOnly(): void
    {
        $handler = $this->createHandler();

        foreach (['publish', 'unpublish', 'delete', 'copy_locale'] as $action) {
            $this->assertTrue($handler->supports('events', $action), $action);
            $this->assertFalse($handler->supports('other', $action), $action);
        }
        $this->assertFalse($handler->supports('events', 'unknown'));
        $this->assertInstanceOf(LocaleCopyHandlerInterface::class, $handler);
    }

    public function testPublishAppliesTheTransitionAndCollectsThePublishedEvent(): void
    {
        $this->contentWorkflow->expects($this->once())
            ->method('apply')
            ->with($this->entities[self::FIRST], ['locale' => 'en'], 'publish');
        $this->contentManager->method('resolve')->willReturn($this->createDimension('en', 'Summer party'));

        $result = $this->createHandler()->handle('publish', [self::FIRST], 'en');

        $this->assertSame(['done' => [self::FIRST], 'failed' => []], $result);
        $this->assertCount(1, $this->collected);
        $this->assertInstanceOf(FakeEntityDomainEvent::class, $this->collected[0]);
        $this->assertSame($this->entities[self::FIRST], $this->collected[0]->entity);
        $this->assertSame(['locale' => 'en', 'title' => 'Summer party'], $this->collected[0]->payload);
        $this->assertSame(['collect', 'flush'], $this->calls, 'the event is collected before the flush that dispatches it');
    }

    public function testUnpublishAppliesTheTransitionAndCollectsTheUnpublishedEvent(): void
    {
        $this->contentWorkflow->expects($this->once())
            ->method('apply')
            ->with($this->entities[self::FIRST], ['locale' => 'de'], 'unpublish');
        $this->contentManager->method('resolve')->willReturn($this->createDimension('de', 'Sommerfest'));

        $result = $this->createHandler(['unpublished' => FakeEntityDomainEvent::class])->handle('unpublish', [self::FIRST], 'de');

        $this->assertSame([self::FIRST], $result['done']);
        $this->assertSame(['locale' => 'de', 'title' => 'Sommerfest'], $this->collected[0]->payload);
    }

    public function testNoDomainEventIsCollectedWithoutAClassForIt(): void
    {
        $this->contentManager->method('resolve')->willReturn($this->createDimension('en', 'Summer party'));

        $result = $this->createHandler([])->handle('publish', [self::FIRST], 'en');

        $this->assertSame([self::FIRST], $result['done']);
        $this->assertSame([], $this->collected);
    }

    public function testWorksWithoutCollectorAndTrash(): void
    {
        $handler = new ContentEntityBulkActionHandler(
            'events',
            \stdClass::class,
            'sulu.events.events',
            $this->entityManager,
            $this->contentWorkflow,
            $this->contentManager,
        );
        $this->contentManager->method('resolve')->willReturn($this->createDimension('en', 'Summer party'));

        $this->assertSame([self::FIRST], $handler->handle('publish', [self::FIRST], 'en')['done']);
        $this->assertSame([self::SECOND], $handler->handle('delete', [self::SECOND], 'de')['done']);
    }

    public function testNothingIsRemovedWhenTheTrashFails(): void
    {
        $this->trashManager->method('store')->willThrowException(new \LogicException('Trash is full.'));
        $this->entityManager->expects($this->never())->method('remove');

        $result = $this->createHandler()->handle('delete', [self::FIRST], 'en');

        $this->assertSame(['done' => [], 'failed' => [self::FIRST => 'Trash is full.']], $result);
        $this->assertSame([], $this->collected);
    }

    public function testDeleteStoresTheEntityInTheTrashBeforeRemovingItAndCollectsTheRemovedEvent(): void
    {
        $this->trashManager->expects($this->once())
            ->method('store')
            ->with('events', $this->entities[self::FIRST])
            ->willReturnCallback(function (): TrashItemInterface {
                $this->calls[] = 'trash';

                return $this->createMock(TrashItemInterface::class);
            });
        $this->entityManager->expects($this->once())->method('remove')->with($this->entities[self::FIRST]);

        $result = $this->createHandler()->handle('delete', [self::FIRST], 'en');

        $this->assertSame(['done' => [self::FIRST], 'failed' => []], $result);
        $this->assertCount(1, $this->collected);
        $this->assertInstanceOf(FakeRemovedDomainEvent::class, $this->collected[0]);
        $this->assertSame(self::FIRST, $this->collected[0]->uuid);
        $this->assertSame('Summer party', $this->collected[0]->title);
        $this->assertSame(['trash', 'remove', 'collect', 'flush'], $this->calls);
    }

    public function testCopyLocaleCopiesTheDraftOfTheSourceIntoTheTarget(): void
    {
        $entity = $this->entities[self::FIRST];
        $this->contentManager->expects($this->once())
            ->method('copy')
            ->with(
                $entity,
                ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'de'],
                $entity,
                ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            );

        $result = $this->createHandler()->copyLocale([self::FIRST], 'de', 'en');

        $this->assertSame(['done' => [self::FIRST], 'failed' => []], $result);
        $this->assertSame(['flush'], $this->calls);
        $this->assertSame([], $this->collected, 'copying is not published');
    }

    public function testOneFailingEntryDoesNotStopTheOthersAndDropsItsEvents(): void
    {
        $this->contentWorkflow->method('apply')->willReturnCallback(function (ContentRichEntityInterface $entity): DimensionContentInterface {
            if ($entity === $this->entities[self::FIRST]) {
                throw new \RuntimeException('Transition "publish" is not enabled.');
            }

            return $this->createDimension('de', 'Jahresbericht');
        });
        $this->contentManager->method('resolve')->willReturn($this->createDimension('de', 'Jahresbericht'));

        $result = $this->createHandler()->handle('publish', [self::FIRST, self::SECOND], 'de');

        $this->assertSame([
            'done' => [self::SECOND],
            'failed' => [self::FIRST => 'Transition "publish" is not enabled.'],
        ], $result);
        $this->assertSame(['clear', 'collect', 'flush'], $this->calls);
    }

    public function testUnknownEntriesFailWithoutStoppingTheOthers(): void
    {
        $this->contentManager->method('resolve')->willReturn($this->createDimension('de', 'Jahresbericht'));

        $result = $this->createHandler()->handle('publish', ['00000000-0000-0000-0000-000000000099', self::SECOND], 'de');

        $this->assertSame([self::SECOND], $result['done']);
        $this->assertSame(['00000000-0000-0000-0000-000000000099' => 'Entry not found.'], $result['failed']);
    }

    public function testCopyLocaleIsNotHandledByHandle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createHandler()->handle('copy_locale', [self::FIRST], 'de');
    }

    /**
     * @param array<string, class-string<DomainEvent>>|null $domainEvents
     */
    private function createHandler(?array $domainEvents = null): ContentEntityBulkActionHandler
    {
        return new ContentEntityBulkActionHandler(
            'events',
            \stdClass::class,
            'sulu.events.events',
            $this->entityManager,
            $this->contentWorkflow,
            $this->contentManager,
            $this->collector,
            $this->trashManager,
            $domainEvents ?? [
                ContentEntityBulkActionHandler::EVENT_PUBLISHED => FakeEntityDomainEvent::class,
                ContentEntityBulkActionHandler::EVENT_UNPUBLISHED => FakeEntityDomainEvent::class,
                ContentEntityBulkActionHandler::EVENT_REMOVED => FakeRemovedDomainEvent::class,
            ],
        );
    }

    /**
     * @param list<array{string, string}> $titlesByLocale
     */
    private function createEntity(array $titlesByLocale): ContentRichEntityInterface
    {
        $entity = $this->createMock(ContentRichEntityInterface::class);
        $entity->method('getDimensionContents')->willReturn(new ArrayCollection(array_map(
            fn (array $row): TitledDimensionContent => $this->createDimension($row[0], $row[1]),
            $titlesByLocale,
        )));

        return $entity;
    }

    private function createDimension(string $locale, string $title): TitledDimensionContent
    {
        $dimension = $this->createMock(TitledDimensionContent::class);
        $dimension->method('getLocale')->willReturn($locale);
        $dimension->method('getTitle')->willReturn($title);

        return $dimension;
    }
}
