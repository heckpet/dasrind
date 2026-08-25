<?php
/**
 * Plugin Name: Das Rind – Event Schema & SEO-Meta (JSON-LD)
 * Description: Gibt Event-Structured-Data für die Veranstaltungen aus dem
 *              hessen-szene-Feed aus (Event auf der Detailseite, ItemList auf
 *              der Programmübersicht), setzt auf der Detailseite Canonical,
 *              Titel und Description pro Termin über die SEOPress-Filter und
 *              stellt sprechende URLs /event-detail/SLUG/ bereit.
 * Version:     1.6
 * Author:      Webdesign Rheingau
 *
 * Ablage: wp-content/mu-plugins/dasrind-event-schema.php
 *
 * Setzt hessen-szene.php voraus (Funktion hessens_fetch_events()). Der Code
 * liest den Feed nur, er verändert ihn nicht – hessen-szene.php bleibt
 * unangetastet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// KONFIGURATION – hier anpassen
// =============================================================================

/**
 * Slug der Seite, auf der die Event-Detailansicht läuft (die Seite, die
 * ?event=SLUG auswertet). Ohne Schrägstriche, so wie in WordPress hinterlegt.
 */
const DASRIND_SCHEMA_DETAIL_SLUG = 'event-detail';

/**
 * Slugs der Seiten, auf denen die Programmübersicht ausgegeben wird.
 * Dort landet eine ItemList mit den kommenden Terminen.
 * Die Detailseite gehört hier NICHT hinein.
 */
const DASRIND_SCHEMA_LIST_SLUGS = array( 'programm' );

/** Wie viele Termine maximal in die ItemList wandern. */
const DASRIND_SCHEMA_LIST_MAX = 30;

/** Angenommene Dauer einer Veranstaltung in Stunden (wie beim iCal-Export). */
const DASRIND_SCHEMA_DURATION_HOURS = 3;

/**
 * Fallback-Adresse, falls der Feed für einen Termin keine Location liefert.
 * Bitte einmal gegen die echten Daten prüfen.
 */
const DASRIND_SCHEMA_VENUE = array(
	'name'    => 'Das Rind',
	'street'  => 'Mainstraße 11',
	'zip'     => '65428',
	'city'    => 'Rüsselsheim am Main',
	'region'  => 'Hessen',
	'country' => 'DE',
);

/**
 * Soll der Veranstaltungstitel zusätzlich als performer ausgegeben werden?
 * Bei Konzerten ist der Titel meist der Künstlername – dann sinnvoll. Bei
 * Formaten wie „Open Stall" oder Partys ist es eher Rauschen. Auf false
 * setzen, wenn du es sauber halten willst.
 */
const DASRIND_SCHEMA_PERFORMER = true;

/** Suffix für den Seitentitel. Leer lassen, wenn du keins willst. */
const DASRIND_SCHEMA_TITLE_SUFFIX = 'Das Rind';

/**
 * Sprechende Event-URLs: /event-detail/SLUG/ statt /event-detail/?event=SLUG.
 * Aufrufe der alten Form werden per 301 auf die neue umgeleitet, bestehende
 * Links und Lesezeichen bleiben also gültig.
 *
 * Auf false setzen, um sofort zum alten Verhalten zurückzukehren – danach
 * einmal die Permalinks speichern, damit die Rewrite-Regel verschwindet.
 */
const DASRIND_SCHEMA_PRETTY_URLS = true;

/**
 * Was passiert mit Event-URLs, die es nicht (mehr) gibt – vor allem mit
 * Terminen, die aus dem Feed gefallen sind, weil sie vorbei sind?
 *
 * Ohne Behandlung liefert die Seite HTTP 200 mit generischem Inhalt. Das ist
 * ein Soft-404: Google sieht „Seite existiert", findet aber nichts Passendes,
 * meldet das in der Search Console und braucht lange, bis die URL verschwindet.
 *
 * Mit true werden solche Aufrufe auf die Programmübersicht umgeleitet. Besucher
 * aus alten Links, geteilten Posts oder Suchergebnissen landen damit beim
 * aktuellen Programm statt im Leeren.
 */
