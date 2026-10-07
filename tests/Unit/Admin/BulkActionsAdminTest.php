<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Unit\Admin;

use Manuxi\SuluBulkActionsBundle\Admin\BulkActionsAdmin;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerRegistry;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

class BulkActionsAdminTest extends TestCase
{
    public function testEveryActionNeedsThePermissionItWouldNeedOneByOne(): void
    {
        $this->assertSame(PermissionTypes::LIVE, BulkActionsAdmin::getPermission('publish'));
        $this->assertSame(PermissionTypes::LIVE, BulkActionsAdmin::getPermission('unpublish'));
        $this->assertSame(PermissionTypes::EDIT, BulkActionsAdmin::getPermission('copy_locale'));
        $this->assertSame(PermissionTypes::DELETE, BulkActionsAdmin::getPermission('delete'));
    }

    public function testTheRoleFormOffersEditForCopyingLocales(): void
    {
        $admin = new BulkActionsAdmin(
            $this->createMock(SecurityCheckerInterface::class),
            new BulkActionHandlerRegistry(),
            [],
            false,
        );

        $permissions = $admin->getSecurityContexts()[Admin::SULU_ADMIN_SECURITY_SYSTEM]['BulkActions'][BulkActionsAdmin::SECURITY_CONTEXT];

        $this->assertSame([PermissionTypes::EDIT, PermissionTypes::LIVE, PermissionTypes::DELETE], $permissions);
    }
}
