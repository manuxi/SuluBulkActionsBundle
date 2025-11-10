# SuluBulkActionBundle
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/manuxi/SuluBulkActionsBundle/LICENSE)
![GitHub Tag](https://img.shields.io/github/v/tag/manuxi/SuluBulkActionsBundle)
![Supports Sulu 2.6 or later](https://img.shields.io/badge/%20Sulu->=2.6-0088cc?color=00b2df)

I made this bundle to have the possibility to manage bulk actions in lists of my projects.

Please feel comfortable submitting feature requests.
This bundle is still in development. Use at own risk 🤞🏻

## 👩🏻‍🏭 Installation
Some tasks are awaiting you!

Install the package with:
```console
composer require manuxi/sulu-bulk-actions-bundle
```

Rebuild admin sources:
```bash
    cd assets/admin
    npm install
    npm run build
```

1. Then add the config for resourcekey and actions to Admin in project/bundle
```php
    public function getConfigKey(): ?string
    {
        return 'sulu_mybundle';
    }

    public function getConfig(): ?array
    {
        return [
            'resourceKey' => 'mybundle',  //with this, the route is build: /admin/api/{resourceKey}/bulk-{action}
            'bulkActions' => [
                'publish' => ['icon' => 'su-eye'],
                'unpublish' => ['icon' => 'su-eye-slash'],
            ],
        ];
    }
```
2. The action-buttons must be added to the Admin-Class in project/bundle
```php
    $listToolbarActions[] = new ToolbarAction('app.bulk.actions_dropdown', [
        'label' => 'sulu_bulk_actions.actions',
        'icon' => 'su-pen',
        'actions' => [
            'app.bulk.publish',
            'app.bulk.unpublish',
        ],
    ]);
```
3. Add a handler in project/bundle
```php
    class MybundleBulkActionHandler
    {
        public function __construct(
            private readonly MybundleModel $model,
        ) {
        }
    
        public function supports(string $resourceKey, string $action): bool
        {
            return 'testimonials' === $resourceKey
                && in_array($action, ['publish', 'unpublish']);
        }
    
        public function handle(string $action, array $ids, Request $request): array
        {
            return match ($action) {
                'publish' => $this->tmodel->publishBulk($ids, $request),
                'unpublish' => $this->model->unpublishBulk($ids, $request),
                default => [],
            };
        }
    }
```

Thats mainly all.

