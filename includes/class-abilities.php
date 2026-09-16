<?php
/**
 * Registrierung der Fähigkeiten (Abilities) für WP AI Edit.
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stellt alle Fähigkeiten bereit, die das Modell aufrufen darf.
 */
class WP_AI_Edit_Abilities {

	/**
	 * Erlaubte Optionen, die das Modell ändern darf.
	 *
	 * @return array
	 */
	public static function erlaubte_optionen(): array {
		return array(
			'blogname'        => 'string',
			'blogdescription' => 'string',
			'admin_email'     => 'string',
			'posts_per_page'  => 'integer',
			'blog_public'     => 'integer',
			'date_format'     => 'string',
			'time_format'     => 'string',
			'start_of_week'   => 'integer',
		);
	}

	/**
	 * Kategorien registrieren.
	 *
	 * @return void
	 */
	public static function kategorien(): void {
		wp_register_ability_category(
			'kiedit',
			array(
				'label'       => __( 'Website-Editor (KI)', 'wp-ai-edit' ),
				'description' => __( 'Fähigkeiten, mit denen ein Sprachmodell Inhalte, Einstellungen und Plugins dieser Website bearbeitet.', 'wp-ai-edit' ),
			)
		);
	}

	/**
	 * Alle Fähigkeiten registrieren.
	 *
	 * @return void
	 */
	public static function registrieren(): void {
		self::site_einlesen();
		self::seite_schreiben();
		self::seite_anlegen();
		self::optionen_setzen();
		self::plugins_auflisten();
		self::plugin_installieren();
		self::plugin_aktivieren();
		self::plugin_option_setzen();
		self::design_einlesen();
		self::snapshot();
		self::rollback();
		self::vorschlaege();
		self::seiten_lesen();
		self::bilder();
	}

