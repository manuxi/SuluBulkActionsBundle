<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\ArticleBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\ContentEntityBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\ContentWorkflowBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\LocaleCopyHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\PageBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\SecuredObjectHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\SnippetBulkActionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Application\Message\CopyLocaleArticleMessage;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CopyLocalePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Snippet\Application\Message\CopyLocaleSnippetMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class LocaleCopyHandlerTest extends TestCase
{
    private const FIRST = '00000000-0000-0000-0000-000000000001';
    private const SECOND = '00000000-0000-0000-0000-000000000002';

    /** @var list<Envelope> */
    private array $dispatched = [];

    /**
     * @return iterable<string, array{string, string, class-string}>
     */
    public static function provideHandlers(): iterable
    {
        yield 'articles' => ['article', 'articles', CopyLocaleArticleMessage::class];
        yield 'snippets' => ['snippet', 'snippets', CopyLocaleSnippetMessage::class];
        yield 'pages' => ['page', 'pages', CopyLocalePageMessage::class];
    }

    /**
     * @param class-string $messageClass
     *
     * @dataProvider provideHandlers
     */
    #[DataProvider('provideHandlers')]
    public function testSupportsCopyLocaleForItsResourceOnly(string $type, string $resourceKey, string $messageClass): void
    {
        $handler = $this->createHandler($type, $this->createRecordingBus());

        $this->assertInstanceOf(LocaleCopyHandlerInterface::class, $handler);
        $this->assertTrue($handler->supports($resourceKey, 'copy_locale'));
        $this->assertFalse($handler->supports('other_resource', 'copy_locale'));
    }

    /**
     * @param class-string $messageClass
     *
     * @dataProvider provideHandlers
     */
    #[DataProvider('provideHandlers')]
    public function testSendsTheCopyLocaleMessageOfSuluForEveryEntry(string $type, string $resourceKey, string $messageClass): void
    {
        $handler = $this->createHandler($type, $this->createRecordingBus());

        $result = $handler->copyLocale([self::FIRST, self::SECOND], 'de', 'en');

        $this->assertSame(['done' => [self::FIRST, self::SECOND], 'failed' => []], $result);
        $this->assertCount(2, $this->dispatched);

        foreach ([self::FIRST, self::SECOND] as $index => $id) {
            $envelope = $this->dispatched[$index];
            $message = $envelope->getMessage();

            $this->assertInstanceOf($messageClass, $message);
            $this->assertSame(['uuid' => $id], $message->getIdentifier());
            $this->assertSame('de', $message->getSourceLocale());
            $this->assertSame('en', $message->getTargetLocale());
            // flushed one by one, like Sulu's own controllers do
            $this->assertNotNull($envelope->last(EnableFlushStamp::class));
        }
    }

    public function testOneFailingEntryDoesNotStopTheOthers(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (Envelope $envelope): Envelope {
            if (['uuid' => self::FIRST] === $envelope->getMessage()->getIdentifier()) {
                throw new \RuntimeException('Copy failed.');
            }

            return $envelope;
        });

        $result = $this->createHandler('article', $bus)->copyLocale([self::FIRST, self::SECOND], 'de', 'en');

        $this->assertSame(['done' => [self::SECOND], 'failed' => [self::FIRST => 'Copy failed.']], $result);
    }

    public function testCopyLocaleIsNotAWorkflowTransition(): void
    {
        $handler = $this->createHandler('snippet', $this->createRecordingBus());

        $this->expectException(\InvalidArgumentException::class);
        $handler->handle('copy_locale', [self::FIRST], 'de');
    }

    public function testPagesOfferOnlyCopyLocale(): void
    {
        $handler = $this->createHandler('page', $this->createRecordingBus());

        foreach (ContentWorkflowBulkActionHandler::ACTIONS as $action) {
            $this->assertFalse($handler->supports('pages', $action), $action);
        }
    }

    public function testPagesAreCheckedWithTheirOwnPermissions(): void
    {
        $handler = $this->createHandler('page', $this->createRecordingBus());

        $this->assertInstanceOf(SecuredObjectHandlerInterface::class, $handler);
        $this->assertSame(Page::class, $handler->getSecuredClass());
    }

    public function testEntitiesOfOtherBundlesDoNotOfferCopyLocale(): void
    {
        $handler = new ContentEntityBulkActionHandler(
            'events',
            \stdClass::class,
            'sulu.events.events',
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ContentWorkflowInterface::class),
        );

        $this->assertNotInstanceOf(LocaleCopyHandlerInterface::class, $handler);
        $this->assertFalse($handler->supports('events', 'copy_locale'));
    }

    private function createRecordingBus(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (Envelope $envelope): Envelope {
            $this->dispatched[] = $envelope;

            return $envelope;
        });

        return $bus;
    }

    private function createHandler(string $type, MessageBusInterface $bus): ContentWorkflowBulkActionHandler&LocaleCopyHandlerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        return match ($type) {
            'article' => new ArticleBulkActionHandler($bus, $entityManager, $this->createMock(GroupProviderInterface::class)),
            'snippet' => new SnippetBulkActionHandler($bus, $entityManager),
            'page' => new PageBulkActionHandler($bus, $entityManager),
        };
    }
}
