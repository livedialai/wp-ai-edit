<?php
/**
 * Liest fremde Webseiten ein und extrahiert daraus eine Design-Vorlage.
 *
 * @package WP_AI_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analysiert eine öffentliche Webseite und liefert kompaktes Design-JSON.
 */
class WP_AI_Edit_Inspector {

	/**
	 * Lädt die Seite und extrahiert Design-Merkmale.
	 *
	 * @param string $url  Vollständige URL.
	 * @param bool   $voll Auch Überschriften, Texte und Bilder mitnehmen.
	 * @return array|WP_Error
	 */
	public static function analysiere( string $url, bool $voll = false ) {
		$pruefung = self::pruefe_url( $url );
		if ( is_wp_error( $pruefung ) ) {
			return $pruefung;
		}

		$antwort = wp_remote_get(
			$url,
			array(
				'timeout'     => 25,
				'redirection' => 3,
				'user-agent'  => 'WP-AI-Edit/' . WPAIE_VERSION . ' (Design-Referenz; +' . home_url() . ')',
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		if ( is_wp_error( $antwort ) ) {
			return $antwort;
		}

		$code = (int) wp_remote_retrieve_response_code( $antwort );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'kiedit_http',
				sprintf(
					/* translators: %d: HTTP-Statuscode. */
					__( 'Die Seite antwortete mit HTTP %d.', 'wp-ai-edit' ),
					$code
				)
			);
		}

		$html = (string) wp_remote_retrieve_body( $antwort );
		if ( '' === $html ) {
			return new WP_Error( 'kiedit_leer', __( 'Leere Antwort.', 'wp-ai-edit' ) );
		}

		return self::extrahiere( $html, $url, $voll, strlen( $html ) );
	}

