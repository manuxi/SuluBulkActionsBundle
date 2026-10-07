<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

/**
 * Optional for handlers whose entries can have permissions of their own (for pages: the "Permissions" tab of a page).
 * The entries are then checked like Sulu checks them one by one: the security context together with the object.
 */
interface SecuredObjectHandlerInterface
{
    /**
     * The class Sulu stores the permissions of single objects for (the "secured class" of its controller).
     */
    public function getSecuredClass(): string;
}