const DASRIND_SCHEMA_EXPIRED_REDIRECT = true;

/** Slug der Seite, auf der abgelaufene Termine landen. */
const DASRIND_SCHEMA_EXPIRED_TARGET_SLUG = 'programm';


// =============================================================================
// GEMEINSAMER ZUGRIFF AUF DAS AKTUELLE EVENT
// =============================================================================

/**
 * Das Event zur aktuell aufgerufenen Detailseite (?event=SLUG) – oder null.
 * Ergebnis wird für den Request gemerkt, damit der Feed pro Seitenaufruf nur
 * einmal durchsucht wird (Schema, Canonical, Titel und Description greifen
 * alle darauf zu).
 */
function dasrind_schema_current_event() {
	static $event  = null;
	static $looked = false;

	if ( $looked ) {
		return $event;
	}
	$looked = true;

	if ( empty( $_GET['event'] ) || ! function_exists( 'hessens_fetch_events' ) ) {
		return null;
	}

	$requested = sanitize_title( wp_unslash( $_GET['event'] ) );
	foreach ( hessens_fetch_events() as $candidate ) {
		if ( isset( $candidate['slug'] ) && $candidate['slug'] === $requested ) {
			$event = $candidate;
			break;
		}
	}

	return $event;
}


// =============================================================================
// AUSGABE
// =============================================================================

add_action( 'wp_head', 'dasrind_schema_output', 20 );

function dasrind_schema_output() {
	if ( is_admin() || ! function_exists( 'hessens_fetch_events' ) ) {
		return;
	}

	// --- Detailseite: ein einzelnes Event -----------------------------------
	if ( ! empty( $_GET['event'] ) ) {
		$event = dasrind_schema_current_event();
		if ( $event ) {
			$node = dasrind_schema_build_event( $event );
			if ( $node ) {
				dasrind_schema_print( $node );
			}
		}
		return;
	}

	// --- Übersichtsseite: ItemList mit den kommenden Terminen ---------------
	if ( ! is_page( DASRIND_SCHEMA_LIST_SLUGS ) ) {
		return;
	}

	$items    = array();
	$position = 0;
	$today    = current_time( 'Y-m-d' );

	foreach ( hessens_fetch_events() as $event ) {
		if ( empty( $event['date_raw'] ) || $event['date_raw'] < $today ) {
			continue; // Vergangenes gehört nicht in die Liste
		}
		$node = dasrind_schema_build_event( $event );
		if ( ! $node ) {
			continue;
		}
		$position++;
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'item'     => $node,
		);
		if ( $position >= DASRIND_SCHEMA_LIST_MAX ) {
			break;
		}
	}

	if ( ! $items ) {
		return;
	}

	dasrind_schema_print(
		array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'itemListElement' => $items,
		)
	);
}

/**
 * Baut den Event-Knoten aus einem Feed-Eintrag.
 *
 * @return array|null Null, wenn Pflichtangaben fehlen (name, startDate).
 */
