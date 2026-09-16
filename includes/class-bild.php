<?php
/**
 * Bildgenerierung über WaveSpeed (oder einen anderen kompatiblen Dienst).
 *
 * Ablauf: Auftrag abschicken, auf das Ergebnis warten, Bild in die Mediathek
 * legen. Nichts davon verändert eine Seite — das passiert erst über einen
 * Vorschlag.
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verbindung zum Bilddienst und Übernahme in die Mediathek.
 */
class WP_AI_Edit_Bild {

	/**
	 * Vorgabe-Adresse des Dienstes.
	 */
	public const BASIS = 'https://api.wavespeed.ai/api/v3';

	/**
	 * Vorgabe-Modell: Seedream 4.5, Text zu Bild.
	 */
	public const MODELL = 'bytedance/seedream-v4.5';

	/**
	 * Vorgabe-Maße.
	 */
	public const GROESSE = '2048*2048';

	/**
	 * Einstellungen holen.
	 *
	 * @return array
	 */
	protected static function s(): array {
		return WP_AI_Edit::settings();
	}

	/**
	 * Ist die Bildfunktion einsatzbereit?
	 *
	 * @return bool
	 */
	public static function bereit(): bool {
		$s = self::s();
		return ! empty( $s['bild_key'] );
	}

	/**
	 * Schlüssel.
	 *
	 * @return string
	 */
	protected static function key(): string {
		$s = self::s();
		return trim( (string) ( $s['bild_key'] ?? '' ) );
	}

	/**
	 * Modell.
	 *
	 * @return string
	 */
	public static function modell(): string {
		$s = self::s();
		$m = trim( (string) ( $s['bild_modell'] ?? '' ) );
		return '' !== $m ? $m : self::MODELL;
	}

	/**
	 * Basis-Adresse ohne abschließenden Schrägstrich.
	 *
	 * @return string
	 */
	public static function basis(): string {
		$s = self::s();
		$b = trim( (string) ( $s['bild_base'] ?? '' ) );
		return '' !== $b ? rtrim( $b, '/' ) : self::BASIS;
	}

