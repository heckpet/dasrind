<?php
/**
 * truncate_html
 * Kürzt HTML-Inhalt auf eine bestimmte Zeichenanzahl (sichtbare Zeichen),
 * behält HTML-Struktur bei und entfernt überschüssige Nodes korrekt.
 */
function truncate_html($html, $maxLength = 200, $suffix = '...') {
    // Tags entfernen, nur sichtbaren Text
    $text = wp_strip_all_tags( $html );
    // Mehrfache Leerzeichen/Zeilenumbrüche bereinigen
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if ( mb_strlen($text) <= $maxLength ) {
        return $text;
    }

    return mb_substr($text, 0, $maxLength) . $suffix;
}


/**
 * Hessen-Szene Event Feed - Etch Dynamic Data + JSON Endpoint
 * Fetcht XML, cached als Transient, stellt JSON-Endpoint bereit.
 *
 * CACHING-STRATEGIE
 * -----------------
 * Der Cache wird NICHT beim Seitenaufruf erneuert, sondern aktiv per WP-Cron
 * alle 15 Minuten. Die Transient-Laufzeit ist bewusst deutlich laenger
 * (2 Stunden) als das Cron-Intervall: So laeuft niemals ein Besucher in einen
 * abgelaufenen Cache und muss auf den langsamen Remote-Fetch warten – auch
 * dann nicht, wenn WP-Cron mal aussetzt (passiert auf Seiten mit wenig
 * Traffic regelmaessig).
 *
 * Zusaetzlich koennen Redakteure (Rolle: Editor) den Feed jederzeit manuell
 * ueber die Admin-Bar aktualisieren – siehe Abschnitt 2b.
 */

// =============================================================================
// 1. XML FETCH & PARSE
// =============================================================================

// Wie lange der Event-Cache maximal gilt (Sicherheitsnetz, falls Cron ausfaellt).
if ( ! defined( 'HESSENS_CACHE_TTL' ) ) {
    define( 'HESSENS_CACHE_TTL', 2 * HOUR_IN_SECONDS );
}
// In diesem Intervall erneuert der Cron den Feed aktiv.
if ( ! defined( 'HESSENS_REFRESH_INTERVAL' ) ) {
    define( 'HESSENS_REFRESH_INTERVAL', 15 * MINUTE_IN_SECONDS );
}
// Dauerhafte Sicherungskopie der zuletzt erfolgreich geladenen Events.
// Wird ausgeliefert, wenn hessen-szene.de gerade nicht erreichbar ist –
// damit die Programmseite nie leer ist.
if ( ! defined( 'HESSENS_BACKUP_OPTION' ) ) {
    define( 'HESSENS_BACKUP_OPTION', 'hessens_events_backup' );
}

/**
 * Laedt die Events.
 *
 * @param bool $force true = Cache ignorieren und frisch vom Server holen.
 * @return array
 */