	/**
	 * Blockt interne Ziele (SSRF-Schutz).
	 *
	 * @param string $url URL.
	 * @return true|WP_Error
	 */
	protected static function pruefe_url( string $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'kiedit_schema', __( 'Nur http(s) erlaubt.', 'wp-ai-edit' ) );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return new WP_Error( 'kiedit_host', __( 'Ungültige Adresse.', 'wp-ai-edit' ) );
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		$gesperrt = false;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			$gesperrt = true;
		}
		if ( in_array( $ip, array( '169.254.169.254', '127.0.0.1', '0.0.0.0' ), true ) ) {
			$gesperrt = true;
		}
		if ( $gesperrt ) {
			return new WP_Error( 'kiedit_intern', __( 'Interne Adressen sind nicht erlaubt.', 'wp-ai-edit' ) );
		}

		return true;
	}

	/**
	 * Wertet das HTML aus.
	 *
	 * @param string $html  HTML-Quelle.
	 * @param string $url   Ursprungs-URL.
	 * @param bool   $voll  Auch Texte und Überschriften.
	 * @param int    $bytes Rohgröße.
	 * @return array
	 */
	protected static function extrahiere( string $html, string $url, bool $voll, int $bytes ): array {
		$out = array(
			'url'         => $url,
			'groesse_kb'  => round( $bytes / 1024 ),
			'titel'       => self::text( '/<title[^>]*>(.*?)<\/title>/is', $html ),
			'beschreibung' => self::meta( $html, 'description' ),
		);

		// ---- Farben ----------------------------------------------------
		$farben = array();

		// Hex-Werte samt Kurzform.
		if ( preg_match_all( '/#([0-9a-f]{3}|[0-9a-f]{6})\b/i', $html, $m ) ) {
			foreach ( $m[0] as $f ) {
				$f = strtolower( $f );
				$farben[ $f ] = ( $farben[ $f ] ?? 0 ) + 1;
			}
		}
		// rgb()/rgba().
		if ( preg_match_all( '/rgba?\(\s*\d+[^)]*\)/i', $html, $m ) ) {
			foreach ( $m[0] as $f ) {
				$f = preg_replace( '/\s+/', '', strtolower( $f ) );
				$farben[ $f ] = ( $farben[ $f ] ?? 0 ) + 1;
			}
		}
		// hsl().
		if ( preg_match_all( '/hsla?\(\s*[^)]*\)/i', $html, $m ) ) {
			foreach ( $m[0] as $f ) {
				$f = preg_replace( '/\s+/', '', strtolower( $f ) );
				$farben[ $f ] = ( $farben[ $f ] ?? 0 ) + 1;
			}
		}

		arsort( $farben );
		$out['farben'] = array();
		$i             = 0;
		foreach ( $farben as $wert => $anzahl ) {
			$out['farben'][] = array( 'wert' => $wert, 'anzahl' => $anzahl );
			if ( ++$i >= 15 ) {
				break;
			}
		}

		// ---- CSS-Variablen --------------------------------------------
		$out['css_variablen'] = array();
		if ( preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;{}]{1,80});/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $mv ) {
				$name = strtolower( $mv[1] );
				if ( count( $out['css_variablen'] ) >= 25 ) {
					break;
				}
				if ( preg_match( '/farbe|color|bg|background|brand|primary|secondary|accent|ink|surface/i', $name )
					|| preg_match( '/#[0-9a-f]{3,6}|rgb|hsl/i', $mv[2] ) ) {
					$out['css_variablen'][ $name ] = trim( $mv[2] );
				}
			}
		}

		// ---- Schriften -------------------------------------------------
		$schriften = array();
		if ( preg_match_all( '/font-family\s*:\s*([^;}"\']{1,120})/i', $html, $m ) ) {
			foreach ( $m[1] as $f ) {
				foreach ( explode( ',', $f ) as $teil ) {
					$teil = trim( trim( $teil ), '"\'' );
					if ( '' === $teil || in_array( strtolower( $teil ), array( 'inherit', 'initial', 'sans-serif', 'serif', 'monospace' ), true ) ) {
						continue;
					}
					$schriften[ $teil ] = ( $schriften[ $teil ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $schriften );
		$out['schriften'] = array_slice( array_keys( $schriften ), 0, 8 );

		// Google Fonts aus den Links.
		if ( preg_match_all( '#fonts\.googleapis\.com/css2?\?family=([^"\'&>]+)#i', $html, $m ) ) {
			$gf = array();
			foreach ( $m[1] as $f ) {
				$gf[] = rawurldecode( str_replace( '+', ' ', explode( ':', $f )[0] ) );
			}
			$out['google_fonts'] = array_values( array_unique( $gf ) );
		}

		// ---- Struktur ---------------------------------------------------
		$zaehle = static function ( string $tag ) use ( $html ): int {
			return (int) preg_match_all( '/<' . $tag . '[\s>]/i', $html );
		};
		$out['struktur'] = array(
			'header'   => $zaehle( 'header' ),
			'nav'      => $zaehle( 'nav' ),
			'section'  => $zaehle( 'section' ),
			'article'  => $zaehle( 'article' ),
			'footer'   => $zaehle( 'footer' ),
			'form'     => $zaehle( 'form' ),
			'button'   => $zaehle( 'button' ) + (int) preg_match_all( '/class="[^"]*btn/i', $html ),
			'grid'     => (int) preg_match_all( '/display\s*:\s*(inline-)?grid/i', $html ),
			'flex'     => (int) preg_match_all( '/display\s*:\s*(inline-)?flex/i', $html ),
			'karten'   => (int) preg_match_all( '/class="[^"]*card/i', $html ),
		);

		if ( $voll ) {
			// ---- Überschriften ------------------------------------------
			$out['ueberschriften'] = array();
			if ( preg_match_all( '/<h([1-3])[^>]*>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $mv ) {
					$t = trim( wp_strip_all_tags( $mv[2] ) );
					if ( '' !== $t ) {
						$out['ueberschriften'][] = 'H' . $mv[1] . ': ' . mb_substr( preg_replace( '/\s+/', ' ', $t ), 0, 90 );
					}
					if ( count( $out['ueberschriften'] ) >= 20 ) {
						break;
					}
				}
			}

			// ---- Handlungsaufrufe ---------------------------------------
			$out['cta'] = array();
			if ( preg_match_all( '/<(?:a|button)[^>]*class="[^"]*(?:btn|button|cta)[^"]*"[^>]*>(.*?)<\/(?:a|button)>/is', $html, $m ) ) {
				foreach ( $m[1] as $t ) {
					$t = trim( wp_strip_all_tags( $t ) );
					if ( '' !== $t && mb_strlen( $t ) < 40 ) {
						$out['cta'][] = $t;
					}
				}
				$out['cta'] = array_values( array_unique( array_slice( $out['cta'], 0, 12 ) ) );
			}

			// ---- Bilder --------------------------------------------------
			$out['bilder_alt'] = array();
			if ( preg_match_all( '/<img[^>]+alt="([^"]{3,60})"/i', $html, $m ) ) {
				$out['bilder_alt'] = array_values( array_unique( array_slice( $m[1], 0, 12 ) ) );
			}
		}

		return $out;
	}

	/**
	 * Erster Treffer eines Regex als Text.
	 *
	 * @param string $muster Regex.
	 * @param string $html   HTML.
	 * @return string
	 */
	protected static function text( string $muster, string $html ): string {
		if ( preg_match( $muster, $html, $m ) ) {
			return mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[1] ) ) ), 0, 160 );
		}
		return '';
	}

	/**
	 * Meta-Tag auslesen.
	 *
	 * @param string $html HTML.
	 * @param string $name Meta-Name.
	 * @return string
	 */
	protected static function meta( string $html, string $name ): string {
		$muster = '/<meta[^>]+name=["\']' . preg_quote( $name, '/' ) . '["\'][^>]+content=["\']([^"\']{0,200})["\']/i';
		return self::text( $muster, $html );
	}
}