function dasrind_schema_build_event( array $event ) {
	$title = trim( (string) ( $event['title'] ?? '' ) );
	if ( '' === $title || empty( $event['date_raw'] ) ) {
		return null;
	}

	// --- Zeitraum ----------------------------------------------------------
	// Achtung, dieselbe Falle wie beim iCal-Export: WordPress stellt PHPs
	// Default-Zeitzone auf UTC. Die Feed-Zeit ist Ortszeit und muss deshalb
	// explizit in wp_timezone() konstruiert werden, sonst wandert der Termin
	// um den Berlin-Offset.
	$start_time = trim( (string) ( $event['start_time'] ?? '' ) );
	$start_date = null;

	if ( '' !== $start_time ) {
		try {
			$start_date = new DateTimeImmutable(
				$event['date_raw'] . ' ' . $start_time,
				wp_timezone()
			);
		} catch ( Exception $e ) {
			$start_date = null;
		}
	}

	$node = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'Event',
		'name'                => $title,
		'eventStatus'         => 'https://schema.org/EventScheduled',
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
	);

	if ( $start_date ) {
		$node['startDate'] = $start_date->format( 'c' );
		$node['endDate']   = $start_date
			->modify( '+' . DASRIND_SCHEMA_DURATION_HOURS . ' hours' )
			->format( 'c' );
	} else {
		// Ohne Uhrzeit nur das Datum – Google akzeptiert das, rät aber davon ab.
		$node['startDate'] = $event['date_raw'];
	}

	// --- Ort ---------------------------------------------------------------
	$node['location'] = dasrind_schema_build_location( $event );

	// --- Beschreibung ------------------------------------------------------
	$description = wp_strip_all_tags( (string) ( $event['long_description'] ?? $event['description'] ?? '' ) );
	$description = trim( preg_replace( '/\s+/u', ' ', $description ) );
	if ( '' !== $description ) {
		$node['description'] = dasrind_schema_shorten( $description, 500 );
	}

	// --- Bild --------------------------------------------------------------
	// Zwei Unterschiede zur Anzeige im Frontend, beide beabsichtigt:
	//
	// 1. Hi-Res zuerst. Google will mindestens 720 px Breite; die low-res
	//    Variante ist auf 800 px gedeckelt und liegt damit an der Grenze.
	// 2. Querformat zuerst. Für die Website bevorzugen wir Image1 (Portrait),
	//    Google empfiehlt für Rich Results aber 16:9, 4:3 oder 1:1 – also
	//    Image2 (Landscape). Deshalb ist die Reihenfolge hier umgedreht.
	//
	// Es werden alle vorhandenen Varianten mitgegeben: Google darf sich das
	// passende Seitenverhältnis aussuchen.
	$images = array();
	foreach ( array( 'image2_url_hires', 'image1_url_hires', 'image_main_url_hires', 'image2_url', 'image1_url', 'image_main_url' ) as $key ) {
		if ( empty( $event[ $key ] ) ) {
			continue;
		}
		$url_img = esc_url_raw( $event[ $key ] );
		if ( ! in_array( $url_img, $images, true ) ) {
			$images[] = $url_img;
		}
	}
	if ( $images ) {
		$node['image'] = $images;
	}

	// --- URL der Detailseite ----------------------------------------------
	$url = dasrind_schema_event_url( $event );
	if ( $url ) {
		$node['url'] = $url;
	}

	// --- Tickets / Preis ---------------------------------------------------
	$offers = dasrind_schema_build_offers( $event, $url );
	if ( $offers ) {
		$node['offers'] = $offers;
	}

	// --- Veranstalter und Act ---------------------------------------------
	$node['organizer'] = array(
		'@type' => 'Organization',
		'name'  => DASRIND_SCHEMA_VENUE['name'],
		'url'   => home_url( '/' ),
	);

	if ( DASRIND_SCHEMA_PERFORMER ) {
		$node['performer'] = array(
			'@type' => 'PerformingGroup',
			'name'  => $title,
		);
	}

	return $node;
}

/**
 * Location aus dem Feed, mit Rückfall auf die konfigurierte Hausadresse.
 * `location_city` liefert „65428 Rüsselsheim" – PLZ und Ort werden getrennt,
 * weil Google beides einzeln erwartet.
 */
