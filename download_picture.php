<?php
/**
 * Presse-Bild-Download für Das Rind
 *
 * Der Download behält IMMER den Original-Dateinamen, unter dem das Bild von
 * hessen-szene.de kommt (dort sind teilweise Urheber-Hinweise enthalten).
 * Es wird kein sprechender Name mehr generiert.
 *
 * Zwei Aufrufvarianten:
 *
 *   1. EMPFOHLEN – Slug + Bildnummer:
 *      /?download_event_image=EVENT-SLUG&img=1
 *      Liefert immer das hi-res Original.
 *
 *   2. Kompatibilität – direkte Bild-URL:
 *      /?download_event_image=https://...
 *      Erlaubt sind hessen-szene.de sowie der eigene Uploads-Ordner.
 */

// -----------------------------------------------------------------------------
// Helfer: Original-Dateinamen aus der Quell-URL übernehmen
// -----------------------------------------------------------------------------

/**
 * Gibt den Dateinamen exakt so zurück, wie er in der Quell-URL steht.
 * Es wird nur das Nötigste entfernt (Pfadanteile, Steuerzeichen, Anführungs-
 * zeichen), damit der Content-Disposition-Header nicht manipulierbar ist.
 * Umlaute, Klammern, Unterstriche, Urheber-Hinweise usw. bleiben erhalten.
 */
function hessens_original_download_filename( $source_url ) {
	$path = (string) parse_url( $source_url, PHP_URL_PATH );
	$name = rawurldecode( basename( $path ) );

	// Pfad-Traversal und Header-Injection unterbinden.
	$name = str_replace( array( '\\', '/', '"', "\r", "\n", "\0" ), '', $name );
	$name = trim( $name );

	// Fallback, falls die URL keinen brauchbaren Dateinamen enthält.
	if ( $name === '' || $name === '.' || $name === '..' ) {
		$name = 'download.jpg';
	}

	return $name;
}

/**
 * Setzt den Content-Disposition-Header. Zusätzlich zur ASCII-Variante wird
 * filename* (RFC 5987) gesendet, damit Umlaute im Original-Namen erhalten
 * bleiben.
 */
function hessens_send_disposition_header( $filename ) {
	$ascii = remove_accents( $filename );
	$ascii = preg_replace( '/[^\x20-\x7E]/', '_', $ascii );

	header(
		'Content-Disposition: attachment; filename="' . $ascii . '"; '
		. "filename*=UTF-8''" . rawurlencode( $filename )
	);
}

/**
 * Sucht das Event, zu dem eine Bild-URL gehört (hi-res oder low-res).
 * Gibt array( $event, $index ) zurück oder array( null, 1 ).
 */
function hessens_find_event_by_image_url( $url ) {
	foreach ( hessens_fetch_events() as $event ) {
		$i = 1;
		while ( isset( $event[ 'image' . $i . '_url_hires' ] ) ) {
			if ( $event[ 'image' . $i . '_url_hires' ] === $url || ( isset( $event[ 'image' . $i . '_url' ] ) && $event[ 'image' . $i . '_url' ] === $url ) ) {
				return array( $event, $i );
			}
			$i++;
		}
	}

	return array( null, 1 );
}

// -----------------------------------------------------------------------------
// Auslieferung
// -----------------------------------------------------------------------------

function hessens_serve_image_download( $url ) {
	$filename     = hessens_original_download_filename( $url );
	$upload       = wp_upload_dir();
	$local_prefix = trailingslashit( $upload['baseurl'] );

	// a) Lokale Datei -> direkt vom Dateisystem ausliefern (kein HTTP-Umweg).
	if ( strpos( $url, $local_prefix ) === 0 ) {
		$file = realpath( trailingslashit( $upload['basedir'] ) . substr( $url, strlen( $local_prefix ) ) );
		$base = realpath( $upload['basedir'] );

		if ( ! $file || ! $base || strpos( $file, $base ) !== 0 || ! is_file( $file ) ) {
			wp_die( 'Datei nicht gefunden.' );
		}

		$type = wp_check_filetype( $file );

		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		hessens_send_disposition_header( $filename );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file );
		exit;
	}

	// b) Remote (hessen-szene.de) -> durchreichen.
	$response = wp_remote_get( $url, array( 'timeout' => 25 ) );

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		wp_die( 'Bild konnte nicht geladen werden.' );
	}

	$body         = wp_remote_retrieve_body( $response );
	$content_type = wp_remote_retrieve_header( $response, 'content-type' );

	nocache_headers();
	header( 'Content-Type: ' . ( $content_type ? $content_type : 'application/octet-stream' ) );
	hessens_send_disposition_header( $filename );
	header( 'Content-Length: ' . strlen( $body ) );
	header( 'X-Content-Type-Options: nosniff' );
	echo $body;
	exit;
}

// -----------------------------------------------------------------------------
// Router
// -----------------------------------------------------------------------------

add_action( 'init', function () {

	if ( empty( $_GET['download_event_image'] ) ) {
		return;
	}

	$param = wp_unslash( $_GET['download_event_image'] );
	$index = isset( $_GET['img'] ) ? max( 1, (int) $_GET['img'] ) : 1;

	// ---------------------------------------------------------------------
	// Variante 1: Slug übergeben (empfohlen)
	// ---------------------------------------------------------------------
	if ( strpos( $param, 'http' ) !== 0 ) {

		$slug  = sanitize_title( $param );
		$event = null;

		foreach ( hessens_fetch_events() as $e ) {
			if ( $e['slug'] === $slug ) {
				$event = $e;
				break;
			}
		}

		if ( ! $event ) {
			wp_die( 'Event nicht gefunden.' );
		}

		// hi-res bevorzugen, sonst low-res als Fallback
		$url = '';
		if ( ! empty( $event[ 'image' . $index . '_url_hires' ] ) ) {
			$url = $event[ 'image' . $index . '_url_hires' ];
		} elseif ( ! empty( $event[ 'image' . $index . '_url' ] ) ) {
			$url = $event[ 'image' . $index . '_url' ];
		}

		if ( empty( $url ) ) {
			wp_die( 'Für dieses Event ist kein Bild ' . (int) $index . ' vorhanden.' );
		}

		hessens_serve_image_download( $url );
	}

	// ---------------------------------------------------------------------
	// Variante 2: direkte URL (Kompatibilität)
	// ---------------------------------------------------------------------
	$url    = esc_url_raw( $param ); // KEIN urldecode – PHP hat bereits dekodiert
	$upload = wp_upload_dir();

	$allowed = array(
		'https://www.hessen-szene.de/',
		'https://hessen-szene.de/',
		trailingslashit( $upload['baseurl'] ),
	);

	$is_allowed = false;
	foreach ( $allowed as $prefix ) {
		if ( strpos( $url, $prefix ) === 0 ) {
			$is_allowed = true;
			break;
		}
	}

	if ( ! $is_allowed ) {
		wp_die( 'Ungültige URL: ' . esc_html( $url ) );
	}

	// Wenn eine low-res URL kommt: auf das hi-res Original hochstufen.
	list( $event, $found_index ) = hessens_find_event_by_image_url( $url );

	if ( $event && ! empty( $event[ 'image' . $found_index . '_url_hires' ] ) ) {
		$url = $event[ 'image' . $found_index . '_url_hires' ];
	}

	hessens_serve_image_download( $url );
} );
