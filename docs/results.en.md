# Result of a bulk action

After a bulk action, the bundle shows a dialog if not all selected entries could be processed.
The dialog names every group on its own, in the language of the admin, and leaves out technical messages where it can.

![Result of a bulk action](img/results.en.png)

---

## Groups

| Group             | Meaning                                                                                       | Example                                                                                               |
|-------------------|-----------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------|
| Done              | The action was executed.                                                                      | "3 entries were published."                                                                           |
| Language missing  | The entry has no version in the chosen language. It is detected up front and skipped.         | "14 entries have no English version and were skipped. Choose another language at the top or create that version first." |
| No permission     | The role may not publish or delete this entry (for example another article group).           | "2 entries were skipped because you lack the permission."                                            |
| Failed            | Any other error. The first five entries are listed with title and message.                    | “Summer party”: Transition "unpublish" is not enabled.                                             |

The title of the dialog is "Bulk action partly done" if at least one entry was done, otherwise "Bulk action not done".
If everything was done, no dialog appears.

The name of the language comes from the browser (`Intl.DisplayNames`), for example "English" for `en`. If the browser
does not know the language, the code is shown.

---

## Response of the endpoint

`POST /admin/api/bulk-actions/{resourceKey}/{action}?locale=en` returns the count of every group:

```json
{
    "error": "14 entries have no content in locale \"en\" and were skipped.",
    "done": 0,
    "missing": 14,
    "denied": 0,
    "failed": 0,
    "locale": "en",
    "failures": []
}
```

| Field      | Content                                                                   |
|------------|---------------------------------------------------------------------------|
| `success`  | `true` if at least one entry was done                                     |
| `error`    | English summary for API clients if nothing was done                       |
| `missing`  | entries without a version in `locale`                                     |
| `denied`   | entries without permission                                                |
| `failed`   | entries with another error                                                |
| `failures` | list of `{id, title, message}`; `title` is `null` if the handler has none |

| Status | When                                                   |
|--------|--------------------------------------------------------|
| 200    | at least one entry done                                |
| 422    | nothing done, entries without a version in the locale  |
| 403    | nothing done, only entries without permission          |
| 500    | nothing done, at least one entry failed                |

---

## Own handlers

The group "language missing" and the titles in error messages are available for every handler that also implements
`Manuxi\SuluBulkActionsBundle\Handler\EntryInfoProviderInterface`:

| Method                                     | Returns                                          |
|--------------------------------------------|--------------------------------------------------|
| `findMissingInLocale(array $ids, $locale)` | IDs of the entries without content in the locale |
| `getTitles(array $ids, $locale)`           | `[ID => title]`, preferably in the locale        |

For content shaped like Sulu 3 content (entity with `dimensionContents`, dimension with `locale`, `stage`, `version`
and optionally `title`), `DimensionContentLookup` does the work:

```php
use Manuxi\SuluBulkActionsBundle\Handler\DimensionContentLookup;

$lookup = new DimensionContentLookup($entityManager, MyEntity::class);

public function findMissingInLocale(array $ids, string $locale): array
{
    return $this->lookup->findMissingInLocale($ids, $locale);
}

public function getTitles(array $ids, string $locale): array
{
    return $this->lookup->getTitles($ids, $locale);
}
```

Articles, snippets, testimonials and events have this built in. Handlers without the interface keep working; their
errors are then shown with the ID instead of the title.
