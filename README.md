# Hessen-Szene Event Feed für Etch / WordPress

Diese Dateien holen Veranstaltungstermine aus dem XML-Feed von
[hessen-szene.de](https://www.hessen-szene.de) und stellen sie in
[Etch](https://etchwp.com) als Dynamic Data zur Verfügung — für Programm-Loops,
Detailseiten, Kategoriefilter, Presse-Bilddownloads und iCal-Export.

Demnächst im Einsatz auf [dasrind.de](https://dasrind.de).

## Dateien

| Datei | Zweck |
|---|---|
| `hessen-szene.php` | Feed-Abruf, Caching, Bildverarbeitung, alle Etch- und JSON-Endpunkte |
| `download_picture.php` | Download der Presse-Bilder unter Beibehaltung des Original-Dateinamens |
| `filter.js` | Kategoriefilter-Buttons über dem Programm-Grid |

Die PHP-Dateien gehören in ein Code-Snippet-Plugin (z. B. WPCodeBox, Code
Snippets) oder in die `functions.php` des Themes. `filter.js` wird auf der
Programmseite eingebunden.

## Einrichtung

### 1. Feed-URL anpassen

In `hessen-szene.php`, Zeile 74:

```php
$feed_url = 'https://www.hessen-szene.de/cdn?type=151&tx_laks_calendar%5Baction%5D=xml&tx_laks_calendar%5Bcenter%5D=11&tx_laks_calendar%5BenableHtml%5D=1';
```

Der Parameter `tx_laks_calendar[center]=11` bestimmt die Spielstätte — hier auf
die eigene ID ändern. `enableHtml=1` muss erhalten bleiben, sonst kommt die
Beschreibung ohne HTML-Formatierung.

### 2. Bilder erstbefüllen

Nach der Installation einmal als Admin aufrufen:

```
/?hessens_process_images=1
```

Das erzeugt die verkleinerten Anzeige-Varianten aller Feed-Bilder. Bei vielen
Events die Seite mehrfach aufrufen, bis „Alle low-res Bilder sind aktuell"
gemeldet wird. Danach übernimmt WP-Cron die Nachverarbeitung automatisch.

## Caching

Der Cache wird **nicht** beim Seitenaufruf erneuert, sondern aktiv per WP-Cron
alle 15 Minuten. Die Transient-Laufzeit liegt mit 2 Stunden bewusst deutlich
darüber: So läuft kein Besucher in einen abgelaufenen Cache und muss auf den
langsamen Remote-Fetch warten — auch dann nicht, wenn WP-Cron aussetzt, was auf
Seiten mit wenig Traffic regelmäßig passiert.

Zusätzlich liegt eine dauerhafte Sicherungskopie der zuletzt erfolgreich
geladenen Events in der Option `hessens_events_backup`. Ist hessen-szene.de
nicht erreichbar, wird diese ausgeliefert — die Programmseite ist also nie leer.

Konfigurierbar über Konstanten am Dateianfang:

| Konstante | Standard | Bedeutung |
|---|---|---|
| `HESSENS_CACHE_TTL` | 2 Stunden | Maximale Cache-Gültigkeit (Sicherheitsnetz) |
| `HESSENS_REFRESH_INTERVAL` | 15 Minuten | Cron-Intervall für Feed und Bilder |
| `HESSENS_LOWRES_MAX` | 800 px | Längste Kante der Anzeige-Variante |
| `HESSENS_LOWRES_DIR` | `hessens-events` | Unterordner in `wp-content/uploads/` |

### Manuelles Aktualisieren

Redakteure (ab Rolle Editor) finden in der Admin-Bar den Eintrag
**Veranstaltungen aktualisieren**. Ein Klick holt den Feed sofort frisch und
meldet zurück, wie viele Termine geladen wurden.

Für Admins zusätzlich:

| URL | Wirkung |
|---|---|
| `/?clear_events_cache=1` | Cache leeren |
| `/?hessens_process_images=1` | Bis zu 200 Bilder verarbeiten |

## Verwendung in Etch

### Programm-Loop

```
{#loop options.events as item data-etch-context="eyJyZWYiOiIxbGJxdWY1In0="}
```

Felder dann als `{item.FELDNAME}` ausgeben, z. B. `{item.title}` oder
`{item.date}`.

### Vorschau-Loop

`options.events_preview` liefert nur die ersten Events — praktisch für einen
Teaser auf der Startseite:

```
{#loop options.events_preview as item data-etch-context="eyJzdHJ1Y3R1cmVTdGF0ZSI6Im9wZW4iLCJyZWYiOiJraTV1bHEyIn0="}
```

Die Anzahl steht in `hessen-szene.php` im Abschnitt 6 (`array_slice( …, 0, 4 )`).

### Detailseite

Eine Seite anlegen und die Felder als `{options.event.FELDNAME}` ausgeben.
Verlinkt wird mit `/event-detail/?event={item.slug}` — `event-detail` durch den
eigenen Seiten-Slug ersetzen.

### JSON-Endpunkt

`/?hessens_events=1` gibt alle Events als JSON zurück. Diese URL lässt sich in
Etch unter *Loop → Source: JSON Data → URL* eintragen.

## Verfügbare Felder

| Feld | Inhalt |
|---|---|
| `id` | Event-ID aus dem Feed |
| `title` | Titel |
| `slug` | Eindeutiger Slug aus Datum und Titel, für Detail-Links |
| `date` | Formatiertes Datum |
| `date_raw` | Datum als `YYYY-MM-DD` |
| `start_time` | Beginn |
| `box_office_start_time` | Einlass / Kassenöffnung |
| `categories` | Alle Kategorien, kommagetrennt |
| `thema` | Letztes Wort der ersten Kategorie, als kurzes Label |
| `price` | Preis |
| `description` | Kurzbeschreibung, 200 Zeichen, HTML-sicher gekürzt |
| `long_description` | Volle Beschreibung mit HTML |
| `location_name` | Name der Spielstätte |
| `location_street` | Straße |
| `location_city` | PLZ und Ort |
| `sold_out` | `1` bei ausverkauft, sonst `0` |
| `link_homepage` | Künstler-Homepage |
| `link_facebook` | Facebook-Profil |
| `link_instagram` | Instagram-Profil |
| `month` | `YYYY-MM`, für Gruppierung |
| `month_label` | Monat ausgeschrieben, z. B. „März 2026" |
| `imageN_url` | Anzeige-Variante, max. 800 px |
| `imageN_url_hires` | Original aus dem Feed, für den Presse-Download |
| `imageN_filename` | Original-Dateiname |
| `image_main_url` | Anzeige-Variante des ersten vorhandenen Bildes |
| `image_main_url_hires` | Original des ersten vorhandenen Bildes |
| `image_main_filename` | Dateiname des ersten vorhandenen Bildes |

Die Bildfelder behalten die **Slot-Nummer aus dem Feed**: `image1_…` ist das
Hochformat (nicht immer gepflegt), `image2_…` das Querformat (immer gepflegt).
Der Feed liefert diese Slots mit Lücken — ein Event kann `<Image2>` enthalten,
ohne dass `<Image1>` existiert. Es wird deshalb **nicht umnummeriert**;
fehlende Slots kommen als leerer String, damit die Felder in Etch immer
existieren. `image1_…` und `image2_…` gibt es immer, weitere Slots nur, wenn
der Feed sie liefert.

Für die Anzeige (Programmübersicht, Karten) gibt es zusätzlich `image_main_…`:
das erste tatsächlich vorhandene Bild, also Hochformat wenn gepflegt, sonst
Querformat. So entsteht nie ein leeres `src`.

## Bildvarianten

Pro Feed-Bild werden zwei Varianten geführt:

- **Anzeige** (`imageN_url`) — auf max. 800 px verkleinert, im Hintergrund per
  WP-Cron erzeugt und in `wp-content/uploads/hessens-events/` abgelegt. Der
  Dateiname ist ein Hash der Original-URL. Solange die Variante noch nicht
  existiert, wird das Original ausgeliefert, es gibt also keine Lücken.
- **Original** (`imageN_url_hires`) — unverändert vom Feed, für den
  Presse-Download.

## Presse-Download

`download_picture.php` liefert Bilder als Download aus. Der **Original-Dateiname
von hessen-szene.de bleibt erhalten**, weil dort teilweise Urheberhinweise
enthalten sind. Umlaute werden über `filename*` (RFC 5987) korrekt übertragen.

Zwei Aufrufvarianten:

```
/?download_event_image=EVENT-SLUG&img=1     ← empfohlen
/?download_event_image=https://…            ← direkte URL
```

Bei der Slug-Variante wird immer das hi-res Original geliefert, mit Fallback auf
die Anzeige-Variante. Bei der URL-Variante sind nur hessen-szene.de und der
eigene Uploads-Ordner erlaubt; eine übergebene Anzeige-URL wird automatisch auf
das Original hochgestuft.

## Kategoriefilter

`filter.js` liest die Kategorien aus den `data-kategorie`-Attributen der Karten
im `.programm__grid`, erzeugt daraus Filter-Buttons und blendet beim Klick die
nicht passenden Listenelemente aus.

Voraussetzungen im Markup:

- Container mit der Klasse `programm__grid`
- Karten als `<article data-kategorie="…">` innerhalb von `<li>`-Elementen

Erzeugte Klassen zum Stylen: `.event-filter-buttons`, `.event-filter-btn` und
`.event-filter-btn.aktiv` für den aktiven Button.

## iCal-Export

`/?hessens_ical=EVENT-SLUG` liefert eine `.ics`-Datei zum Eintragen in den
Kalender. Die Dauer wird pauschal mit 3 Stunden ab Beginn angesetzt.

`DTSTART`/`DTEND` stehen in echter UTC (`…Z`). Die Ortszeit aus dem Feed wird
dafür explizit in der Zeitzone aus den WordPress-Einstellungen (`wp_timezone()`)
konstruiert und dann nach UTC konvertiert — Sommer-/Winterzeit inklusive.

> **Stolperfalle:** WordPress setzt PHPs Default-Zeitzone auf UTC. Ein
> `gmdate( 'Ymd\THis\Z', strtotime( '2026-08-24 20:00' ) )` liest die Ortszeit
> deshalb als UTC und stempelt sie erneut als UTC — der Termin landet im
> Kalender um den lokalen Offset verschoben (im Sommer +2 h). Genau dieser
> Fehler steckte bis 2026-08-21 im Export.

Text-Felder (`SUMMARY`, `DESCRIPTION`, `LOCATION`) werden nach RFC 5545
escaped (`\,` `\;` `\\` `\n`) und auf 75 Oktette pro Zeile gefaltet.
