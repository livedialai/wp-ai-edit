<?php
/**
 * Meldung an die Sammelstelle admin.gomeetme.de.
 *
 * Beim Aktivieren meldet sich das Plugin einmalig bei der GoMeetMe-Sammelstelle
 * an, damit der Betreiber sieht, wo es installiert ist. Gleiche Bauart wie bei
 * den übrigen Plugins (Mistral Voice Agent, GoMeetMe, gomeetme-pro).
 *
 * Abschaltbar: Einstellungen → WP AI Edit → Mitarbeit, oder über die Konstante
 * WPAIE_MELDUNG in wp-config.php.
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meldung bei der Sammelstelle.
 */
class WP_AI_Edit_Meldung {

	/**
	 * Adresse der Sammelstelle.
	 */
	public const ENDPUNKT = 'https://admin.gomeetme.de/wp-json/gomeetme/v1/activate';

	/**
	 * Gemeinsames Geheimnis der Sammelstelle.
	 */
	public const GEHEIMNIS = 'gomeetme_secret_2026';

	/**
	 * Kennung, unter der dieses Plugin in der Liste erscheint.
	 */
	public const KENNUNG = 'WP-AI-Edit';

	/**
	 * Ist die Meldung eingeschaltet?
	 *
	 * @return bool
	 */
	public static function eingeschaltet(): bool {
		if ( defined( 'WPAIE_MELDUNG' ) && ! WPAIE_MELDUNG ) {
			return false;
		}
		$s = WP_AI_Edit::settings();
		return ! empty( $s['melden'] );
	}

	/**
	 * Adresse der Sammelstelle, änderbar per Filter.
	 *
	 * @return string
	 */
	public static function endpunkt(): string {
		return (string) apply_filters( 'wpaie_meldung_endpunkt', self::ENDPUNKT );
	}

	/**
	 * Geheimnis, änderbar per Filter oder Konstante.
	 *
	 * @return string
	 */
	public static function geheimnis(): string {
		$g = defined( 'WPAIE_MELDUNG_GEHEIMNIS' ) ? (string) WPAIE_MELDUNG_GEHEIMNIS : self::GEHEIMNIS;
		return (string) apply_filters( 'wpaie_meldung_geheimnis', $g );
	}

	/**
	 * Meldet die Aktivierung.
	 *
	 * @param bool $warten Auf die Antwort warten? Beim Seitenaufbau im Backend
	 *                     nicht, damit die Seite nicht hängt.
	 * @return array|WP_Error Antwort oder Fehler. Ohne Warten ein leeres Ergebnis.
	 */
	public static function melden( bool $warten = true ) {
		if ( ! self::eingeschaltet() ) {
			return new WP_Error( 'wpaie_meldung_aus', __( 'Die Meldung ist abgeschaltet.', 'wp-ai-edit' ) );
		}

		$antwort = wp_remote_post(
			self::endpunkt(),
			array(
				'timeout'     => 15,
				'blocking'    => $warten,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'homepage'       => home_url(),
					'admin_email'    => (string) get_option( 'admin_email', '' ),
					'activated_at'   => current_time( 'mysql' ),
					'plugin_version' => defined( 'WPAIE_VERSION' ) ? WPAIE_VERSION : '',
					'plugin_type'    => self::KENNUNG,
					'secret'         => self::geheimnis(),
				),
			)
		);

		// Ohne Warten gibt es keine Antwort — nur das Absetzen zählt.
		if ( ! $warten ) {
			return array(
				'ok'       => true,
				'code'     => 0,
				'text'     => __( 'Meldung abgeschickt (ohne Rückmeldung abzuwarten).', 'wp-ai-edit' ),
				'endpunkt' => self::endpunkt(),
			);
		}

		if ( is_wp_error( $antwort ) ) {
			self::merken( 'Fehler: ' . $antwort->get_error_message(), false );
			return $antwort;
		}

		$code = (int) wp_remote_retrieve_response_code( $antwort );
		$body = json_decode( wp_remote_retrieve_body( $antwort ), true );
		$ok   = $code >= 200 && $code < 300;

		$text = $ok
			? sprintf(
				/* translators: 1: HTTP-Code, 2: Meldung der Sammelstelle */
				__( 'Angemeldet (HTTP %1$d): %2$s', 'wp-ai-edit' ),
				$code,
				(string) ( $body['message'] ?? 'ok' )
			)
			: sprintf(
				/* translators: 1: HTTP-Code, 2: Meldung */
				__( 'Abgelehnt (HTTP %1$d): %2$s', 'wp-ai-edit' ),
				$code,
				(string) ( $body['message'] ?? mb_substr( (string) wp_remote_retrieve_body( $antwort ), 0, 200 ) )
			);

		self::merken( $text, $ok );

		return array(
			'ok'      => $ok,
			'code'    => $code,
			'text'    => $text,
			'endpunkt' => self::endpunkt(),
		);
	}

	/**
	 * Letztes Meldeergebnis speichern, damit es in den Einstellungen sichtbar ist.
	 *
	 * @param string $text Meldung.
	 * @param bool   $ok   Erfolgreich?
	 * @return void
	 */
	protected static function merken( string $text, bool $ok ): void {
		update_option(
			'wp_ai_edit_meldung',
			array(
				'zeit' => current_time( 'mysql' ),
				'ok'   => $ok,
				'text' => $text,
			),
			false
		);
	}

	/**
	 * Zuletzt gemeldetes Ergebnis.
	 *
	 * @return array
	 */
	public static function letzte(): array {
		$v = get_option( 'wp_ai_edit_meldung', array() );
		return is_array( $v ) ? $v : array();
	}
}
