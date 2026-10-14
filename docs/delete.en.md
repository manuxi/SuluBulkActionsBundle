# Delete

The bulk action "Delete" removes all selected entries of a list at once, after a confirmation.

---

## Workflow

1. Select entries in the list (articles, snippets, testimonials or events).
2. "Bulk actions" → "Delete".
3. Confirm the question. The result is shown in a dialog if not all entries could be deleted (see [Result of a bulk action](results.en.md)).

Only entries that have a version in the language of the list are deleted; the others are skipped and reported.

---

## What happens to an entry

| Resource                    | Behaviour                                                                                                  |
|-----------------------------|------------------------------------------------------------------------------------------------------------|
| Articles, snippets          | The remove message of Sulu is sent, like the delete button in the form.                                    |
| Testimonials, events        | The entry is stored in the trash first, then removed. The domain event "removed" leaves the search index and the activity log up to date, like deleting in the form. |

Every entry is handled on its own: if one fails, the ones before it stay deleted and the failure is reported with the title.

---

## Permissions

| Level   | Context                                                                                         | Permission |
|---------|-------------------------------------------------------------------------------------------------|------------|
| Switch  | `sulu.bulk_actions.actions` ("BulkActions" in the role form)                                    | "Delete"   |
| Entry   | Context of the entry (article group, `sulu.snippet.snippets`, `sulu.testimonials.testimonials`, `sulu.events.events`) | "Delete"   |

The action is only in the menu of a list if the role has "Delete" in both. Entries without permission are skipped and reported.

---

## Configuration

Delete is part of the default actions of articles, snippets, testimonials and events. To remove it for everybody,
whatever the roles say:

```yaml
sulu_bulk_actions:
    delete_enabled: false
```

If you set the actions of a list yourself, add `delete`:

```yaml
sulu_bulk_actions:
    resources:
        articles:
            view_prefixes: ['sulu_article.article.list_']
            actions: [publish, unpublish, copy_locale, delete]
```

---

## Endpoint

`POST /admin/api/bulk-actions/{resourceKey}/delete?locale=de` with `{"ids": ["…"]}`. The response is the same as for the
other actions (see [Result of a bulk action](results.en.md#response-of-the-endpoint)). With `delete_enabled: false` the
endpoint answers `403`.

---

## Own handlers

A handler offers `delete` if `supports($resourceKey, 'delete')` is true and `handle('delete', $ids, $locale)` removes
the entries. Handlers based on `ContentWorkflowBulkActionHandler` only return the remove message (`createRemoveMessage()`).
Handlers for content entities of other bundles can use `ContentEntityBulkActionHandler`, which stores the entity in the
trash and collects the domain event "removed" given as class.