function hessens_fetch_events( $force = false ) {
    $transient_key = 'hessens_events_feed';

    if ( ! $force ) {
        $cached = get_transient( $transient_key );
        if ( $cached !== false ) {
            return $cached;
        }
    }

    $feed_url = 'https://www.hessen-szene.de/cdn?type=151&tx_laks_calendar%5Baction%5D=xml&tx_laks_calendar%5Bcenter%5D=11&tx_laks_calendar%5BenableHtml%5D=1';
    $response = wp_remote_get( $feed_url, array( 'timeout' => 15 ) );

    // Harter Fehler -> letzte bekannte Daten weiterverwenden statt leere Seite.
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return hessens_get_backup_events();
    }

    $xml_string = wp_remote_retrieve_body( $response );

    libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $xml_string );

    if ( ! $xml ) {
        return hessens_get_backup_events();
    }

    $events     = array();
    $image_base = 'https://www.hessen-szene.de';

    foreach ( $xml->Event as $event ) {

        // Kategorien + Thema (letztes Wort des ersten Kategorietitels)
        $categories = array();
        $thema      = '';
        if ( isset( $event->Categories->Category ) ) {
            foreach ( $event->Categories->Category as $cat ) {
                $cat_title    = (string) $cat->Title;
                $categories[] = $cat_title;
                if ( $thema === '' ) {
                    $words = explode( ' ', trim( $cat_title ) );
                    $thema = end( $words );
                }
            }
        }

        // Eintrittspreis
        $price  = '';
        $no_fee = ( (string) $event->noFee === '1' );
        if ( $no_fee ) {
            $price = 'Eintritt frei';
        } elseif ( ! empty( (string) $event->FeeAdvanced ) ) {
            $price = 'VVK: ' . (string) $event->FeeAdvanced . ' EUR (zzgl. VVK-Gebühr)';
            if ( ! empty( (string) $event->FeeRegular ) ) {
                $price .= ' / AK: ' . (string) $event->FeeRegular . ' EUR';
            }
        } elseif ( ! empty( (string) $event->FeeRegular ) ) {
            $price = 'AK: ' . (string) $event->FeeRegular . ' EUR';
        }

        // Datum formatiert
        $raw_date       = (string) $event->StartDate->Value;
        $timestamp      = strtotime( $raw_date );
        $date_formatted = $timestamp ? wp_date( 'D, j. M Y', $timestamp ) : $raw_date;


        // Uhrzeit
        $start_time_raw = (string) $event->StartTime->Value;
        $start_time     = ( strlen( $start_time_raw ) >= 5 ) ? substr( $start_time_raw, 0, 5 ) : $start_time_raw;

        // Einlasszeit
        $box_office_start_time_raw = (string) $event->BoxOfficeStartTime->Value;
        $box_office_start_time     = ( strlen( $box_office_start_time_raw ) >= 5 )
            ? '- Einlass: ' . substr( $box_office_start_time_raw, 0, 5 )
            : '';

        // Bilder: alle durchnummeriert einsammeln (Image1, Image2, Image3, ...)
        //
        // Pro Bild werden zwei Varianten bereitgestellt:
        //   image{N}_url        -> low-res (max. 800px) fuer die Anzeige in
        //                          Programmuebersicht und Detailseiten.
        //   image{N}_url_hires  -> Original aus dem XML (hi-res) fuer den
        //                          Presse-Download-Bereich.
        // Die low-res Datei wird im Hintergrund erzeugt (siehe Abschnitt 1b).
        // Solange sie noch nicht existiert, liefert image{N}_url als Fallback
        // das Original aus, damit nie ein kaputtes Bild angezeigt wird.
        $image_fields = array();
        $i = 1;
        while ( isset( $event->{'Image' . $i} ) ) {
            $img_node  = $event->{'Image' . $i};
            $img_path  = (string) $img_node->PublicUrl;
            $url_key   = 'image' . $i . '_url';        // low-res (Anzeige)
            $hires_key = 'image' . $i . '_url_hires';  // Original (Presse-Download)
            $name_key  = 'image' . $i . '_filename';
            if ( ! empty( $img_path ) ) {
                $original_url = $image_base . $img_path;
                $image_fields[ $hires_key ] = $original_url;
                $image_fields[ $url_key ]   = hessens_lowres_display_url( $original_url );
                $image_fields[ $name_key ]  = basename( $img_path );
            } else {
                $image_fields[ $hires_key ] = '';
                $image_fields[ $url_key ]   = '';
                $image_fields[ $name_key ]  = '';
            }
            $i++;
        }
        if ( ! isset( $image_fields['image1_url'] ) ) {
            $image_fields['image1_url']       = '';
            $image_fields['image1_url_hires'] = '';
            $image_fields['image1_filename']  = '';
        }

        // Location
        $location_name   = '';
        $location_street = '';
        $location_city   = '';
        if ( isset( $event->Locations->Location ) ) {
            $loc             = $event->Locations->Location;
            $location_name   = (string) $loc->Title;
            $location_street = (string) $loc->Street;
            $location_city   = trim( (string) $loc->Zip . ' ' . (string) $loc->City );
        }

        // Langbeschreibung: HTML behalten, nur sicher bereinigen
        $description_long = wp_kses_post( trim( (string) $event->Description ) );

        // Kurzbeschreibung: 200 Zeichen, HTML-sicher
        $description_short = truncate_html( $description_long, 200, '...' );

        // Ausverkauft
        $sold_out = ( ! empty( (string) $event->soldOut ) ) ? '1' : '0';

        // Kuenstler-Links (Homepage, Facebook, Instagram)
        //
        // Im XML liegen die Links unter <Links><Link><Title>/<URI>.
        // Die Titel sind fest ("Homepage", "Facebook", "Instagram"); wir
        // ordnen sie per Titel zu (Reihenfolge egal) und liefern feste
        // Schluessel, die in Etch immer existieren – auch wenn ein Link fehlt.
        $link_homepage  = '';
        $link_facebook  = '';
        $link_instagram = '';
        if ( isset( $event->Links->Link ) ) {
            foreach ( $event->Links->Link as $link ) {
                $link_title = strtolower( trim( (string) $link->Title ) );
                $link_uri   = trim( (string) $link->URI );
                if ( $link_title === 'homepage' ) {
                    $link_homepage = $link_uri;
                } elseif ( $link_title === 'facebook' ) {
                    $link_facebook = $link_uri;
                } elseif ( $link_title === 'instagram' ) {
                    $link_instagram = $link_uri;
                }
            }
        }

        // Slug
        $slug = sanitize_title( $raw_date . '-' . (string) $event->Title );

        // Basis-Event-Array
        $event_data = array(
            'id'                    => (string) $event->Id,
            'title'                 => (string) $event->Title,
            'slug'                  => $slug,
            'date'                  => $date_formatted,
            'date_raw'              => $raw_date,
            'start_time'            => $start_time,
            'box_office_start_time' => $box_office_start_time,
            'categories'            => implode( ', ', $categories ),
            'thema'                 => $thema,
            'price'                 => $price,
            'description'           => $description_short,
            'long_description'      => $description_long,
            'location_name'         => $location_name,
            'location_street'       => $location_street,
            'location_city'         => $location_city,
            'sold_out'              => $sold_out,
            'link_homepage'         => $link_homepage,
            'link_facebook'         => $link_facebook,
            'link_instagram'        => $link_instagram,
            'month' => $timestamp ? wp_date( 'Y-m', $timestamp ) : '',
            'month_label' => $timestamp ? wp_date( 'F Y', $timestamp ) : '',
        );

        // Bilder zusammenfuehren
        $event_data = array_merge( $event_data, $image_fields );

        $events[] = $event_data;
    }

    set_transient( $transient_key, $events, HESSENS_CACHE_TTL );

    // Sicherungskopie mitschreiben (autoload = false, damit sie nicht bei
    // jedem Seitenaufruf mitgeladen wird).
    update_option( HESSENS_BACKUP_OPTION, $events, false );

    // Zeitstempel des letzten erfolgreichen Abrufs merken (fuer die Anzeige).
    update_option( 'hessens_last_refresh', time(), false );

    return $events;
}

