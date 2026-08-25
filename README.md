# Hessen-Szene Event Feed für Etch / WordPress

Diese Dateien holen Veranstaltungstermine aus dem XML-Feed von
[hessen-szene.de](https://www.hessen-szene.de) und stellen sie in
[Etch](https://etchwp.com) als Dynamic Data zur Verfügung — für Programm-Loops,
Detailseiten, Kategoriefilter, Presse-Bilddownloads, iCal-Export und
strukturierte Daten für Google.

Im Einsatz auf [dasrind.de](https://dasrind.de).

## Dateien

| Datei | Ablage | Zweck |
|---|---|---|
| `hessen-szene.php` | Snippet-Plugin / `functions.php` | Feed-Abruf, Caching, Bildverarbeitung, alle Etch- und JSON-Endpunkte |
| `download_picture.php` | Snippet-Plugin / `functions.php` | Download der Presse-Bilder unter Beibehaltung des Original-Dateinamens |
| `filter.js` | Programmseite | Kategoriefilter-Buttons über dem Programm-Grid |
| `dasrind-event-schema.php` | `wp-content/mu-plugins/` | Event-Structured-Data, SEO-Meta pro Termin, sprechende Event-URLs |
| `dasrind-doi-guard.php` | `wp-content/mu-plugins/` | Schützt Fluent-Forms-Double-Opt-in-Links vor E-Mail-Scannern |

Die beiden `dasrind-*.php` sind **Must-Use-Plugins**: einfach in
`wp-content/mu-plugins/` ablegen, sie sind damit sofort aktiv und lassen sich im
Backend nicht versehentlich deaktivieren. Der Ordner muss gegebenenfalls
angelegt werden.

> **Bei Migrationen mitnehmen.** mu-Plugins werden von Umzugs-Werkzeugen zwar
> mitkopiert, bei manuellen Umzügen aber gern übersehen — und fehlen sie, kehren
> die Probleme zurück, die sie lösen.

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

Verlinkt wird mit `/event-detail/{item.slug}/` — `event-detail` durch den
eigenen Seiten-Slug ersetzen. Diese sprechende Form setzt `dasrind-event-schema.php`
voraus; ohne dieses mu-Plugin lautet der Link `/event-detail/?event={item.slug}`.
Beide Formen funktionieren, sobald das Plugin aktiv ist — die alte wird per 301
auf die neue umgeleitet.

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

---

# Event-Schema und SEO-Meta

`dasrind-event-schema.php` — mu-Plugin. Liest den Feed über
`hessens_fetch_events()` nur mit; `hessen-szene.php` bleibt unverändert.

Die Termine sind keine WordPress-Beiträge, sondern kommen live aus dem Feed.
Ein SEO-Plugin kann daran nicht andocken: Alle Detailseiten sind aus Sicht von
WordPress ein und dieselbe Seite. Dieses mu-Plugin schließt diese Lücke.

## Was es tut

**Structured Data.** Auf der Detailseite ein `Event`-Objekt (Name, Start- und
Endzeit, Ort, Beschreibung, Bilder, Preis, Verfügbarkeit, Veranstalter), auf der
Programmübersicht eine `ItemList` der kommenden Termine. Damit sind Rich Results
mit Datum, Ort und Ticketlink möglich.

**SEO-Meta pro Termin.** Canonical, Titel, Description sowie `og:url`,
`og:title` und `og:description` werden pro Event gesetzt. Ohne das trägt jede
Detailseite dasselbe Canonical und denselben Titel — und Google wertet alle
Termine als Duplikate einer einzigen Seite.

**Sprechende URLs.** `/event-detail/SLUG/` statt `/event-detail/?event=SLUG`.

**Abgelaufene Termine.** Wer eine URL aufruft, deren Termin aus dem Feed
gefallen ist, landet auf der Programmübersicht statt auf einer inhaltsleeren
Seite.

## Konfiguration

Konstanten am Dateianfang:

| Konstante | Bedeutung |
|---|---|
| `DASRIND_SCHEMA_DETAIL_SLUG` | Slug der Detailseite, die `?event=` auswertet |
| `DASRIND_SCHEMA_LIST_SLUGS` | Slugs der Übersichtsseiten für die `ItemList` — die Detailseite gehört hier **nicht** hinein |
| `DASRIND_SCHEMA_LIST_MAX` | Maximale Anzahl Termine in der `ItemList` |
| `DASRIND_SCHEMA_DURATION_HOURS` | Angenommene Dauer, wie beim iCal-Export |
| `DASRIND_SCHEMA_VENUE` | Adresse als Rückfall, falls der Feed keine Location liefert |
| `DASRIND_SCHEMA_PERFORMER` | Titel zusätzlich als `performer` ausgeben — bei Konzerten sinnvoll, bei Partys eher nicht |
| `DASRIND_SCHEMA_TITLE_SUFFIX` | Suffix für den Seitentitel |
| `DASRIND_SCHEMA_PRETTY_URLS` | Sprechende URLs an/aus |
| `DASRIND_SCHEMA_EXPIRED_REDIRECT` | Umleitung abgelaufener Termine an/aus |
| `DASRIND_SCHEMA_EXPIRED_TARGET_SLUG` | Ziel für abgelaufene Termine |

Nach dem Umstellen von `DASRIND_SCHEMA_PRETTY_URLS` einmal
*Einstellungen → Permalinks → Speichern*.

## Preise

Die Beträge werden aus dem aufbereiteten `price`-String zurückgewonnen:

| Feed-Wert | Ausgabe |
|---|---|
| `Eintritt frei` | `Offer` mit `price: 0` |
| `AK: 18,00 EUR` | `Offer` mit `price: 18.00` |
| `VVK: 12,00 EUR … / AK: 15,00 EUR` | `AggregateOffer` mit `lowPrice`/`highPrice` |

`sold_out` aus dem Feed setzt die Verfügbarkeit auf `SoldOut`.

## Bilder

Für das Schema wird **Querformat zuerst** ausgegeben (`image2` vor `image1`) —
also genau umgekehrt zur Anzeige im Frontend. Google empfiehlt für Rich Results
16:9, 4:3 oder 1:1 und mindestens 720 px Breite; Hochformat wird häufig
verworfen. Mitgegeben werden alle vorhandenen Varianten, damit Google das
passende Seitenverhältnis wählen kann.

## Sprechende URLs im Detail

Die Rewrite-Regel bildet `^SLUG-DER-DETAILSEITE/([^/]+)/?$` auf dieselbe Seite
mit `event=$matches[1]` ab. Nach dem Auflösen der Route wird der Slug zusätzlich
in `$_GET['event']` geschrieben — dadurch laufen `hessen-szene.php` und alle
Etch-Templates unverändert weiter, denn die lesen `$_GET['event']`.

Per 301 auf die kanonische Form umgeleitet werden:

- die alte Form `?event=SLUG`
- die Variante ohne abschließenden Schrägstrich

Parameter wie `utm_*` oder `fbclid` überleben die Weiterleitung.

> **Stolperfalle:** WordPress' eigene Canonical-Weiterleitung
> (`redirect_canonical`) biegt die sprechende URL sonst auf die nackte
> Seiten-URL zurück und baut zusammen mit der eigenen Weiterleitung eine
> Endlosschleife. Sie wird auf dieser Route deshalb abgeschaltet — im Gegenzug
> muss der fehlende Schrägstrich selbst behandelt werden.

> **Stolperfalle:** mu-Plugins kennen keinen Aktivierungs-Hook. Die
> Rewrite-Regeln werden deshalb versioniert genau einmal per
> `flush_rewrite_rules()` geschrieben (Option `dasrind_pretty_rules_version`)
> statt bei jedem Seitenaufruf.

## Abgelaufene Termine

Termine verschwinden aus dem Feed, sobald sie vorbei sind — ihre URLs bleiben
aber im Suchindex und in geteilten Links. Ohne Behandlung antwortet die Seite
mit HTTP 200 und generischem Inhalt: ein **Soft-404**, den Google gesondert
meldet und nur langsam wieder vergisst.

Unbekannte Slugs werden deshalb auf die Programmübersicht umgeleitet,
unterschieden nach dem Datum im Slug:

| Fall | Status |
|---|---|
| Datum liegt in der Vergangenheit | `301` — der Termin ist endgültig vorbei |
| alles andere | `302` |

Der Unterschied ist wichtig: Fiele der Feed einmal komplett aus, wären
schlagartig alle Termine unbekannt. Ein pauschaler `301` würde sämtliche
Event-URLs dauerhaft umleiten und aus dem Index werfen; mit dem `302` bleibt ein
solcher Ausfall folgenlos.

## SEOPress-Filter

> **Stolperfalle:** Die Filter sind nicht einheitlich.
> `seopress_titles_title` und `seopress_titles_desc` erwarten den **reinen
> Text**, `seopress_titles_canonical` und die `seopress_social_*`-Filter
> dagegen das **fertige HTML-Tag**. Gibt man dort nur eine URL zurück, landet
> sie als nackter Text im `<head>` und das Tag fehlt komplett — ohne
> Fehlermeldung.

`og:image` wird bewusst nicht gefiltert: SEOPress gibt dort einen ganzen Block
mit `secure_url`, `width`, `height` und `alt` aus, den ein Eingriff zerlegen
würde.

Zur Diagnose eignet sich ein Aufruf mit einem Slug, den es nicht gibt. Greifen
die Filter dann nicht und die Tags sind wieder vollständig, liegt es am eigenen
Code und nicht an SEOPress.

---

# Double-Opt-in-Schutz

`dasrind-doi-guard.php` — mu-Plugin. Unabhängig vom Event-Feed; es geht um
Fluent Forms Pro und dessen Double-Opt-in.

## Das Problem

Link-Scanner wie Microsoft Safe Links rufen jeden Link in einer E-Mail schon
beim Zustellen per `GET` auf. Damit ist der Opt-in-Token verbraucht, bevor der
Empfänger überhaupt klickt. Der sieht dann „The confirmation of the double
opt-in has already been carried out…" — und im Protokoll steht die IP-Adresse
des Scanners als Nachweis der Einwilligung, nicht die des Abonnenten.

## Die Lösung

Das Plugin hängt sich auf `init` mit Priorität 0 ein und kommt damit vor Fluent
Forms' eigene Verarbeitung, die auf `wp` läuft. Erkennt es `ff_landing` und
`entry_confirmation` in der URL, verhält es sich je nach Zugriffsart:

| Zugriff | Reaktion |
|---|---|
| `HEAD` | `200` ohne jede Wirkung — Scanner prüfen oft nur die Erreichbarkeit |
| `GET` | Zwischenseite mit einem Bestätigungs-Button |
| `POST` mit dem Feld `dasrind_doi_confirm` | wird durchgelassen |

Scanner folgen Links, senden aber keine Formulare ab. Der Token bleibt also
unangetastet, bis ein Mensch den Button drückt. Admin, AJAX, Cron und WP-CLI
werden übersprungen.

Zusätzlich werden die beiden englischen Fluent-Forms-Meldungen über
`fluentform/double_optin_already_confirmed_message` und
`fluentform/double_optin_invalid_confirmation_url_message` durch eigene deutsche
Texte ersetzt.

## Anpassung

Alles Sichtbare steht als Klassenkonstante am Dateianfang:

| Konstante | Bedeutung |
|---|---|
| `FIELD` | Name des Formularfelds, das den echten Klick kennzeichnet |
| `PAGE_TITLE`, `HEADLINE`, `INTRO`, `BUTTON_LABEL`, `FOOTNOTE` | Texte der Zwischenseite |
| `ALREADY_HEADLINE`, `ALREADY_TEXT`, `ALREADY_HINT` | Meldung „bereits bestätigt" |
| `INVALID_HEADLINE`, `INVALID_TEXT` | Meldung „Link nicht mehr gültig" |
| `ALREADY_REDIRECT_URL`, `INVALID_REDIRECT_URL` | Leer lassen für die eingebauten Meldungsseiten, oder eigene WordPress-Seiten eintragen, auf die stattdessen umgeleitet wird |
| `COLOR_ACCENT`, `COLOR_TEXT`, `COLOR_MUTED` | Farben |
