<?php
/**
 * Plugin Name:       WP AI Edit
 * Plugin URI:        https://github.com/livedialai/wp-ai-edit
 * Description:       KI-Chat im WordPress-Backend, der die Website bearbeitet: Seiten befüllen, Plugins installieren und konfigurieren, Designs fremder Seiten als Inspiration einlesen. Erscheint ausschließlich im Backend als schwebendes Widget – auf der öffentlichen Website existiert es nicht.
 * Version:           1.1.5
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            Weser AI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-edit
 *
 * @package WP_AI_Edit
 */

// Direkter Aufruf verboten.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAIE_VERSION', '1.1.5' );
define( 'WPAIE_FILE', __FILE__ );
define( 'WPAIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIE_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAIE_OPT', 'wp_ai_edit' );

/**
 * Hauptklasse. Registriert Hooks, REST-Routen und die Admin-Oberflaeche.
 */
class WP_AI_Edit {

	/**
	 * Standardwerte der Plugin-Einstellungen.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'        => 1,
			// Welche Admin-Seiten zeigen das Widget? Leer = alle.
			'admin_pages'    => array(),
			// Welche Rolle darf das Widget nutzen?
			'min_capability' => 'edit_pages',
			// Bearbeitungsmodus: Nachrichtenverlauf begrenzen (Tokens sparen).
			'history_limit'  => 12,
			// Eigene Systemanweisungen. Leer = mitgelieferte Datei aus /prompts.
			'prompt_chat'    => '',
			'prompt_edit'    => '',
			// Arbeitsweise: 'stage' = immer erst vorschlagen, 'direct' = sofort anwenden.
			'workflow'       => 'stage',
			// Einmalige Anmeldung der Installation.
			'melden'         => 1,
			// Bildgenerierung. Schlüssel bleibt leer — er gehört in die Option, nicht ins Repo.
			'bild_key'       => '',
			'bild_modell'    => 'bytedance/seedream-v4.5',
			'bild_base'      => 'https://api.wavespeed.ai/api/v3',
			'bild_groesse'   => '2048*2048',
		);
	}

	/**
	 * Eingebauter Standard-Prompt, falls weder Option noch Datei vorliegt.
	 *
	 * @param string $modus chat|edit.
	 * @return string
	 */
	public static function prompt_standard( string $modus ): string {
		if ( 'edit' === $modus ) {
			return 'Du bist der Website-Editor dieser WordPress-Installation. Du darfst Seiten anlegen und ändern, '
				. 'Einstellungen setzen und Plugins installieren. Lies zuerst mit kiedit/inspect-site den Zustand. '
				. 'Schreibe Inhalte ausschließlich als gültiges Block-Markup. Antworte auf Deutsch.';
		}
		return 'Du bist ein Assistent im WordPress-Backend. Du darfst ausschließlich lesen und beraten, nicht ändern. Antworte auf Deutsch.';
	}

	/**
	 * Einstellungen lesen.
	 *
	 * @return array
	 */
	public static function settings(): array {
		$gespeichert = get_option( WPAIE_OPT, array() );
		if ( ! is_array( $gespeichert ) ) {
			$gespeichert = array();
		}
		return array_merge( self::defaults(), $gespeichert );
	}

	/**
	 * Einstellungen schreiben.
	 *
	 * @param array $werte Zu speichernde Werte.
	 * @return void
	 */
	public static function update( array $werte ): void {
		update_option( WPAIE_OPT, array_merge( self::settings(), $werte ) );
	}