/**
 * Letzte erfolgreich geladene Events aus der Sicherungskopie.
 */
function hessens_get_backup_events() {
    $backup = get_option( HESSENS_BACKUP_OPTION );

    return is_array( $backup ) ? $backup : array();
}


// =============================================================================
// 1b. BILD-VERARBEITUNG (low-res Cache)
//     Erzeugt aus den (kuenftig hi-res) Original-Fotos verkleinerte Kopien
//     mit max. 800px Kantenlaenge und legt sie dauerhaft im Uploads-Ordner ab.
//     Die Verarbeitung laeuft im Hintergrund (WP-Cron), nicht beim Seitenaufruf.
// =============================================================================

// Unterordner im Uploads-Verzeichnis, in dem die low-res Dateien liegen.
if ( ! defined( 'HESSENS_LOWRES_DIR' ) ) {
    define( 'HESSENS_LOWRES_DIR', 'hessens-events' );
}
// Maximale Kantenlaenge der low-res Variante (Breite ODER Hoehe).
if ( ! defined( 'HESSENS_LOWRES_MAX' ) ) {
    define( 'HESSENS_LOWRES_MAX', 800 );
}

/**
 * Liefert Datei-Pfad und URL der low-res Variante zu einer Original-URL.
 * Der Dateiname ist ein eindeutiger Hash der Original-URL.
 */
function hessens_lowres_paths( $original_url ) {
    $upload    = wp_upload_dir();
    $path_part = (string) parse_url( $original_url, PHP_URL_PATH );
    $ext       = strtolower( pathinfo( $path_part, PATHINFO_EXTENSION ) );

    if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
        $ext = 'jpg';
    }

    $filename = md5( $original_url ) . '.' . $ext;

    return array(
        'path' => trailingslashit( $upload['basedir'] ) . HESSENS_LOWRES_DIR . '/' . $filename,
        'url'  => trailingslashit( $upload['baseurl'] ) . HESSENS_LOWRES_DIR . '/' . $filename,
    );
}

/**
 * Liefert die anzuzeigende Bild-URL:
 * - die low-res Datei, sobald sie erzeugt wurde
 * - sonst (Fallback) das Original, bis der Cron-Job nachzieht
 */