	/**
	 * Bildgenerierung über WaveSpeed.
	 *
	 * @return void
	 */
	protected static function bilder(): void {
		wp_register_ability(
			'kiedit/generate-image',
			array(
				'label'               => __( 'Bild erzeugen', 'wp-ai-edit' ),
				'description'         => __( 'Erzeugt ein Bild aus einer Beschreibung und legt es in der Mediathek ab. Danach mit der zurückgegebenen URL oder Anhang-ID in einer Seite verwenden. Sinnvoll für Speisekarten, Stimmungsbilder, Produktfotos.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt' => array( 'type' => 'string', 'description' => __( 'Bildbeschreibung. Je genauer — Motiv, Licht, Stil, Perspektive — desto besser das Ergebnis.', 'wp-ai-edit' ) ),
						'titel'  => array( 'type' => 'string', 'description' => __( 'Titel und Alternativtext in der Mediathek.', 'wp-ai-edit' ) ),
						'groesse' => array( 'type' => 'string', 'description' => __( 'Gewünschte Maße, z. B. 2048*2048. Leer lassen für die Standardgröße.', 'wp-ai-edit' ) ),
						'modell' => array( 'type' => 'string', 'description' => __( 'Nur nötig, wenn ein anderes Modell als das eingestellte verwendet werden soll.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_bild_erzeugen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'kiedit/image-status',
			array(
				'label'               => __( 'Bildauftrag abholen', 'wp-ai-edit' ),
				'description'         => __( 'Holt einen noch laufenden Bildauftrag ab und legt das Ergebnis in die Mediathek. Nur nötig, wenn „Bild erzeugen" meldet, dass der Auftrag noch läuft.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'auftrag' => array( 'type' => 'string', 'description' => __( 'Auftrags-ID aus „Bild erzeugen".', 'wp-ai-edit' ) ),
						'titel'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'auftrag' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_bild_abholen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Bild erzeugen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_bild_erzeugen( $input = array() ) {
		$prompt = trim( (string) ( $input['prompt'] ?? '' ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'kiedit_prompt', __( 'Ohne Beschreibung kein Bild.', 'wp-ai-edit' ) );
		}

		$auftrag = WP_AI_Edit_Bild::erzeugen(
			$prompt,
			(string) ( $input['groesse'] ?? '' ),
			(string) ( $input['modell'] ?? '' )
		);
		if ( is_wp_error( $auftrag ) ) {
			return $auftrag;
		}

		self::protokoll( 'generate-image', mb_substr( $prompt, 0, 200 ), 'Auftrag ' . $auftrag );

		return self::bild_abholen( $auftrag, (string) ( $input['titel'] ?? '' ) );
	}

	/**
	 * Callback: noch laufenden Bildauftrag abholen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_bild_abholen( $input = array() ) {
		$auftrag = sanitize_text_field( (string) ( $input['auftrag'] ?? '' ) );
		if ( '' === $auftrag ) {
			return new WP_Error( 'kiedit_auftrag', __( 'Auftrags-ID fehlt.', 'wp-ai-edit' ) );
		}
		return self::bild_abholen( $auftrag, (string) ( $input['titel'] ?? '' ) );
	}

	/**
	 * Wartet auf das Ergebnis und legt es in die Mediathek.
	 *
	 * @param string $auftrag Auftrags-ID.
	 * @param string $titel   Titel.
	 * @return array|WP_Error
	 */
	protected static function bild_abholen( string $auftrag, string $titel = '' ) {
		$url = WP_AI_Edit_Bild::warten( $auftrag, 45 );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$anhang = WP_AI_Edit_Bild::in_mediathek( $url, $titel );
		if ( is_wp_error( $anhang ) ) {
			return $anhang;
		}

		$datei = get_attached_file( $anhang );
		$maße  = $datei && file_exists( $datei ) ? wp_getimagesize( $datei ) : null;

		return array(
			'erzeugt'      => true,
			'anhang_id'    => $anhang,
			'url'          => wp_get_attachment_url( $anhang ),
			'titel'        => get_the_title( $anhang ),
			'breite'       => $maße ? $maße[0] : null,
			'hoehe'        => $maße ? $maße[1] : null,
			'mediathek'    => admin_url( 'upload.php?item=' . $anhang ),
			'hinweis'      => __( 'Das Bild liegt in der Mediathek. Es ist auf keiner Seite eingebaut — das passiert erst über einen Vorschlag.', 'wp-ai-edit' ),
		);
	}

	/**
	 * Eine Seite auslesen und einzelne Textstellen ersetzen.
	 *
	 * @return void
	 */
	protected static function seiten_lesen(): void {
		wp_register_ability(
			'kiedit/get-page',
			array(
				'label'               => __( 'Seite auslesen', 'wp-ai-edit' ),
				'description'         => __( 'Liefert Titel, Status, Link und den vollständigen Block-Inhalt einer Seite. Immer aufrufen, bevor du Inhalte änderst — sonst überschreibst du blind.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array( 'type' => 'integer', 'description' => __( 'Seiten-ID aus „Website einlesen".', 'wp-ai-edit' ) ),
						'slug' => array( 'type' => 'string', 'description' => __( 'Alternativ: der Slug der Seite.', 'wp-ai-edit' ) ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_seite_lesen' ),
				'permission_callback' => array( __CLASS__, 'darf_lesen' ),
				'meta'                => array( 'readonly' => true, 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'kiedit/replace-text',
			array(
				'label'               => __( 'Textstelle ersetzen', 'wp-ai-edit' ),
				'description'         => __( 'Ersetzt eine genau bezeichnete Textstelle auf einer Seite und lässt alles andere unberührt. Das ist der sichere Weg für kleine Änderungen wie Öffnungszeiten, Preise oder Telefonnummern. Erst „Seite auslesen", dann diese Fähigkeit.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array( 'type' => 'integer', 'description' => __( 'Seiten-ID.', 'wp-ai-edit' ) ),
						'suchen'   => array( 'type' => 'string', 'description' => __( 'Die exakte Stelle, wie sie aktuell auf der Seite steht.', 'wp-ai-edit' ) ),
						'ersetzen' => array( 'type' => 'string', 'description' => __( 'Der neue Text.', 'wp-ai-edit' ) ),
						'alle'     => array( 'type' => 'boolean', 'description' => __( 'Auch weitere Vorkommen ersetzen. Standard: nur das erste.', 'wp-ai-edit' ) ),
						'direkt'   => array( 'type' => 'boolean', 'description' => __( 'Nur setzen, wenn der Nutzer sofortige Änderung verlangt. Sonst leer lassen: wird als Vorschlag vorgelegt.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'id', 'suchen', 'ersetzen' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_text_ersetzen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Prüft, ob eine Seite mit einem Seitenbauer (Elementor, Divi, WPBakery)
	 * gebaut ist. Deren Inhalte liegen NICHT in post_content, sondern in eigenen
	 * Feldern — Schreibversuche dort wären wirkungslos.
	 *
	 * @param int $id Seiten-ID.
	 * @return string Name des Seitenbauers oder leer.
	 */
	public static function seitenbauer( int $id ): string {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}

		// Elementor
		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) ) {
			return 'Elementor';
		}
		// Divi
		if ( 'on' === get_post_meta( $id, '_et_pb_use_builder', true ) ) {
			return 'Divi';
		}
		// WPBakery / Visual Composer
		if ( 'true' === get_post_meta( $id, '_wpb_vc_js_status', true ) ) {
			return 'WPBakery';
		}
		// Bricks (Wert ist ein Array)
		$bricks = get_post_meta( $id, '_bricks_page_content_2', true );
		if ( ! empty( $bricks ) ) {
			return 'Bricks';
		}
		// Oxygen
		$oxygen = get_post_meta( $id, 'ct_builder_shortcodes', true );
		if ( ! empty( $oxygen ) ) {
			return 'Oxygen';
		}

		return '';
	}

	/**
	 * Meldung, wenn eine Seite mit einem Seitenbauer gebaut ist.
	 *
	 * @param int    $id   Seiten-ID.
	 * @param string $name Name des Seitenbauers.
	 * @return WP_Error
	 */
	protected static function bauer_fehler( int $id, string $name ): WP_Error {
		return new WP_Error(
			'kiedit_seitenbauer',
			sprintf(
				/* translators: 1: Name des Seitenbauers, 2: Seiten-ID */
				__(
					'Diese Seite ist mit %1$s gebaut (ID %2$d). Ihr Inhalt liegt nicht im WordPress-Inhalt, sondern in eigenen Feldern. Ein Schreibversuch hier würde nichts bewirken und die Seite nur beschädigen. Sage dem Nutzer, dass diese Seite nicht über WordPress-Inhalte bearbeitbar ist — er muss sie in %1$s selbst ändern.',
					'wp-ai-edit'
				),
				$name,
				$id
			)
		);
	}

	/**
	 * Callback: Seite auslesen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_seite_lesen( $input = array() ) {
		$post = null;
		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
		} elseif ( ! empty( $input['slug'] ) ) {
			$post = get_page_by_path( sanitize_title( (string) $input['slug'] ), OBJECT, array( 'page', 'post' ) );
		}
		if ( ! $post ) {
			return new WP_Error( 'kiedit_unbekannt', __( 'Seite nicht gefunden.', 'wp-ai-edit' ) );
		}

		return array(
			'id'      => (int) $post->ID,
			'titel'   => $post->post_title,
			'slug'    => $post->post_name,
			'status'  => $post->post_status,
			'link'    => get_permalink( $post->ID ),
			'geaendert' => $post->post_modified,
			'inhalt'  => $post->post_content,
			'zeichen' => mb_strlen( $post->post_content ),
			'bloecke' => count( array_filter( (array) parse_blocks( $post->post_content ), static fn( $b ) => ! empty( $b['blockName'] ) ) ),
			'seitenbauer' => self::seitenbauer( (int) $post->ID ),
			'bearbeitbar' => '' === self::seitenbauer( (int) $post->ID )
				? __( 'ja, der Inhalt liegt in WordPress', 'wp-ai-edit' )
				: __( 'NEIN — diese Seite wird von einem Seitenbauer verwaltet. Nicht über update-page oder replace-text bearbeiten.', 'wp-ai-edit' ),
		);
	}

	/**
	 * Callback: Textstelle ersetzen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_text_ersetzen( $input = array() ) {
		$id     = (int) ( $input['id'] ?? 0 );
		$suchen = (string) ( $input['suchen'] ?? '' );
		$neu    = (string) ( $input['ersetzen'] ?? '' );
		$post   = get_post( $id );

		if ( ! $post ) {
			return new WP_Error( 'kiedit_unbekannt', __( 'Seite nicht gefunden.', 'wp-ai-edit' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'kiedit_rechte', __( 'Keine Berechtigung für diese Seite.', 'wp-ai-edit' ) );
		}
		if ( '' === $suchen ) {
			return new WP_Error( 'kiedit_suchen', __( 'Die zu ersetzende Stelle fehlt.', 'wp-ai-edit' ) );
		}

		$bauer = self::seitenbauer( $id );
		if ( '' !== $bauer ) {
			return self::bauer_fehler( $id, $bauer );
		}

		$anzahl = substr_count( $post->post_content, $suchen );
		if ( 0 === $anzahl ) {
			return new WP_Error(
				'kiedit_nicht_gefunden',
				__( 'Diese Stelle steht nicht auf der Seite. Erst „Seite auslesen" und den Text genau übernehmen.', 'wp-ai-edit' )
			);
		}

		$alle      = ! empty( $input['alle'] );
		$geaendert = 0;

		if ( $alle ) {
			$inhalt_neu = str_replace( $suchen, $neu, $post->post_content );
			$geaendert  = $anzahl;
		} else {
			$pos = strpos( $post->post_content, $suchen );
			$inhalt_neu = substr_replace( $post->post_content, $neu, $pos, strlen( $suchen ) );
			$geaendert  = 1;
		}

		$beschreibung = sprintf(
			/* translators: 1: Seitentitel, 2: Anzahl, 3: alter Text, 4: neuer Text */
			__( 'Auf „%1$s" %2$d Textstelle(n) ersetzen: „%3$s" → „%4$s"', 'wp-ai-edit' ),
			$post->post_title,
			$geaendert,
			mb_substr( $suchen, 0, 60 ),
			mb_substr( $neu, 0, 60 )
		);

		// Vorschlags-Modus.
		if ( ! WP_AI_Edit_Workflow::direkt_erlaubt( $input ) ) {
			$v = WP_AI_Edit_Workflow::einreihen(
				'update-page',
				array( 'id' => $id, 'titel' => $post->post_title ),
				array( 'inhalt' => $inhalt_neu ),
				array( 'inhalt' => $post->post_content ),
				$beschreibung
			);
			return array(
				'vorgeschlagen'    => true,
				'vorschlag_id'     => $v['id'],
				'vorschau'         => $v['vorschau'],
				'nicht_angewendet' => true,
				'stellen'          => $geaendert,
				'beschreibung'     => $beschreibung,
				'hinweis'          => __( 'Nur vorgeschlagen, nichts geändert. Der Nutzer bestätigt im Chat.', 'wp-ai-edit' ),
			);
		}

		self::sicherung_anlegen( 'replace-text', $id, $post->post_content, $post->post_title );
		$r = wp_update_post( array( 'ID' => $id, 'post_content' => $inhalt_neu ), true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::protokoll( 'replace-text', $beschreibung, 'ok' );

		return array(
			'geaendert' => true,
			'stellen'   => $geaendert,
			'id'        => $id,
			'link'      => get_permalink( $id ),
			'beschreibung' => $beschreibung,
		);
	}

	/**
	 * Fähigkeiten für den Umgang mit Vorschlägen.
	 *
	 * @return void
	 */
	protected static function vorschlaege(): void {
		wp_register_ability(
			'kiedit/list-pending',
			array(
				'label'               => __( 'Offene Vorschläge auflisten', 'wp-ai-edit' ),
				'description'         => __( 'Zeigt Änderungen, die vorgeschlagen, aber noch nicht übernommen wurden. Vor dem Anwenden aufrufen, um die Vorschlags-ID zu erhalten.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
				'execute_callback'    => array( __CLASS__, 'cb_vorschlaege_auflisten' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'readonly' => true, 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'kiedit/apply-pending',
			array(
				'label'               => __( 'Vorschlag übernehmen', 'wp-ai-edit' ),
				'description'         => __( 'Wendet einen vorgeschlagenen Entwurf an. Nur aufrufen, wenn der Nutzer ausdrücklich zugestimmt hat.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'vorschlag_id' => array( 'type' => 'string', 'description' => __( 'ID aus „Offene Vorschläge auflisten".', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'vorschlag_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_vorschlag_uebernehmen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'kiedit/discard-pending',
			array(
				'label'               => __( 'Vorschlag verwerfen', 'wp-ai-edit' ),
				'description'         => __( 'Verwirft einen vorgeschlagenen Entwurf, ohne etwas zu ändern.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'vorschlag_id' => array( 'type' => 'string', 'description' => __( 'ID aus „Offene Vorschläge auflisten".', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'vorschlag_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_vorschlag_verwerfen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: offene Vorschläge auflisten.
	 *
	 * @return array
	 */
	public static function cb_vorschlaege_auflisten(): array {
		$liste = array();
		foreach ( WP_AI_Edit_Workflow::alle() as $v ) {
			$liste[] = array(
				'vorschlag_id' => $v['id'],
				'art'          => $v['art'],
				'zeit'         => $v['zeit'],
				'beschreibung' => $v['text'],
				'vorschau'     => $v['vorschau'],
				'ziel_id'      => (int) ( $v['ziel']['id'] ?? 0 ),
			);
		}
		return array(
			'anzahl'     => count( $liste ),
			'vorschlaege' => $liste,
			'arbeitsweise' => WP_AI_Edit_Workflow::modus(),
		);
	}

	/**
	 * Callback: Vorschlag übernehmen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_vorschlag_uebernehmen( $input = array() ) {
		$id = sanitize_text_field( (string) ( $input['vorschlag_id'] ?? '' ) );
		if ( '' === $id ) {
			return new WP_Error( 'kiedit_id', __( 'Vorschlags-ID fehlt.', 'wp-ai-edit' ) );
		}
		return WP_AI_Edit_Workflow::anwenden( $id );
	}

	/**
	 * Callback: Vorschlag verwerfen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_vorschlag_verwerfen( $input = array() ) {
		$id = sanitize_text_field( (string) ( $input['vorschlag_id'] ?? '' ) );
		if ( '' === $id ) {
			return new WP_Error( 'kiedit_id', __( 'Vorschlags-ID fehlt.', 'wp-ai-edit' ) );
		}
		$ok = WP_AI_Edit_Workflow::verwerfen( $id );
		if ( ! $ok ) {
			return new WP_Error( 'kiedit_vorschlag', __( 'Vorschlag nicht gefunden.', 'wp-ai-edit' ) );
		}
		return array( 'verworfen' => true, 'vorschlag_id' => $id );
	}

	/**
	 * Prüft die Grundberechtigung für schreibende Fähigkeiten.
	 *
	 * Muss public sein: die Registrierung ruft den Callback von außerhalb
	 * der Klasse auf, ein protected/private Callback wird abgelehnt.
	 *
	 * @return bool
	 */
	/**
	 * Lesen darf, wer Inhalte bearbeiten darf.
	 *
	 * @return bool
	 */
	public static function darf_lesen(): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
	}

	public static function darf_schreiben(): bool {
		return current_user_can( 'edit_pages' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * Name der aktuellen Fähigkeit (für Protokollierung).
	 *
	 * @param string $name Fähigkeitsname.
	 * @return void
	 */
	protected static function protokoll( string $name, $eingabe, $ergebnis ): void {
		$log = get_option( 'wp_ai_edit_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'zeit'    => current_time( 'mysql' ),
				'nutzer'  => get_current_user_id(),
				'ability' => $name,
				'eingabe' => wp_json_encode( $eingabe ),
				'status'  => is_wp_error( $ergebnis ) ? 'fehler' : 'ok',
			)
		);
		update_option( 'wp_ai_edit_log', array_slice( $log, 0, 200 ), false );
	}

	/* ------------------------------------------------------------------ */
	/* 1. Website einlesen                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Fähigkeit: Website-Zustand als kompaktes JSON.
	 *
	 * @return void
	 */
	protected static function site_einlesen(): void {
		wp_register_ability(
			'kiedit/inspect-site',
			array(
				'label'               => __( 'Website einlesen', 'wp-ai-edit' ),
				'description'         => __( 'Liefert den aktuellen Zustand der Website: Identität, Theme, Seiten mit IDs und Auszügen, Menüs, aktive Plugins. Vor jeder Änderung aufrufen.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'bereiche' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string', 'enum' => array( 'identitaet', 'theme', 'seiten', 'menues', 'plugins' ) ),
							'description' => __( 'Optional: nur diese Bereiche zurückgeben.', 'wp-ai-edit' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => array( __CLASS__, 'cb_site_einlesen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'readonly' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Website einlesen.
	 *
	 * @param array $input Eingabe.
	 * @return array
	 */
	public static function cb_site_einlesen( $input = array() ): array {
		$bereiche = isset( $input['bereiche'] ) && is_array( $input['bereiche'] ) ? $input['bereiche'] : array();
		$alle     = empty( $bereiche );
		$out      = array();

		if ( $alle || in_array( 'identitaet', $bereiche, true ) ) {
			$out['identitaet'] = array(
				'titel'       => get_option( 'blogname' ),
				'beschreibung' => get_option( 'blogdescription' ),
				'url'         => home_url(),
				'sprache'     => get_locale(),
			);
		}

		if ( $alle || in_array( 'theme', $bereiche, true ) ) {
			$theme              = wp_get_theme();
			$out['theme']       = array(
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'block_theme' => $theme->is_block_theme(),
				'stylesheet'  => get_stylesheet(),
			);
		}

		if ( $alle || in_array( 'seiten', $bereiche, true ) ) {
			$seiten = get_posts(
				array(
					'post_type'      => array( 'page', 'post' ),
					'post_status'    => array( 'publish', 'draft' ),
					'numberposts'    => 30,
					'orderby'        => 'menu_order date',
					'order'          => 'ASC',
				)
			);
			$liste  = array();
			foreach ( $seiten as $p ) {
				$liste[] = array(
					'id'      => $p->ID,
					'typ'     => $p->post_type,
					'status'  => $p->post_status,
					'titel'   => $p->post_title,
					'slug'    => $p->post_name,
					'auszug'  => mb_substr( wp_strip_all_tags( $p->post_content ), 0, 220 ),
					'zeichen' => mb_strlen( $p->post_content ),
				);
			}
			$out['seiten'] = $liste;
		}

		if ( $alle || in_array( 'menues', $bereiche, true ) ) {
			$menues = wp_get_nav_menus();
			$liste  = array();
			foreach ( $menues as $m ) {
				$items = wp_get_nav_menu_items( $m->term_id );
				$liste[] = array(
					'id'    => $m->term_id,
					'name'  => $m->name,
					'punkte' => is_array( $items ) ? array_map(
						static function ( $i ) {
							return array( 'titel' => $i->title, 'url' => $i->url );
						},
						$items
					) : array(),
				);
			}
			$out['menues'] = $liste;
		}

		if ( $alle || in_array( 'plugins', $bereiche, true ) ) {
			$out['plugins'] = self::plugins_daten();
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* 2. Seiten schreiben                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Fähigkeit: bestehende Seite aktualisieren.
	 *
	 * @return void
	 */
	protected static function seite_schreiben(): void {
		wp_register_ability(
			'kiedit/update-page',
			array(
				'label'               => __( 'Seite aktualisieren', 'wp-ai-edit' ),
				'description'         => __( 'Ersetzt den Inhalt einer bestehenden Seite. Der Inhalt muss gültiges Block-Markup sein. Vorher wird automatisch eine Sicherung angelegt.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array( 'type' => 'integer', 'description' => __( 'Seiten-ID.', 'wp-ai-edit' ) ),
						'titel'   => array( 'type' => 'string', 'description' => __( 'Optional: neuer Titel.', 'wp-ai-edit' ) ),
						'inhalt'  => array( 'type' => 'string', 'description' => __( 'Neuer Inhalt als Block-Markup.', 'wp-ai-edit' ) ),
						'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'private' ) ),
						'direkt'  => array( 'type' => 'boolean', 'description' => __( 'Nur setzen, wenn der Nutzer ausdrücklich sofortige Veröffentlichung verlangt. Sonst leer lassen: die Änderung wird dann als Vorschlag vorgelegt.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'id', 'inhalt' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_seite_schreiben' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'destructive' => true, 'idempotent' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Seite aktualisieren.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_seite_schreiben( $input = array() ) {
		$id     = (int) ( $input['id'] ?? 0 );
		$inhalt = (string) ( $input['inhalt'] ?? '' );
		$post   = get_post( $id );

		if ( ! $post ) {
			return new WP_Error( 'kiedit_unbekannt', __( 'Seite nicht gefunden.', 'wp-ai-edit' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'kiedit_rechte', __( 'Keine Berechtigung für diese Seite.', 'wp-ai-edit' ) );
		}
		if ( '' === trim( $inhalt ) ) {
			return new WP_Error( 'kiedit_leer', __( 'Der neue Inhalt ist leer. Abbruch, um nichts zu überschreiben.', 'wp-ai-edit' ) );
		}

		$bauer = self::seitenbauer( $id );
		if ( '' !== $bauer ) {
			return self::bauer_fehler( $id, $bauer );
		}

		// Vorschlags-Modus: nicht anwenden, sondern zur Bestätigung vorlegen.
		if ( ! WP_AI_Edit_Workflow::direkt_erlaubt( $input ) ) {
			$neu = array( 'inhalt' => wp_kses_post( $inhalt ) );
			if ( ! empty( $input['titel'] ) ) {
				$neu['titel'] = sanitize_text_field( (string) $input['titel'] );
			}
			if ( ! empty( $input['status'] ) ) {
				$neu['status'] = (string) $input['status'];
			}
			$text = sprintf(
				/* translators: 1: Seitentitel, 2: Zeichenzahl */
				__( 'Seite „%1$s" ändern (%2$d Zeichen neu)', 'wp-ai-edit' ),
				$post->post_title,
				mb_strlen( $inhalt )
			);
			$v = WP_AI_Edit_Workflow::einreihen( 'update-page', array( 'id' => $id, 'titel' => $post->post_title ), $neu, array( 'inhalt' => $post->post_content ), $text );

			return array(
				'vorgeschlagen' => true,
				'vorschlag_id'  => $v['id'],
				'vorschau'      => $v['vorschau'],
				'nicht_angewendet' => true,
				'beschreibung'  => $text,
				'hinweis'       => __( 'Die Änderung ist NICHT live. Der Nutzer muss sie im Chat bestätigen oder mit direkt=true erneut anfordern.', 'wp-ai-edit' ),
			);
		}

		self::sicherung_anlegen( 'update-page', $id, $post->post_content, $post->post_title );

		$werte = array(
			'ID'           => $id,
			'post_content' => wp_kses_post( $inhalt ),
		);
		if ( isset( $input['titel'] ) && '' !== $input['titel'] ) {
			$werte['post_title'] = sanitize_text_field( (string) $input['titel'] );
		}
		if ( isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'draft', 'private' ), true ) ) {
			$werte['post_status'] = $input['status'];
		}

		$neu = wp_update_post( $werte, true );
		if ( is_wp_error( $neu ) ) {
			return $neu;
		}
		self::protokoll( 'kiedit/update-page', $input, $neu );

		return array(
			'id'      => $id,
			'status'  => get_post_status( $id ),
			'link'    => get_permalink( $id ),
			'zeichen' => mb_strlen( $inhalt ),
		);
	}

	/**
	 * Fähigkeit: neue Seite anlegen.
	 *
	 * @return void
	 */
	protected static function seite_anlegen(): void {
		wp_register_ability(
			'kiedit/create-page',
			array(
				'label'               => __( 'Seite anlegen', 'wp-ai-edit' ),
				'description'         => __( 'Legt eine neue Seite mit Block-Markup an.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'titel'  => array( 'type' => 'string' ),
						'slug'   => array( 'type' => 'string' ),
						'inhalt' => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ) ),
						'direkt' => array( 'type' => 'boolean', 'description' => __( 'Nur setzen, wenn der Nutzer ausdrücklich sofortige Veröffentlichung verlangt. Sonst leer lassen: die Seite wird dann nur vorgeschlagen.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'titel', 'inhalt' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_seite_anlegen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Seite anlegen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_seite_anlegen( $input = array() ) {
		$titel = sanitize_text_field( (string) ( $input['titel'] ?? '' ) );
		if ( '' === $titel ) {
			return new WP_Error( 'kiedit_titel', __( 'Titel fehlt.', 'wp-ai-edit' ) );
		}

		$ist_entwurf = in_array( ( $input['status'] ?? 'draft' ), array( 'publish', 'draft' ), true ) ? $input['status'] : 'draft';

		// Vorschlags-Modus: nur vorlegen.
		if ( ! WP_AI_Edit_Workflow::direkt_erlaubt( $input ) ) {
			$text = sprintf(
				/* translators: %s: Seitentitel */
				__( 'Neue Seite „%s" anlegen', 'wp-ai-edit' ),
				$titel
			);
			$v = WP_AI_Edit_Workflow::einreihen(
				'create-page',
				array( 'titel' => $titel ),
				array(
					'titel'  => $titel,
					'slug'   => sanitize_title( (string) ( $input['slug'] ?? $titel ) ),
					'inhalt' => (string) ( $input['inhalt'] ?? '' ),
					'status' => $ist_entwurf,
				),
				array(),
				$text
			);

			return array(
				'vorgeschlagen'    => true,
				'vorschlag_id'     => $v['id'],
				'vorschau'         => $v['vorschau'],
				'nicht_angewendet' => true,
				'beschreibung'     => $text,
				'hinweis'          => __( 'Die Seite ist noch NICHT angelegt. Der Nutzer muss sie im Chat bestätigen.', 'wp-ai-edit' ),
			);
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $titel,
				'post_name'    => sanitize_title( (string) ( $input['slug'] ?? $titel ) ),
				'post_content' => wp_kses_post( (string) ( $input['inhalt'] ?? '' ) ),
				'post_status'  => $ist_entwurf,
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::protokoll( 'kiedit/create-page', $input, $id );

		return array(
			'id'     => $id,
			'titel'  => $titel,
			'status' => get_post_status( $id ),
			'link'   => get_permalink( $id ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* 3. Einstellungen                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Fähigkeit: Website-Optionen setzen.
	 *
	 * @return void
	 */
	protected static function optionen_setzen(): void {
		wp_register_ability(
			'kiedit/set-options',
			array(
				'label'               => __( 'Einstellungen ändern', 'wp-ai-edit' ),
				'description'         => __( 'Ändert freigegebene Website-Einstellungen: Titel, Untertitel, Beitragsanzahl, Datumsformat und weitere.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'optionen' => array(
							'type'        => 'object',
							'description' => __( 'Schlüssel-Wert-Paare der zu ändernden Optionen.', 'wp-ai-edit' ),
						),
						'direkt'   => array( 'type' => 'boolean', 'description' => __( 'Nur setzen, wenn der Nutzer sofortige Änderung verlangt. Sonst leer lassen: wird als Vorschlag vorgelegt.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'optionen' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_optionen_setzen' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Optionen setzen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_optionen_setzen( $input = array() ) {
		$erlaubt = self::erlaubte_optionen();
		$werte   = isset( $input['optionen'] ) && is_array( $input['optionen'] ) ? $input['optionen'] : array();

		if ( empty( $werte ) ) {
			return new WP_Error( 'kiedit_leer', __( 'Keine Optionen angegeben.', 'wp-ai-edit' ) );
		}

		// Vorschlags-Modus: nur vorlegen.
		if ( ! WP_AI_Edit_Workflow::direkt_erlaubt( $input ) ) {
			$namen = array();
			$alt   = array();
			foreach ( $werte as $k => $v ) {
				if ( isset( $erlaubt[ $k ] ) ) {
					$namen[] = $k;
					$alt[ $k ] = get_option( $k );
				}
			}
			$text = sprintf(
				/* translators: %s: Optionsnamen */
				__( 'Einstellungen ändern: %s', 'wp-ai-edit' ),
				implode( ', ', $namen )
			);
			$v = WP_AI_Edit_Workflow::einreihen( 'set-options', array(), $werte, $alt, $text );

			return array(
				'vorgeschlagen'    => true,
				'vorschlag_id'     => $v['id'],
				'nicht_angewendet' => true,
				'beschreibung'     => $text,
				'hinweis'          => __( 'Die Einstellungen sind NICHT geändert. Der Nutzer muss bestätigen.', 'wp-ai-edit' ),
			);
		}

		$geaendert = array();
		$abgelehnt = array();

		foreach ( $werte as $key => $wert ) {
			if ( ! isset( $erlaubt[ $key ] ) ) {
				$abgelehnt[] = $key;
				continue;
			}
			self::sicherung_anlegen( 'set-options', 0, (string) get_option( $key ), $key );

			if ( 'integer' === $erlaubt[ $key ] ) {
				$wert = (int) $wert;
			} elseif ( in_array( $key, array( 'blogname', 'blogdescription' ), true ) ) {
				$wert = sanitize_text_field( (string) $wert );
			} elseif ( 'admin_email' === $key ) {
				$wert = sanitize_email( (string) $wert );
			} else {
				$wert = sanitize_text_field( (string) $wert );
			}

			update_option( $key, $wert );
			$geaendert[ $key ] = $wert;
		}

		self::protokoll( 'kiedit/set-options', $input, $geaendert );

		return array(
			'geaendert' => $geaendert,
			'abgelehnt' => $abgelehnt,
			'erlaubt'   => array_keys( $erlaubt ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* 4. Plugins                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Liefert Plugin-Daten.
	 *
	 * @return array
	 */
	protected static function plugins_daten(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$alle   = get_plugins();
		$aktiv  = (array) get_option( 'active_plugins', array() );
		$liste  = array();

		foreach ( $alle as $datei => $info ) {
			$liste[] = array(
				'datei'   => $datei,
				'slug'    => dirname( $datei ),
				'name'    => $info['Name'],
				'version' => $info['Version'],
				'aktiv'   => in_array( $datei, $aktiv, true ),
			);
		}
		return $liste;
	}

	/**
	 * Fähigkeit: Plugins auflisten.
	 *
	 * @return void
	 */
	protected static function plugins_auflisten(): void {
		wp_register_ability(
			'kiedit/list-plugins',
			array(
				'label'               => __( 'Plugins auflisten', 'wp-ai-edit' ),
				'description'         => __( 'Listet alle installierten Plugins mit Version und Aktivierungsstatus.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
				'execute_callback'    => array( __CLASS__, 'cb_plugins_auflisten' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'readonly' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Plugins auflisten.
	 *
	 * @return array
	 */
	public static function cb_plugins_auflisten(): array {
		return self::plugins_daten();
	}

	/**
	 * Fähigkeit: Plugin installieren.
	 *
	 * @return void
	 */
	protected static function plugin_installieren(): void {
		wp_register_ability(
			'kiedit/install-plugin',
			array(
				'label'               => __( 'Plugin installieren', 'wp-ai-edit' ),
				'description'         => __( 'Installiert ein Plugin aus dem offiziellen WordPress-Repository über seinen Slug, zum Beispiel "contact-form-7". Fremde URLs sind nicht erlaubt.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'     => array( 'type' => 'string', 'description' => __( 'Repository-Slug, z. B. contact-form-7.', 'wp-ai-edit' ) ),
						'aktivieren' => array( 'type' => 'boolean', 'description' => __( 'Nach der Installation aktivieren.', 'wp-ai-edit' ) ),
						'version'  => array( 'type' => 'string', 'description' => __( 'Optional: feste Version.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_plugin_installieren' ),
				'permission_callback' => static fn() => current_user_can( 'install_plugins' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Plugin installieren.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_plugin_installieren( $input = array() ) {
		$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'kiedit_slug', __( 'Slug fehlt.', 'wp-ai-edit' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Nur aus dem offiziellen Repository.
		$info = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $info ) ) {
			return new WP_Error( 'kiedit_unbekannt', __( 'Plugin im Repository nicht gefunden.', 'wp-ai-edit' ) );
		}

		$version = isset( $input['version'] ) && '' !== $input['version'] ? (string) $input['version'] : '';
		$quelle  = $version
			? sprintf( 'https://downloads.wordpress.org/plugin/%s.%s.zip', $slug, $version )
			: sprintf( 'https://downloads.wordpress.org/plugin/%s.zip', $slug );

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$ergebnis = $upgrader->install( $quelle );

		if ( is_wp_error( $ergebnis ) ) {
			return $ergebnis;
		}
		if ( true !== $ergebnis ) {
			return new WP_Error( 'kiedit_install', __( 'Installation fehlgeschlagen.', 'wp-ai-edit' ) );
		}

		$datei   = $upgrader->plugin_info();
		$aktiviert = false;

		if ( ! empty( $input['aktivieren'] ) && $datei ) {
			$aktiviert = null === activate_plugin( $datei );
		}

		self::protokoll( 'kiedit/install-plugin', $input, $datei );

		return array(
			'slug'      => $slug,
			'version'   => $info->version ?? $version,
			'datei'     => $datei,
			'aktiviert' => $aktiviert,
			'name'      => $info->name ?? $slug,
		);
	}

	/**
	 * Fähigkeit: Plugin aktivieren oder deaktivieren.
	 *
	 * @return void
	 */
	protected static function plugin_aktivieren(): void {
		wp_register_ability(
			'kiedit/toggle-plugin',
			array(
				'label'               => __( 'Plugin aktivieren/deaktivieren', 'wp-ai-edit' ),
				'description'         => __( 'Aktiviert oder deaktiviert ein bereits installiertes Plugin. Der Dateipfad aus "Plugins auflisten" wird benötigt.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'datei'  => array( 'type' => 'string', 'description' => __( 'Plugin-Datei, z. B. contact-form-7/wp-contact-form-7.php', 'wp-ai-edit' ) ),
						'aktiv'  => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'datei', 'aktiv' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_plugin_aktivieren' ),
				'permission_callback' => static fn() => current_user_can( 'activate_plugins' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Plugin aktivieren/deaktivieren.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_plugin_aktivieren( $input = array() ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$datei = (string) ( $input['datei'] ?? '' );
		$aktiv = ! empty( $input['aktiv'] );

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $datei ) ) {
			return new WP_Error( 'kiedit_datei', __( 'Plugin-Datei nicht gefunden.', 'wp-ai-edit' ) );
		}

		if ( $aktiv ) {
			$r = activate_plugin( $datei );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			deactivate_plugins( array( $datei ) );
		}

		self::protokoll( 'kiedit/toggle-plugin', $input, $datei );

		return array( 'datei' => $datei, 'aktiv' => $aktiv );
	}

	/**
	 * Fähigkeit: Plugin-Einstellung ändern.
	 *
	 * @return void
	 */
	protected static function plugin_option_setzen(): void {
		wp_register_ability(
			'kiedit/set-plugin-setting',
			array(
				'label'               => __( 'Plugin-Einstellung ändern', 'wp-ai-edit' ),
				'description'         => __( 'Schreibt eine einzelne Plugin-Option. Nur Optionsnamen mit mindestens einem Unterstrich und einer der Präfixe der aktiven Plugins sind erlaubt.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'option' => array( 'type' => 'string' ),
						'wert'   => array( 'description' => __( 'Neuer Wert (Text, Zahl, Wahrheitswert oder Objekt).', 'wp-ai-edit' ) ),
						'direkt' => array( 'type' => 'boolean', 'description' => __( 'Nur setzen, wenn der Nutzer sofortige Änderung verlangt. Sonst leer lassen: wird als Vorschlag vorgelegt.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'option', 'wert' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_plugin_option_setzen' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Plugin-Einstellung ändern.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_plugin_option_setzen( $input = array() ) {
		$option = sanitize_key( (string) ( $input['option'] ?? '' ) );

		if ( '' === $option || ! str_contains( $option, '_' ) ) {
			return new WP_Error( 'kiedit_option', __( 'Optionsname unplausibel.', 'wp-ai-edit' ) );
		}
		// Core-Optionen nur ueber set-options.
		if ( isset( self::erlaubte_optionen()[ $option ] ) ) {
			return new WP_Error( 'kiedit_option', __( 'Core-Option: bitte set-options verwenden.', 'wp-ai-edit' ) );
		}

		$wert = $input['wert'] ?? '';

		// Vorschlags-Modus: nur vorlegen.
		if ( ! WP_AI_Edit_Workflow::direkt_erlaubt( $input ) ) {
			$text = sprintf(
				/* translators: %s: Optionsname */
				__( 'Plugin-Einstellung ändern: %s', 'wp-ai-edit' ),
				$option
			);
			$v = WP_AI_Edit_Workflow::einreihen(
				'set-plugin-setting',
				array( 'option' => $option ),
				array( 'wert' => $wert ),
				array( 'wert' => get_option( $option ) ),
				$text
			);

			return array(
				'vorgeschlagen'    => true,
				'vorschlag_id'     => $v['id'],
				'nicht_angewendet' => true,
				'beschreibung'     => $text,
				'hinweis'          => __( 'Die Einstellung ist NICHT geändert. Der Nutzer muss bestätigen.', 'wp-ai-edit' ),
			);
		}

		self::sicherung_anlegen( 'set-plugin-setting', 0, (string) get_option( $option ), $option );

		if ( is_array( $wert ) ) {
			$wert = map_deep( $wert, 'sanitize_text_field' );
		} elseif ( is_bool( $wert ) || is_int( $wert ) || is_float( $wert ) ) {
			$wert = $wert;
		} else {
			$wert = sanitize_textarea_field( (string) $wert );
		}

		update_option( $option, $wert );
		self::protokoll( 'kiedit/set-plugin-setting', $input, $option );

		return array( 'option' => $option, 'gespeichert' => true );
	}

	/* ------------------------------------------------------------------ */
	/* 5. Designs fremder Seiten einlesen                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Fähigkeit: Design-Inspiration von einer URL einlesen.
	 *
	 * @return void
	 */
	protected static function design_einlesen(): void {
		wp_register_ability(
			'kiedit/fetch-design',
			array(
				'label'               => __( 'Design einer fremden Seite einlesen', 'wp-ai-edit' ),
				'description'         => __( 'Liest eine öffentliche Webseite und extrahiert Farben, Schriften, Überschriftenstruktur und Layout-Abschnitte als Inspiration. Gibt kompaktes JSON zurück.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'        => array( 'type' => 'string', 'description' => __( 'Vollständige URL mit https://', 'wp-ai-edit' ) ),
						'umfang'     => array( 'type' => 'string', 'enum' => array( 'kurz', 'voll' ) ),
					),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_design_einlesen' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'readonly' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Design einlesen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_design_einlesen( $input = array() ) {
		$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'kiedit_url', __( 'URL fehlt.', 'wp-ai-edit' ) );
		}

		$analyse = WP_AI_Edit_Inspector::analysiere( $url, 'voll' === ( $input['umfang'] ?? 'kurz' ) );
		if ( is_wp_error( $analyse ) ) {
			return $analyse;
		}

		self::protokoll( 'kiedit/fetch-design', $input, $url );
		return $analyse;
	}

	/* ------------------------------------------------------------------ */
	/* 6. Sicherung und Rücksetzen                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Legt eine Sicherung des vorherigen Zustands an.
	 *
	 * @param string $art       Art der Änderung.
	 * @param int    $objekt_id Objekt-ID (0 wenn keins).
	 * @param string $alt       Alter Wert.
	 * @param string $bezeichnung Bezeichnung.
	 * @return void
	 */
	public static function sicherung_anlegen( string $art, int $objekt_id, string $alt, string $bezeichnung = '' ): void {
		$sicherungen = get_option( 'wp_ai_edit_snapshots', array() );
		if ( ! is_array( $sicherungen ) ) {
			$sicherungen = array();
		}
		array_unshift(
			$sicherungen,
			array(
				'zeit'        => current_time( 'mysql' ),
				'nutzer'      => get_current_user_id(),
				'art'         => $art,
				'objekt_id'   => $objekt_id,
				'bezeichnung' => $bezeichnung,
				'wert'        => $alt,
			)
		);
		update_option( 'wp_ai_edit_snapshots', array_slice( $sicherungen, 0, 100 ), false );
	}

	/**
	 * Fähigkeit: Sicherung anlegen (manueller Aufruf durch das Modell).
	 *
	 * @return void
	 */
	protected static function snapshot(): void {
		wp_register_ability(
			'kiedit/snapshot',
			array(
				'label'               => __( 'Sicherung anlegen', 'wp-ai-edit' ),
				'description'         => __( 'Sichert den aktuellen Inhalt einer Seite, bevor sie geändert wird.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array( 'type' => 'integer', 'description' => __( 'Seiten-ID.', 'wp-ai-edit' ) ),
						'bezeichnung' => array( 'type' => 'string' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_snapshot' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'idempotent' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Sicherung anlegen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_snapshot( $input = array() ) {
		$id   = (int) ( $input['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'kiedit_unbekannt', __( 'Seite nicht gefunden.', 'wp-ai-edit' ) );
		}
		self::sicherung_anlegen( 'manuell', $id, $post->post_content, (string) ( $input['bezeichnung'] ?? $post->post_title ) );
		return array( 'id' => $id, 'gesichert' => true, 'zeichen' => mb_strlen( $post->post_content ) );
	}

	/**
	 * Fähigkeit: Stand zurücksetzen.
	 *
	 * @return void
	 */
	protected static function rollback(): void {
		wp_register_ability(
			'kiedit/rollback',
			array(
				'label'               => __( 'Stand zurücksetzen', 'wp-ai-edit' ),
				'description'         => __( 'Stellt den Inhalt einer Seite aus der jüngsten Sicherung wieder her.', 'wp-ai-edit' ),
				'category'            => 'kiedit',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array( 'type' => 'integer', 'description' => __( 'Seiten-ID.', 'wp-ai-edit' ) ),
						'index' => array( 'type' => 'integer', 'description' => __( '0 = jüngste Sicherung.', 'wp-ai-edit' ) ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cb_rollback' ),
				'permission_callback' => array( __CLASS__, 'darf_schreiben' ),
				'meta'                => array( 'destructive' => true, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Callback: Stand zurücksetzen.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function cb_rollback( $input = array() ) {
		$id      = (int) ( $input['id'] ?? 0 );
		$index   = (int) ( $input['index'] ?? 0 );
		$alle    = get_option( 'wp_ai_edit_snapshots', array() );
		$passend = array();

		if ( is_array( $alle ) ) {
			foreach ( $alle as $s ) {
				if ( (int) $s['objekt_id'] === $id && ! empty( $s['wert'] ) ) {
					$passend[] = $s;
				}
			}
		}

		if ( ! isset( $passend[ $index ] ) ) {
			return new WP_Error( 'kiedit_sicherung', __( 'Keine passende Sicherung gefunden.', 'wp-ai-edit' ) );
		}

		$s = $passend[ $index ];
		self::sicherung_anlegen( 'rollback', $id, (string) get_post_field( 'post_content', $id ), 'vor Rücksetzung' );

		$r = wp_update_post( array( 'ID' => $id, 'post_content' => $s['wert'] ), true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::protokoll( 'kiedit/rollback', $input, $id );

		return array( 'id' => $id, 'wiederhergestellt_aus' => $s['zeit'] );
	}
}
