# Funktionen — vollständige Referenz

Stand: 2026-09-16 13:49:25 · WordPress 7.1 · PHP 8.2.33 · Plugin 1.1.1

Diese Datei wird aus einer **laufenden Installation** erzeugt: die Fähigkeiten kommen
aus `wp_get_abilities()`, die Routen aus dem REST-Server, die Modi aus `class-rest.php`.
Sie beschreibt also, was tatsächlich registriert ist — nicht, was geplant war. Die
Zahlen oben stammen von der Referenzinstallation; das Plugin selbst verlangt nur
WordPress 6.9+ und PHP 8.0+.

---

## Fähigkeiten im Überblick

| Fähigkeit | Bezeichnung | Nur-Lese-Chat | Bearbeitungsmodus |
|---|---|:--:|:--:|
| `kiedit/inspect-site` | Website einlesen | ✅ | ✅ |
| `kiedit/update-page` | Seite aktualisieren | — | ✅ |
| `kiedit/create-page` | Seite anlegen | — | ✅ |
| `kiedit/set-options` | Einstellungen ändern | — | ✅ |
| `kiedit/list-plugins` | Plugins auflisten | ✅ | ✅ |
| `kiedit/install-plugin` | Plugin installieren | — | ✅ |
| `kiedit/toggle-plugin` | Plugin aktivieren/deaktivieren | — | ✅ |
| `kiedit/set-plugin-setting` | Plugin-Einstellung ändern | — | ✅ |
| `kiedit/fetch-design` | Design einer fremden Seite einlesen | ✅ | ✅ |
| `kiedit/snapshot` | Sicherung anlegen | — | ✅ |
| `kiedit/rollback` | Stand zurücksetzen | — | ✅ |
| `kiedit/list-pending` | Offene Vorschläge auflisten | — | ✅ |
| `kiedit/apply-pending` | Vorschlag übernehmen | — | ✅ |
| `kiedit/discard-pending` | Vorschlag verwerfen | — | ✅ |
| `kiedit/get-page` | Seite auslesen | ✅ | ✅ |
| `kiedit/replace-text` | Textstelle ersetzen | — | ✅ |
| `kiedit/generate-image` | Bild erzeugen | — | ✅ |
| `kiedit/image-status` | Bildauftrag abholen | — | ✅ |

Im Nur-Lese-Modus gibt der Agent 4 Fähigkeiten dieses Plugins frei — dazu kommt `core/get-site-info` aus dem WordPress-Kern. Im Bearbeitungsmodus stehen alle 18 bereit.

---

## Die einzelnen Fähigkeiten

### `kiedit/inspect-site` — Website einlesen

Liefert den aktuellen Zustand der Website: Identität, Theme, Seiten mit IDs und Auszügen, Menüs, aktive Plugins. Vor jeder Änderung aufrufen.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `bereiche` | array&lt;identitaet \| theme \| seiten \| menues \| plugins&gt; | — | Optional: nur diese Bereiche zurückgeben. |

Natur: nur lesend.

Verfügbar im: Nur-Lese-Chat, Bearbeitungsmodus.

### `kiedit/update-page` — Seite aktualisieren

Ersetzt den Inhalt einer bestehenden Seite. Der Inhalt muss gültiges Block-Markup sein. Vorher wird automatisch eine Sicherung angelegt.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `id` | Ganzzahl | ✅ | Seiten-ID. |
| `titel` | Text | — | Optional: neuer Titel. |
| `inhalt` | Text | ✅ | Neuer Inhalt als Block-Markup. |
| `status` | `publish` \| `draft` \| `private` | — | Seitenstatus: publish = veröffentlicht, draft = Entwurf, private = nur für Angemeldete. |
| `direkt` | Wahrheitswert | — | Nur setzen, wenn der Nutzer ausdrücklich sofortige Veröffentlichung verlangt. Sonst leer lassen: die Änderung wird dann als Vorschlag vorgelegt. |

