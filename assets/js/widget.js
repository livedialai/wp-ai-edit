/**
 * WP AI Edit – Chat-Widget im Backend.
 *
 * Läuft ausschließlich in wp-admin. Der Endpunkt ist ohne Anmeldung nicht
 * erreichbar (kein wp_ajax_nopriv, REST-Permission prüft is_user_logged_in).
 */
( function () {
	'use strict';

	var wrap = document.getElementById( 'wpaie-wrap' );
	if ( ! wrap || typeof WPAIE === 'undefined' ) {
		return;
	}

	var bubble = document.getElementById( 'wpaie-bubble' );
	var panel  = document.getElementById( 'wpaie-panel' );
	var closer = document.getElementById( 'wpaie-close' );
	var form   = document.getElementById( 'wpaie-form' );
	var input  = document.getElementById( 'wpaie-input' );
	var log    = document.getElementById( 'wpaie-log' );
	var modus  = document.getElementById( 'wpaie-mode' );
	var status = document.getElementById( 'wpaie-status' );
	var send   = document.getElementById( 'wpaie-send' );
	var aktuellerModus = 'chat';

	/* ---------------------------------------------------------------- */
	/* Hilfsfunktionen                                                   */
	/* ---------------------------------------------------------------- */

	function setzeStatus( text, typ ) {
		status.textContent = text;
		status.className = typ ? 'wpaie-status-' + typ : '';
	}

	function setzeModus( m ) {
		aktuellerModus = m === 'edit' ? 'edit' : 'chat';
		modus.textContent = aktuellerModus === 'edit' ? 'Bearbeitung' : 'Chat';
		modus.className = 'wpaie-badge ' + ( aktuellerModus === 'edit' ? 'wpaie-badge-edit' : '' );
		document.body.classList.toggle( 'wpaie-edit-mode', aktuellerModus === 'edit' );
	}

	function oeffnen() {
		panel.hidden = false;
		bubble.classList.add( 'wpaie-aktiv' );
		input.focus();
	}

	function schliessen() {
		panel.hidden = true;
		bubble.classList.remove( 'wpaie-aktiv' );
	}

	/**
	 * Nachricht an den Verlauf anhängen.
	 *
	 * @param {string} text     Inhalt.
	 * @param {string} rolle    user|assistant|system.
	 * @param {boolean} alsCode Als Codeblock darstellen.
	 */
	function anhaengen( text, rolle, alsCode ) {
		var zeile = document.createElement( 'div' );
		zeile.className = 'wpaie-msg wpaie-' + rolle;

		var inhalt = document.createElement( 'div' );
		inhalt.className = 'wpaie-text';

		if ( alsCode ) {
			var pre = document.createElement( 'pre' );
			pre.textContent = text;
			inhalt.appendChild( pre );
		} else {
			inhalt.textContent = text;
		}

		zeile.appendChild( inhalt );
		log.appendChild( zeile );
		log.scrollTop = log.scrollHeight;
		return zeile;
	}

	function tippZeile() {
		var zeile = document.createElement( 'div' );
		zeile.className = 'wpaie-msg wpaie-assistant wpaie-tippt';
		zeile.innerHTML = '<span class="wpaie-dot"></span><span class="wpaie-dot"></span><span class="wpaie-dot"></span>';
		log.appendChild( zeile );
		log.scrollTop = log.scrollHeight;
		return zeile;
	}

	/* ---------------------------------------------------------------- */
	/* Serveraufruf                                                      */
	/* ---------------------------------------------------------------- */

	function frage( nachricht ) {
		setzeStatus( 'denkt …', 'laden' );
		send.disabled = true;
		var tipp = tippZeile();

		window.wp.apiFetch( {
			path: '/wp-ai-edit/v1/chat',
			method: 'POST',
			data: { nachricht: nachricht, modus: aktuellerModus }
		} )
			.then( function ( res ) {
				tipp.remove();
				if ( res && res.modus ) {
					setzeModus( res.modus );
				}
				var antwort = res && res.antwort ? String( res.antwort ) : '(keine Antwort)';
				anhaengen( antwort, 'assistant', antwort.length > 400 && antwort.trim().charAt( 0 ) === '{' );
				setzeStatus( res && res.abilities ? res.abilities + ' Fähigkeiten aktiv' : 'bereit' );
			} )
			.catch( function ( fehler ) {
				tipp.remove();
				var text = fehler && fehler.message ? fehler.message : 'Unbekannter Fehler';
				if ( fehler && fehler.data && fehler.data.message ) {
					text = fehler.data.message;
				}
				anhaengen( 'Fehler: ' + text, 'system' );
				setzeStatus( 'Fehler', 'fehler' );
			} )
			.then( function () {
				send.disabled = false;
				input.focus();
			} );
	}

	/* ---------------------------------------------------------------- */
	/* Ereignisse                                                        */
	/* ---------------------------------------------------------------- */

	bubble.addEventListener( 'click', function () {
		if ( panel.hidden ) {
			oeffnen();
		} else {
			schliessen();
		}
	} );

	closer.addEventListener( 'click', schliessen );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var text = input.value.trim();
		if ( ! text ) {
			return;
		}
		anhaengen( text, 'user' );
		input.value = '';
		frage( text );
	} );

	// Eingabetaste sendet, Umschalt+Eingabe macht einen Zeilenumbruch.
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		}
	} );

	// Schnellbefehle.
	Array.prototype.forEach.call( document.querySelectorAll( '.wpaie-chip' ), function ( chip ) {
		chip.addEventListener( 'click', function () {
			var befehl = chip.getAttribute( 'data-cmd' );
			if ( ! befehl ) {
				return;
			}
			anhaengen( befehl, 'user' );
			frage( befehl );
		} );
	} );

	// Tastenkürzel Strg/Cmd + K.
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'k' ) {
			e.preventDefault();
			oeffnen();
		}
		if ( e.key === 'Escape' && ! panel.hidden ) {
			schliessen();
		}
	} );

	/* ---------------------------------------------------------------- */
	/* Start                                                             */
	/* ---------------------------------------------------------------- */

	window.wp.apiFetch( { path: '/wp-ai-edit/v1/status' } )
		.then( function ( s ) {
			if ( s && s.modus ) {
				setzeModus( s.modus );
			}
			if ( s && s.ki_bereit ) {
				setzeStatus( 'bereit · ' + ( s.modell || 'Modell' ) + ' · ' + ( s.abilities || 0 ) + ' Fähigkeiten' );
			} else {
				setzeStatus( 'kein KI-Zugang konfiguriert', 'fehler' );
				anhaengen(
					'Es ist kein KI-Zugang hinterlegt. Bitte unter Einstellungen → WP AI Edit Basis-URL, Modell und API-Schlüssel eintragen.',
					'system'
				);
			}
		} )
		.catch( function () {
			setzeStatus( 'Status nicht abrufbar', 'fehler' );
		} );
} )();