function hessens_lowres_display_url( $original_url ) {
    if ( empty( $original_url ) ) {
        return '';
    }

    $paths = hessens_lowres_paths( $original_url );

    if ( file_exists( $paths['path'] ) ) {
        return $paths['url'];
    }

    return $original_url;
}

/**
 * Erzeugt eine einzelne low-res Datei: Original herunterladen, auf
 * max. HESSENS_LOWRES_MAX px verkleinern (Seitenverhaeltnis bleibt erhalten)
 * und im Cache-Ordner ablegen. Gibt true zurueck, wenn die Datei vorliegt.
 */
function hessens_generate_one_lowres( $original_url ) {
    if ( empty( $original_url ) ) {
        return false;
    }

    $paths = hessens_lowres_paths( $original_url );

    // Schon vorhanden -> nichts zu tun.
    if ( file_exists( $paths['path'] ) ) {
        return true;
    }

    // Zielordner sicherstellen.
    $dir = dirname( $paths['path'] );
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    // Original temporaer herunterladen.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $tmp = download_url( $original_url, 20 );
    if ( is_wp_error( $tmp ) ) {
        return false;
    }

    $size = @getimagesize( $tmp );
    if ( ! $size ) {
        @unlink( $tmp );
        return false;
    }

    $width  = (int) $size[0];
    $height = (int) $size[1];

    // Bereits klein genug -> Original 1:1 in den Cache kopieren (kein Qualitaetsverlust).
    if ( $width <= HESSENS_LOWRES_MAX && $height <= HESSENS_LOWRES_MAX ) {
        $ok = copy( $tmp, $paths['path'] );
        @unlink( $tmp );
        return (bool) $ok;
    }

    // Verkleinern: passt das Bild in eine Box von MAX x MAX,
    // Seitenverhaeltnis bleibt erhalten (landscape -> max. Breite,
    // portrait -> max. Hoehe).
    $editor = wp_get_image_editor( $tmp );
    if ( is_wp_error( $editor ) ) {
        @unlink( $tmp );
        return false;
    }

    $editor->resize( HESSENS_LOWRES_MAX, HESSENS_LOWRES_MAX, false );
    $editor->set_quality( 82 );
    $saved = $editor->save( $paths['path'] );

    @unlink( $tmp );

    return ! is_wp_error( $saved );
}

/**
 * Verarbeitet fehlende low-res Bilder in Batches.
 * $limit begrenzt die Anzahl pro Aufruf, damit kein PHP-Timeout entsteht.
 * Gibt zurueck, wie viele Bilder erzeugt wurden und wie viele noch offen sind.
 */
function hessens_process_images( $limit = 10 ) {
    @set_time_limit( 0 );

    $events    = hessens_fetch_events();
    $processed = 0;
    $remaining = 0;

    foreach ( $events as $event ) {
        $i = 1;
        while ( isset( $event[ 'image' . $i . '_url_hires' ] ) ) {
            $hires = $event[ 'image' . $i . '_url_hires' ];
            $i++;

            if ( empty( $hires ) ) {
                continue;
            }

            $paths = hessens_lowres_paths( $hires );
            if ( file_exists( $paths['path'] ) ) {
                continue; // schon erledigt
            }

            // Batch-Grenze erreicht -> nur noch zaehlen.
            if ( $processed >= $limit ) {
                $remaining++;
                continue;
            }

            if ( hessens_generate_one_lowres( $hires ) ) {
                $processed++;
            }
        }
    }

    // Wenn neue Dateien erzeugt wurden, Event-Cache neu aufbauen,
    // damit image{N}_url ab sofort auf die fertige low-res Datei zeigt.
    if ( $processed > 0 ) {
        hessens_fetch_events( true );
    }

    return array( 'processed' => $processed, 'remaining' => $remaining );
}

// --- WP-Cron: Feed und Bilder im Hintergrund aktualisieren -------------------

// Eigenes 15-Minuten-Intervall registrieren.
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['hessens_15min'] ) ) {
        $schedules['hessens_15min'] = array(
            'interval' => HESSENS_REFRESH_INTERVAL,
            'display'  => 'Alle 15 Minuten (Hessen-Szene)',
        );
    }
    return $schedules;
} );