Natur: verändernd, wiederholbar ohne Zusatzwirkung.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/create-page` — Seite anlegen

Legt eine neue Seite mit Block-Markup an.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `titel` | Text | ✅ | Titel der neuen Seite. |
| `slug` | Text | — | Adressteil, z. B. speisekarte. Leer lassen erzeugt WordPress aus dem Titel. |
| `inhalt` | Text | ✅ | Inhalt als Block-Markup. |
| `status` | `publish` \| `draft` | — | draft = Entwurf (Standard), publish = sofort veröffentlicht. |
| `direkt` | Wahrheitswert | — | Nur setzen, wenn der Nutzer ausdrücklich sofortige Veröffentlichung verlangt. Sonst leer lassen: die Seite wird dann nur vorgeschlagen. |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/set-options` — Einstellungen ändern

Ändert freigegebene Website-Einstellungen: Titel, Untertitel, Beitragsanzahl, Datumsformat und weitere.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `optionen` | Objekt | ✅ | Schlüssel-Wert-Paare der zu ändernden Optionen. |
| `direkt` | Wahrheitswert | — | Nur setzen, wenn der Nutzer sofortige Änderung verlangt. Sonst leer lassen: wird als Vorschlag vorgelegt. |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/list-plugins` — Plugins auflisten

Listet alle installierten Plugins mit Version und Aktivierungsstatus.

*Keine Parameter.*

Natur: nur lesend.

Verfügbar im: Nur-Lese-Chat, Bearbeitungsmodus.

### `kiedit/install-plugin` — Plugin installieren

Installiert ein Plugin aus dem offiziellen WordPress-Repository über seinen Slug, zum Beispiel "contact-form-7". Fremde URLs sind nicht erlaubt.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `slug` | Text | ✅ | Repository-Slug, z. B. contact-form-7. |
| `aktivieren` | Wahrheitswert | — | Nach der Installation aktivieren. |
| `version` | Text | — | Optional: feste Version. |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/toggle-plugin` — Plugin aktivieren/deaktivieren

Aktiviert oder deaktiviert ein bereits installiertes Plugin. Der Dateipfad aus "Plugins auflisten" wird benötigt.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `datei` | Text | ✅ | Plugin-Datei, z. B. contact-form-7/wp-contact-form-7.php |
| `aktiv` | Wahrheitswert | ✅ | true = aktivieren, false = deaktivieren. |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/set-plugin-setting` — Plugin-Einstellung ändern

Schreibt eine einzelne Plugin-Option. Nur Optionsnamen mit mindestens einem Unterstrich und einer der Präfixe der aktiven Plugins sind erlaubt.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `option` | Text | ✅ | Name der Einstellung, z. B. woocommerce_currency. Muss zu einem aktiven Plugin gehören. |
| `wert` | ? | ✅ | Neuer Wert (Text, Zahl, Wahrheitswert oder Objekt). |
| `direkt` | Wahrheitswert | — | Nur setzen, wenn der Nutzer sofortige Änderung verlangt. Sonst leer lassen: wird als Vorschlag vorgelegt. |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/fetch-design` — Design einer fremden Seite einlesen

Liest eine öffentliche Webseite und extrahiert Farben, Schriften, Überschriftenstruktur und Layout-Abschnitte als Inspiration. Gibt kompaktes JSON zurück.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `url` | Text | ✅ | Vollständige URL mit https:// |
| `umfang` | `kurz` \| `voll` | — | kurz = nur Farben, Schriften und Aufbau (Standard). voll = zusätzlich Überschriften, Buttons und Bildtexte. |

Natur: nur lesend.

Verfügbar im: Nur-Lese-Chat, Bearbeitungsmodus.

### `kiedit/snapshot` — Sicherung anlegen

Sichert den aktuellen Inhalt einer Seite, bevor sie geändert wird.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `id` | Ganzzahl | ✅ | Seiten-ID. |
| `bezeichnung` | Text | — | Kurze Bezeichnung, damit die Sicherung später zuzuordnen ist, z. B. „Vor Umbau der Startseite". |

