<?php
/**
 * Vorschlags-Schicht: Änderungen landen als Entwurf zur Bestätigung,
 * können aber auf Wunsch sofort angewendet werden.
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verwaltet vorgeschlagene Änderungen, deren Anwendung und die Vorschau.
 */
class WP_AI_Edit_Workflow {

	/**
	 * Option, in der die offenen Vorschläge liegen.
	 */
	public const OPT = 'wp_ai_edit_pending';

	/**
	 * Höchstzahl offener Vorschläge.
	 */
	public const MAX = 40;

	/**
	 * Arbeitsweise: 'stage' = immer vorschlagen, 'direct' = sofort anwenden.
	 *
	 * @return string
	 */
	public static function modus(): string {
		$s = WP_AI_Edit::settings();
		$m = isset( $s['workflow'] ) ? (string) $s['workflow'] : 'stage';
		return in_array( $m, array( 'stage', 'direct' ), true ) ? $m : 'stage';
	}

	/**
	 * Darf die Änderung sofort angewendet werden?
	 *
	 * Der Nutzer kann im Chat "mach direkt" sagen. Dann setzt das Modell
	 * das Feld direkt=true und die Änderung geht ohne Rückfrage live.
	 *
	 * @param array $input Eingabe der Fähigkeit.
	 * @return bool
	 */
	public static function direkt_erlaubt( array $input ): bool {
		if ( ! empty( $input['direkt'] ) ) {
			return true;
		}
		return 'direct' === self::modus();
	}

	/**
	 * Offene Vorschläge laden.
	 *
	 * @return array
	 */
	public static function alle(): array {
		$p = get_option( self::OPT, array() );
		return is_array( $p ) ? $p : array();
	}

	/**
	 * Einen Vorschlag speichern.
	 *
	 * @param string $art      Art: update-page, create-page, set-options, plugin.
	 * @param array  $ziel     Ziel, z. B. array( 'id' => 12 ).
	 * @param array  $neu      Neue Werte.
	 * @param array  $alt      Alte Werte (für die Rücknahme).
	 * @param string $text     Beschreibung für die Oberfläche.
	 * @return array Der angelegte Vorschlag.
	 */
	public static function einreihen( string $art, array $ziel, array $neu, array $alt, string $text ): array {
		$vorschlag = array(
			'id'      => substr( md5( $art . microtime( true ) . wp_rand() ), 0, 12 ),
			'zeit'    => current_time( 'mysql' ),
			'nutzer'  => get_current_user_id(),
			'herkunft' => ( class_exists( 'WP_AI_Edit_Fernzugriff' ) && WP_AI_Edit_Fernzugriff::ist_fern() ) ? 'fern' : 'lokal',
			'art'     => $art,
			'ziel'    => $ziel,
			'neu'     => $neu,
			'alt'     => $alt,
			'text'    => $text,
			'status'  => 'offen',
			'vorschau' => '',
		);

		$alle = self::alle();
		array_unshift( $alle, $vorschlag );
		update_option( self::OPT, array_slice( $alle, 0, self::MAX ), false );

		$vorschlag['vorschau'] = self::vorschau_url( $vorschlag['id'] );
		$alle[0]['vorschau']   = $vorschlag['vorschau'];
		update_option( self::OPT, $alle, false );

		self::protokoll( 'vorschlag', $art, $text );

		return $vorschlag;
	}

