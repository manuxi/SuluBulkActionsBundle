# Löschen

Die Sammelaktion „Löschen“ entfernt nach einer Rückfrage alle markierten Einträge einer Liste auf einmal.

---

## Ablauf

1. Einträge in der Liste markieren (Artikel, Schnipsel, Testimonials oder Events).
2. „Sammelaktionen“ → „Löschen“.
3. Die Rückfrage bestätigen. Konnten nicht alle Einträge gelöscht werden, erklärt ein Dialog warum (siehe [Ergebnis einer Sammelaktion](results.de.md)).

Gelöscht werden nur Einträge, die eine Fassung in der Sprache der Liste haben; die übrigen werden übersprungen und gemeldet.

---

## Was mit einem Eintrag passiert

| Ressource                   | Verhalten                                                                                                  |
|-----------------------------|------------------------------------------------------------------------------------------------------------|
| Artikel, Schnipsel          | Die Entfernen-Nachricht von Sulu wird gesendet, wie beim Löschen-Knopf im Formular.                       |
| Testimonials, Events        | Der Eintrag kommt zuerst in den Papierkorb und wird dann entfernt. Das Domain-Event „removed“ hält Suchindex und Aktivitätsprotokoll aktuell, wie beim Löschen im Formular. |

Jeder Eintrag wird für sich bearbeitet: Schlägt einer fehl, bleiben die vorherigen gelöscht und der Fehler wird mit Titel gemeldet.

---

## Berechtigungen

| Stufe     | Kontext                                                                                          | Recht      |
|-----------|--------------------------------------------------------------------------------------------------|------------|
| Schalter  | `sulu.bulk_actions.actions` („BulkActions“ im Rollenformular)                                    | „Löschen“  |
| Eintrag   | Kontext des Eintrags (Artikelgruppe, `sulu.snippet.snippets`, `sulu.testimonials.testimonials`, `sulu.events.events`) | „Löschen“  |

Die Aktion erscheint im Menü einer Liste nur, wenn die Rolle „Löschen“ in beiden hat. Einträge ohne Berechtigung werden übersprungen und gemeldet.

---

## Konfiguration

Löschen gehört zu den Standardaktionen von Artikeln, Schnipseln, Testimonials und Events. Um es für alle zu entfernen,
egal was die Rollen sagen:

```yaml
sulu_bulk_actions:
    delete_enabled: false
```

Wer die Aktionen einer Liste selbst festlegt, nimmt `delete` mit auf:

```yaml
sulu_bulk_actions:
    resources:
        articles:
            view_prefixes: ['sulu_article.article.list_']
            actions: [publish, unpublish, copy_locale, delete]
```

---

## Endpunkt

`POST /admin/api/bulk-actions/{resourceKey}/delete?locale=de` mit `{"ids": ["…"]}`. Die Antwort entspricht der der
anderen Aktionen (siehe [Ergebnis einer Sammelaktion](results.de.md#antwort-des-endpunkts)). Mit `delete_enabled: false`
antwortet der Endpunkt mit `403`.

---

## Eigene Handler

Ein Handler bietet `delete` an, wenn `supports($resourceKey, 'delete')` wahr ist und `handle('delete', $ids, $locale)` die
Einträge entfernt. Handler auf Basis von `ContentWorkflowBulkActionHandler` liefern nur die Entfernen-Nachricht
(`createRemoveMessage()`). Handler für Inhalts-Entitäten anderer Bundles können `ContentEntityBulkActionHandler` nutzen,
der die Entität in den Papierkorb legt und das als Klasse übergebene Domain-Event „removed“ sammelt.