Natur: wiederholbar ohne Zusatzwirkung.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/rollback` — Stand zurücksetzen

Stellt den Inhalt einer Seite aus der jüngsten Sicherung wieder her.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `id` | Ganzzahl | ✅ | Seiten-ID. |
| `index` | Ganzzahl | — | 0 = jüngste Sicherung. |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/list-pending` — Offene Vorschläge auflisten

Zeigt Änderungen, die vorgeschlagen, aber noch nicht übernommen wurden. Vor dem Anwenden aufrufen, um die Vorschlags-ID zu erhalten.

*Keine Parameter.*

Natur: nur lesend.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/apply-pending` — Vorschlag übernehmen

Wendet einen vorgeschlagenen Entwurf an. Nur aufrufen, wenn der Nutzer ausdrücklich zugestimmt hat.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `vorschlag_id` | Text | ✅ | ID aus „Offene Vorschläge auflisten". |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/discard-pending` — Vorschlag verwerfen

Verwirft einen vorgeschlagenen Entwurf, ohne etwas zu ändern.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `vorschlag_id` | Text | ✅ | ID aus „Offene Vorschläge auflisten". |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/get-page` — Seite auslesen

Liefert Titel, Status, Link und den vollständigen Block-Inhalt einer Seite. Immer aufrufen, bevor du Inhalte änderst — sonst überschreibst du blind.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `id` | Ganzzahl | — | Seiten-ID aus „Website einlesen". |
| `slug` | Text | — | Alternativ: der Slug der Seite. |

Natur: nur lesend.

Verfügbar im: Nur-Lese-Chat, Bearbeitungsmodus.

### `kiedit/replace-text` — Textstelle ersetzen

Ersetzt eine genau bezeichnete Textstelle auf einer Seite und lässt alles andere unberührt. Das ist der sichere Weg für kleine Änderungen wie Öffnungszeiten, Preise oder Telefonnummern. Erst „Seite auslesen", dann diese Fähigkeit.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `id` | Ganzzahl | ✅ | Seiten-ID. |
| `suchen` | Text | ✅ | Die exakte Stelle, wie sie aktuell auf der Seite steht. |
| `ersetzen` | Text | ✅ | Der neue Text. |
| `alle` | Wahrheitswert | — | Auch weitere Vorkommen ersetzen. Standard: nur das erste. |
| `direkt` | Wahrheitswert | — | Nur setzen, wenn der Nutzer sofortige Änderung verlangt. Sonst leer lassen: wird als Vorschlag vorgelegt. |

Natur: verändernd.

Verfügbar im: Bearbeitungsmodus.

### `kiedit/generate-image` — Bild erzeugen

Erzeugt ein Bild aus einer Beschreibung und legt es in der Mediathek ab. Danach mit der zurückgegebenen URL oder Anhang-ID in einer Seite verwenden. Sinnvoll für Speisekarten, Stimmungsbilder, Produktfotos.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `prompt` | Text | ✅ | Bildbeschreibung. Je genauer — Motiv, Licht, Stil, Perspektive — desto besser das Ergebnis. |
| `titel` | Text | — | Titel und Alternativtext in der Mediathek. |
| `groesse` | Text | — | Gewünschte Maße, z. B. 2048*2048. Leer lassen für die Standardgröße. |
| `modell` | Text | — | Nur nötig, wenn ein anderes Modell als das eingestellte verwendet werden soll. |

Verfügbar im: Bearbeitungsmodus.

### `kiedit/image-status` — Bildauftrag abholen

Holt einen noch laufenden Bildauftrag ab und legt das Ergebnis in die Mediathek. Nur nötig, wenn „Bild erzeugen" meldet, dass der Auftrag noch läuft.

| Parameter | Typ | Pflicht | Bedeutung |
|---|:--:|:--:|---|
| `auftrag` | Text | ✅ | Auftrags-ID aus „Bild erzeugen". |
| `titel` | Text | — | Titel und Alternativtext in der Mediathek. |

