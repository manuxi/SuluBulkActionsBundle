<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\DependencyInjection;

use Manuxi\SuluBulkActionsBundle\Handler\ArticleBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\SnippetBulkActionHandler;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
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
            'actions' => ['publish', 'unpublish'],
        ],
        'snippets' => [
            'view_prefixes' => ['sulu_snippet.snippet.list'],
            'actions' => ['publish', 'unpublish'],
        ],
    ];

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

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
                    new Reference('sulu_article.article_repository'),
                    new Reference('sulu_admin.metadata_group_provider'),
                ])
                ->addTag('sulu_bulk_actions.handler'));
        }

        if ($this->isInstalled('snippets')) {
            $container->setDefinition(SnippetBulkActionHandler::class, (new Definition(SnippetBulkActionHandler::class))
                ->setArguments([new Reference(MessageBusInterface::class)])
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
            default => false,
        };
    }
}
