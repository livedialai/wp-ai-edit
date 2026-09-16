<?php
/**
 * REST-Schnittstelle des Widgets. Ausschließlich für angemeldete Nutzer.
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat-Endpunkt im Backend.
 */
class WP_AI_Edit_REST {

	/**
	 * Namespace der Routen.
	 */
	public const NAMESPACE = 'wp-ai-edit/v1';

	/**
	 * Fähigkeiten im normalen Chat (nur lesend).
	 *
	 * @return array
	 */
	protected static function abilities_chat(): array {
		return array(
			'kiedit/inspect-site',
			'kiedit/list-plugins',
			'kiedit/fetch-design',
			'kiedit/get-page',
			'core/get-site-info',
		);
	}

	/**
	 * Fähigkeiten im Bearbeitungsmodus.
	 *
	 * @return array
	 */
	protected static function abilities_edit(): array {
		return array(
			'kiedit/inspect-site',
			'kiedit/list-plugins',
			'kiedit/fetch-design',
			'kiedit/create-page',
			'kiedit/update-page',
			'kiedit/set-options',
			'kiedit/set-plugin-setting',
			'kiedit/install-plugin',
			'kiedit/toggle-plugin',
			'kiedit/snapshot',
			'kiedit/rollback',
			'kiedit/list-pending',
			'kiedit/apply-pending',
			'kiedit/discard-pending',
			'kiedit/get-page',
			'kiedit/replace-text',
			'kiedit/generate-image',
			'kiedit/image-status',
		);
	}