	/**
	 * Einen Vorschlag finden.
	 *
	 * @param string $id Vorschlags-ID.
	 * @return array|null
	 */
	public static function finden( string $id ): ?array {
		foreach ( self::alle() as $v ) {
			if ( $v['id'] === $id ) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Vorschlag verwerfen.
	 *
	 * @param string $id Vorschlags-ID.
	 * @return bool
	 */
	public static function verwerfen( string $id ): bool {
		$alle  = self::alle();
		$neu   = array();
		$treffer = false;

		foreach ( $alle as $v ) {
			if ( $v['id'] === $id ) {
				$treffer = true;
				continue;
			}
			$neu[] = $v;
		}
		if ( $treffer ) {
			update_option( self::OPT, $neu, false );
			self::protokoll( 'verworfen', 'vorschlag', $id );
		}
		return $treffer;
	}

	/**
	 * Vorschlag anwenden.
	 *
	 * @param string $id Vorschlags-ID.
	 * @return array|WP_Error
	 */
	public static function anwenden( string $id ) {
		$v = self::finden( $id );
		if ( ! $v ) {
			return new WP_Error( 'wpaie_vorschlag', __( 'Vorschlag nicht gefunden.', 'wp-ai-edit' ) );
		}

		switch ( $v['art'] ) {
			case 'update-page':
				$post_id = (int) ( $v['ziel']['id'] ?? 0 );
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return new WP_Error( 'wpaie_ziel', __( 'Seite nicht gefunden.', 'wp-ai-edit' ) );
				}
				WP_AI_Edit_Abilities::sicherung_anlegen( 'vorschlag-uebernommen', $post_id, $post->post_content, $post->post_title );

				$werte = array( 'ID' => $post_id, 'post_content' => $v['neu']['inhalt'] );
				if ( ! empty( $v['neu']['titel'] ) ) {
					$werte['post_title'] = $v['neu']['titel'];
				}
				if ( ! empty( $v['neu']['status'] ) ) {
					$werte['post_status'] = $v['neu']['status'];
				}
				$r = wp_update_post( $werte, true );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$ergebnis = array( 'id' => $post_id, 'link' => get_permalink( $post_id ), 'status' => get_post_status( $post_id ) );
				break;

			case 'create-page':
				$r = WP_AI_Edit_Abilities::cb_seite_anlegen( array_merge( $v['neu'], array( 'direkt' => true ) ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$ergebnis = $r;
				break;

			case 'set-options':
				$r = WP_AI_Edit_Abilities::cb_optionen_setzen( array( 'optionen' => $v['neu'], 'direkt' => true ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$ergebnis = $r;
				break;

			case 'set-plugin-setting':
				$r = WP_AI_Edit_Abilities::cb_plugin_option_setzen(
					array(
						'option' => (string) ( $v['ziel']['option'] ?? '' ),
						'wert'   => $v['neu']['wert'] ?? '',
						'direkt' => true,
					)
				);
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$ergebnis = $r;
				break;

			default:
				return new WP_Error( 'wpaie_art', __( 'Unbekannte Vorschlagsart.', 'wp-ai-edit' ) );
		}

		self::verwerfen( $id );
		self::protokoll( 'uebernommen', $v['art'], $v['text'] );

		return array(
			'uebernommen' => true,
			'art'         => $v['art'],
			'ergebnis'    => $ergebnis,
		);
	}

	/**
	 * Vorschau-URL für einen Vorschlag.
	 *
	 * @param string $id Vorschlags-ID.
	 * @return string
	 */
	public static function vorschau_url( string $id ): string {
		return add_query_arg(
			array( 'wpaie_vorschau' => $id ),
			home_url( '/' )
		);
	}

	/**
	 * Rendert die Vorschau für angemeldete Nutzer.
	 *
	 * Läuft auf init: ?wpaie_vorschau=<id> gibt die vorgeschlagene Fassung
	 * als eigenständige Seite aus, ohne die Live-Seite zu verändern.
	 *
	 * @return void
	 */
	public static function vorschau_ausgeben(): void {
		if ( ! isset( $_GET['wpaie_vorschau'] ) ) {
			return;
		}
		$id = sanitize_text_field( wp_unslash( $_GET['wpaie_vorschau'] ) );

		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Nicht angemeldet.', 'wp-ai-edit' ), 403 );
		}

		$v = self::finden( $id );
		if ( ! $v ) {
			wp_die( esc_html__( 'Vorschlag nicht gefunden oder bereits übernommen.', 'wp-ai-edit' ), 404 );
		}

		$inhalt = (string) ( $v['neu']['inhalt'] ?? '' );
		$titel  = (string) ( $v['neu']['titel'] ?? ( $v['ziel']['titel'] ?? '' ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( 'Vorschau: ' . $titel ); ?></title>
<?php wp_head(); ?>
<style>
	body.wpaie-vorschau { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
	.wpaie-leiste { position: sticky; top: 0; z-index: 9999; display: flex; gap: 12px; align-items: center;
		background: #1d2327; color: #fff; padding: 12px 18px; font-size: 14px; }
	.wpaie-leiste strong { font-weight: 600; }
	.wpaie-leiste .wpaie-art { background: rgba(255,255,255,.15); border-radius: 999px; padding: 2px 10px; font-size: 12px; }
	.wpaie-leiste .wpaie-hinweis { opacity: .8; margin-left: auto; font-size: 12px; }
	.wpaie-inhalt { max-width: 900px; margin: 0 auto; padding: 40px 20px; }
</style>
</head>
<body class="wpaie-vorschau">
	<div class="wpaie-leiste">
		<strong>Vorschau</strong>
		<span class="wpaie-art"><?php echo esc_html( $v['art'] ); ?></span>
		<span><?php echo esc_html( $v['text'] ); ?></span>
		<span class="wpaie-hinweis"><?php echo esc_html__( 'Die Live-Seite ist unverändert. Bestätige im Backend.', 'wp-ai-edit' ); ?></span>
	</div>
	<div class="wpaie-inhalt">
		<?php if ( '' !== $titel ) : ?>
			<h1><?php echo esc_html( $titel ); ?></h1>
		<?php endif; ?>
		<?php
		// Genau wie im Frontend rendern: Blöcke + the_content-Filter.
		echo apply_filters( 'the_content', $inhalt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Protokolleintrag schreiben.
	 *
	 * @param string $ereignis Ereignis.
	 * @param string $art      Art.
	 * @param string $text     Text.
	 * @return void
	 */
	protected static function protokoll( string $ereignis, string $art, string $text ): void {
		$log = get_option( 'wp_ai_edit_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'zeit'    => current_time( 'mysql' ),
				'nutzer'  => get_current_user_id(),
				'ability' => $art,
				'eingabe' => $text,
				'status'  => $ereignis,
			)
		);
		update_option( 'wp_ai_edit_log', array_slice( $log, 0, 200 ), false );
	}
}
