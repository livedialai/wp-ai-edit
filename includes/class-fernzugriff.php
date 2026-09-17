<?php
/**
 * Fernzugriff: Anwendungspasswörter, Auskunft und Protokoll.
 *
 * Eine Agentur-Instanz greift über HTTPS und ein Anwendungspasswort auf diese
 * Website zu. Für WordPress ist das ein normaler Benutzer, die
 * Berechtigungsprüfungen der Fähigkeiten greifen also unverändert.
 *
 * Diese Klasse kümmert sich um das Drumherum: erkennen, dass ein Zugriff von
 * außen kommt, ihn protokollieren, eine Auskunft über die Website liefern und
 * die Zugänge im Backend verwalten.
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verwaltung des Fernzugriffs.
 */
class WP_AI_Edit_Fernzugriff {

	/**
	 * Option mit dem Protokoll der Fernzugriffe.
	 */
	const PROTOKOLL = 'wp_ai_edit_fernprotokoll';

	/**
	 * Option mit der dauerhaften Kennung dieser Website.
	 */
	const KENNUNG = 'wp_ai_edit_fernkennung';

	/**
	 * Option: Fernzugriff erlaubt?
	 */
	const SCHALTER = 'fern_an';

	/**
	 * Wurde die aktuelle Anfrage per Anwendungspasswort angemeldet?
	 *
	 * @var bool
	 */
	private static $fern = false;

	/**
	 * Wer hat sich angemeldet?
	 *
	 * @var int
	 */
	private static $nutzer = 0;

	/**
	 * Name des verwendeten Zugangs.
	 *
	 * @var string
	 */
	private static $zugang = '';

