# SuluBulkActionsBundle (Sulu 3.x)

Bulk actions for lists in the Sulu admin: mark rows, then publish, unpublish or (optionally) delete them in one go.
Articles (all groups) and snippets work out of the box; other bundles bring their own handler.

This is the `3.x` branch for Sulu 3.0. The `main` branch is the version for Sulu 2.6.

## Installation

```console
composer require manuxi/sulu-bulk-actions-bundle:3.x-dev
```

1. Register the bundle in `config/bundles.php`:
   `Manuxi\SuluBulkActionsBundle\SuluBulkActionsBundle::class => ['all' => true]`
2. Import the routes in `config/routes_admin.yaml`:
   ```yaml
   SuluBulkActionsBundle:
       resource: '@SuluBulkActionsBundle/Resources/config/routes_admin.yaml'
   ```
3. Add the JS to `assets/admin/package.json` (`"sulu-bulk-actions-bundle": "file:../../vendor/manuxi/sulu-bulk-actions-bundle/src/Resources/js"`)
   and `assets/admin/app.js` (`import 'sulu-bulk-actions-bundle';`), then `npm install --force && npm run build`.
4. Give the "Bulk actions" context to a role (see Permissions).

## Permissions

The bundle has its own security context `sulu.bulk_actions.actions` ("Bulk actions" in the role form):

| Permission | Allows |
|---|---|
| Live | bulk publish and unpublish |
| Delete | bulk delete (only if `delete_enabled` is on, see below) |

Nobody has it by default. Bulk actions are separate from the rights on the single entries, so it is meant for admins.
The dropdown only appears for users who have the permission, and the endpoint checks it again.

## Configuration

```yaml
sulu_bulk_actions:
    delete_enabled: false        # bulk delete is off; turning it on also needs the "Delete" permission
    resources:                   # optional; articles and snippets are added by default
        articles:
            view_prefixes: ['sulu_article.article.list_']
            actions: [publish, unpublish]
```

## Handlers for other resources

Implement `Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface` (it is tagged automatically) and add the
resource to `sulu_bulk_actions.resources` with the names of its list views:

```php
public function supports(string $resourceKey, string $action): bool
{
    return 'testimonials' === $resourceKey && \in_array($action, ['publish', 'unpublish'], true);
}

public function handle(string $action, array $ids, string $locale): array
{
    // ... return ['done' => [...ids], 'failed' => [id => message]]
}
```

## Endpoint

`POST /admin/api/bulk-actions/{resourceKey}/{action}?locale=de` with `{"ids": ["..."]}`. The prefix is deliberately not
`/admin/api/{resourceKey}/...`, which would collide with the routes of the resources themselves.
