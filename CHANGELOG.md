# Changelog

## 1.3.1 - 2026-10-15

### Fixed

- Entities with integer IDs (the forms) failed with "No IDs provided.": the controller only accepted string IDs, but the list sends integers for them. Integer IDs are accepted and handled as strings.

## 1.3.0 - 2026-10-15

### Added

- Bulk action `copy_locale` for the list of the forms of the SuluFormBundle (`FormBulkActionHandler`, resource key `forms`, list view `sulu_form.list`). The handler and the default list are only registered if the `FormManager` of the installed form bundle has `copyLocale()`.

## 1.2.0 - 2026-10-14

### Added

- Bulk action `delete` in the default actions of articles, snippets, testimonials and events.
- Bulk action `copy_locale` for testimonials and events (`ContentEntityBulkActionHandler` implements `LocaleCopyHandlerInterface` and copies the draft with the content manager).
- `ContentEntityBulkActionHandler` deletes like the controllers of the Testimonials and Events bundles: the entity is stored in the trash (if the trash bundle is available), removed, and the domain event "removed" is collected.
- Documentation of delete: `docs/delete.en.md`, `docs/delete.de.md`.

### Changed

- `sulu_bulk_actions.delete_enabled` is `true` by default. Delete is still only offered to roles with the "Delete" permission in the "Bulk actions" context and in the context of the list; `false` removes it for everybody.
- Publish and unpublish for testimonials and events collect the domain events "published" and "unpublished" (activity log and search index of the bundles were not updated before).
- Services are defined in `Resources/config/services.yaml` instead of `services.xml`; `symfony/yaml` is a requirement.
- The constructor of `ContentEntityBulkActionHandler` takes the content manager, the domain event collector, the trash manager and the domain event classes (the last three optional).