function dasrind_schema_build_location( array $event ) {
	$name   = trim( (string) ( $event['location_name'] ?? '' ) );
	$street = trim( (string) ( $event['location_street'] ?? '' ) );
	$zip    = '';
	$city   = trim( (string) ( $event['location_city'] ?? '' ) );

	if ( preg_match( '/^\s*(\d{4,5})\s+(.+)$/u', $city, $m ) ) {
		$zip  = $m[1];
		$city = trim( $m[2] );
	}

	if ( '' === $name ) {
		$name = DASRIND_SCHEMA_VENUE['name'];
	}
	if ( '' === $street ) {
		$street = DASRIND_SCHEMA_VENUE['street'];
	}
	if ( '' === $zip ) {
		$zip = DASRIND_SCHEMA_VENUE['zip'];
	}
	if ( '' === $city ) {
		$city = DASRIND_SCHEMA_VENUE['city'];
	}

	return array(
		'@type'   => 'Place',
		'name'    => $name,
		'address' => array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $street,
			'postalCode'      => $zip,
			'addressLocality' => $city,
			'addressRegion'   => DASRIND_SCHEMA_VENUE['region'],
			'addressCountry'  => DASRIND_SCHEMA_VENUE['country'],
		),
	);
}

/**
 * Preisangaben aus dem aufbereiteten `price`-String zurückgewinnen.
 * Mögliche Formen aus hessen-szene.php:
 *   „Eintritt frei"
 *   „VVK: 15,00 EUR (zzgl. VVK-Gebühr) / AK: 18,00 EUR"
 *   „AK: 18,00 EUR"
 */
function dasrind_schema_build_offers( array $event, $url ) {
	$price_text = (string) ( $event['price'] ?? '' );
	$sold_out   = ( '1' === (string) ( $event['sold_out'] ?? '0' ) );

	$availability = $sold_out
		? 'https://schema.org/SoldOut'
		: 'https://schema.org/InStock';

	$base = array(
		'priceCurrency' => 'EUR',
		'availability'  => $availability,
	);
	if ( $url ) {
		$base['url'] = $url;
	}

	if ( false !== stripos( $price_text, 'frei' ) ) {
		return array( '@type' => 'Offer', 'price' => '0' ) + $base;
	}

	// Alle Beträge einsammeln (deutsches Dezimalkomma zulassen).
	$values = array();
	if ( preg_match_all( '/(\d+(?:[.,]\d{1,2})?)\s*EUR/u', $price_text, $m ) ) {
		foreach ( $m[1] as $raw ) {
			$values[] = (float) str_replace( ',', '.', $raw );
		}
	}

	if ( ! $values ) {
		return null; // Ohne Preis lieber gar kein offers-Objekt als ein leeres
	}

	sort( $values );
	$low  = number_format( $values[0], 2, '.', '' );
	$high = number_format( end( $values ), 2, '.', '' );

	if ( $low === $high ) {
		return array( '@type' => 'Offer', 'price' => $low ) + $base;
	}

	return array(
		'@type'    => 'AggregateOffer',
		'lowPrice' => $low,
		'highPrice' => $high,
	) + $base;
}

/** Absolute URL der Detailseite für ein Event. */
function dasrind_schema_event_url( array $event ) {
	if ( empty( $event['slug'] ) ) {
		return '';
	}

	$page = get_page_by_path( DASRIND_SCHEMA_DETAIL_SLUG );
	$base = $page ? get_permalink( $page ) : home_url( '/' . DASRIND_SCHEMA_DETAIL_SLUG . '/' );

	if ( DASRIND_SCHEMA_PRETTY_URLS && get_option( 'permalink_structure' ) ) {
		return trailingslashit( $base ) . $event['slug'] . '/';
	}

	return add_query_arg( 'event', $event['slug'], $base );
}

/** Text auf maximal $max Zeichen kürzen, ohne ein Wort zu zerschneiden. */
function dasrind_schema_shorten( $text, $max ) {
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max );
	$space = mb_strrpos( $cut, ' ' );

	return ( false !== $space ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
}

/** JSON-LD ausgeben. */
function dasrind_schema_print( array $data ) {
	echo "\n<script type=\"application/ld+json\">"
		. wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		. "</script>\n";
}


