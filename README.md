# YForm Feed-Generator

Erzeugt RSS-2.0-Feeds aus beliebigen YForm-Tabellen. Felder werden im Backend zugeordnet, Einträge lassen sich nach Status, Zeitraum und beliebigen Kriterien filtern – zum Beispiel nach Kategorie, auch wenn ein Eintrag mehreren Kategorien zugeordnet ist.

## Voraussetzungen

- REDAXO 5.15 oder neuer, PHP 8.1 oder neuer
- YForm 4 oder 5, Media Manager
- optional: URL-AddOn 2.x (Links auf Detailseiten), YRewrite (Domain für absolute URLs)

## Installation

1. ZIP entpacken nach `redaxo/src/addons/yform_feed_generator` (oder über den Installer).
2. AddOn installieren und aktivieren.
3. Unter **Feed-Generator → Feeds** einen Feed anlegen.

Bei der Installation werden die Media-Manager-Typen `yform_feed_image` (1200 px breit) und `yform_feed_thumb` (400 px breit) angelegt. Größe und Format lassen sich im Media Manager ändern; je Feed kann auch ein anderer Typ gewählt werden.

Rechte: Redakteure brauchen das Recht `yform_feed_generator[]`. Die Einstellungen sind Administratoren vorbehalten.

## Feed anlegen

### Allgemein

- **Titel, Beschreibung:** erscheinen im `channel` des Feeds.
- **Schlüssel:** Teil der Adresse, z. B. `aktuelles` → `/rss/aktuelles.xml`.
- **Cache (Sekunden):** so lange wird der fertige Feed ausgeliefert, ohne neu erzeugt zu werden. Ändert sich ein Datensatz der Tabelle über YForm, wird der Cache sofort geleert.
- **Zugriffs-Token:** optional. Mit Token ist der Feed nur über `?token=…` abrufbar (sonst 403).

### Felder zuordnen

Nach Auswahl der Tabelle werden die Felder nachgeladen.

| Zuordnung | Pflicht | Hinweis |
|---|---|---|
| Titel | ja | |
| Datum | ja | Sortierung und `pubDate`. Datum, Datum mit Uhrzeit oder Unix-Zeitstempel |
| Anriss / Beschreibung | | HTML wird entfernt, Entities werden aufgelöst; optional gekürzt |
| Ersatz für Anriss | | wird genommen, wenn der Anriss leer ist |
| Volltext | | als `content:encoded` (HTML, CDATA) |
| Bild | | Medienpool-Feld; bei mehreren Dateien die erste |
| Bildbeschreibung | | als `media:description` |
| Autor | | Text, Benutzer (`be_user`) oder Relation |
| Kategorie | | Text oder Relation, auch mehrfach – jede Kategorie wird ein eigenes `<category>` |
| Externe URL | | ist das Feld gefüllt (http/https), wird diese Adresse als Link verwendet |

Bei Relationen wird der Anzeigewert der Zieltabelle ausgegeben (das in YForm hinterlegte Anzeigefeld).

### Nur veröffentlichte Einträge

- **Status-Feld + Wert:** z. B. `status` = `1`.
- **Datum in der Zukunft ausblenden:** Einträge erscheinen erst ab ihrem Datum.
- **Veröffentlichung ab / bis:** optionale eigene Felder.
- **„Bis“ gilt nur, wenn …:** für Tabellen, in denen ein Ablaufdatum nur bei gesetztem Schalter gelten soll (z. B. `timer` = `1`). Ohne Schalter bleibt der Eintrag sichtbar, auch wenn ein altes Datum im Feld steht.

Leere Datumswerte (leer, `0`, `0000-00-00`) bedeuten „keine Einschränkung“.

### Filter

Beliebig viele Bedingungen, die alle zutreffen müssen:

| Bedingung | Bedeutung |
|---|---|
| ist gleich / ist nicht gleich | Vergleich mit einem Wert |
| ist leer / ist nicht leer | leer, `0` oder nicht gesetzt |
| enthält einen von | mindestens einer der gewählten Werte ist zugeordnet |
| enthält alle von | alle gewählten Werte sind zugeordnet |
| enthält keinen von | keiner der gewählten Werte ist zugeordnet |

Für Relationsfelder werden die möglichen Werte aus der Zieltabelle geladen und ausgewählt. Unterstützt werden:

- Relationen mit IDs in der eigenen Spalte (Auswahl einfach/mehrfach, Popup einfach/mehrfach)
- Relationen über eine Zwischentabelle (n:m)

Nicht unterstützt (für Filter und Kategorie): 1:n-Inline-Relationen, deren Daten in der Zieltabelle liegen.

Bei Feldern ohne Relation werden die Werte für „enthält …“ kommagetrennt eingegeben (z. B. für Textfelder mit kommagetrennten Werten).

**Beispiel:** Nur Nachrichten der Kategorie „Fair Play“, ohne interne Einträge:

1. Kategorie – enthält einen von – „Fair Play“
2. intern – ist leer

Ein Eintrag mit den Kategorien „Verband, Fair Play“ ist enthalten, einer mit nur „Verband“ nicht.

### Ausgabe

- **Anzahl Einträge:** 1–500, sortiert nach Datum (neueste zuerst).
- **URL-Profil:** Profil des URL-AddOns für die Tabelle. Der Link wird über `rex_getUrl()` mit dem Namespace des Profils erzeugt.
- **Ersatz-Link:** wenn kein Profil gewählt ist oder es den Datensatz nicht auflöst, z. B. `/news/?id={id}`.
- **Media-Manager-Typen** für Bild und Vorschaubild.

Die Vorschau unter dem Formular zeigt nach dem Speichern die ersten Einträge mit Link, Kategorien und Bild.

## Abruf

```
https://example.org/rss/<schluessel>.xml
https://example.org/rss/<schluessel>.xml?token=<token>
```

Ohne Rewrite: `index.php?rex-api-call=yform_feed&key=<schluessel>`.

Der Pfad (`rss`) ist in den Einstellungen änderbar.

## Inhalt des Feeds

- RSS 2.0 mit `atom:link rel="self"`, `lastBuildDate` (= Datum des neuesten Eintrags), `ttl`
- `guid` stabil aus Tabelle und ID (`tabelle:id@host`, `isPermaLink="false"`) – bleibt gleich, auch wenn sich Titel oder URL ändern
- Bild als `enclosure` (url, length, type) sowie `media:content` (mit Breite/Höhe) und `media:thumbnail`, alle als absolute URLs über den Media Manager
- Text sauber escapt, Volltext als CDATA

## Sicherheit

- Der Feed gibt nur die zugeordneten Spalten aus; andere Spalten werden nicht gelesen.
- Tabellen- und Spaltennamen werden gegen die YForm-Struktur geprüft, Werte als Parameter übergeben.
- Die AJAX-Endpunkte im Backend erfordern eine Backend-Sitzung, das Recht `yform_feed_generator[]` und ein CSRF-Token. Sie liefern nur Strukturinformationen (Felder, Auswahlwerte von Relationen), keine Datensätze.
- Ausgabe mit `ETag`/`Last-Modified` (304 bei unverändertem Feed), `Cache-Control` (bei Token `private`), Datei-Cache und Sperre gegen gleichzeitiges Neuerzeugen. Weitere Abfrageparameter werden ignoriert und umgehen den Cache nicht.

## Deinstallation

Entfernt die Feed-Tabelle und den Cache. Die Media-Manager-Typen bleiben erhalten.
