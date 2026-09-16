<?php
/**
 * Minimaler OpenAI-kompatibler Client mit Werkzeugaufrufen gegen die Abilities.
 *
 * Bewusst ohne den Core-KI-Client: Basis-URL, Modell und Schlüssel sollen frei
 * einstellbar sein (DeepSeek, OpenAI, Mistral, Ollama, beliebige Gateways).
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spricht /chat/completions im OpenAI-Format und führt Werkzeugaufrufe aus.
 */
class WP_AI_Edit_LLM {

	/**
	 * Standardwerte des API-Zugangs.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'base_url'    => 'https://api.deepseek.com',
			'modell'      => 'deepseek-flash',
			'schluessel'  => '',
			'temperatur'  => 0.3,
			'max_runden'  => 6,
			'timeout'     => 120,
			'thinking'    => 0,
		);
	}

	/**
	 * Einstellungen des API-Zugangs.
	 *
	 * @return array
	 */
	public static function settings(): array {
		$s = get_option( 'wp_ai_edit_llm', array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array_merge( self::defaults(), $s );
	}

	/**
	 * Ist der Zugang vollständig konfiguriert?
	 *
	 * @return bool
	 */
	public static function bereit(): bool {
		$s = self::settings();
		return '' !== trim( (string) $s['base_url'] ) && '' !== trim( (string) $s['modell'] ) && '' !== trim( (string) $s['schluessel'] );
	}

	/**
	 * Schlüssel für die Anzeige maskieren.
	 *
	 * @return string
	 */
	public static function maske(): string {
		$k = (string) self::settings()['schluessel'];
		if ( strlen( $k ) < 8 ) {
			return '' === $k ? '' : '••••';
		}
		return substr( $k, 0, 4 ) . '…' . substr( $k, -4 );
	}

	/* ------------------------------------------------------------------ */
	/* Werkzeuge aus den Abilities bauen                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Wandelt einen Ability-Namen in einen für die API gültigen Funktionsnamen.
	 *
	 * @param string $ability_name Ability-Name.
	 * @return string
	 */
	public static function funktionsname( string $ability_name ): string {
		$name = str_replace( array( '/', '-' ), '_', $ability_name );
		return substr( preg_replace( '/[^a-zA-Z0-9_]/', '', $name ), 0, 64 );
	}

	/**
	 * Baut die Werkzeugdefinitionen und die Rückabbildung.
	 *
	 * @param array $ability_names Ability-Namen.
	 * @return array{0: array, 1: array}
	 */
	protected static function werkzeuge( array $ability_names ): array {
		$tools = array();
		$map   = array();

		foreach ( $ability_names as $name ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
			if ( ! $ability ) {
				continue;
			}

			$fname = self::funktionsname( $name );
			$map[ $fname ] = $name;

			$schema = $ability->get_input_schema();
			if ( ! is_array( $schema ) || empty( $schema ) ) {
				$schema = array( 'type' => 'object', 'properties' => array() );
			}
			if ( isset( $schema['properties'] ) && array() === $schema['properties'] ) {
				$schema['properties'] = new stdClass();
			}

			$tools[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $fname,
					'description' => (string) $ability->get_description(),
					'parameters'  => $schema,
				),
			);
		}

		return array( $tools, $map );
	}

	/**
	 * Führt einen Werkzeugaufruf über die Ability aus.
	 *
	 * @param string $fname     Funktionsname.
	 * @param array  $arguments Argumente.
	 * @param array  $map       Rückabbildung.
	 * @return string JSON des Ergebnisses.
	 */
	protected static function ausfuehren( string $fname, array $arguments, array $map ): string {
		if ( ! isset( $map[ $fname ] ) ) {
			return wp_json_encode( array( 'fehler' => 'Unbekanntes Werkzeug: ' . $fname ) );
		}

		$ability = wp_get_ability( $map[ $fname ] );
		if ( ! $ability ) {
			return wp_json_encode( array( 'fehler' => 'Fähigkeit nicht gefunden.' ) );
		}

		$rechte = $ability->check_permissions( $arguments );
		if ( is_wp_error( $rechte ) ) {
			return wp_json_encode( array( 'fehler' => $rechte->get_error_message() ) );
		}
		if ( true !== $rechte && ! is_wp_error( $rechte ) ) {
			return wp_json_encode( array( 'fehler' => 'Keine Berechtigung für dieses Werkzeug.' ) );
		}

		try {
			$ergebnis = $ability->execute( $arguments );
		} catch ( Throwable $e ) {
			return wp_json_encode( array( 'fehler' => $e->getMessage() ) );
		}

		if ( is_wp_error( $ergebnis ) ) {
			return wp_json_encode( array( 'fehler' => $ergebnis->get_error_message() ) );
		}

		$json = wp_json_encode( $ergebnis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! $json ) {
			$json = wp_json_encode( array( 'ergebnis' => 'nicht serialisierbar' ) );
		}
		// Antworten begrenzen, damit der Kontext nicht überläuft.
		if ( strlen( $json ) > 24000 ) {
			$json = substr( $json, 0, 24000 ) . ' …(gekürzt)';
		}

		return $json;
	}