Verfügbar im: Bearbeitungsmodus.

---

## REST-Schnittstelle

Namensraum: `wp-ai-edit/v1`. Alle Routen außer der Übersicht verlangen eine
angemeldete Sitzung mit dem Mindestrecht; ohne Anmeldung antworten sie mit HTTP 401.

| Methode | Pfad | Parameter | Geschützt |
|---|:--:|---|:--:|
| GET | `/` | `namespace`, `context` | — (Übersicht) |
| POST | `/chat` | `nachricht`, `modus` | ✅ |
| POST | `/reset` | — | ✅ |
| GET | `/status` | — | ✅ |
| POST | `/test` | — | ✅ |
| GET | `/pending` | — | ✅ |
| POST | `/pending/apply` | `id` | ✅ |
| POST | `/pending/discard` | `id` | ✅ |

Vom Chatfenster benutzt werden `/status`, `/chat`, `/reset` und die drei
`/pending`-Routen; `/test` gehört zur Einstellungsseite.

---

## Einstellungen

### KI-Zugang und Bildgenerierung

| Schlüssel | Vorgabe | Bedeutung |
|---|---|---|
| `base_url` | `https://api.deepseek.com` | Ohne `/chat/completions` — der Pfad wird angehängt |
| `modell` | `deepseek-flash` | Muss Werkzeugaufrufe unterstützen |
| `schluessel` | *(wird beim Einrichten eingetragen)* | Wird maskiert angezeigt; leer lassen behält den alten Wert |
| `temperatur` | `0.3` | 0 = deterministisch, 1 = erfindungsfreudig |
| `max_runden` | `6` | Wie oft das Modell Werkzeuge aufrufen darf, bevor abgebrochen wird |
| `timeout` | `120` | Sekunden pro Anfrage |
| `thinking` | `0` | Schaltet den Denkmodus des Modells zu, wo vorhanden |

### Verhalten und Bildgenerierung

| Schlüssel | Vorgabe | Bedeutung |
|---|---|---|
| `enabled` | `1` | Widget im Backend ein- oder ausschalten |
| `admin_pages` | `(leer)` | Leer = auf allen Backend-Seiten sichtbar |
| `min_capability` | `edit_pages` | Ab welchem Recht das Widget erscheint |
| `history_limit` | `12` | Wie viele Nachrichten der Gesprächsverlauf behält |
| `prompt_chat` | *(leer)* | Eigene Systemanweisung für den Nur-Lese-Modus; leer = mitgelieferte Datei |
| `prompt_edit` | *(leer)* | Eigene Systemanweisung für den Bearbeitungsmodus; leer = mitgelieferte Datei |
| `workflow` | `stage` | `stage` = erst vorschlagen, `direct` = sofort anwenden |
| `bild_key` | *(wird beim Einrichten eingetragen)* | Schlüssel des Bilddienstes (WaveSpeed: `wsk_live_…`) |
| `bild_modell` | `bytedance/seedream-v4.5` | Vorgabe: Seedream 4.5, Text zu Bild |
| `bild_base` | `https://api.wavespeed.ai/api/v3` | Adresse der Bildschnittstelle |
| `bild_groesse` | `2048*2048` | Maße als BREITE*HÖHE |

---

## Datenbank

| Option | Inhalt |
|---|---|
| `wp_ai_edit` | Alle Einstellungen oben, als ein Feld |
| `wp_ai_edit_llm` | KI-Zugang (Basis-URL, Modell, Schlüssel) |
| `wp_ai_edit_pending` | Offene Vorschläge samt vorgeschlagener Fassung |
| `wp_ai_edit_snapshots` | Sicherungen vor Änderungen (höchstens 100) |
| `wp_ai_edit_log` | Protokoll der Werkzeugaufrufe (höchstens 200) |

Schlüssel liegen ausschließlich in `wp_ai_edit_llm` und `wp_ai_edit` und werden nur an
die dort eingetragenen Adressen gesendet. Im Plugin-Quelltext stehen sie nicht.

