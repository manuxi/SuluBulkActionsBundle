<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\Controller;

use Manuxi\SuluBulkActionsBundle\Controller\Admin\BulkActionController;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerRegistry;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeBulkActionHandler;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class BulkActionControllerTest extends TestCase
{
    public function testEntriesWithoutTheLocaleAreSkippedAndReportedOnTheirOwn(): void
    {
        $handler = new FakeBulkActionHandler(['a' => ['de'], 'b' => ['de']]);

        $response = $this->execute($handler, ['a', 'b'], 'en');
        $data = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $handler->handledIds, 'entries without the locale must not reach the workflow');
        $this->assertSame(0, $data['done']);
        $this->assertSame(2, $data['missing']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame('en', $data['locale']);
        $this->assertSame([], $data['failures']);
        $this->assertSame('2 entries have no content in locale "en" and were skipped.', $data['error']);
    }

    public function testMissingLocaleIsCheckedBeforeThePermission(): void
    {
        $handler = new FakeBulkActionHandler(['a' => ['de']], contexts: ['a' => 'forbidden']);

        $data = $this->decode($this->execute($handler, ['a'], 'en', ['forbidden' => false]));

        $this->assertSame(1, $data['missing']);
        $this->assertSame(0, $data['denied']);
    }

    public function testMixedResultCountsEveryCategoryAndNamesFailuresByTitle(): void
    {
        $handler = new FakeBulkActionHandler(
            ['a' => ['de', 'en'], 'b' => ['de'], 'c' => ['en'], 'd' => ['en']],
            titles: ['c' => 'Summer party'],
            failures: ['c' => 'Transition "publish" is not enabled.'],
            contexts: ['d' => 'forbidden'],
        );

        $response = $this->execute($handler, ['a', 'b', 'c', 'd'], 'en', ['forbidden' => false]);
        $data = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertSame(['a', 'c'], $handler->handledIds);
        $this->assertSame(1, $data['done']);
        $this->assertSame(1, $data['missing']);
        $this->assertSame(1, $data['denied']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(
            [['id' => 'c', 'title' => 'Summer party', 'message' => 'Transition "publish" is not enabled.']],
            $data['failures'],
        );
    }

    public function testFailuresWithoutAnyDoneUseTheTitleInTheMessage(): void
    {
        $handler = new FakeBulkActionHandler(
            ['a' => ['en']],
            titles: ['a' => 'Summer party'],
            failures: ['a' => 'Something broke.'],
        );

        $response = $this->execute($handler, ['a'], 'en');
        $data = $this->decode($response);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('1 entry failed: Summer party: Something broke.', $data['error']);
    }

    public function testOnlyDeniedEntriesAreForbidden(): void
    {
        $handler = new FakeBulkActionHandler(['a' => ['en']], contexts: ['a' => 'forbidden']);

        $response = $this->execute($handler, ['a'], 'en', ['forbidden' => false]);
        $data = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(1, $data['denied']);
        $this->assertSame('1 entry was skipped: no permission.', $data['error']);
    }

    public function testHandlersWithoutEntryInfoKeepWorking(): void
    {
        $handler = new class implements BulkActionHandlerInterface {
            public function supports(string $resourceKey, string $action): bool
            {
                return 'items' === $resourceKey;
            }

            public function getSecurityContext(string $id, string $locale): ?string
            {
                return null;
            }

            public function getListSecurityContext(string $viewName): ?string
            {
                return null;
            }

            public function handle(string $action, array $ids, string $locale): array
            {
                return ['done' => [], 'failed' => ['a' => 'No content found for attributes [locale=en].']];
            }
        };

        $response = $this->execute($handler, ['a'], 'en');
        $data = $this->decode($response);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(0, $data['missing']);
        $this->assertSame([['id' => 'a', 'title' => null, 'message' => 'No content found for attributes [locale=en].']], $data['failures']);
    }

    /**
     * @param list<string> $ids
     * @param array<string, bool> $permissions security context => granted (default: granted)
     */
    private function execute(BulkActionHandlerInterface $handler, array $ids, string $locale, array $permissions = []): JsonResponse
    {
        $registry = new BulkActionHandlerRegistry();
        $registry->addHandler($handler);

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')
            ->willReturnCallback(static fn (string $context): bool => $permissions[$context] ?? true);

        $controller = new BulkActionController($registry, $securityChecker, false);
        $request = Request::create('/admin/api/bulk-actions/items/publish?locale='.$locale, 'POST', content: json_encode(['ids' => $ids]));

        return $controller->execute('items', 'publish', $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }
}
