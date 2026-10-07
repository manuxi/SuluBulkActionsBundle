<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\Controller;

use Manuxi\SuluBulkActionsBundle\Admin\BulkActionsAdmin;
use Manuxi\SuluBulkActionsBundle\Controller\Admin\BulkActionController;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerRegistry;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeBulkActionHandler;
use Manuxi\SuluBulkActionsBundle\Tests\Fixtures\FakeLocaleCopyHandler;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Localization\Manager\LocalizationManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class BulkActionControllerCopyLocaleTest extends TestCase
{
    /** @var list<array{string|SecurityCondition, string}> subject and permission of every check of an entry */
    private array $checks = [];

    /** @var list<array{string, string}> context and permission of the check of the bulk context */
    private array $bulkChecks = [];

    public function testCopiesEntriesWithTheSourceAndSkipsTheOthersWithTheirTitles(): void
    {
        $handler = new FakeLocaleCopyHandler(
            ['a' => ['de'], 'b' => ['en'], 'c' => ['de', 'en']],
            titles: ['a' => 'Sommerfest', 'b' => 'Summer party', 'c' => 'Jahresbericht'],
        );

        $response = $this->copy($handler, ['a', 'b', 'c'], ['sourceLocale' => 'de', 'targetLocale' => 'en']);
        $data = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertSame(['a'], $handler->handledIds);
        $this->assertSame(['de', 'en'], $handler->copiedLocales);
        $this->assertSame(1, $data['done']);
        $this->assertSame(1, $data['missing']);
        $this->assertSame(1, $data['existing']);
        $this->assertSame('de', $data['locale']);
        $this->assertSame('en', $data['targetLocale']);
        $this->assertSame([
            ['id' => 'b', 'title' => 'Summer party', 'reason' => 'missing'],
            ['id' => 'c', 'title' => 'Jahresbericht', 'reason' => 'existing'],
        ], $data['skipped']);
    }

    public function testOverwriteCopiesIntoAnExistingTarget(): void
    {
        $handler = new FakeLocaleCopyHandler(['a' => ['de'], 'c' => ['de', 'en']]);

        $data = $this->decode($this->copy($handler, ['a', 'c'], ['sourceLocale' => 'de', 'targetLocale' => 'en', 'overwrite' => true]));

        $this->assertSame(['a', 'c'], $handler->handledIds);
        $this->assertSame(2, $data['done']);
        $this->assertSame(0, $data['existing']);
        $this->assertSame([], $data['skipped']);
    }

    public function testOverwriteMustBeTrueNotJustTruthy(): void
    {
        $handler = new FakeLocaleCopyHandler(['c' => ['de', 'en']]);

        $response = $this->copy($handler, ['c'], ['sourceLocale' => 'de', 'targetLocale' => 'en', 'overwrite' => 'yes']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $handler->handledIds);
    }

    public function testNothingCopiedBecauseAllTargetsExistIsExplained(): void
    {
        $handler = new FakeLocaleCopyHandler(['c' => ['de', 'en']], titles: ['c' => 'Jahresbericht']);

        $response = $this->copy($handler, ['c'], ['sourceLocale' => 'de', 'targetLocale' => 'en']);
        $data = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('1 entry already has content in locale "en" and was not overwritten.', $data['error']);
    }

    public function testPermissionIsEditInTheTargetLocale(): void
    {
        $handler = new FakeLocaleCopyHandler(['a' => ['de'], 'd' => ['de']], contexts: ['a' => 'sulu.snippet.snippets', 'd' => 'forbidden']);

        $data = $this->decode($this->copy($handler, ['a', 'd'], ['sourceLocale' => 'de', 'targetLocale' => 'en'], ['forbidden' => false]));

        $this->assertSame([[BulkActionsAdmin::SECURITY_CONTEXT, PermissionTypes::EDIT]], $this->bulkChecks);
        $this->assertCount(2, $this->checks);

        [$subject, $permission] = $this->checks[0];
        $this->assertInstanceOf(SecurityCondition::class, $subject);
        $this->assertSame('sulu.snippet.snippets', $subject->getSecurityContext());
        $this->assertSame('en', $subject->getLocale());
        $this->assertSame(PermissionTypes::EDIT, $permission);

        $this->assertSame(['a'], $handler->handledIds);
        $this->assertSame(1, $data['denied']);
    }

    public function testSourceLocaleDefaultsToTheLocaleOfTheList(): void
    {
        $handler = new FakeLocaleCopyHandler(['a' => ['de']]);

        $this->copy($handler, ['a'], ['targetLocale' => 'en'], [], 'de');

        $this->assertSame(['de', 'en'], $handler->copiedLocales);
    }

    public function testRejectsTheSameLocaleTwice(): void
    {
        $handler = new FakeLocaleCopyHandler(['a' => ['de']]);

        $response = $this->copy($handler, ['a'], ['sourceLocale' => 'de', 'targetLocale' => 'de']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($handler->copiedLocales);
    }

    public function testRejectsAMissingTargetLocale(): void
    {
        $response = $this->copy(new FakeLocaleCopyHandler(['a' => ['de']]), ['a'], ['sourceLocale' => 'de']);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRejectsLocalesThatAreNotContentLocales(): void
    {
        $localizationManager = $this->createMock(LocalizationManagerInterface::class);
        $localizationManager->method('getLocales')->willReturn(['de', 'en']);

        $handler = new FakeLocaleCopyHandler(['a' => ['de']]);
        $response = $this->copy($handler, ['a'], ['sourceLocale' => 'de', 'targetLocale' => 'fr'], localizationManager: $localizationManager);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('"fr"', $this->decode($response)['error']);
        $this->assertNull($handler->copiedLocales);
    }

    public function testHandlersWithoutLocaleCopyAreNotFound(): void
    {
        $response = $this->copy(new FakeBulkActionHandler(['a' => ['de']]), ['a'], ['sourceLocale' => 'de', 'targetLocale' => 'en']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFailuresAreNamedByTheirTitle(): void
    {
        $handler = new FakeLocaleCopyHandler(
            ['a' => ['de']],
            titles: ['a' => 'Sommerfest'],
            failures: ['a' => 'Something broke.'],
        );

        $response = $this->copy($handler, ['a'], ['sourceLocale' => 'de', 'targetLocale' => 'en']);
        $data = $this->decode($response);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([['id' => 'a', 'title' => 'Sommerfest', 'message' => 'Something broke.']], $data['failures']);
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $body without the IDs
     * @param array<string, bool> $permissions security context => granted (default: granted)
     */
    private function copy(
        BulkActionHandlerInterface $handler,
        array $ids,
        array $body,
        array $permissions = [],
        string $locale = 'de',
        ?LocalizationManagerInterface $localizationManager = null,
    ): JsonResponse {
        $registry = new BulkActionHandlerRegistry();
        $registry->addHandler($handler);

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('checkPermission')
            ->willReturnCallback(function (string $context, string $permission): bool {
                $this->bulkChecks[] = [$context, $permission];

                return true;
            });
        $securityChecker->method('hasPermission')
            ->willReturnCallback(function (string|SecurityCondition $subject, string $permission) use ($permissions): bool {
                $this->checks[] = [$subject, $permission];
                $context = $subject instanceof SecurityCondition ? $subject->getSecurityContext() : $subject;

                return $permissions[$context] ?? true;
            });

        $controller = new BulkActionController($registry, $securityChecker, false, $localizationManager);
        $request = Request::create(
            '/admin/api/bulk-actions/items/copy_locale?locale='.$locale,
            'POST',
            content: json_encode(['ids' => $ids] + $body),
        );

        return $controller->execute('items', 'copy_locale', $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }
}
