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
 */

// =============================================================================
// 1. XML FETCH & PARSE
// =============================================================================

function hessens_fetch_events() {
    $transient_key = 'hessens_events_feed';

    $cached = get_transient( $transient_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $feed_url = 'https://www.hessen-szene.de/cdn?type=151&tx_laks_calendar%5Baction%5D=xml&tx_laks_calendar%5Bcenter%5D=11&tx_laks_calendar%5BenableHtml%5D=1';
    $response = wp_remote_get( $feed_url, array( 'timeout' => 15 ) );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return array();
    }

    $xml_string = wp_remote_retrieve_body( $response );

    libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $xml_string );

    if ( ! $xml ) {
        return array();
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
            $price = 'VVK: ' . (string) $event->FeeAdvanced . ' EUR';
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
        $image_fields = array();
        $i = 1;
        while ( isset( $event->{'Image' . $i} ) ) {
            $img_node  = $event->{'Image' . $i};
            $img_path  = (string) $img_node->PublicUrl;
            $field_key = 'image' . $i . '_url';
            $name_key  = 'image' . $i . '_filename';
            if ( ! empty( $img_path ) ) {
                $image_fields[ $field_key ] = $image_base . $img_path;
                $image_fields[ $name_key ]  = basename( $img_path );
            } else {
                $image_fields[ $field_key ] = '';
                $image_fields[ $name_key ]  = '';
            }
            $i++;
        }
        if ( ! isset( $image_fields['image1_url'] ) ) {
            $image_fields['image1_url']      = '';
            $image_fields['image1_filename'] = '';
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
            'month' => $timestamp ? wp_date( 'Y-m', $timestamp ) : '',
            'month_label' => $timestamp ? wp_date( 'F Y', $timestamp ) : '',
        );

        // Bilder zusammenfuehren
        $event_data = array_merge( $event_data, $image_fields );

        $events[] = $event_data;
    }

    set_transient( $transient_key, $events, HOUR_IN_SECONDS );

    return $events;
}


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
