<?php
/**
 * Plugin Name: Das Rind – Double-Opt-in-Schutz
 * Description: Verhindert, dass Link-Scanner (Microsoft Safe Links, Google u. a.) den Double-Opt-in-Bestaetigungslink vorab aufrufen und damit den Token verbrauchen. Verlangt einen echten Klick und ersetzt die englischen Fluent-Forms-Standardmeldungen durch verstaendliche deutsche Texte.
 * Version:     1.0.0
 * Author:      Das Rind
 *
 * Ablage: wp-content/mu-plugins/dasrind-doi-guard.php
 *
 * Hintergrund
 * -----------
 * Fluent Forms Pro baut den Bestaetigungslink als
 *     https://<domain>/?ff_landing=<Formular-ID>&entry_confirmation=<Hash>
 * (fluentformpro/src/classes/DoubleOptin.php, Zeile ~209).
 *
 * Verarbeitet wird er in
 *     fluentformpro/src/classes/SharePage/SharePage.php -> renderLandingForm()
 * das per add_action('wp', ...) haengt und dann
 *     do_action('fluentform/entry_confirmation', $requestData)
 * ausloest. DoubleOptin::confirmSubmission() setzt daraufhin den Eintrag auf
 * "confirmed" und feuert alle Formular-Aktionen (Benachrichtigungen, Brevo).
 *
 * Ein einfacher GET-Aufruf reicht also aus, um die Bestaetigung abzuschliessen.
 * Genau das machen Microsoft Safe Links und die Scanner von Google/Gmail: Sie
 * rufen jeden Link in einer eingehenden Mail vorab auf. Der Token ist damit
 * verbraucht, bevor der Mensch klickt – und im Fluent-Forms-Log landet die
 * IP-Adresse des Scanners statt der des Abonnenten.
 *
 * Dieses mu-Plugin haengt sich frueher ein (init, Prioritaet 0) und laesst den
 * Aufruf nur durch, wenn er per POST aus einem echten Klick stammt. Scanner
 * folgen Links per GET/HEAD, senden aber keine Formulare ab.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class DasRind_DOI_Guard
{
    /** Name des Feldes, das den echten Klick markiert. */
    const FIELD = 'dasrind_doi_confirm';

    /* ------------------------------------------------------------------ *
     * Texte – hier anpassen
     * ------------------------------------------------------------------ */

    /** Zwischenseite */
    const PAGE_TITLE   = 'Newsletter-Anmeldung bestätigen';
    const HEADLINE     = 'Nur noch ein Klick';
    const INTRO        = 'Bitte bestätige deine Anmeldung zum Newsletter von Das Rind mit einem Klick auf den Button.';
    const BUTTON_LABEL = 'Anmeldung jetzt bestätigen';
    const FOOTNOTE     = 'Du hast dich nicht angemeldet? Dann schließe dieses Fenster einfach – ohne den Klick auf den Button passiert nichts.';

    /** Meldung, wenn die Bestätigung schon erfolgt ist */
    const ALREADY_HEADLINE = 'Deine Anmeldung ist bereits bestätigt';
    const ALREADY_TEXT     = 'Diese Bestätigung wurde bereits durchgeführt und ist aus Sicherheitsgründen nur einmal möglich. Du bist eingetragen und musst nichts weiter tun.';
    const ALREADY_HINT     = 'Das passiert manchmal, wenn dein E-Mail-Anbieter Links in Nachrichten automatisch auf Sicherheit prüft und den Bestätigungslink dabei schon aufruft.';

    /** Meldung, wenn der Link ungültig oder abgelaufen ist */
    const INVALID_HEADLINE = 'Dieser Bestätigungslink ist nicht mehr gültig';
    const INVALID_TEXT     = 'Der Link ist abgelaufen oder wurde bereits verwendet. Bitte melde dich einfach noch einmal über das Formular auf unserer Website an.';

    /** Optional: eigene WordPress-Seiten statt der schlichten Textausgabe.
     *  Leer lassen = eingebaute Darstellung verwenden. */
    const ALREADY_REDIRECT_URL = '';
    const INVALID_REDIRECT_URL = '';

    /** Farben */
    const COLOR_ACCENT = '#111111';
    const COLOR_TEXT   = '#1a1a1a';
    const COLOR_MUTED  = '#666666';

    /* ------------------------------------------------------------------ */

    public static function boot()
    {
        // Muss vor SharePage::renderLandingForm() laufen (haengt an 'wp').
        add_action('init', [__CLASS__, 'maybeInterrupt'], 0);

        add_filter('fluentform/double_optin_already_confirmed_message', [__CLASS__, 'alreadyConfirmedMessage'], 10, 1);
        add_filter('fluentform/double_optin_invalid_confirmation_url_message', [__CLASS__, 'invalidUrlMessage'], 10, 1);
    }

    /**
     * Faengt den Bestaetigungsaufruf ab, solange kein echter Klick vorliegt.
     */
    public static function maybeInterrupt()
    {
        if (is_admin()
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('WP_CLI') && WP_CLI)
        ) {
            return;
        }

        if (empty($_GET['ff_landing']) || empty($_GET['entry_confirmation'])) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        // Scanner pruefen haeufig nur die Erreichbarkeit per HEAD.
        if ('HEAD' === $method) {
            status_header(200);
            exit;
        }

        // Echter Klick auf den Button -> normal weiterlaufen lassen.
        if ('POST' === $method && !empty($_POST[self::FIELD])) {
            return;
        }

        self::renderInterstitial();
    }

    /**
     * Zwischenseite mit Bestaetigungs-Button.
     */
    private static function renderInterstitial()
    {
        $action = add_query_arg(
            [
                'ff_landing'         => (int) $_GET['ff_landing'],
                'entry_confirmation' => sanitize_text_field(wp_unslash($_GET['entry_confirmation'])),
            ],
            home_url('/')
        );

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        status_header(200);

        $lang = esc_attr(get_bloginfo('language'));
        ?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html(self::PAGE_TITLE); ?></title>
<style>
    :root { color-scheme: light dark; }
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        line-height: 1.6;
        color: <?php echo esc_html(self::COLOR_TEXT); ?>;
        background: #f6f6f6;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 1.5rem;
        box-sizing: border-box;
    }
    .box {
        background: #fff;
        max-width: 34rem;
        width: 100%;
        padding: 2.5rem 2rem;
        border-radius: 12px;
        box-shadow: 0 2px 16px rgba(0,0,0,.08);
        text-align: center;
    }
    h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
    p  { margin: 0 0 1.5rem; }
    .btn {
        display: inline-block;
        border: 0;
        cursor: pointer;
        background: <?php echo esc_html(self::COLOR_ACCENT); ?>;
        color: #fff;
        font-size: 1rem;
        font-weight: 600;
        padding: .9rem 1.75rem;
        border-radius: 6px;
        text-decoration: none;
        font-family: inherit;
    }
    .btn:hover { opacity: .88; }
    .note { font-size: .875rem; color: <?php echo esc_html(self::COLOR_MUTED); ?>; margin: 1.75rem 0 0; }