	/**
	 * Haken anmelden.
	 *
	 * @return void
	 */
	public static function starten(): void {
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'angemeldet' ), 10, 2 );
		add_action( 'application_password_failed_authentication', array( __CLASS__, 'fehlversuch' ), 10, 2 );
		add_action( 'rest_post_dispatch', array( __CLASS__, 'mitschreiben' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routen' ) );
	}

	/**
	 * Kennzeichnet die Anfrage als Fernzugriff.
	 *
	 * @param WP_User $nutzer Angemeldeter Benutzer.
	 * @param array   $eintrag Eintrag des Anwendungspassworts.
	 * @return void
	 */
	public static function angemeldet( $nutzer, $eintrag ): void {
		self::$fern   = true;
		self::$nutzer = (int) $nutzer->ID;
		self::$zugang = isset( $eintrag['name'] ) ? (string) $eintrag['name'] : '';

		$eintrag = array(
			'art'        => 'anmeldung',
			'zugang'     => isset( $eintrag['name'] ) ? (string) $eintrag['name'] : '(ohne Namen)',
			'nutzer'     => $nutzer->user_login,
			'ip'         => self::ip(),
			'zeit'       => current_time( 'mysql' ),
		);
		self::anhang( $eintrag );
	}

	/**
	 * Ein gescheiterter Anmeldeversuch.
	 *
	 * @param string $benutzer Benutzername.
	 * @param mixed  $eintrag  Eintrag oder Fehler.
	 * @return void
	 */
	public static function fehlversuch( $benutzer, $eintrag ): void {
		self::anhang(
			array(
				'art'    => 'fehlversuch',
				'zugang' => is_array( $eintrag ) && isset( $eintrag['name'] ) ? (string) $eintrag['name'] : '(unbekannt)',
				'nutzer' => (string) $benutzer,
				'ip'     => self::ip(),
				'zeit'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Schreibt jeden Fernaufruf ins Protokoll.
	 *
	 * @param WP_REST_Response $antwort Antwort.
	 * @param WP_REST_Server   $server  Server.
	 * @param WP_REST_Request  $anfrage Anfrage.
	 * @return WP_REST_Response Unveränderte Antwort.
	 */
	public static function mitschreiben( $antwort, $server, $anfrage ) {
		if ( ! self::$fern ) {
			return $antwort;
		}
		$route = $anfrage->get_route();
		// Eigenes Plugin und die Faehigkeiten des WordPress-Kerns protokollieren.
		if ( false === strpos( $route, '/wp-ai-edit/' )
			&& false === strpos( $route, '/wp-abilities/' ) ) {
			return $antwort;
		}
		self::anhang(
			array(
				'art'      => 'aufruf',
				'zugang'   => self::zugangsname(),
				'nutzer'   => (string) self::$nutzer,
				'route'    => $route,
				'methode'  => $anfrage->get_method(),
				'status'   => is_object( $antwort ) && method_exists( $antwort, 'get_status' ) ? (int) $antwort->get_status() : 0,
				'ip'       => self::ip(),
				'zeit'     => current_time( 'mysql' ),
			)
		);
		return $antwort;
	}

	/**
	 * Ist die laufende Anfrage ein Fernzugriff?
	 *
	 * @return bool
	 */
	public static function ist_fern(): bool {
		return self::$fern;
	}

	/**
	 * Name des verwendeten Zugangs, wenn bekannt.
	 *
	 * @return string
	 */
	public static function zugangsname(): string {
		if ( self::$zugang ) {
			return self::$zugang . ' (' . self::$nutzer . ')';
		}
		if ( ! self::$nutzer ) {
			return '(unbekannt)';
		}
		return 'Benutzer ' . self::$nutzer;
	}

	/**
	 * Erlaubt der Betreiber den Fernzugriff?
	 *
	 * @return bool
	 */
	public static function erlaubt(): bool {
		if ( ! wp_is_application_passwords_available() ) {
			return false;
		}
		$s = get_option( 'wp_ai_edit_settings', array() );
		return ! isset( $s[ self::SCHALTER ] ) || ! empty( $s[ self::SCHALTER ] );
	}

	/**
	 * IP-Adresse der Anfrage, gekürzt.
	 *
	 * @return string
	 */
	private static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! $ip ) {
			return '(unbekannt)';
		}
		// Letzte Stelle abschneiden, das reicht zur Zuordnung.
		return preg_replace( '/[.:][^.:]*$/', '.0', $ip );
	}

	/**
	 * Hängt einen Eintrag ans Protokoll.
	 *
	 * @param array $eintrag Eintrag.
	 * @return void
	 */
	private static function anhang( array $eintrag ): void {
		$p = get_option( self::PROTOKOLL, array() );
		if ( ! is_array( $p ) ) {
			$p = array();
		}
		array_unshift( $p, $eintrag );
		$p = array_slice( $p, 0, 100 );
		update_option( self::PROTOKOLL, $p, false );
	}

	/**
	 * Liest das Protokoll.
	 *
	 * @param int $anzahl Anzahl der Einträge.
	 * @return array
	 */
	public static function protokoll( int $anzahl = 30 ): array {
		$p = get_option( self::PROTOKOLL, array() );
		return is_array( $p ) ? array_slice( $p, 0, $anzahl ) : array();
	}

	/**
	 * Löscht das Protokoll.
	 *
	 * @return void
	 */
	public static function protokoll_leeren(): void {
		delete_option( self::PROTOKOLL );
	}

	/**
	 * Dauerhafte Kennung dieser Website.
	 *
	 * @return string
	 */
	public static function kennung(): string {
		$k = get_option( self::KENNUNG );
		if ( ! $k ) {
			$k = substr( md5( home_url() . '|' . wp_rand() ), 0, 16 );
			update_option( self::KENNUNG, $k, false );
		}
		return (string) $k;
	}

	/**
	 * Alle Anwendungspasswörter der berechtigten Benutzer.
	 *
	 * @return array Liste aus Benutzer und Eintrag.
	 */
	public static function tokens(): array {
		$liste = array();
		foreach ( get_users( array( 'capability' => array( 'edit_pages' ), 'number' => 50 ) ) as $u ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $u->ID ) as $eintrag ) {
				$liste[] = array(
					'nutzer_id'  => (int) $u->ID,
					'nutzer'     => $u->user_login,
					'uuid'       => (string) $eintrag['uuid'],
					'name'       => (string) $eintrag['name'],
					'angelegt'   => (int) $eintrag['created'],
					'zuletzt'    => isset( $eintrag['last_used'] ) ? (int) $eintrag['last_used'] : 0,
					'letzte_ip'  => isset( $eintrag['last_ip'] ) ? (string) $eintrag['last_ip'] : '',
				);
			}
		}
		usort( $liste, static fn( $a, $b ) => $b['angelegt'] <=> $a['angelegt'] );
		return $liste;
	}

	/**
	 * Legt ein Anwendungspasswort an.
	 *
	 * @param string $name Anzeigename des Zugangs.
	 * @param int    $nutzer_id Benutzer, für den es gilt.
	 * @return array|WP_Error Passwort und Eintrag oder Fehler.
	 */
	public static function anlegen( string $name, int $nutzer_id = 0 ) {
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'wpaie_fern_name', __( 'Bitte einen Namen für den Zugang angeben.', 'wp-ai-edit' ) );
		}
		if ( ! $nutzer_id ) {
			$nutzer_id = get_current_user_id();
		}
		if ( ! user_can( $nutzer_id, 'edit_pages' ) ) {
			return new WP_Error( 'wpaie_fern_recht', __( 'Dieser Benutzer darf keine Fernzugriffe erhalten.', 'wp-ai-edit' ) );
		}
		$ergebnis = WP_Application_Passwords::create_new_application_password(
			$nutzer_id,
			array( 'name' => sanitize_text_field( $name ) )
		);
		if ( is_wp_error( $ergebnis ) ) {
			return $ergebnis;
		}
		self::anhang(
			array(
				'art'    => 'zugang angelegt',
				'zugang' => sanitize_text_field( $name ),
				'nutzer' => $nutzer_id,
				'ip'     => self::ip(),
				'zeit'   => current_time( 'mysql' ),
			)
		);
		return $ergebnis;
	}

	/**
	 * Widerruft einen Zugang.
	 *
	 * @param int    $nutzer_id Benutzer.
	 * @param string $uuid      Kennung des Anwendungspassworts.
	 * @return bool|WP_Error
	 */
	public static function widerrufen( int $nutzer_id, string $uuid ) {
		$ergebnis = WP_Application_Passwords::delete_application_password( $nutzer_id, $uuid );
		if ( ! is_wp_error( $ergebnis ) ) {
			self::anhang(
				array(
					'art'    => 'zugang widerrufen',
					'zugang' => $uuid,
					'nutzer' => $nutzer_id,
					'ip'     => self::ip(),
					'zeit'   => current_time( 'mysql' ),
				)
			);
		}
		return $ergebnis;
	}

	/**
	 * Meldet die Route an, über die die Agentur die Website abfragt.
	 *
	 * @return void
	 */
	public static function routen(): void {
		register_rest_route(
			'wp-ai-edit/v1',
			'/auskunft',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'auskunft' ),
				'permission_callback' => static fn() => current_user_can( 'edit_pages' ),
			)
		);
	}

	/**
	 * Auskunft über diese Website.
	 *
	 * @return WP_REST_Response
	 */
	public static function auskunft(): WP_REST_Response {
		$s        = get_option( 'wp_ai_edit_settings', array() );
		$theme    = wp_get_theme();
		$plugins  = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $datei ) {
			$daten = get_file_data(
				WP_PLUGIN_DIR . '/' . $datei,
				array( 'Name' => 'Plugin Name', 'Version' => 'Version' )
			);
			$plugins[] = array(
				'datei'   => $datei,
				'name'    => $daten['Name'] ? $daten['Name'] : $datei,
				'version' => $daten['Version'],
			);
		}
		$faehigkeiten = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		// wp_get_abilities() liefert Objekte der Klasse WP_Ability, keine Arrays.
		$eigene       = array_filter(
			$faehigkeiten,
			static function ( $a ) {
				// WP_Ability hat private Eigenschaften — der Name kommt ueber get_name().
				if ( is_object( $a ) && method_exists( $a, 'get_name' ) ) {
					$name = (string) $a->get_name();
				} elseif ( is_array( $a ) ) {
					$name = (string) ( $a['name'] ?? '' );
				} else {
					$name = '';
				}
				return 0 === strpos( $name, 'kiedit/' );
			}
		);

		return new WP_REST_Response(
			array(
				'kennung'      => self::kennung(),
				'website'      => home_url(),
				'name'         => get_bloginfo( 'name' ),
				'beschreibung' => get_bloginfo( 'description' ),
				'sprache'      => get_bloginfo( 'language' ),
				'wp'           => get_bloginfo( 'version' ),
				'php'          => PHP_VERSION,
				'theme'        => array(
					'name'        => $theme->get( 'Name' ),
					'version'     => $theme->get( 'Version' ),
					'blocktheme'  => wp_is_block_theme(),
				),
				'plugins'      => $plugins,
				'wpaie'        => array(
					'version'      => defined( 'WPAIE_VERSION' ) ? WPAIE_VERSION : '',
					'faehigkeiten' => count( $eigene ),
					'arbeitsweise' => isset( $s['workflow'] ) ? $s['workflow'] : 'stage',
					'bild'         => ! empty( $s['bild_key'] ),
				),
				'nutzer'       => array(
					'id'   => get_current_user_id(),
					'name' => wp_get_current_user()->user_login,
					'darf' => array(
						'seiten'   => current_user_can( 'edit_pages' ),
						'plugins'  => current_user_can( 'install_plugins' ),
						'optionen' => current_user_can( 'manage_options' ),
					),
				),
				'zugang'       => array(
					'anwendungspasswoerter' => wp_is_application_passwords_available(),
					'fernerlaubt'           => self::erlaubt(),
					'kennung_gesetzt'       => (bool) get_option( self::KENNUNG ),
				),
				'zeit'         => current_time( 'mysql' ),
			)
		);
	}
}