// =============================================================================
// SEO-META PRO EVENT
//
// Ohne diesen Teil bleibt das Schema oben wirkungslos: SEOPress setzt auf der
// Detailseite das Canonical auf die Seite selbst (/event-detail/), also ohne
// ?event=. Damit erklärt sich jeder Termin zum Duplikat ein und derselben
// Seite, und Google nimmt keine der Event-URLs in den Index. Titel und
// Description sind aus demselben Grund für alle Termine identisch.
//
// Die Filter greifen ausschließlich, wenn ?event= auf ein Event im Feed passt –
// die Seite ohne Parameter behält ihre normalen SEOPress-Werte.
// =============================================================================

// ACHTUNG – die SEOPress-Filter sind nicht einheitlich:
//
//   seopress_titles_title / seopress_titles_desc  -> erwarten den reinen TEXT
//   seopress_titles_canonical / seopress_social_* -> erwarten das fertige
//                                                    HTML-TAG
//
// Gibt man bei den Tag-Filtern nur eine URL zurück, landet diese als nackter
// Text im <head> und das Tag fehlt komplett. Genau das war beim ersten Anlauf
// der Fall: Canonical und og:* verschwanden.
add_filter( 'seopress_titles_title', 'dasrind_meta_title' );
add_filter( 'seopress_titles_desc', 'dasrind_meta_description' );

add_filter( 'seopress_titles_canonical', 'dasrind_meta_canonical_tag' );
add_filter( 'seopress_social_og_url', 'dasrind_meta_og_url_tag' );
add_filter( 'seopress_social_og_title', 'dasrind_meta_og_title_tag' );
add_filter( 'seopress_social_og_desc', 'dasrind_meta_og_desc_tag' );

function dasrind_meta_canonical_tag( $html ) {
	$url = dasrind_meta_event_url_or_false();

	return ( false === $url )
		? $html
		: '<link rel="canonical" href="' . esc_url( $url ) . '" />';
}

function dasrind_meta_og_url_tag( $html ) {
	$url = dasrind_meta_event_url_or_false();

	return ( false === $url )
		? $html
		: '<meta property="og:url" content="' . esc_url( $url ) . '" />';
}

function dasrind_meta_og_title_tag( $html ) {
	$event = dasrind_schema_current_event();
	if ( ! $event ) {
		return $html;
	}

	return '<meta property="og:title" content="' . esc_attr( dasrind_meta_title( '' ) ) . '" />';
}

function dasrind_meta_og_desc_tag( $html ) {
	$event = dasrind_schema_current_event();
	if ( ! $event ) {
		return $html;
	}

	return '<meta property="og:description" content="' . esc_attr( dasrind_meta_description( '' ) ) . '" />';
}

/** Event-URL der aktuellen Detailseite, oder false wenn kein Event gefunden. */
function dasrind_meta_event_url_or_false() {
	$event = dasrind_schema_current_event();
	if ( ! $event ) {
		return false;
	}
	$url = dasrind_schema_event_url( $event );

	return $url ? $url : false;
}

function dasrind_meta_title( $value ) {
	$event = dasrind_schema_current_event();
	if ( ! $event || empty( $event['title'] ) ) {
		return $value;
	}

	$title = trim( $event['title'] );
	$date  = dasrind_meta_date_label( $event );
	if ( '' !== $date ) {
		$title .= ' – ' . $date;
	}

	// Suffix nur anhängen, solange der Titel nicht ausufert. Google schneidet
	// in der Regel um die 60 Zeichen ab; der Bandname soll auf jeden Fall
	// vollständig sichtbar bleiben.
	$suffix = DASRIND_SCHEMA_TITLE_SUFFIX;
	if ( '' !== $suffix && mb_strlen( $title . ' | ' . $suffix ) <= 70 ) {
		$title .= ' | ' . $suffix;
	}

	return $title;
}

