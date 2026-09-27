<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sulu_bulk_actions');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('delete_enabled')
                    ->defaultFalse()
                    ->info('Bulk delete is off by default. Turning it on also needs the "delete" permission of the "Bulk actions" context in a role.')
                ->end()
                ->arrayNode('resources')
                    ->info('Lists that get the bulk actions dropdown, by resource key. Articles and snippets are added by default when installed.')
                    ->useAttributeAsKey('resource_key')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('view_prefixes')
                                ->info('Names (or beginnings of names) of the list views, e.g. "sulu_article.article.list_".')
                                ->scalarPrototype()->end()
                                ->requiresAtLeastOneElement()
                            ->end()
                            ->arrayNode('actions')
                                ->info('publish, unpublish and/or delete; a handler for the resource key must exist.')
                                ->scalarPrototype()->end()
                                ->defaultValue(['publish', 'unpublish'])
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