// Cron-Events einplanen, falls noch nicht vorhanden.
add_action( 'init', function () {
    // Feed aktiv erneuern, damit der Cache immer warm ist.
    if ( ! wp_next_scheduled( 'hessens_refresh_feed_event' ) ) {
        wp_schedule_event( time() + 30, 'hessens_15min', 'hessens_refresh_feed_event' );
    }
    // Bilder nachverarbeiten.
    if ( ! wp_next_scheduled( 'hessens_process_images_event' ) ) {
        wp_schedule_event( time() + 60, 'hessens_15min', 'hessens_process_images_event' );
    }
} );

// Feed-Refresh: holt das XML frisch und schreibt Cache + Sicherungskopie.
add_action( 'hessens_refresh_feed_event', function () {
    hessens_fetch_events( true );
} );

// Der eigentliche Bild-Job: pro Lauf max. 10 Bilder.
add_action( 'hessens_process_images_event', function () {
    hessens_process_images( 10 );
} );

// --- Manueller Trigger fuer die Bildverarbeitung (nur fuer Admins) -----------
// Erstbefuellung nach dem Umstieg: deine-seite.de/?hessens_process_images=1
add_action( 'init', function () {
    if ( isset( $_GET['hessens_process_images'] ) && current_user_can( 'manage_options' ) ) {
        $result = hessens_process_images( 200 );
        wp_die( sprintf(
            'Bilder verarbeitet: %d &middot; Noch offen: %d.<br>%s',
            (int) $result['processed'],
            (int) $result['remaining'],
            $result['remaining'] > 0
                ? 'Bitte diese Seite erneut aufrufen, um die restlichen Bilder zu erzeugen.'
                : 'Alle low-res Bilder sind aktuell.'
        ) );
    }
} );


// =============================================================================
// 2. CACHE LEEREN (nur fuer Admins: deine-seite.de/?clear_events_cache=1)
// =============================================================================

add_action( 'init', function () {
    if ( isset( $_GET['clear_events_cache'] ) && current_user_can( 'manage_options' ) ) {
        delete_transient( 'hessens_events_feed' );
        wp_die( 'Event-Cache geleert.' );
    }
} );


// =============================================================================
// 2b. MANUELLES AKTUALISIEREN FUER REDAKTEURE
//     Admin-Bar-Eintrag "Veranstaltungen aktualisieren" – sichtbar ab der
//     Rolle Editor (Capability edit_posts). Ein Klick holt den Feed sofort
//     frisch von hessen-szene.de und meldet zurueck, wie viele Termine
//     geladen wurden.
// =============================================================================

/**
 * Eintrag in der Admin-Bar (Frontend und Backend).
 */
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {

    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }

    // Zurueck-Ziel: die Seite, auf der man gerade ist.
    $back = is_admin()
        ? admin_url()
        : home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );

    $url = wp_nonce_url(
        add_query_arg( 'hessens_refresh_events', '1', $back ),
        'hessens_refresh_events'
    );

    $last  = (int) get_option( 'hessens_last_refresh' );
    $title = 'Veranstaltungen aktualisieren';
    $meta  = array();

    if ( $last ) {
        $meta['title'] = 'Zuletzt aktualisiert: ' . wp_date( 'd.m.Y, H:i', $last ) . ' Uhr';
    }

    $wp_admin_bar->add_node( array(
        'id'    => 'hessens-refresh',
        'title' => $title,
        'href'  => $url,
        'meta'  => $meta,
    ) );

}, 100 );

/**
 * Der Aktualisierungs-Vorgang.
 */
add_action( 'init', function () {

    if ( empty( $_GET['hessens_refresh_events'] ) ) {
        return;
    }

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( 'Keine Berechtigung zum Aktualisieren der Veranstaltungen.' );
    }

    // Schuetzt davor, dass der Aufruf ungewollt von aussen ausgeloest wird.
    check_admin_referer( 'hessens_refresh_events' );

    $events = hessens_fetch_events( true );
    $count  = count( $events );

    // Neue Termine bringen oft neue Bilder mit: Verarbeitung gleich anstossen,
    // aber im Hintergrund, damit der Redakteur nicht warten muss.
    wp_schedule_single_event( time() + 20, 'hessens_process_images_event' );

    $back = wp_get_referer();
    if ( ! $back ) {
        $back = home_url( '/' );
    }
    // Den Trigger-Parameter aus der Ruecksprung-URL entfernen.
    $back = remove_query_arg( array( 'hessens_refresh_events', '_wpnonce' ), $back );

    $message = $count > 0
        ? sprintf( 'Es wurden <strong>%d Termine</strong> geladen.', $count )
        : 'Es wurden <strong>keine Termine</strong> geladen. Bitte pruefen, ob auf hessen-szene.de Veranstaltungen freigegeben sind.';

    wp_die(
        '<p>' . $message . '</p>'
        . '<p style="color:#555;font-size:13px;">Hinweis: Wenn eine gerade eingegebene Veranstaltung noch fehlt, '
        . 'liegt das am Zwischenspeicher von hessen-szene.de. Bitte in ein paar Minuten erneut aktualisieren.</p>'
        . '<p><a href="' . esc_url( $back ) . '">&laquo; Zurueck zur Seite</a></p>',
        'Veranstaltungen aktualisiert',
        array( 'response' => 200, 'back_link' => false )
    );
} );