	/**
	 * Konstruktor: alle Hooks registrieren.
	 */
	public function __construct() {
		require_once WPAIE_DIR . 'includes/class-inspector.php';
		require_once WPAIE_DIR . 'includes/class-abilities.php';
		require_once WPAIE_DIR . 'includes/class-workflow.php';
		require_once WPAIE_DIR . 'includes/class-bild.php';
		require_once WPAIE_DIR . 'includes/class-meldung.php';
		require_once WPAIE_DIR . 'includes/class-llm.php';
		require_once WPAIE_DIR . 'includes/class-rest.php';

		add_action( 'wp_abilities_api_categories_init', array( 'WP_AI_Edit_Abilities', 'kategorien' ) );
		add_action( 'wp_abilities_api_init', array( 'WP_AI_Edit_Abilities', 'registrieren' ) );

		add_action( 'rest_api_init', array( 'WP_AI_Edit_REST', 'routen' ) );

		// Vorschau vorgeschlagener Änderungen (?wpaie_vorschau=<id>).
		add_action( 'init', array( 'WP_AI_Edit_Workflow', 'vorschau_ausgeben' ) );

		add_action( 'admin_menu', array( $this, 'menue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_footer', array( $this, 'widget' ) );
		add_action( 'admin_notices', array( $this, 'hinweis_connector' ) );
		add_action( 'admin_init', array( $this, 'bild_test' ) );

		// Anmeldung beim Aktivieren.
		register_activation_hook( WPAIE_FILE, array( 'WP_AI_Edit_Meldung', 'melden' ) );
		add_action( 'admin_init', array( $this, 'meldung_test' ) );
		// Meldet sich auch nach einem Update — der Aktivierungshaken feuert dabei nicht.
		add_action( 'admin_init', array( $this, 'versionsabgleich' ) );
	}

	/**
	 * Meldet sich, wenn die laufende Version noch nicht gemeldet wurde.
	 *
	 * Der Aktivierungshaken feuert bei einem Update nicht. Ohne diese Prüfung
	 * bliebe jede aktualisierte Installation unregistriert.
	 *
	 * @return void
	 */
	public function versionsabgleich(): void {
		if ( ! WP_AI_Edit_Meldung::eingeschaltet() ) {
			return;
		}
		$gemeldet = (string) get_option( 'wp_ai_edit_gemeldet', '' );
		if ( $gemeldet === WPAIE_VERSION ) {
			return;
		}
		// Zuerst merken, dann senden — sonst wiederholt es sich bei jedem Seitenaufbau.
		update_option( 'wp_ai_edit_gemeldet', WPAIE_VERSION, false );
		WP_AI_Edit_Meldung::melden( false );
	}

	/**
	 * Anmeldung jetzt auslösen (?meldung_test=1).
	 *
	 * @return void
	 */
	public function meldung_test(): void {
		if ( ! isset( $_GET['meldung_test'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wpaie_meldung_test' );

		$r = WP_AI_Edit_Meldung::melden();
		if ( is_wp_error( $r ) ) {
			set_transient( 'wpaie_meldung_ergebnis', 'Fehler: ' . $r->get_error_message(), 60 );
		} else {
			set_transient( 'wpaie_meldung_ergebnis', $r['text'], 60 );
		}

		wp_safe_redirect( add_query_arg( 'page', 'wp-ai-edit', admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Verbindung und Guthaben des Bilddienstes prüfen (?bild_test=1).
	 *
	 * @return void
	 */
	public function bild_test(): void {
		if ( ! isset( $_GET['bild_test'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wpaie_bild_test' );

		$r = WP_AI_Edit_Bild::guthaben();
		if ( is_wp_error( $r ) ) {
			$text = 'Fehler: ' . $r->get_error_message();
		} else {
			$text = sprintf(
				'Verbindung steht. Modell %s · Guthaben %s',
				(string) $r['modell'],
				null === $r['guthaben'] ? 'unbekannt' : number_format_i18n( (float) $r['guthaben'], 2 ) . ' $'
			);
		}

		set_transient( 'wpaie_bild_test', $text, 60 );
		wp_safe_redirect( add_query_arg( 'page', 'wp-ai-edit', admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Einstellungsseite anlegen.
	 *
	 * @return void
	 */
	public function menue(): void {
		add_options_page(
			__( 'WP AI Edit', 'wp-ai-edit' ),
			__( 'WP AI Edit', 'wp-ai-edit' ),
			'manage_options',
			'wp-ai-edit',
			array( $this, 'einstellungen_seite' )
		);
	}

	/**
	 * Prueft, ob der aktuelle Nutzer das Widget sehen darf.
	 *
	 * @param string $seite Aktuelle Admin-Seite (Hook-Suffix).
	 * @return bool
	 */
	public static function darf_sehen( string $seite = '' ): bool {
		$s = self::settings();

		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( ! is_user_logged_in() || ! current_user_can( $s['min_capability'] ) ) {
			return false;
		}
		if ( ! empty( $s['admin_pages'] ) && ! in_array( $seite, (array) $s['admin_pages'], true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Zeigt einen Hinweis, wenn der API-Zugang noch nicht konfiguriert ist.
	 *
	 * @return void
	 */
	public function hinweis_connector(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( WP_AI_Edit_LLM::bereit() ) {
			return;
		}
		$url = admin_url( 'options-general.php?page=wp-ai-edit' );
		printf(
			'<div class="notice notice-warning"><p><strong>WP AI Edit:</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Kein KI-Zugang hinterlegt. Basis-URL, Modell und API-Schlüssel fehlen.', 'wp-ai-edit' ),
			esc_url( $url ),
			esc_html__( 'Jetzt einstellen', 'wp-ai-edit' )
		);
	}

	/**
	 * Assets nur im Backend laden.
	 *
	 * @param string $seite Hook-Suffix der aktuellen Seite.
	 * @return void
	 */
	public function assets( $seite ): void {
		if ( ! self::darf_sehen( (string) $seite ) ) {
			return;
		}

		wp_enqueue_style( 'wp-ai-edit', WPAIE_URL . 'assets/css/widget.css', array(), WPAIE_VERSION );
		wp_enqueue_script( 'wp-ai-edit', WPAIE_URL . 'assets/js/widget.js', array( 'wp-api-fetch', 'wp-i18n' ), WPAIE_VERSION, true );

		wp_localize_script(
			'wp-ai-edit',
			'WPAIE',
			array(
				'rest'     => esc_url_raw( rest_url( WP_AI_Edit_REST::NAMESPACE ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'version'  => WPAIE_VERSION,
				'befehle'  => array( '/editsite', '/normal', '/reset', '/hilfe' ),
				'connector' => array_keys( (array) ( function_exists( 'wp_get_connectors' ) ? wp_get_connectors() : array() ) ),
			)
		);
	}

	/**
	 * Markup des schwebenden Widgets ausgeben.
	 *
	 * @return void
	 */
	public function widget(): void {
		$seite = isset( $GLOBALS['hook_suffix'] ) ? (string) $GLOBALS['hook_suffix'] : '';
		if ( ! self::darf_sehen( $seite ) ) {
			return;
		}
		?>
		<div id="wpaie-wrap" aria-live="polite">
			<button id="wpaie-bubble" type="button" aria-label="<?php esc_attr_e( 'KI-Seiteneditor öffnen', 'wp-ai-edit' ); ?>">
				<span class="wpaie-bubble-icon" aria-hidden="true">◈</span>
				<span class="wpaie-bubble-label"><?php esc_html_e( 'KI-Editor', 'wp-ai-edit' ); ?></span>
			</button>

			<div id="wpaie-panel" hidden>
				<header id="wpaie-head">
					<div>
						<strong id="wpaie-title"><?php esc_html_e( 'KI-Seiteneditor', 'wp-ai-edit' ); ?></strong>
						<span id="wpaie-mode" class="wpaie-badge"><?php esc_html_e( 'Chat', 'wp-ai-edit' ); ?></span>
					</div>
					<button id="wpaie-close" type="button" aria-label="<?php esc_attr_e( 'Schließen', 'wp-ai-edit' ); ?>">×</button>
				</header>

				<div id="wpaie-log"></div>

				<div id="wpaie-tools">
					<button type="button" class="wpaie-chip" data-cmd="/editsite">/editsite</button>
					<button type="button" class="wpaie-chip" data-cmd="/inspect"><?php esc_html_e( 'Seite einlesen', 'wp-ai-edit' ); ?></button>
					<button type="button" class="wpaie-chip" data-cmd="/reset">/reset</button>
				</div>

				<form id="wpaie-form">
					<textarea id="wpaie-input" rows="2" placeholder="<?php esc_attr_e( 'Anweisung … (/editsite für Bearbeitungsmodus)', 'wp-ai-edit' ); ?>"></textarea>
					<button id="wpaie-send" type="submit" aria-label="<?php esc_attr_e( 'Senden', 'wp-ai-edit' ); ?>">➤</button>
				</form>

				<footer id="wpaie-foot">
					<span id="wpaie-status"><?php esc_html_e( 'bereit', 'wp-ai-edit' ); ?></span>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Einstellungsseite: API-Zugang, Prompts, Sichtbarkeit.
	 *
	 * @return void
	 */
	public function einstellungen_seite(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['wpaie_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpaie_nonce'] ) ), 'wpaie_settings' ) ) {

			// Sichtbarkeit.
			self::update(
				array(
					'enabled'        => isset( $_POST['enabled'] ) ? 1 : 0,
					'min_capability' => isset( $_POST['min_capability'] ) ? sanitize_text_field( wp_unslash( $_POST['min_capability'] ) ) : 'edit_pages',
					'history_limit'  => isset( $_POST['history_limit'] ) ? max( 2, min( 40, (int) $_POST['history_limit'] ) ) : 12,
					'workflow'       => ( isset( $_POST['workflow'] ) && 'direct' === $_POST['workflow'] ) ? 'direct' : 'stage',
				)
			);

			self::update(
				array(
					'bild_key'     => isset( $_POST['bild_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bild_key'] ) ) : '',
					'bild_modell'  => isset( $_POST['bild_modell'] ) ? sanitize_text_field( wp_unslash( $_POST['bild_modell'] ) ) : '',
					'bild_base'    => isset( $_POST['bild_base'] ) ? esc_url_raw( wp_unslash( $_POST['bild_base'] ) ) : '',
					'bild_groesse' => isset( $_POST['bild_groesse'] ) ? sanitize_text_field( wp_unslash( $_POST['bild_groesse'] ) ) : '',
				)
			);

			// Prompts: leeres Feld setzt auf die mitgelieferte Fassung zurueck.
			$prompt_chat = isset( $_POST['prompt_chat'] ) ? wp_kses_post( wp_unslash( $_POST['prompt_chat'] ) ) : '';
			$prompt_edit = isset( $_POST['prompt_edit'] ) ? wp_kses_post( wp_unslash( $_POST['prompt_edit'] ) ) : '';
			self::update(
				array(
					'prompt_chat' => trim( $prompt_chat ),
					'prompt_edit' => trim( $prompt_edit ),
				)
			);

			// API-Zugang.
			$llm   = WP_AI_Edit_LLM::settings();
			$key   = isset( $_POST['llm_schluessel'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['llm_schluessel'] ) ) ) : '';
			$neuer = array(
				'base_url'   => isset( $_POST['llm_base_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['llm_base_url'] ) ) ) : $llm['base_url'],
				'modell'     => isset( $_POST['llm_modell'] ) ? sanitize_text_field( wp_unslash( $_POST['llm_modell'] ) ) : $llm['modell'],
				'temperatur' => isset( $_POST['llm_temperatur'] ) ? (float) str_replace( ',', '.', (string) $_POST['llm_temperatur'] ) : $llm['temperatur'],
				'max_runden' => isset( $_POST['llm_max_runden'] ) ? max( 1, min( 20, (int) $_POST['llm_max_runden'] ) ) : $llm['max_runden'],
				'timeout'    => isset( $_POST['llm_timeout'] ) ? max( 15, min( 600, (int) $_POST['llm_timeout'] ) ) : $llm['timeout'],
				'thinking'   => isset( $_POST['llm_thinking'] ) ? 1 : 0,
			);
			// Schluessel nur ueberschreiben, wenn ein neuer eingegeben wurde.
			if ( '' !== $key ) {
				$neuer['schluessel'] = $key;
			}
			update_option( 'wp_ai_edit_llm', array_merge( $llm, $neuer ) );

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Gespeichert.', 'wp-ai-edit' ) . '</p></div>';
		}

		$s    = self::settings();
		$llm  = WP_AI_Edit_LLM::settings();
		$chat = '' !== trim( (string) $s['prompt_chat'] ) ? (string) $s['prompt_chat'] : self::prompt_datei( 'chat' );
		$edit = '' !== trim( (string) $s['prompt_edit'] ) ? (string) $s['prompt_edit'] : self::prompt_datei( 'edit' );
		?>
		<div class="wrap">
			<h1>WP AI Edit</h1>
			<p><?php esc_html_e( 'Chat-Widget im Backend, das die Website bearbeitet. Auf der öffentlichen Website erscheint es nicht.', 'wp-ai-edit' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'wpaie_settings', 'wpaie_nonce' ); ?>

				<h2><?php esc_html_e( 'KI-Zugang', 'wp-ai-edit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="llm_base_url"><?php esc_html_e( 'Basis-URL', 'wp-ai-edit' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="llm_base_url" name="llm_base_url" value="<?php echo esc_attr( (string) $llm['base_url'] ); ?>" placeholder="https://api.deepseek.com">
							<p class="description"><?php esc_html_e( 'Ohne /chat/completions – der Pfad wird angehängt. OpenAI-kompatibel: DeepSeek, OpenAI, Mistral, Ollama, eigenes Gateway.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="llm_modell"><?php esc_html_e( 'Modell', 'wp-ai-edit' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="llm_modell" name="llm_modell" value="<?php echo esc_attr( (string) $llm['modell'] ); ?>" placeholder="deepseek-flash">
							<p class="description"><?php esc_html_e( 'Muss Werkzeugaufrufe (tool calls) unterstützen. DeepSeek: deepseek-flash oder deepseek-v4-pro.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="llm_schluessel"><?php esc_html_e( 'API-Schlüssel', 'wp-ai-edit' ); ?></label></th>
						<td>
							<input type="password" class="regular-text code" id="llm_schluessel" name="llm_schluessel" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( WP_AI_Edit_LLM::maske() ); ?>">
							<p class="description"><?php esc_html_e( 'Leer lassen, um den gespeicherten Schlüssel zu behalten.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Parameter', 'wp-ai-edit' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Temperatur', 'wp-ai-edit' ); ?> <input type="text" size="4" name="llm_temperatur" value="<?php echo esc_attr( (string) $llm['temperatur'] ); ?>"></label>
							&nbsp;
							<label><?php esc_html_e( 'max. Werkzeugrunden', 'wp-ai-edit' ); ?> <input type="number" min="1" max="20" size="3" name="llm_max_runden" value="<?php echo (int) $llm['max_runden']; ?>"></label>
							&nbsp;
							<label><?php esc_html_e( 'Timeout (s)', 'wp-ai-edit' ); ?> <input type="number" min="15" max="600" size="4" name="llm_timeout" value="<?php echo (int) $llm['timeout']; ?>"></label>
							<br><br>
							<label><input type="checkbox" name="llm_thinking" value="1" <?php checked( ! empty( $llm['thinking'] ) ); ?>> <?php esc_html_e( 'Denkmodus des Modells aktivieren (langsamer, gründlicher)', 'wp-ai-edit' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verbindung', 'wp-ai-edit' ); ?></th>
						<td>
							<button type="button" class="button" id="wpaie-test"><?php esc_html_e( 'Verbindung testen', 'wp-ai-edit' ); ?></button>
							<span id="wpaie-test-ausgabe" style="margin-left:10px;font-family:monospace;"></span>
							<p class="description"><?php esc_html_e( 'Sendet eine kurze Testanfrage an das eingestellte Modell.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Systemanweisungen', 'wp-ai-edit' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Feld leeren und speichern, um die mitgelieferte Fassung wiederherzustellen.', 'wp-ai-edit' ); ?></p>
				<h3><?php esc_html_e( 'Nur-Lese-Modus (Chat)', 'wp-ai-edit' ); ?></h3>
				<textarea name="prompt_chat" rows="10" class="large-text code" spellcheck="false"><?php echo esc_textarea( $chat ); ?></textarea>
				<h3><?php esc_html_e( 'Bearbeitungsmodus (/editsite)', 'wp-ai-edit' ); ?></h3>
				<textarea name="prompt_edit" rows="24" class="large-text code" spellcheck="false"><?php echo esc_textarea( $edit ); ?></textarea>

				<h2><?php esc_html_e( 'Bildgenerierung', 'wp-ai-edit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bild_base"><?php esc_html_e( 'Dienst', 'wp-ai-edit' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="bild_base" name="bild_base" value="<?php echo esc_attr( $s['bild_base'] ); ?>" placeholder="https://api.wavespeed.ai/api/v3">
							<p class="description"><?php esc_html_e( 'Adresse der Schnittstelle. WaveSpeed: https://api.wavespeed.ai/api/v3 — jeder andere kompatible Dienst geht auch.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bild_key"><?php esc_html_e( 'Schlüssel', 'wp-ai-edit' ); ?></label></th>
						<td>
							<input type="password" class="regular-text code" id="bild_key" name="bild_key" value="<?php echo esc_attr( $s['bild_key'] ); ?>" autocomplete="off" placeholder="wsk_live_…">
							<p class="description"><?php esc_html_e( 'Wird nur in der Datenbank gespeichert und ausschließlich an den oben genannten Dienst gesendet.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bild_modell"><?php esc_html_e( 'Modell', 'wp-ai-edit' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="bild_modell" name="bild_modell" value="<?php echo esc_attr( $s['bild_modell'] ); ?>" placeholder="bytedance/seedream-v4.5">
							<p class="description">
								<?php esc_html_e( 'Vorgabe: Seedream 4.5, Text zu Bild. Alternativen bei WaveSpeed:', 'wp-ai-edit' ); ?>
								<code>bytedance/seedream-v5.0-pro</code> (<?php esc_html_e( 'Design und dichte Layouts', 'wp-ai-edit' ); ?>),
								<code>bytedance/seedream-v4</code> (<?php esc_html_e( 'Illustration, vielseitige Stile', 'wp-ai-edit' ); ?>),
								<code>wavespeed-ai/z-image/turbo</code> (<?php esc_html_e( 'schnell und günstig für Mengen', 'wp-ai-edit' ); ?>).
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bild_groesse"><?php esc_html_e( 'Größe', 'wp-ai-edit' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="bild_groesse" name="bild_groesse" value="<?php echo esc_attr( $s['bild_groesse'] ); ?>" placeholder="2048*2048">
							<p class="description"><?php esc_html_e( 'Maße als BREITE*HÖHE. Seedream 4.5 kann bis 8192*8192.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Prüfen', 'wp-ai-edit' ); ?></th>
						<td>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'bild_test', '1', menu_page_url( 'wp-ai-edit', false ) ), 'wpaie_bild_test' ) ); ?>"><?php esc_html_e( 'Verbindung und Guthaben prüfen', 'wp-ai-edit' ); ?></a>
							<?php
							$test = get_transient( 'wpaie_bild_test' );
							if ( $test ) {
								delete_transient( 'wpaie_bild_test' );
								echo '<p><strong>' . esc_html( $test ) . '</strong></p>';
							}
							?>
							<p class="description"><?php esc_html_e( 'Der Agent kann Bilder über die Fähigkeit „Bild erzeugen" anlegen. Sie landen in der Mediathek und werden erst über einen Vorschlag in eine Seite eingebaut.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Arbeitsweise', 'wp-ai-edit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Änderungen', 'wp-ai-edit' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="workflow" value="stage" <?php checked( 'direct' !== $s['workflow'] ); ?>> <strong><?php esc_html_e( 'Erst vorschlagen (empfohlen)', 'wp-ai-edit' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Der Agent legt Änderungen als Vorschlag mit Vorschau-Link vor. Live wird erst nach „Übernehmen".', 'wp-ai-edit' ); ?></p>
								<br>
								<label><input type="radio" name="workflow" value="direct" <?php checked( 'direct' === $s['workflow'] ); ?>> <strong><?php esc_html_e( 'Sofort anwenden', 'wp-ai-edit' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Änderungen gehen direkt live. Sicherung vor jeder Änderung und Rücknahme bleiben erhalten.', 'wp-ai-edit' ); ?></p>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Unabhängig davon kann der Nutzer im Chat „mach das direkt" sagen – dann wird die einzelne Änderung ohne Rückfrage angewendet. Umgekehrt fragt der Agent vorher, wenn er unsicher ist.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sichtbarkeit', 'wp-ai-edit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Widget aktiv', 'wp-ai-edit' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Im Backend anzeigen', 'wp-ai-edit' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mindestberechtigung', 'wp-ai-edit' ); ?></th>
						<td>
							<select name="min_capability">
								<?php
								foreach ( array( 'edit_posts', 'edit_pages', 'edit_theme_options', 'manage_options' ) as $cap ) {
									printf(
										'<option value="%1$s" %2$s>%1$s</option>',
										esc_attr( $cap ),
										selected( $s['min_capability'], $cap, false )
									);
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'Wer diese Berechtigung hat, sieht das Widget. Die einzelnen Fähigkeiten prüfen zusätzlich ihre eigenen Rechte.', 'wp-ai-edit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verlauf (Nachrichten)', 'wp-ai-edit' ); ?></th>
						<td><input type="number" name="history_limit" min="2" max="40" value="<?php echo (int) $s['history_limit']; ?>"></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Registrierte Fähigkeiten', 'wp-ai-edit' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'wp-ai-edit' ); ?></th><th><?php esc_html_e( 'Beschreibung', 'wp-ai-edit' ); ?></th></tr></thead>
				<tbody>
				<?php
				if ( function_exists( 'wp_get_abilities' ) ) {
					foreach ( wp_get_abilities() as $name => $ability ) {
						if ( 0 !== strpos( (string) $name, 'kiedit/' ) ) {
							continue;
						}
						printf(
							'<tr><td><code>%s</code></td><td>%s</td></tr>',
							esc_html( (string) $name ),
							esc_html( (string) $ability->get_description() )
						);
					}
				}
				?>
				</tbody>
			</table>
		</div>

		<script>
		( function () {
			var btn = document.getElementById( 'wpaie-test' );
			var out = document.getElementById( 'wpaie-test-ausgabe' );
			if ( ! btn || ! out ) { return; }
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				out.textContent = 'teste …';
				out.style.color = '#2271b1';
				window.wp.apiFetch( { path: '/wp-ai-edit/v1/test', method: 'POST' } )
					.then( function ( r ) {
						out.style.color = '#00a32a';
						out.textContent = '✔ ' + ( r.modell || '' ) + ' antwortet: ' + ( r.antwort || '' );
					} )
					.catch( function ( e ) {
						out.style.color = '#d63638';
						out.textContent = '✘ ' + ( ( e && e.data && e.data.message ) || ( e && e.message ) || 'Fehler' );
					} )
					.then( function () { btn.disabled = false; } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Inhalt einer mitgelieferten Prompt-Datei.
	 *
	 * @param string $modus chat|edit.
	 * @return string
	 */
	public static function prompt_datei( string $modus ): string {
		$datei = WPAIE_DIR . 'prompts/' . ( 'edit' === $modus ? 'editsite.md' : 'chat.md' );
		if ( file_exists( $datei ) ) {
			$inhalt = (string) file_get_contents( $datei );
			if ( '' !== trim( $inhalt ) ) {
				return $inhalt;
			}
		}
		return self::prompt_standard( $modus );
	}
}

new WP_AI_Edit();
