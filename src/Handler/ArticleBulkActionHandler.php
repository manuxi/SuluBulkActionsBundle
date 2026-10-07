<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\CopyLocaleArticleMessage;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Articles have one security context per article group (Blog, FAQ, ...), the same ones the role form shows.
 */
class ArticleBulkActionHandler extends ContentWorkflowBulkActionHandler implements LocaleCopyHandlerInterface
{
    private readonly DimensionContentLookup $lookup;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupProviderInterface $groupProvider,
    ) {
        parent::__construct($messageBus);
        $this->lookup = new DimensionContentLookup($entityManager, ArticleInterface::class);
    }

    protected function getResourceKey(): string
    {
        return 'articles';
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        // A scalar query on purpose: loading the article as an object would fill the identity map with the draft
        // dimension only, and the workflow would then not find the live one.
        $templateKey = $this->entityManager->createQueryBuilder()
            ->select('dimensionContent.templateKey')
            ->from(ArticleDimensionContentInterface::class, 'dimensionContent')
            ->innerJoin('dimensionContent.article', 'article')
            ->where('article.uuid = :uuid')
            ->andWhere('dimensionContent.stage = :stage')
            ->andWhere('dimensionContent.locale = :locale')
            ->andWhere('dimensionContent.version = 0')
            ->setParameter('uuid', $id)
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->setParameter('locale', $locale)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        $groups = $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE);
        foreach ($groups as $group) {
            if (null !== $templateKey && \in_array($templateKey, $group->templates, true)) {
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

    public function findMissingInLocale(array $ids, string $locale): array
    {
        return $this->lookup->findMissingInLocale($ids, $locale);
    }

    public function getTitles(array $ids, string $locale): array
    {
        return $this->lookup->getTitles($ids, $locale);
    }

    protected function createTransitionMessage(array $identifier, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionArticleMessage($identifier, $locale, $transition);
    }

    protected function createRemoveMessage(array $identifier, string $locale): object
    {
        return new RemoveArticleMessage($identifier, $locale);
    }

    protected function createCopyLocaleMessage(array $identifier, string $sourceLocale, string $targetLocale): object
    {
        return new CopyLocaleArticleMessage($identifier, $sourceLocale, $targetLocale);
    }
}