---

## Befehle im Chat

| Befehl | Wirkung |
|---|---|
| `/editsite` | Bearbeitungsmodus: alle Fähigkeiten, Bearbeitungs-Prompt |
| `/normal` | Zurück in den Nur-Lese-Modus |
| `/inspect` | Zustand der Website als JSON ausgeben |
| `/reset` | Gesprächsverlauf löschen |
| `/hilfe` | Befehlsübersicht |

Tastenkürzel: **Strg/Cmd + K** öffnet das Widget, **Esc** schließt es.

---

## Aufbau des Quelltexts

### `class-abilities.php` — `WP_AI_Edit_Abilities`

Registrierung der Fähigkeiten (Abilities) für WP AI Edit. @package WP_AI_Edit / if ( ! defined( 'ABSPATH' ) ) { exit; } / Stellt alle Fähigkeiten bereit, die das Modell aufrufen darf.

| Methode | Sichtbar | Zweck |
|---|:--:|---|
| `erlaubte_optionen()` | public | Registrierung der Fähigkeiten (Abilities) für WP AI Edit. |
| `kategorien()` | public | Kategorien registrieren. |
| `registrieren()` | public | Alle Fähigkeiten registrieren. |
| `cb_bild_erzeugen()` | public | Callback: Bild erzeugen. |
| `cb_bild_abholen()` | public | Callback: noch laufenden Bildauftrag abholen. |
| `seitenbauer()` | public | Prüft, ob eine Seite mit einem Seitenbauer (Elementor, Divi, WPBakery) |
| `cb_seite_lesen()` | public | Callback: Seite auslesen. |
| `cb_text_ersetzen()` | public | Callback: Textstelle ersetzen. |
| `cb_vorschlaege_auflisten()` | public | Callback: offene Vorschläge auflisten. |
| `cb_vorschlag_uebernehmen()` | public | Callback: Vorschlag übernehmen. |
| `cb_vorschlag_verwerfen()` | public | Callback: Vorschlag verwerfen. |
| `darf_lesen()` | public | Prüft die Grundberechtigung für schreibende Fähigkeiten. |
| `cb_site_einlesen()` | public | Callback: Website einlesen. |
| `cb_seite_schreiben()` | public | Callback: Seite aktualisieren. |
| `cb_seite_anlegen()` | public | Callback: Seite anlegen. |
| `cb_optionen_setzen()` | public | Callback: Optionen setzen. |
| `cb_plugins_auflisten()` | public | Callback: Plugins auflisten. |
| `cb_plugin_installieren()` | public | Callback: Plugin installieren. |
| `cb_plugin_aktivieren()` | public | Callback: Plugin aktivieren/deaktivieren. |
| `cb_plugin_option_setzen()` | public | Callback: Plugin-Einstellung ändern. |
| `cb_design_einlesen()` | public | Callback: Design einlesen. |
| `sicherung_anlegen()` | public | Legt eine Sicherung des vorherigen Zustands an. |
| `cb_snapshot()` | public | Callback: Sicherung anlegen. |
| `cb_rollback()` | public | Callback: Stand zurücksetzen. |

*Dazu 18 interne Methoden.*

### `class-bild.php` — `WP_AI_Edit_Bild`

Bildgenerierung über WaveSpeed (oder einen anderen kompatiblen Dienst). Ablauf: Auftrag abschicken, auf das Ergebnis warten, Bild in die Mediathek legen. Nichts davon verändert eine Seite — das passiert erst über einen Vorschlag. @package WP_AI_Edit / if ( ! defined( 'ABSPATH' ) ) { exit; } / Verbindung zum Bilddienst und Übernahme in die Mediathek.