	/**
	 * Routen registrieren.
	 *
	 * @return void
	 */
	public static function routen(): void {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => array( __CLASS__, 'nur_backend' ),
				'args'                => array(
					'nachricht' => array( 'type' => 'string', 'required' => true ),
					'modus'     => array( 'type' => 'string', 'default' => 'chat' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'reset' ),
				'permission_callback' => array( __CLASS__, 'nur_backend' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'nur_backend' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'pending' ),
				'permission_callback' => array( __CLASS__, 'nur_backend' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pending/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pending_apply' ),
				'permission_callback' => array( __CLASS__, 'nur_backend' ),
				'args'                => array( 'id' => array( 'type' => 'string', 'required' => true ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pending/discard',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pending_discard' ),
				'permission_callback' => array( __CLASS__, 'nur_backend' ),
				'args'                => array( 'id' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
	}

	/**
	 * Offene Vorschläge auflisten.
	 *
	 * @return WP_REST_Response
	 */
	public static function pending() {
		$liste = array();
		foreach ( WP_AI_Edit_Workflow::alle() as $v ) {
			$liste[] = array(
				'id'           => $v['id'],
				'art'          => $v['art'],
				'beschreibung' => $v['text'],
				'vorschau'     => $v['vorschau'],
				'zeit'         => $v['zeit'],
			);
		}
		return rest_ensure_response(
			array(
				'anzahl'     => count( $liste ),
				'vorschlaege' => $liste,
				'arbeitsweise' => WP_AI_Edit_Workflow::modus(),
			)
		);
	}

	/**
	 * Vorschlag übernehmen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pending_apply( WP_REST_Request $request ) {
		$r = WP_AI_Edit_Workflow::anwenden( sanitize_text_field( (string) $request->get_param( 'id' ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( $r );
	}

	/**
	 * Vorschlag verwerfen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pending_discard( WP_REST_Request $request ) {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		if ( ! WP_AI_Edit_Workflow::verwerfen( $id ) ) {
			return new WP_Error( 'wpaie_vorschlag', __( 'Vorschlag nicht gefunden.', 'wp-ai-edit' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'verworfen' => true, 'id' => $id ) );
	}

	/**
	 * Verbindungstest gegen die konfigurierte API.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test() {
		$ergebnis = WP_AI_Edit_LLM::test();
		if ( is_wp_error( $ergebnis ) ) {
			return $ergebnis;
		}
		return rest_ensure_response( $ergebnis );
	}

	/**
	 * Nur angemeldete Nutzer mit ausreichender Berechtigung.
	 *
	 * @return bool|WP_Error
	 */
	public static function nur_backend() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'wpaie_anmeldung', __( 'Nicht angemeldet.', 'wp-ai-edit' ), array( 'status' => 401 ) );
		}
		$s = WP_AI_Edit::settings();
		if ( ! current_user_can( $s['min_capability'] ) ) {
			return new WP_Error( 'wpaie_rechte', __( 'Keine Berechtigung.', 'wp-ai-edit' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Verlaufsspeicher-Schlüssel.
	 *
	 * @return string
	 */
	protected static function verlauf_key(): string {
		return 'wpaie_verlauf_' . get_current_user_id();
	}

	/**
	 * Verlauf laden.
	 *
	 * @return array
	 */
	protected static function verlauf(): array {
		$v = get_transient( self::verlauf_key() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Verlauf speichern.
	 *
	 * @param array $verlauf Verlauf.
	 * @return void
	 */
	protected static function verlauf_speichern( array $verlauf ): void {
		$s = WP_AI_Edit::settings();
		set_transient( self::verlauf_key(), array_slice( $verlauf, -1 * (int) $s['history_limit'] ), 4 * HOUR_IN_SECONDS );
	}

	/**
	 * Systemanweisung laden: zuerst die im Backend gespeicherte Fassung,
	 * sonst die mitgelieferte Datei, sonst der eingebaute Standard.
	 *
	 * @param string $modus chat|edit.
	 * @return string
	 */
	protected static function systemprompt( string $modus ): string {
		$s    = WP_AI_Edit::settings();
		$key  = ( 'edit' === $modus ) ? 'prompt_edit' : 'prompt_chat';
		$eigen = isset( $s[ $key ] ) ? trim( (string) $s[ $key ] ) : '';

		if ( '' !== $eigen ) {
			return $eigen;
		}

		$datei = WPAIE_DIR . 'prompts/' . ( 'edit' === $modus ? 'editsite.md' : 'chat.md' );

		if ( file_exists( $datei ) ) {
			$inhalt = (string) file_get_contents( $datei );
			if ( '' !== trim( $inhalt ) ) {
				return $inhalt;
			}
		}

		return WP_AI_Edit::prompt_standard( $modus );
	}

	/**
	 * Chat-Anfrage verarbeiten.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function chat( WP_REST_Request $request ) {
		$nachricht = trim( (string) $request->get_param( 'nachricht' ) );
		$modus     = (string) $request->get_param( 'modus' );
		$modus     = in_array( $modus, array( 'chat', 'edit' ), true ) ? $modus : 'chat';

		if ( '' === $nachricht ) {
			return new WP_Error( 'wpaie_leer', __( 'Leere Nachricht.', 'wp-ai-edit' ), array( 'status' => 400 ) );
		}

		// ---- Befehle -----------------------------------------------------
		if ( '/' === substr( $nachricht, 0, 1 ) ) {
			$befehl = strtolower( trim( strtok( $nachricht, ' ' ) ) );
			switch ( $befehl ) {
				case '/editsite':
					if ( ! current_user_can( 'edit_pages' ) ) {
						return new WP_Error( 'wpaie_rechte', __( 'Keine Berechtigung für den Bearbeitungsmodus.', 'wp-ai-edit' ), array( 'status' => 403 ) );
					}
					update_user_meta( get_current_user_id(), 'wpaie_modus', 'edit' );
					return rest_ensure_response(
						array(
							'modus'    => 'edit',
							'befehl'   => true,
							'antwort'  => __( "Bearbeitungsmodus aktiv.\nIch darf jetzt Seiten anlegen und ändern, Einstellungen setzen und Plugins installieren. Vor jeder Änderung lege ich eine Sicherung an. Mit /normal geht es zurück in den Nur-Lese-Modus.", 'wp-ai-edit' ),
							'abilities' => count( self::abilities_edit() ),
						)
					);

				case '/normal':
				case '/chat':
					update_user_meta( get_current_user_id(), 'wpaie_modus', 'chat' );
					return rest_ensure_response(
						array(
							'modus'   => 'chat',
							'befehl'  => true,
							'antwort' => __( 'Zurück im Nur-Lese-Modus. Ich berate, ändere aber nichts.', 'wp-ai-edit' ),
						)
					);

				case '/reset':
					delete_transient( self::verlauf_key() );
					return rest_ensure_response(
						array(
							'modus'   => $modus,
							'befehl'  => true,
							'antwort' => __( 'Verlauf gelöscht.', 'wp-ai-edit' ),
						)
					);

				case '/inspect':
					$daten = WP_AI_Edit_Abilities::cb_site_einlesen( array() );
					return rest_ensure_response(
						array(
							'modus'   => $modus,
							'befehl'  => true,
							'antwort' => wp_json_encode( $daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						)
					);

				case '/hilfe':
					return rest_ensure_response(
						array(
							'modus'   => $modus,
							'befehl'  => true,
							'antwort' => __( "/editsite – Bearbeitungsmodus aktivieren\n/normal – zurück in den Nur-Lese-Modus\n/inspect – Zustand der Website ausgeben\n/reset – Verlauf löschen", 'wp-ai-edit' ),
						)
					);
			}
		}

		// ---- Modus aus dem Nutzerprofil ---------------------------------
		$gewaehlt   = get_user_meta( get_current_user_id(), 'wpaie_modus', true );
		$echter_modus = ( 'edit' === $gewaehlt && 'chat' === $modus ) || 'edit' === $modus ? 'edit' : 'chat';

		// ---- Zugang prüfen ----------------------------------------------
		if ( ! WP_AI_Edit_LLM::bereit() ) {
			return new WP_Error(
				'wpaie_llm',
				__( 'Kein API-Zugang hinterlegt. Bitte unter Einstellungen → WP AI Edit Basis-URL, Modell und Schlüssel eintragen.', 'wp-ai-edit' ),
				array( 'status' => 503 )
			);
		}

		// ---- Nachrichten im OpenAI-Format bauen --------------------------
		$verlauf     = self::verlauf();
		$nachrichten = array(
			array(
				'role'    => 'system',
				'content' => self::systemprompt( $echter_modus ),
			),
		);
		foreach ( $verlauf as $eintrag ) {
			$nachrichten[] = array(
				'role'    => ( 'user' === $eintrag['rolle'] ? 'user' : 'assistant' ),
				'content' => (string) $eintrag['text'],
			);
		}
		$nachrichten[] = array( 'role' => 'user', 'content' => $nachricht );

		// ---- Aufruf mit Werkzeugschleife ---------------------------------
		$antwort = WP_AI_Edit_LLM::chat(
			$nachrichten,
			'edit' === $echter_modus ? self::abilities_edit() : self::abilities_chat()
		);

		if ( is_wp_error( $antwort ) ) {
			return $antwort;
		}
		$antwort = (string) $antwort;

		// ---- Verlauf fortschreiben --------------------------------------
		$verlauf[] = array( 'rolle' => 'user', 'text' => $nachricht );
		$verlauf[] = array( 'rolle' => 'assistant', 'text' => $antwort );
		self::verlauf_speichern( $verlauf );

		// ---- Offene Vorschläge für die Oberfläche ------------------------
		$offen = array();
		foreach ( WP_AI_Edit_Workflow::alle() as $v ) {
			$offen[] = array(
				'id'           => $v['id'],
				'art'          => $v['art'],
				'beschreibung' => $v['text'],
				'vorschau'     => $v['vorschau'],
			);
		}

		return rest_ensure_response(
			array(
				'modus'      => $echter_modus,
				'antwort'    => $antwort,
				'abilities'  => count( 'edit' === $echter_modus ? self::abilities_edit() : self::abilities_chat() ),
				'vorschlaege' => $offen,
			)
		);
	}

	/**
	 * Verlauf zurücksetzen.
	 *
	 * @return WP_REST_Response
	 */
	public static function reset() {
		delete_transient( self::verlauf_key() );
		update_user_meta( get_current_user_id(), 'wpaie_modus', 'chat' );
		return rest_ensure_response( array( 'zurueckgesetzt' => true ) );
	}

	/**
	 * Status für die Oberfläche.
	 *
	 * @return WP_REST_Response
	 */
	public static function status() {
		$llm = WP_AI_Edit_LLM::settings();

		return rest_ensure_response(
			array(
				'modus'       => (string) ( get_user_meta( get_current_user_id(), 'wpaie_modus', true ) ?: 'chat' ),
				'ki_bereit'   => WP_AI_Edit_LLM::bereit(),
				'modell'      => (string) $llm['modell'],
				'base_url'    => (string) $llm['base_url'],
				'schluessel'  => WP_AI_Edit_LLM::maske(),
				'wp_version'  => get_bloginfo( 'version' ),
				'block_theme' => wp_get_theme()->is_block_theme(),
				'abilities'   => function_exists( 'wp_get_abilities' )
					? count( array_filter( array_keys( wp_get_abilities() ), static fn( $n ) => 0 === strpos( (string) $n, 'kiedit/' ) ) )
					: 0,
			)
		);
	}
}
