# Sprache kopieren

Mit der Sammelaktion „Sprache kopieren“ wird der Inhalt einer Sprache für viele Einträge auf einmal in eine andere Sprache übernommen.
Sie macht dasselbe wie „Sprache kopieren“ in der Werkzeugleiste des Formulars, nur für alle markierten Einträge einer Liste.

![Sprache kopieren](img/copy-locale.de.png)

---

## Ablauf

1. Einträge in der Liste markieren (Artikel oder Schnipsel).
2. „Sammelaktionen“ → „Sprache kopieren“.
3. Im Dialog die Quellsprache („Von Sprache“) und die Zielsprache („In Sprache“) wählen. Die Zielsprache kann nicht gleich der Quellsprache sein.
4. „OK“: Das Ergebnis erscheint in einem Dialog, auch wenn alles geklappt hat.

Zur Auswahl stehen die Sprachen der Liste (die Inhaltssprachen der Webspaces). Hat die Liste nur eine Sprache, fehlt die Aktion im Menü.

---

## Regeln

| Fall                                   | Verhalten                                                                                                 |
|----------------------------------------|-----------------------------------------------------------------------------------------------------------|
| Eintrag hat die Quellsprache           | Der Entwurf der Quellsprache wird in die Zielsprache kopiert.                                             |
| Ergebnis                               | Immer ein **Entwurf** in der Zielsprache. Nichts wird veröffentlicht; eine vorhandene Live-Fassung bleibt unverändert. |
| Eintrag hat keine Quellsprache         | Wird übersprungen und mit Titel gemeldet („hat keine Fassung auf Deutsch“).                              |
| Eintrag hat die Zielsprache schon      | Wird **nicht** überschrieben, sondern übersprungen und mit Titel gemeldet.                               |
| „Vorhandene Fassungen überschreiben“   | Schalter im Dialog, Standard: aus. Mit Haken wird der Entwurf der Zielsprache ersetzt.                   |
| Keine Berechtigung                     | Wird übersprungen und gemeldet (siehe Berechtigungen).                                                   |

Die Titel in den Meldungen stammen bevorzugt aus der Quellsprache, sonst aus einer anderen Sprache des Eintrags.

---

## Berechtigungen

Wie bei den anderen Sammelaktionen gibt es zwei Stufen:

| Stufe     | Kontext                                                                 | Recht                                  |
|-----------|-------------------------------------------------------------------------|----------------------------------------|
| Schalter  | `sulu.bulk_actions.actions` („BulkActions“ im Rollenformular)           | „Bearbeiten“                           |
| Eintrag   | Kontext des Eintrags (Artikelgruppe, `sulu.snippet.snippets`, Webspace) | „Bearbeiten“ **in der Zielsprache**    |

Ist eine Rolle in den Benutzereinstellungen auf bestimmte Sprachen beschränkt, zählt die Zielsprache.
Bei Seiten werden zusätzlich die Berechtigungen der einzelnen Seite (Tab „Berechtigungen“) geprüft.

---

## Konfiguration

Artikel und Schnipsel bekommen die Aktion ohne weitere Konfiguration. Wer die Aktionen einer Liste selbst festlegt,
nimmt `copy_locale` mit auf:

```yaml
sulu_bulk_actions:
    resources:
        articles:
            view_prefixes: ['sulu_article.article.list_']
            actions: [publish, unpublish, copy_locale]
```

**Seiten:** Der Handler für Seiten ist vorhanden (`resourceKey` `pages`, nur `copy_locale`). Der Seitenbaum von Sulu kann
keine Einträge markieren, deshalb erscheint die Aktion dort nicht. Ein Projekt mit einer eigenen Seitenliste (Listenansicht mit
`resourceKey` `pages`) trägt sie unter `resources.pages` ein.

---

## Endpunkt

`POST /admin/api/bulk-actions/{resourceKey}/copy_locale?locale=de`

```json
{
    "ids": ["…", "…"],
    "sourceLocale": "de",
    "targetLocale": "en",
    "overwrite": false
}
```

Antwort (zusätzlich zu den Feldern aus [Ergebnis einer Sammelaktion](results.de.md)):

| Feld           | Inhalt                                                                        |
|----------------|-------------------------------------------------------------------------------|
| `missing`      | Einträge ohne Fassung in der Quellsprache                                     |
| `existing`     | Einträge, die die Zielsprache schon haben (nur ohne `overwrite`)              |
| `locale`       | Quellsprache                                                                  |
| `targetLocale` | Zielsprache                                                                   |
| `skipped`      | Liste `{id, title, reason}`; `reason` ist `missing` oder `existing`           |

| Status | Wann                                                                     |
|--------|--------------------------------------------------------------------------|
| 200    | mindestens ein Eintrag kopiert                                           |
| 400    | Quell- oder Zielsprache fehlt, unbekannt oder beide gleich               |
| 422    | nichts kopiert, Einträge ohne Quellsprache oder mit vorhandener Zielsprache |
| 403    | nichts kopiert, nur Einträge ohne Berechtigung                           |
| 500    | nichts kopiert, mindestens ein Eintrag fehlgeschlagen                    |

---

## Eigene Handler

Ein Handler bietet `copy_locale` an, wenn er `Manuxi\SuluBulkActionsBundle\Handler\LocaleCopyHandlerInterface` umsetzt.
Das Interface erweitert `EntryInfoProviderInterface` (siehe [Ergebnis einer Sammelaktion](results.de.md)), damit fehlende und
vorhandene Sprachen vorab erkannt werden.

| Methode                                                   | Rückgabe                                   |
|-----------------------------------------------------------|--------------------------------------------|
| `copyLocale(array $ids, $sourceLocale, $targetLocale)`    | `['done' => [...], 'failed' => [id => …]]` |

Handler auf Basis von `ContentWorkflowBulkActionHandler` deklarieren nur das Interface und liefern die Nachricht:

```php
class MyBulkActionHandler extends ContentWorkflowBulkActionHandler implements LocaleCopyHandlerInterface
{
    protected function createCopyLocaleMessage(array $identifier, string $sourceLocale, string $targetLocale): object
    {
        return new CopyLocaleMyEntityMessage($identifier, $sourceLocale, $targetLocale);
    }
}
```

Jede Nachricht wird einzeln mit `EnableFlushStamp` verschickt, wie in den Controllern von Sulu. Schlägt ein Eintrag fehl,
bleiben die vorher kopierten erhalten.