function dasrind_meta_description( $value ) {
	$event = dasrind_schema_current_event();
	if ( ! $event ) {
		return $value;
	}

	$text = wp_strip_all_tags( (string) ( $event['description'] ?? '' ) );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
	$text = rtrim( $text, '.… ' );

	if ( '' === $text ) {
		// Rückfall, wenn der Feed keine Beschreibung liefert.
		$date = dasrind_meta_date_label( $event );
		$text = trim( $event['title'] ?? '' );
		if ( '' !== $date ) {
			$text .= ' am ' . $date;
		}
		$text .= ' im Das Rind in ' . DASRIND_SCHEMA_VENUE['city'];
		if ( ! empty( $event['price'] ) ) {
			$text .= '. ' . $event['price'];
		}
	}

	return dasrind_schema_shorten( $text, 155 );
}

/**
 * og:image bleibt bewusst unangetastet. SEOPress gibt dort einen ganzen Block
 * aus (og:image, :secure_url, :width, :height, :alt); ein Eingriff würde die
 * Zusatzangaben verlieren, und ein generisches Vorschaubild ist weniger
 * schlimm als ein halb ausgegebener Block.
 */

// =============================================================================
// SPRECHENDE EVENT-URLS
//
// /event-detail/SLUG/  ->  intern dieselbe Seite mit ?event=SLUG
//
// Der Slug wird nach dem Auflösen der Route zusätzlich in $_GET['event']
// geschrieben. Dadurch funktionieren hessen-szene.php und die Etch-Templates
// unverändert weiter – die lesen alle $_GET['event'], und an dieser Datei
// vorbei muss nichts angepasst werden.
// =============================================================================

if ( DASRIND_SCHEMA_PRETTY_URLS ) {
	add_action( 'init', 'dasrind_pretty_rewrite' );
	add_filter( 'query_vars', 'dasrind_pretty_query_var' );
	add_action( 'parse_request', 'dasrind_pretty_fill_get' );
	add_action( 'template_redirect', 'dasrind_pretty_redirect_old', 1 );
	add_filter( 'redirect_canonical', 'dasrind_pretty_keep_url', 10, 2 );
}

/**
 * WordPress' eigene Canonical-Weiterleitung würde /event-detail/SLUG/ auf die
 * nackte Seiten-URL zurückbiegen, weil die Route intern auf dieselbe Seite
 * zeigt – zusammen mit unserem 301 gäbe das eine Endlosschleife. Auf der
 * Event-Route wird sie deshalb abgeschaltet.
 */
function dasrind_pretty_keep_url( $redirect_url, $requested_url ) {
	if ( '' !== (string) get_query_var( 'event' ) ) {
		return false;
	}

	return $redirect_url;
}

function dasrind_pretty_rewrite() {
	add_rewrite_rule(
		'^' . DASRIND_SCHEMA_DETAIL_SLUG . '/([^/]+)/?$',
		'index.php?pagename=' . DASRIND_SCHEMA_DETAIL_SLUG . '&event=$matches[1]',
		'top'
	);

	// mu-Plugins kennen keinen Aktivierungs-Hook. Die Regeln werden deshalb
	// genau einmal pro Plugin-Version neu geschrieben – nicht bei jedem
	// Seitenaufruf, das wäre teuer.
	if ( get_option( 'dasrind_pretty_rules_version' ) !== '1.4' ) {
		flush_rewrite_rules( false );
		update_option( 'dasrind_pretty_rules_version', '1.4', false );
	}
}

function dasrind_pretty_query_var( $vars ) {
	$vars[] = 'event';

	return $vars;
}

function dasrind_pretty_fill_get( $wp ) {
	if ( ! empty( $wp->query_vars['event'] ) && empty( $_GET['event'] ) ) {
		$_GET['event'] = sanitize_title( $wp->query_vars['event'] );
	}
}

/**
 * Sorgt dafür, dass ein Event nur unter einer einzigen Adresse erreichbar ist.
 * Per 301 umgeleitet werden:
 *
 *   /event-detail/?event=SLUG   (alte Form, bestehende Links und Lesezeichen)
 *   /event-detail/SLUG          (ohne abschließenden Schrägstrich)
 *
 * Der zweite Fall ist nötig, weil wir `redirect_canonical` auf dieser Route
 * abschalten müssen – WordPress ergänzt den Schrägstrich hier also nicht mehr
 * von selbst.
 */