| Methode | Sichtbar | Zweck |
|---|:--:|---|
| `bereit()` | public | Ist die Bildfunktion einsatzbereit? |
| `modell()` | public | Modell. |
| `basis()` | public | Basis-Adresse ohne abschließenden Schrägstrich. |
| `erzeugen()` | public | Auftrag abschicken. |
| `warten()` | public | Auf das Ergebnis warten. |
| `in_mediathek()` | public | Bild in die Mediathek übernehmen. |
| `guthaben()` | public | Guthaben abfragen. |

*Dazu 3 interne Methoden.*

### `class-inspector.php` — `WP_AI_Edit_Inspector`

Liest fremde Webseiten ein und extrahiert daraus eine Design-Vorlage. @package WP_AI_Edit / if ( ! defined( 'ABSPATH' ) ) { exit; } / Analysiert eine öffentliche Webseite und liefert kompaktes Design-JSON.

| Methode | Sichtbar | Zweck |
|---|:--:|---|
| `analysiere()` | public | Liest fremde Webseiten ein und extrahiert daraus eine Design-Vorlage. |

*Dazu 4 interne Methoden.*

### `class-llm.php` — `WP_AI_Edit_LLM`

Minimaler OpenAI-kompatibler Client mit Werkzeugaufrufen gegen die Abilities. Bewusst ohne den Core-KI-Client: Basis-URL, Modell und Schlüssel sollen frei einstellbar sein (DeepSeek, OpenAI, Mistral, Ollama, beliebige Gateways). @package WP_AI_Edit / if ( ! defined( 'ABSPATH' ) ) { exit; } / Spricht /chat/completions im OpenAI-Format und führt Werkzeugaufrufe aus.

| Methode | Sichtbar | Zweck |
|---|:--:|---|
| `defaults()` | public | Minimaler OpenAI-kompatibler Client mit Werkzeugaufrufen gegen die Abilities. |
| `settings()` | public | Einstellungen des API-Zugangs. |
| `bereit()` | public | Ist der Zugang vollständig konfiguriert? |
| `maske()` | public | Schlüssel für die Anzeige maskieren. |
| `funktionsname()` | public | Wandelt einen Ability-Namen in einen für die API gültigen Funktionsnamen. |
| `chat()` | public | Sendet ein Gespräch und führt Werkzeugaufrufe so lange aus, bis das |
| `test()` | public | Verbindungstest gegen die API. |

*Dazu 2 interne Methoden.*

### `class-rest.php` — `WP_AI_Edit_REST`

REST-Schnittstelle des Widgets. Ausschließlich für angemeldete Nutzer. @package WP_AI_Edit / if ( ! defined( 'ABSPATH' ) ) { exit; } / Chat-Endpunkt im Backend.

| Methode | Sichtbar | Zweck |
|---|:--:|---|
| `routen()` | public | Routen registrieren. |
| `pending()` | public | Offene Vorschläge auflisten. |
| `pending_apply()` | public | Vorschlag übernehmen. |
| `pending_discard()` | public | Vorschlag verwerfen. |
| `test()` | public | Verbindungstest gegen die konfigurierte API. |
| `nur_backend()` | public | Nur angemeldete Nutzer mit ausreichender Berechtigung. |
| `chat()` | public | Chat-Anfrage verarbeiten. |
| `reset()` | public | Verlauf zurücksetzen. |
| `status()` | public | Status für die Oberfläche. |

*Dazu 6 interne Methoden.*

### `class-workflow.php` — `WP_AI_Edit_Workflow`

Vorschlags-Schicht: Änderungen landen als Entwurf zur Bestätigung, können aber auf Wunsch sofort angewendet werden. @package WP_AI_Edit / if ( ! defined( 'ABSPATH' ) ) { exit; } / Verwaltet vorgeschlagene Änderungen, deren Anwendung und die Vorschau.