	/* ------------------------------------------------------------------ */
	/* Anfrage                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Sendet ein Gespräch und führt Werkzeugaufrufe so lange aus, bis das
	 * Modell eine Textantwort liefert.
	 *
	 * @param array $messages      Nachrichten im OpenAI-Format.
	 * @param array $ability_names Erlaubte Fähigkeiten.
	 * @return string|WP_Error
	 */
	public static function chat( array $messages, array $ability_names ) {
		$s = self::settings();

		if ( ! self::bereit() ) {
			return new WP_Error( 'wpaie_llm', __( 'Kein API-Zugang konfiguriert (Basis-URL, Modell, Schlüssel).', 'wp-ai-edit' ) );
		}

		list( $tools, $map ) = self::werkzeuge( $ability_names );

		$url = rtrim( (string) $s['base_url'], '/' ) . '/chat/completions';
		$verlauf = array_values( $messages );
		$runden  = 0;
		$max     = max( 1, (int) $s['max_runden'] );
		$benutzt = array();

		while ( $runden < $max ) {
			++$runden;

			$koerper = array(
				'model'       => (string) $s['modell'],
				'messages'    => $verlauf,
				'temperature' => (float) $s['temperatur'],
			);

			if ( ! empty( $tools ) ) {
				$koerper['tools']       = $tools;
				$koerper['tool_choice'] = 'auto';
			}
			if ( ! empty( $s['thinking'] ) ) {
				$koerper['thinking'] = array( 'type' => 'enabled' );
			}

			$antwort = wp_remote_post(
				$url,
				array(
					'timeout'     => (int) $s['timeout'],
					'headers'     => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . (string) $s['schluessel'],
						'Accept'        => 'application/json',
					),
					'body'        => wp_json_encode( $koerper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'data_format' => 'body',
				)
			);

			if ( is_wp_error( $antwort ) ) {
				return new WP_Error( 'wpaie_netz', __( 'Verbindung fehlgeschlagen: ', 'wp-ai-edit' ) . $antwort->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $antwort );
			$roh  = (string) wp_remote_retrieve_body( $antwort );
			$daten = json_decode( $roh, true );

			if ( $code < 200 || $code >= 300 ) {
				$meldung = '';
				if ( is_array( $daten ) ) {
					$meldung = $daten['error']['message'] ?? ( $daten['message'] ?? '' );
				}
				return new WP_Error(
					'wpaie_api',
					sprintf(
						/* translators: 1: HTTP-Status, 2: Fehlermeldung */
						__( 'API-Fehler HTTP %1$d: %2$s', 'wp-ai-edit' ),
						$code,
						'' !== $meldung ? $meldung : substr( $roh, 0, 300 )
					)
				);
			}

			if ( ! is_array( $daten ) || ! isset( $daten['choices'][0]['message'] ) ) {
				return new WP_Error( 'wpaie_format', __( 'Unerwartete Antwortstruktur der API.', 'wp-ai-edit' ) );
			}

			$nachricht = $daten['choices'][0]['message'];
			$aufrufe   = $nachricht['tool_calls'] ?? array();

			// Keine Werkzeugaufrufe -> fertige Antwort.
			if ( empty( $aufrufe ) ) {
				$text = (string) ( $nachricht['content'] ?? '' );
				if ( '' === trim( $text ) ) {
					$text = __( '(leere Antwort des Modells)', 'wp-ai-edit' );
				}
				if ( ! empty( $benutzt ) ) {
					$text .= "\n\n— Werkzeuge: " . implode( ', ', array_unique( $benutzt ) );
				}
				return $text;
			}

			// Assistentennachricht mit den Aufrufen in den Verlauf.
			$verlauf[] = array(
				'role'       => 'assistant',
				'content'    => $nachricht['content'] ?? '',
				'tool_calls' => $aufrufe,
			);

			foreach ( $aufrufe as $aufruf ) {
				$fname = (string) ( $aufruf['function']['name'] ?? '' );
				$args  = json_decode( (string) ( $aufruf['function']['arguments'] ?? '{}' ), true );
				$args  = is_array( $args ) ? $args : array();

				$benutzt[] = $map[ $fname ] ?? $fname;

				$verlauf[] = array(
					'role'         => 'tool',
					'tool_call_id' => (string) ( $aufruf['id'] ?? '' ),
					'name'         => $fname,
					'content'      => self::ausfuehren( $fname, $args, $map ),
				);
			}
		}

		return new WP_Error(
			'wpaie_runden',
			sprintf(
				/* translators: %d: Anzahl Runden */
				__( 'Abbruch nach %d Werkzeugrunden. Bitte die Anweisung enger fassen.', 'wp-ai-edit' ),
				$max
			)
		);
	}

	/**
	 * Verbindungstest gegen die API.
	 *
	 * @return array|WP_Error
	 */
	public static function test() {
		$antwort = self::chat(
			array(
				array( 'role' => 'system', 'content' => 'Antworte mit genau einem Wort.' ),
				array( 'role' => 'user', 'content' => 'Sag OK.' ),
			),
			array()
		);

		if ( is_wp_error( $antwort ) ) {
			return $antwort;
		}
		return array( 'antwort' => trim( (string) $antwort ), 'modell' => self::settings()['modell'] );
	}
}