	/**
	 * Fehlermeldung des Dienstes aus einer Antwort ziehen.
	 *
	 * @param mixed $body Antwortkörper.
	 * @return string
	 */
	protected static function fehlertext( $body ): string {
		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			return (string) $body['message'];
		}
		return is_string( $body ) ? mb_substr( $body, 0, 300 ) : '';
	}

	/**
	 * Auftrag abschicken.
	 *
	 * @param string $prompt  Beschreibung.
	 * @param string $groesse Maße, z. B. 2048*2048.
	 * @param string $modell  Abweichendes Modell.
	 * @return string|WP_Error Auftrags-ID.
	 */
	public static function erzeugen( string $prompt, string $groesse = '', string $modell = '' ) {
		if ( ! self::bereit() ) {
			return new WP_Error(
				'wpaie_bild_key',
				__( 'Kein Schlüssel für die Bildgenerierung hinterlegt. Einstellungen → WP AI Edit → Bildgenerierung.', 'wp-ai-edit' )
			);
		}

		$s = self::s();
		$masse = '' !== trim( $groesse ) ? trim( $groesse ) : (string) ( $s['bild_groesse'] ?? self::GROESSE );
		$model = '' !== trim( $modell ) ? trim( $modell ) : self::modell();

		$antwort = wp_remote_post(
			self::basis() . '/' . ltrim( $model, '/' ),
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'prompt' => $prompt,
						'size'   => $masse,
					)
				),
			)
		);

		if ( is_wp_error( $antwort ) ) {
			return $antwort;
		}

		$code = (int) wp_remote_retrieve_response_code( $antwort );
		$body = json_decode( wp_remote_retrieve_body( $antwort ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'wpaie_bild_http',
				sprintf(
					/* translators: 1: HTTP-Code, 2: Meldung */
					__( 'Bilddienst antwortete mit HTTP %1$d: %2$s', 'wp-ai-edit' ),
					$code,
					self::fehlertext( $body )
				)
			);
		}

		$id = $body['data']['id'] ?? '';
		if ( '' === $id ) {
			return new WP_Error( 'wpaie_bild_id', __( 'Der Bilddienst hat keine Auftrags-ID geliefert.', 'wp-ai-edit' ) );
		}

		return (string) $id;
	}

	/**
	 * Auf das Ergebnis warten.
	 *
	 * @param string $auftrag   Auftrags-ID.
	 * @param int    $sekunden  Höchstdauer.
	 * @return string|WP_Error Bild-URL.
	 */
	public static function warten( string $auftrag, int $sekunden = 45 ) {
		$ende = time() + max( 5, $sekunden );

		while ( time() < $ende ) {
			$antwort = wp_remote_get(
				self::basis() . '/predictions/' . rawurlencode( $auftrag ) . '/result',
				array(
					'timeout' => 25,
					'headers' => array( 'Authorization' => 'Bearer ' . self::key() ),
				)
			);

			if ( is_wp_error( $antwort ) ) {
				return $antwort;
			}

			$body = json_decode( wp_remote_retrieve_body( $antwort ), true );
			$d    = $body['data'] ?? array();
			$st   = (string) ( $d['status'] ?? '' );

			if ( 'completed' === $st ) {
				$url = $d['outputs'][0] ?? '';
				if ( '' === $url ) {
					return new WP_Error( 'wpaie_bild_leer', __( 'Der Auftrag ist fertig, hat aber kein Bild geliefert.', 'wp-ai-edit' ) );
				}
				return (string) $url;
			}

			if ( in_array( $st, array( 'failed', 'cancelled', 'timeout' ), true ) ) {
				return new WP_Error(
					'wpaie_bild_fehl',
					sprintf(
						/* translators: 1: Status, 2: Meldung */
						__( 'Bildauftrag %1$s: %2$s', 'wp-ai-edit' ),
						$st,
						(string) ( $d['error'] ?? '' )
					)
				);
			}

			sleep( 3 );
		}

		return new WP_Error(
			'wpaie_bild_laeuft',
			sprintf(
				/* translators: %s: Auftrags-ID */
				__( 'Der Auftrag %s läuft noch. Mit „Bildauftrag abholen" nachfassen.', 'wp-ai-edit' ),
				$auftrag
			)
		);
	}

	/**
	 * Bild in die Mediathek übernehmen.
	 *
	 * @param string $url   Bild-URL.
	 * @param string $titel Titel und Alternativtext.
	 * @return int|WP_Error Anhang-ID.
	 */
	public static function in_mediathek( string $url, string $titel = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$pfad = wp_parse_url( $url, PHP_URL_PATH );
		$name = $pfad ? basename( $pfad ) : 'bild.jpg';
		if ( '' === trim( $titel ) ) {
			$titel = __( 'KI-Bild', 'wp-ai-edit' ) . ' ' . gmdate( 'Y-m-d H:i' );
		}
		// Sinnvoller Dateiname aus dem Titel.
		$sauber = sanitize_title( $titel );
		if ( '' !== $sauber ) {
			$name = $sauber . '.' . pathinfo( $name, PATHINFO_EXTENSION );
		}

		$tmp = download_url( $url, 90 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$datei = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $datei, 0, $titel );

		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return $id;
		}

		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => $titel,
				'post_excerpt' => $titel,
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', $titel );
		update_post_meta( $id, '_wpaie_quelle', 'wavespeed' );

		return (int) $id;
	}

	/**
	 * Guthaben abfragen.
	 *
	 * @return array|WP_Error
	 */
	public static function guthaben() {
		if ( ! self::bereit() ) {
			return new WP_Error( 'wpaie_bild_key', __( 'Kein Schlüssel hinterlegt.', 'wp-ai-edit' ) );
		}
		$antwort = wp_remote_get(
			self::basis() . '/balance',
			array(
				'timeout' => 25,
				'headers' => array( 'Authorization' => 'Bearer ' . self::key() ),
			)
		);
		if ( is_wp_error( $antwort ) ) {
			return $antwort;
		}
		$code = (int) wp_remote_retrieve_response_code( $antwort );
		$body = json_decode( wp_remote_retrieve_body( $antwort ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'wpaie_bild_http',
				sprintf(
					/* translators: 1: HTTP-Code, 2: Meldung */
					__( 'HTTP %1$d: %2$s', 'wp-ai-edit' ),
					$code,
					self::fehlertext( $body )
				)
			);
		}

		return array(
			'guthaben' => $body['data']['balance'] ?? null,
			'modell'   => self::modell(),
			'basis'    => self::basis(),
		);
	}
}