// =============================================================================
// 3. ETCH DYNAMIC DATA HOOK
// =============================================================================

add_filter( 'etch/dynamic_data/option', function ( $data ) {
    $data['events'] = hessens_fetch_events();
    return $data;
} );


// =============================================================================
// 4. JSON ENDPOINT (deine-seite.de/?hessens_events=1)
//    Diese URL traegst du in Etch unter Loop > Source: JSON Data > URL ein.
// =============================================================================

add_action( 'init', function () {
    if ( isset( $_GET['hessens_events'] ) ) {
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'Access-Control-Allow-Origin: *' );
        echo json_encode( hessens_fetch_events(), JSON_UNESCAPED_UNICODE );
        exit;
    }
} );


// =============================================================================
// 5. SINGLE EVENT: options.event (fuer die Detailseite)
//    Liest ?event=SLUG aus der URL und gibt das passende Event zurueck.
// =============================================================================

add_filter( 'etch/dynamic_data/option', function ( $data ) {
    if ( isset( $_GET['event'] ) && ! empty( $_GET['event'] ) ) {
        $requested_slug = sanitize_title( $_GET['event'] );
        $all_events     = hessens_fetch_events();
        foreach ( $all_events as $event ) {
            if ( $event['slug'] === $requested_slug ) {
                $data['event'] = $event;
                break;
            }
        }
    }
    return $data;
} );


// =============================================================================
// 6. LIMITIERTER ENDPOINT
//    Gibt nur die ersten N Events zurueck.
// =============================================================================

add_filter( 'etch/dynamic_data/option', function ( $data ) {
    $data['events_preview'] = array_slice( hessens_fetch_events(), 0, 4 );
    return $data;
} );

// =============================================================================
// 7. iCAL ENDPOINT (deine-seite.de/?hessens_ical=EVENT-SLUG)
// =============================================================================

add_action( 'init', function () {
    if ( isset( $_GET['hessens_ical'] ) && ! empty( $_GET['hessens_ical'] ) ) {
        $requested_slug = sanitize_title( $_GET['hessens_ical'] );
        $all_events     = hessens_fetch_events();
        $event          = null;

        foreach ( $all_events as $e ) {
            if ( $e['slug'] === $requested_slug ) {
                $event = $e;
                break;
            }
        }

        if ( ! $event ) {
            wp_die( 'Event nicht gefunden.' );
        }

        // Datum + Zeit für iCAL formatieren (YYYYMMDDTHHmmssZ)
        $start_datetime = gmdate( 'Ymd\THis\Z', strtotime( $event['date_raw'] . ' ' . $event['start_time'] ) );
        // Ende: 3 Stunden nach Beginn als Schätzwert
        $end_datetime   = gmdate( 'Ymd\THis\Z', strtotime( $event['date_raw'] . ' ' . $event['start_time'] ) + 3 * HOUR_IN_SECONDS );

        $location = trim( $event['location_name'] . ', ' . $event['location_street'] . ', ' . $event['location_city'] );
        $uid      = $event['id'] . '@dasrind.de';

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Das Rind//Event//DE\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $uid . "\r\n";
        $ics .= "DTSTAMP:" . gmdate( 'Ymd\THis\Z' ) . "\r\n";
        $ics .= "DTSTART:" . $start_datetime . "\r\n";
        $ics .= "DTEND:" . $end_datetime . "\r\n";
        $ics .= "SUMMARY:" . $event['title'] . "\r\n";
        $ics .= "DESCRIPTION:" . wp_strip_all_tags( $event['description'] ) . "\r\n";
        $ics .= "LOCATION:" . $location . "\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $event['slug'] . '.ics"' );
        echo $ics;
        exit;
    }
} );