function dasrind_pretty_redirect_old() {
	if ( is_admin() || wp_doing_ajax() || empty( $_GET['event'] ) ) {
		return;
	}

	if ( ! is_page( DASRIND_SCHEMA_DETAIL_SLUG ) ) {
		return;
	}

	$event = dasrind_schema_current_event();
	if ( ! $event ) {
		return; // Unbekannter Slug: lieber die Seite normal ausliefern
	}

	$target = dasrind_schema_event_url( $event );
	if ( ! $target ) {
		return;
	}

	// Übrige Parameter erhalten (utm_*, fbclid und Ähnliches), nur `event`
	// fällt weg – der steckt jetzt im Pfad.
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
	if ( '' !== $query ) {
		parse_str( $query, $params );
		unset( $params['event'] );
		if ( $params ) {
			$target = add_query_arg( $params, $target );
		}
	}

	// Nur bei echtem Unterschied umleiten – sonst dreht sich das im Kreis.
	if ( home_url( $uri ) === $target ) {
		return;
	}

	wp_safe_redirect( $target, 301 );
	exit;
}


// =============================================================================
// ABGELAUFENE UND UNBEKANNTE EVENTS
//
// Termine verschwinden aus dem Feed, sobald sie vorbei sind. Die zugehörige
// URL bleibt aber im Google-Index und in geteilten Links bestehen. Ohne
// Behandlung antwortet sie mit HTTP 200 und generischem Inhalt – ein Soft-404.
//
// Unterschieden wird nach dem Datum im Slug (YYYY-MM-DD-...):
//
//   Datum in der Vergangenheit -> 301, der Termin ist endgültig vorbei
//   alles andere               -> 302, könnte eine Feed-Störung oder eine
//                                 kurzfristige Absage sein
//
// Der Unterschied ist wichtig: Fiele der Feed einmal komplett aus, würden sonst
// alle Event-URLs auf einen Schlag dauerhaft umgeleitet und aus dem Index
// fallen. Mit dem 302 ist dieser Fall folgenlos.
// =============================================================================

if ( DASRIND_SCHEMA_EXPIRED_REDIRECT ) {
	add_action( 'template_redirect', 'dasrind_expired_event_redirect', 2 );
}

function dasrind_expired_event_redirect() {
	if ( is_admin() || wp_doing_ajax() || empty( $_GET['event'] ) ) {
		return;
	}

	if ( ! is_page( DASRIND_SCHEMA_DETAIL_SLUG ) ) {
		return;
	}

	// Event vorhanden? Dann ist hier nichts zu tun.
	if ( dasrind_schema_current_event() ) {
		return;
	}

	$slug     = sanitize_title( wp_unslash( $_GET['event'] ) );
	$dauerhaft = false;
	if ( preg_match( '/^(\d{4}-\d{2}-\d{2})-/', $slug, $treffer ) ) {
		$dauerhaft = ( $treffer[1] < current_time( 'Y-m-d' ) );
	}

	$ziel_seite = get_page_by_path( DASRIND_SCHEMA_EXPIRED_TARGET_SLUG );
	$ziel       = $ziel_seite
		? get_permalink( $ziel_seite )
		: home_url( '/' . DASRIND_SCHEMA_EXPIRED_TARGET_SLUG . '/' );

	wp_safe_redirect( $ziel, $dauerhaft ? 301 : 302 );
	exit;
}


/** Datum eines Events als 12.09.2026. */
function dasrind_meta_date_label( array $event ) {
	if ( empty( $event['date_raw'] ) ) {
		return '';
	}
	try {
		$date = new DateTimeImmutable( $event['date_raw'], wp_timezone() );
	} catch ( Exception $e ) {
		return '';
	}

	return $date->format( 'd.m.Y' );
}
