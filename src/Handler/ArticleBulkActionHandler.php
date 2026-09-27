<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Articles have one security context per article group (Blog, FAQ, ...), the same ones the role form shows.
 */
class ArticleBulkActionHandler extends ContentWorkflowBulkActionHandler
{
    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly GroupProviderInterface $groupProvider,
    ) {
        parent::__construct($messageBus);
    }

    protected function getResourceKey(): string
    {
        return 'articles';
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        $article = $this->articleRepository->findOneBy(
            ['uuid' => $id],
            [
                ArticleRepositoryInterface::SELECT_ARTICLE_CONTENT => [
                    'dimensionAttributes' => [
                        'locale' => $locale,
                        'stage' => DimensionContentInterface::STAGE_DRAFT,
                    ],
                ],
            ],
        );

        $templateKey = null;
        foreach ($article?->getDimensionContents() ?? [] as $dimensionContent) {
            $templateKey = $dimensionContent->getTemplateKey();
            if (null !== $templateKey) {
                break;
            }
        }

        $groups = $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE);
        foreach ($groups as $group) {
            if (\in_array($templateKey, $group->templates, true)) {
                return $this->contextOfGroup($groups, $group->identifier);
            }
        }

        // template not in any group (or article not found in this locale): the base context
        return ArticleAdmin::SECURITY_CONTEXT;
    }

    public function getListSecurityContext(string $viewName): ?string
    {
        $prefix = ArticleAdmin::LIST_VIEW.'_';
        if (!str_starts_with($viewName, $prefix)) {
            return null;
        }

        return $this->contextOfGroup(
            $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE),
            substr($viewName, \strlen($prefix)),
        );
    }

    /**
     * Same rule as in the ArticleAdmin: one group or the default group use the base context.
     *
     * @param array<string, \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup> $groups
     */
    private function contextOfGroup(array $groups, string $identifier): string
    {
        if (1 === \count($groups) || GroupProviderInterface::DEFAULT_GROUP === $identifier) {
            return ArticleAdmin::SECURITY_CONTEXT;
        }

        return ArticleAdmin::getArticleSecurityContext($identifier);
    }

    protected function createTransitionMessage(array $identifier, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionArticleMessage($identifier, $locale, $transition);
    }

    protected function createRemoveMessage(array $identifier, string $locale): object
    {
        return new RemoveArticleMessage($identifier, $locale);
    }
}
