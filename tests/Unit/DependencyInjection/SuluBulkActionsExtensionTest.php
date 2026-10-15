<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\DependencyInjection;

use Manuxi\SuluBulkActionsBundle\Controller\Admin\BulkActionController;
use Manuxi\SuluBulkActionsBundle\DependencyInjection\SuluBulkActionsExtension;
use Manuxi\SuluBulkActionsBundle\Handler\ArticleBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\ContentEntityBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\FormBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\SnippetBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\Entity\Item;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeEntityDomainEvent;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeFormManager;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeRemovedDomainEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

class SuluBulkActionsExtensionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // the bundles of the same family are not installed here: stand-ins for the classes the extension looks for
        $aliases = [
            'Manuxi\SuluEventBundle\Entity\Event' => Item::class,
            'Manuxi\SuluEventBundle\Domain\Event\Event\PublishedEvent' => FakeEntityDomainEvent::class,
            'Manuxi\SuluEventBundle\Domain\Event\Event\RemovedEvent' => FakeRemovedDomainEvent::class,
            'Manuxi\SuluTestimonialsBundle\Entity\Testimonial' => Item::class,
            'Sulu\Bundle\FormBundle\Manager\FormManager' => FakeFormManager::class,
        ];

        foreach ($aliases as $original => $alias) {
            if (!class_exists($original)) {
                class_alias($alias, $original);
            }
        }
    }

    public function testEveryDefaultListOffersDeleteAndCopyLocale(): void
    {
        $container = $this->load();

        $resources = $container->getParameter('sulu_bulk_actions.resources');

        foreach (['articles', 'snippets', 'testimonials', 'events'] as $resourceKey) {
            $this->assertArrayHasKey($resourceKey, $resources);
            $this->assertSame(['publish', 'unpublish', 'copy_locale', 'delete'], $resources[$resourceKey]['actions'], $resourceKey);
        }
    }

    public function testFormsGetOnlyCopyLocaleInTheirList(): void
    {
        $container = $this->load();

        $this->assertSame(
            ['view_prefixes' => ['sulu_form.list'], 'actions' => ['copy_locale']],
            $container->getParameter('sulu_bulk_actions.resources')['forms'],
        );

        $definition = $container->getDefinition(FormBulkActionHandler::class);
        $this->assertTrue($definition->hasTag('sulu_bulk_actions.handler'));
        $this->assertSame('sulu_form.manager.form', (string) $definition->getArgument(0));
        $this->assertSame('doctrine.orm.entity_manager', (string) $definition->getArgument(1));
    }

    public function testDeleteIsOnByDefaultAndCanBeSwitchedOff(): void
    {
        $this->assertTrue($this->load()->getParameter('sulu_bulk_actions.delete_enabled'));
        $this->assertFalse($this->load(['delete_enabled' => false])->getParameter('sulu_bulk_actions.delete_enabled'));
    }

    public function testProjectConfigurationOfAListWins(): void
    {
        $container = $this->load(['resources' => ['articles' => [
            'view_prefixes' => ['my_articles.list'],
            'actions' => ['publish'],
        ]]]);

        $this->assertSame(
            ['view_prefixes' => ['my_articles.list'], 'actions' => ['publish']],
            $container->getParameter('sulu_bulk_actions.resources')['articles'],
        );
    }

    public function testServicesAreLoadedFromYaml(): void
    {
        $container = $this->load();

        $this->assertTrue($container->hasDefinition('sulu_bulk_actions.handler_registry'));
        $this->assertTrue($container->hasDefinition(BulkActionController::class));
        $this->assertTrue($container->getDefinition(BulkActionController::class)->isPublic());
        $this->assertTrue($container->getDefinition(BulkActionController::class)->hasTag('controller.service_arguments'));

        $localizationManager = $container->getDefinition(BulkActionController::class)->getArgument(3);
        $this->assertInstanceOf(Reference::class, $localizationManager);
        $this->assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $localizationManager->getInvalidBehavior());

        $this->assertTrue($container->hasDefinition(ArticleBulkActionHandler::class));
        $this->assertTrue($container->hasDefinition(SnippetBulkActionHandler::class));
    }

    public function testHandlersOfTheOtherBundlesGetTheServicesAndOnlyTheDomainEventsThatExist(): void
    {
        $definition = $this->load()->getDefinition('sulu_bulk_actions.handler.events');

        $this->assertSame(ContentEntityBulkActionHandler::class, $definition->getClass());
        $this->assertTrue($definition->hasTag('sulu_bulk_actions.handler'));
        $this->assertSame('sulu_content.content_manager', (string) $definition->getArgument(5));
        $this->assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $definition->getArgument(6)->getInvalidBehavior());
        $this->assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $definition->getArgument(7)->getInvalidBehavior());
        $this->assertSame([
            ContentEntityBulkActionHandler::EVENT_PUBLISHED => 'Manuxi\SuluEventBundle\Domain\Event\Event\PublishedEvent',
            ContentEntityBulkActionHandler::EVENT_REMOVED => 'Manuxi\SuluEventBundle\Domain\Event\Event\RemovedEvent',
        ], $definition->getArgument(8));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new SuluBulkActionsExtension())->load([$config], $container);

        return $container;
    }
}
