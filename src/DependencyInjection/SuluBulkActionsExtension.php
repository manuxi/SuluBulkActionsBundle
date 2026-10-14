<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\DependencyInjection;

use Manuxi\SuluBulkActionsBundle\Handler\ArticleBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\ContentEntityBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\PageBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\SnippetBulkActionHandler;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Page\Application\Message\CopyLocalePageMessage;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Messenger\MessageBusInterface;

class SuluBulkActionsExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Lists that get the dropdown without any project configuration.
     */
    private const DEFAULT_RESOURCES = [
        'articles' => [
            'view_prefixes' => ['sulu_article.article.list_'],
            'actions' => ['publish', 'unpublish', 'copy_locale', 'delete'],
        ],
        'snippets' => [
            'view_prefixes' => ['sulu_snippet.snippet.list'],
            'actions' => ['publish', 'unpublish', 'copy_locale', 'delete'],
        ],
        'testimonials' => [
            'view_prefixes' => ['sulu_testimonials.testimonials.list'],
            'actions' => ['publish', 'unpublish', 'copy_locale', 'delete'],
        ],
        'events' => [
            'view_prefixes' => ['sulu_event.event.list'],
            'actions' => ['publish', 'unpublish', 'copy_locale', 'delete'],
        ],
    ];

    /**
     * Bundles of the same family that work with the repository, the content manager and the content workflow: all
     * actions are handled generically. Registered only if the bundle is installed. The domain events keep the activity
     * log and the search index of the bundle up to date, see ContentEntityBulkActionHandler.
     */
    private const OWN_BUNDLE_ENTITIES = [
        'testimonials' => [
            'entity' => 'Manuxi\SuluTestimonialsBundle\Entity\Testimonial',
            'security_context' => 'sulu.testimonials.testimonials',
            'domain_events' => [
                ContentEntityBulkActionHandler::EVENT_PUBLISHED => 'Manuxi\SuluTestimonialsBundle\Domain\Event\TestimonialPublishedEvent',
                ContentEntityBulkActionHandler::EVENT_UNPUBLISHED => 'Manuxi\SuluTestimonialsBundle\Domain\Event\TestimonialUnpublishedEvent',
                ContentEntityBulkActionHandler::EVENT_REMOVED => 'Manuxi\SuluTestimonialsBundle\Domain\Event\TestimonialRemovedEvent',
            ],
        ],
        'events' => [
            'entity' => 'Manuxi\SuluEventBundle\Entity\Event',
            'security_context' => 'sulu.events.events',
            'domain_events' => [
                ContentEntityBulkActionHandler::EVENT_PUBLISHED => 'Manuxi\SuluEventBundle\Domain\Event\Event\PublishedEvent',
                ContentEntityBulkActionHandler::EVENT_UNPUBLISHED => 'Manuxi\SuluEventBundle\Domain\Event\Event\UnpublishedEvent',
                ContentEntityBulkActionHandler::EVENT_REMOVED => 'Manuxi\SuluEventBundle\Domain\Event\Event\RemovedEvent',
            ],
        ],
    ];

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $resources = $config['resources'];
        foreach (self::DEFAULT_RESOURCES as $resourceKey => $defaults) {
            if (!$this->isInstalled($resourceKey)) {
                continue;
            }
            $resources[$resourceKey] ??= $defaults;
        }

        $container->setParameter('sulu_bulk_actions.delete_enabled', $config['delete_enabled']);
        $container->setParameter('sulu_bulk_actions.resources', $resources);

        $container->registerForAutoconfiguration(BulkActionHandlerInterface::class)
            ->addTag('sulu_bulk_actions.handler');

        if ($this->isInstalled('articles')) {
            $container->setDefinition(ArticleBulkActionHandler::class, (new Definition(ArticleBulkActionHandler::class))
                ->setArguments([
                    new Reference(MessageBusInterface::class),
                    new Reference('doctrine.orm.entity_manager'),
                    new Reference('sulu_admin.metadata_group_provider'),
                ])
                ->addTag('sulu_bulk_actions.handler'));
        }

        if ($this->isInstalled('snippets')) {
            $container->setDefinition(SnippetBulkActionHandler::class, (new Definition(SnippetBulkActionHandler::class))
                ->setArguments([
                    new Reference(MessageBusInterface::class),
                    new Reference('doctrine.orm.entity_manager'),
                ])
                ->addTag('sulu_bulk_actions.handler'));
        }

        // pages: only copy_locale and without a default list (the page tree cannot select), see PageBulkActionHandler
        if ($this->isInstalled('pages')) {
            $container->setDefinition(PageBulkActionHandler::class, (new Definition(PageBulkActionHandler::class))
                ->setArguments([
                    new Reference(MessageBusInterface::class),
                    new Reference('doctrine.orm.entity_manager'),
                ])
                ->addTag('sulu_bulk_actions.handler'));
        }

        foreach (self::OWN_BUNDLE_ENTITIES as $resourceKey => $bundle) {
            if (!$this->isInstalled($resourceKey)) {
                continue;
            }

            $container->setDefinition('sulu_bulk_actions.handler.'.$resourceKey, (new Definition(ContentEntityBulkActionHandler::class))
                ->setArguments([
                    $resourceKey,
                    $bundle['entity'],
                    $bundle['security_context'],
                    new Reference('doctrine.orm.entity_manager'),
                    new Reference('sulu_content.content_workflow'),
                    new Reference('sulu_content.content_manager'),
                    new Reference('sulu_activity.domain_event_collector', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('sulu_trash.trash_manager', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    array_filter($bundle['domain_events'], 'class_exists'),
                ])
                ->addTag('sulu_bulk_actions.handler'));
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => [
                        __DIR__.'/../Resources/translations',
                    ],
                ],
            ]);
        }
    }

    private function isInstalled(string $resourceKey): bool
    {
        return match ($resourceKey) {
            'articles' => class_exists(ApplyWorkflowTransitionArticleMessage::class),
            'snippets' => class_exists(ApplyWorkflowTransitionSnippetMessage::class),
            'pages' => class_exists(CopyLocalePageMessage::class),
            default => isset(self::OWN_BUNDLE_ENTITIES[$resourceKey]) && class_exists(self::OWN_BUNDLE_ENTITIES[$resourceKey]['entity']),
        };
    }
}