| Methode | Sichtbar | Zweck |
|---|:--:|---|
| `modus()` | public | Vorschlags-Schicht: Änderungen landen als Entwurf zur Bestätigung, |
| `direkt_erlaubt()` | public | Darf die Änderung sofort angewendet werden? |
| `alle()` | public | Offene Vorschläge laden. |
| `einreihen()` | public | Einen Vorschlag speichern. |
| `finden()` | public | Einen Vorschlag finden. |
| `verwerfen()` | public | Vorschlag verwerfen. |
| `anwenden()` | public | Vorschlag anwenden. |
| `vorschau_url()` | public | Vorschau-URL für einen Vorschlag. |
| `vorschau_ausgeben()` | public | Rendert die Vorschau für angemeldete Nutzer. |

*Dazu 1 interne Methoden.*

### `wp-ai-edit.php` — `WP_AI_Edit`

Plugin Name:       WP AI Edit Plugin URI:        https://gomeetme.de Description:       KI-Chat im WordPress-Backend, der die Website bearbeitet: Seiten befüllen, Plugins installieren und konfigurieren, Designs fremder Seiten als Inspiration einlesen. Erscheint ausschließlich im Backend als schwebendes Widget – auf der öffentlichen Website existiert es nicht. Version:           1.1.1 Requires at least: 6.9 Requires PHP:      8.0 Author:            Weser AI License:           GPL-2.0-or-later License URI:       https://www.gnu.org/licenses/gpl-2.0.html Text Domain:       wp-ai-edit @package WP_AI_Edit / // Direkter Aufruf verboten. if ( ! defined( 'ABSPATH' ) ) { exit; } define( 'WPAIE_VERSION', '1.1.1' ); define( 'WPAIE_FILE', __FILE__ ); define( 'WPAIE_DIR', plugin_dir_path( __FILE__ ) ); define( 'WPAIE_URL', plugin_dir_url( __FILE__ ) ); define( 'WPAIE_OPT', 'wp_ai_edit' ); / Hauptklasse. Registriert Hooks, REST-Routen und die Admin-Oberflaeche.

| Methode | Sichtbar | Zweck |
|---|:--:|---|
| `defaults()` | public | Plugin Name:       WP AI Edit |
| `prompt_standard()` | public | Eingebauter Standard-Prompt, falls weder Option noch Datei vorliegt. |
| `settings()` | public | Einstellungen lesen. |
| `update()` | public | Einstellungen schreiben. |
| `bild_test()` | public | Verbindung und Guthaben des Bilddienstes prüfen (?bild_test=1). |
| `menue()` | public | Einstellungsseite anlegen. |
| `darf_sehen()` | public | Prueft, ob der aktuelle Nutzer das Widget sehen darf. |
| `hinweis_connector()` | public | Zeigt einen Hinweis, wenn der API-Zugang noch nicht konfiguriert ist. |
| `assets()` | public | Assets nur im Backend laden. |
| `widget()` | public | Markup des schwebenden Widgets ausgeben. |
| `einstellungen_seite()` | public | Einstellungsseite: API-Zugang, Prompts, Sichtbarkeit. |
| `prompt_datei()` | public | Inhalt einer mitgelieferten Prompt-Datei. |

*Dazu 0 interne Methoden.*

### Hooks

| Hook | Ruf | Zweck |
|---|---|---|

Kein `wp_enqueue_scripts`, kein `wp_ajax_nopriv_*` — deshalb existiert das Widget
auf der öffentlichen Website nicht.

---

## Seitenbauer-Erkennung

`WP_AI_Edit_Abilities::seitenbauer()` prüft je Seite, ob ein Seitenbauer sie
verwaltet. `update-page` und `replace-text` verweigern dann die Arbeit.

| Seitenbauer | Merkmal |
|---|---|
| Elementor | `_elementor_edit_mode` = `builder` |
| Divi | `_et_pb_use_builder` = `on` |
| WPBakery | `_wpb_vc_js_status` = `true` |
| Bricks | `_bricks_page_content_2` gefüllt (Array) |
| Oxygen | `ct_builder_shortcodes` gefüllt |

`get-page` meldet das Ergebnis im Feld `bearbeitbar`, damit der Agent den Nutzer
informieren kann, statt stumm wirkungslos zu schreiben.

