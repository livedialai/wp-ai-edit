<?php
/**
 * Maschinenlesbare Bestandsaufnahme: alle Fähigkeiten, Routen, Einstellungen.
 * Grundlage für docs/funktionen.md — damit die Dokumentation nicht aus dem
 * Gedächtnis entsteht, sondern aus dem, was WordPress tatsächlich registriert.
 */

wp_set_current_user( 1 );

$out = array(
	'stand'        => current_time( 'mysql' ),
	'wp'           => get_bloginfo( 'version' ),
	'php'          => PHP_VERSION,
	'plugin'       => defined( 'WPAIE_VERSION' ) ? WPAIE_VERSION : '?',
	'faehigkeiten' => array(),
	'routen'       => array(),
	'einstellungen' => array(),
	'optionen'     => array(),
);

// ---- Fähigkeiten ------------------------------------------------------
$alle = wp_get_abilities();
foreach ( $alle as $name => $a ) {
	if ( ! str_starts_with( (string) $name, 'kiedit/' ) ) {
		continue;
	}
	$d = null;
	if ( method_exists( $a, 'get_input_schema' ) ) {
		$d = $a->get_input_schema();
	} elseif ( method_exists( $a, 'to_array' ) ) {
		$x = $a->to_array();
		$d = $x['input_schema'] ?? null;
	}
	$meta = method_exists( $a, 'get_meta' ) ? $a->get_meta() : array();
	$out['faehigkeiten'][] = array(
		'name'        => (string) $name,
		'label'       => method_exists( $a, 'get_label' ) ? $a->get_label() : '',
		'beschreibung' => method_exists( $a, 'get_description' ) ? $a->get_description() : '',
		'schema'      => $d,
		'meta'        => is_array( $meta ) ? $meta : (array) $meta,
	);
}

// ---- REST-Routen ------------------------------------------------------
$server = rest_get_server();
foreach ( $server->get_routes() as $route => $handler ) {
	if ( ! str_contains( $route, 'wp-ai-edit/v1' ) ) {
		continue;
	}
	foreach ( $handler as $endpoint ) {
		$out['routen'][] = array(
			'route'   => $route,
			'method'  => is_array( $endpoint['methods'] ) ? implode( ',', array_keys( $endpoint['methods'] ) ) : (string) $endpoint['methods'],
			'args'    => array_keys( (array) ( $endpoint['args'] ?? array() ) ),
			'geschuetzt' => ! empty( $endpoint['permission_callback'] ),
		);
	}
}

// ---- Einstellungen des Plugins ---------------------------------------
$out['einstellungen']['wp_ai_edit']     = get_option( 'wp_ai_edit', array() );
$out['einstellungen']['wp_ai_edit_llm'] = get_option( 'wp_ai_edit_llm', array() );
foreach ( array( 'schluessel', 'bild_key' ) as $k ) {
	if ( isset( $out['einstellungen']['wp_ai_edit_llm'][ $k ] ) ) {
		$out['einstellungen']['wp_ai_edit_llm'][ $k ] = '(gesetzt)';
	}
	if ( isset( $out['einstellungen']['wp_ai_edit'][ $k ] ) ) {
		$out['einstellungen']['wp_ai_edit'][ $k ] = '(gesetzt)';
	}
}

// ---- Eigene Optionen --------------------------------------------------
foreach ( array( 'wp_ai_edit', 'wp_ai_edit_llm', 'wp_ai_edit_pending', 'wp_ai_edit_snapshots', 'wp_ai_edit_log' ) as $o ) {
	$v = get_option( $o );
	$out['optionen'][ $o ] = is_array( $v ) ? count( $v ) . ' Eintraege' : gettype( $v );
}

// ---- Vorgabewerte aus dem Code ---------------------------------------
$out['vorgaben'] = array(
	'WP_AI_Edit'        => WP_AI_Edit::defaults(),
	'WP_AI_Edit_LLM'    => WP_AI_Edit_LLM::defaults(),
);

// Zugangsdaten entfernen
foreach ( array( 'bild_key' ) as $k ) {
	if ( isset( $out['vorgaben']['WP_AI_Edit'][ $k ] ) ) {
		$out['vorgaben']['WP_AI_Edit'][ $k ] = $out['vorgaben']['WP_AI_Edit'][ $k ] ? '(gesetzt)' : '';
	}
}
if ( isset( $out['vorgaben']['WP_AI_Edit_LLM']['schluessel'] ) ) {
	$out['vorgaben']['WP_AI_Edit_LLM']['schluessel'] = $out['vorgaben']['WP_AI_Edit_LLM']['schluessel'] ? '(gesetzt)' : '';
}

$ziel = getenv( 'WPAIE_DATEN' );
if ( ! $ziel ) {
	$ziel = '/tmp/wpaie-doku.json';
}
file_put_contents( $ziel, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo 'Faehigkeiten: ' . count( $out['faehigkeiten'] ) . "\n";
echo 'Routen:       ' . count( $out['routen'] ) . "\n";
echo 'geschrieben:  ' . filesize( $ziel ) . " Bytes nach $ziel\n";