</style>
</head>
<body>
<div class="box">
    <h1><?php echo esc_html(self::HEADLINE); ?></h1>
    <p><?php echo esc_html(self::INTRO); ?></p>
    <form method="post" action="<?php echo esc_url($action); ?>">
        <input type="hidden" name="<?php echo esc_attr(self::FIELD); ?>" value="1">
        <button type="submit" class="btn"><?php echo esc_html(self::BUTTON_LABEL); ?></button>
    </form>
    <p class="note"><?php echo esc_html(self::FOOTNOTE); ?></p>
</div>
</body>
</html>
        <?php
        exit;
    }

    /**
     * Ersetzt "The confirmation of the double opt-in has already been carried out ...".
     */
    public static function alreadyConfirmedMessage($message)
    {
        if (self::ALREADY_REDIRECT_URL) {
            wp_safe_redirect(self::ALREADY_REDIRECT_URL);
            exit;
        }

        return self::messageMarkup(
            self::ALREADY_HEADLINE,
            self::ALREADY_TEXT,
            self::ALREADY_HINT
        );
    }

    /**
     * Ersetzt "Sorry! The confirmation URL is invalid or has expired ...".
     */
    public static function invalidUrlMessage($message)
    {
        if (self::INVALID_REDIRECT_URL) {
            wp_safe_redirect(self::INVALID_REDIRECT_URL);
            exit;
        }

        return self::messageMarkup(
            self::INVALID_HEADLINE,
            self::INVALID_TEXT,
            ''
        );
    }

    /**
     * Fluent Forms gibt die Meldung per die(wp_kses_post($msg)) aus.
     * wp_kses_post entfernt <style>- und <html>-Tags, erlaubt aber
     * style-Attribute – deshalb alles inline.
     */
    private static function messageMarkup($headline, $text, $hint)
    {
        $wrap = 'max-width:34rem;margin:4rem auto;padding:2.5rem 2rem;background:#fff;'
              . 'border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);text-align:center;'
              . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;'
              . 'line-height:1.6;color:' . self::COLOR_TEXT . ';';

        $out  = '<div style="' . esc_attr($wrap) . '">';
        $out .= '<h1 style="font-size:1.5rem;margin:0 0 .75rem;">' . esc_html($headline) . '</h1>';
        $out .= '<p style="margin:0;">' . esc_html($text) . '</p>';

        if ($hint) {
            $out .= '<p style="' . esc_attr('font-size:.875rem;color:' . self::COLOR_MUTED . ';margin:1.75rem 0 0;') . '">'
                  . esc_html($hint) . '</p>';
        }

        $out .= '<p style="margin:2rem 0 0;"><a href="' . esc_url(home_url('/')) . '" style="color:'
              . esc_attr(self::COLOR_ACCENT) . ';">Zur Startseite</a></p>';
        $out .= '</div>';

        return $out;
    }
}

DasRind_DOI_Guard::boot();
