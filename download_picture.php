<?php 
add_action( 'init', function () {
    if ( isset( $_GET['download_event_image'] ) && ! empty( $_GET['download_event_image'] ) ) {
        $url = esc_url_raw( urldecode( $_GET['download_event_image'] ) );
        
        // Nur hessen-szene.de erlauben
        if ( strpos( $url, 'https://www.hessen-szene.de/' ) !== 0 ) {
            wp_die( 'Ungültige URL.' );
        }

        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $response ) ) {
            wp_die( 'Bild konnte nicht geladen werden.' );
        }

        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $filename     = basename( parse_url( $url, PHP_URL_PATH ) );
        $body         = wp_remote_retrieve_body( $response );

        header( 'Content-Type: ' . $content_type );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $body ) );
        echo $body;
        exit;
    }
} );

