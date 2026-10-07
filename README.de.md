# SuluBulkActionsBundle (Sulu 3.x)
![php workflow](https://github.com/manuxi/SuluBulkActionsBundle/actions/workflows/php.yml/badge.svg)
![symfony workflow](https://github.com/manuxi/SuluBulkActionsBundle/actions/workflows/symfony.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/manuxi/SuluBulkActionsBundle/blob/main/LICENSE)
![GitHub Tag](https://img.shields.io/github/v/tag/manuxi/SuluBulkActionsBundle)
![Supports Sulu 3.0 or later](https://img.shields.io/badge/%20Sulu->=3.0-0088cc?color=00b2df)

[🇬🇧 English Version](README.md)

Sammelaktionen für Listen im Sulu-Admin: Zeilen markieren, dann in einem Rutsch veröffentlichen, die Veröffentlichung
zurückziehen, eine Sprache kopieren oder (optional) löschen.
Artikel (alle Gruppen), Schnipsel, [Testimonials](https://github.com/manuxi/SuluTestimonialsBundle) und [Events](https://github.com/manuxi/SuluEventBundle) funktionieren ohne weiteres (Veröffentlichen und Zurückziehen; Artikel und Schnipsel auch Sprache kopieren); andere Bundles bringen ihren eigenen Handler mit.

Dies ist der Branch `3.x` für Sulu 3.0. Der Branch `main` ist die Version für Sulu 2.6.

## Installation

```console
composer require manuxi/sulu-bulk-actions-bundle:3.x-dev
```

1. Bundle in `config/bundles.php` registrieren:
   `Manuxi\SuluBulkActionsBundle\SuluBulkActionsBundle::class => ['all' => true]`
2. Routen in `config/routes_admin.yaml` einbinden:
   ```yaml
   SuluBulkActionsBundle:
       resource: '@SuluBulkActionsBundle/Resources/config/routes_admin.yaml'
   ```
3. Das JS in `assets/admin/package.json` (`"sulu-bulk-actions-bundle": "file:../../vendor/manuxi/sulu-bulk-actions-bundle/src/Resources/js"`)
   und `assets/admin/app.js` (`import 'sulu-bulk-actions-bundle';`) eintragen, dann `npm install --force && npm run build`.
4. Einer Rolle den Kontext „Bulk actions“ geben (siehe Berechtigungen).

## Berechtigungen

Zwei Stufen, damit niemand gesammelt tun kann, was er einzeln nicht darf:

1. **Schalter:** Der Sicherheitskontext `sulu.bulk_actions.actions` („BulkActions“ im Rollenformular) entscheidet, ob
   eine Rolle Sammelaktionen überhaupt nutzen darf. „Live“ erlaubt Veröffentlichen und Zurückziehen, „Bearbeiten“
   erlaubt Sprache kopieren, „Löschen“ erlaubt Löschen (nur wenn `delete_enabled` an ist, siehe unten). Standardmäßig
   hat ihn niemand; im Rollenformular vergeben.
2. **Einträge:** Jeder Eintrag wird gegen seinen eigenen Sicherheitskontext geprüft, mit derselben Berechtigung („Live“,
   „Bearbeiten“ oder „Löschen“). Bei Artikeln ist das der Kontext der Artikelgruppe (`sulu.article.articles_blog`, ...),
   bei Schnipseln `sulu.snippet.snippets`. Sprache kopieren prüft „Bearbeiten“ in der Zielsprache. Einträge ohne
   Berechtigung werden übersprungen und gemeldet. Das Menü zeigt nur die Aktionen, die in dieser Liste erlaubt sind
   (z. B. nur im Blog-Tab, wenn die Rolle das Recht für Blog hat).

Neue Artikelgruppen brauchen nichts: Ihre Kontexte stehen schon im Rollenformular.

## Ergebnis

Konnten nicht alle markierten Einträge bearbeitet werden, erklärt ein Dialog in der Sprache des Admins, warum: Einträge
ohne Fassung in der gewählten Sprache (vorab erkannt und übersprungen), Einträge ohne Berechtigung und andere Fehler mit
dem Titel des Eintrags. Details: [Ergebnis einer Sammelaktion](docs/results.de.md)

## Sprache kopieren

„Sprache kopieren“ übernimmt den Inhalt einer Sprache für alle markierten Artikel oder Schnipsel in eine andere Sprache,
wie „Sprache kopieren“ im Formular. Ein Dialog fragt nach Quell- und Zielsprache. Das Ergebnis ist immer ein Entwurf;
nichts wird veröffentlicht. Einträge ohne Quellsprache und Einträge, die die Zielsprache schon haben, werden übersprungen
und mit Titel aufgelistet; der Schalter „Vorhandene Fassungen überschreiben“ (Standard: aus) ersetzt vorhandene Entwürfe.
Für Seiten gibt es einen Handler, aber noch keine Liste im Admin (der Seitenbaum kann nicht markieren).
Details: [Sprache kopieren](docs/copy-locale.de.md)

## Konfiguration

```yaml
sulu_bulk_actions:
    delete_enabled: false        # Sammel-Löschen ist aus; zum Einschalten braucht die Rolle zusätzlich „Löschen“
    resources:                   # optional; Artikel und Schnipsel kommen von selbst dazu
        articles:
            view_prefixes: ['sulu_article.article.list_']
            actions: [publish, unpublish, copy_locale]
```

## Handler für andere Ressourcen

`Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface` umsetzen (wird automatisch getaggt) und die Ressource
mit den Namen ihrer Listenansichten unter `sulu_bulk_actions.resources` eintragen:

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

Zusätzlich `EntryInfoProviderInterface` umsetzen, damit Einträge ohne die Sprache mit klarer Meldung übersprungen werden
und Fehler den Titel statt der ID zeigen (siehe [Ergebnis einer Sammelaktion](docs/results.de.md#eigene-handler)).
`LocaleCopyHandlerInterface` ergänzt die Aktion `copy_locale` (siehe [Sprache kopieren](docs/copy-locale.de.md#eigene-handler)).

## Endpunkt

`POST /admin/api/bulk-actions/{resourceKey}/{action}?locale=de` mit `{"ids": ["..."]}`. Das Präfix ist bewusst nicht
`/admin/api/{resourceKey}/...`, das würde mit den Routen der Ressourcen selbst kollidieren. Die Antwort zählt
`done`, `missing`, `denied` und `failed` (siehe [Ergebnis einer Sammelaktion](docs/results.de.md#antwort-des-endpunkts)).
`copy_locale` nimmt zusätzlich `sourceLocale`, `targetLocale` und `overwrite` (siehe [Sprache kopieren](docs/copy-locale.de.md#endpunkt)).

## Tests

```console
composer install
vendor/bin/phpunit
```
