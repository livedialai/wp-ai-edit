# WP AI Edit (v1.1.1)

KI-Chat **im WordPress-Backend**, der die Website bearbeitet. Erscheint als
schwebendes Widget unten rechts in wp-admin — auf der öffentlichen Website
existiert er nicht.

## Was es kann

| Bereich | Fähigkeit |
|---|---|
| Seiten befüllen | `create-page`, `update-page` mit Block-Markup |
| Einstellungen | `set-options` (Titel, Untertitel, Beitragsanzahl …) |
| Plugins | `install-plugin`, `toggle-plugin`, `set-plugin-setting`, `list-plugins` |
| Design-Vorlagen | `fetch-design` – liest fremde Seiten und extrahiert Farben, Schriften, Aufbau |
| Sicherheit | `snapshot` vor jeder Änderung, `rollback` zum Zurücksetzen |
| Lesen | `inspect-site` – Identität, Theme, Seiten, Menüs, Plugins; `get-page` für den vollen Seiteninhalt |
| Kleine Änderungen | `replace-text` – ersetzt eine Textstelle, alles andere bleibt unberührt |
| Bilder | `generate-image` – Bild aus Beschreibung erzeugen, in die Mediathek legen |
| Vorschläge | `list-pending`, `apply-pending`, `discard-pending` |

## Aufbau

```
wp-ai-edit.php                  Hauptdatei: Hooks, Backend-Only, Einstellungen
includes/class-abilities.php    18 Fähigkeiten über die WordPress Abilities API
includes/class-rest.php         REST-Routen /chat /reset /status /pending
includes/class-inspector.php    Design-Extraktion fremder Seiten
includes/class-workflow.php     Vorschläge, Vorschau, Übernehmen, Verwerfen
includes/class-bild.php         Bildgenerierung über WaveSpeed
includes/class-llm.php          OpenAI-kompatibler Client mit Werkzeugaufrufen
assets/js/widget.js             Chat-Oberfläche, Vorschlagskarten, Bildvorschau
assets/css/widget.css           Sprechblase, Panel, Karten
prompts/chat.md                 Systemanweisung Nur-Lese-Modus
prompts/editsite.md             Systemanweisung Bearbeitungsmodus
docs/funktionen.md              erzeugte Referenz aller Funktionen
docs/referenz-erheben.php       Erhebungsskript
docs/referenz-erzeugen.py       Erzeuger der Referenz
```

## Dokumentation

| Datei | Inhalt |
|---|---|
| `README.md` | Überblick, Einbau, Konzept, Entscheidungen, Testergebnisse |
| `docs/funktionen.md` | **Vollständige Referenz:** alle 18 Fähigkeiten mit Parametern, Typen und Pflichtfeldern; REST-Routen; jede Einstellung mit Vorgabewert; Datenbankoptionen; alle Klassen mit ihren öffentlichen Methoden; Hooks; Befehle; Seitenbauer-Erkennung |
| `docs/referenz-erheben.php` | Erhebungsskript, läuft in der Installation |
| `docs/referenz-erzeugen.py` | Erzeugt daraus `docs/funktionen.md` |

### Referenz neu erzeugen

`docs/funktionen.md` wird **nicht von Hand geschrieben**. Die Daten kommen aus dem, was
WordPress tatsächlich registriert — damit die Referenz nicht veraltet, während der Code
weiterläuft:

```bash
# 1. Bestand in der Installation erheben
cp docs/referenz-erheben.php /tmp/
wp eval-file /tmp/referenz-erheben.php        # schreibt /tmp/wpaie-doku.json

# 2. JSON herunterladen und Referenz erzeugen
python3 docs/referenz-erzeugen.py <plugin-verzeichnis> <pfad-zu-wpaie-doku.json>
```

Das Erhebungsskript legt seine Ausgabe über die Umgebungsvariable `WPAIE_DATEN` ab,
das Erzeugerskript seinen Zielpfad über `WPAIE_ZIEL`. Schlüssel werden dabei durch
`(gesetzt)` ersetzt — die Referenz enthält nie Zugangsdaten.

## Warum es auf der Website nicht existiert

```php
add_action( 'admin_enqueue_scripts', … );   // nur im Backend
add_action( 'admin_footer', … );            // Markup nur im Backend
register_rest_route( …, [ 'permission_callback' => 'nur_backend' ] );
```

Kein `wp_enqueue_scripts`, kein `wp_ajax_nopriv_*`, keine Ausgabe im Frontend.
Ein Aufruf der REST-Route ohne Anmeldung endet mit HTTP 401.

## Befehle im Chat

| Befehl | Wirkung |
|---|---|
| `/editsite` | Bearbeitungsmodus: alle Fähigkeiten, Bearbeitungs-Prompt |
| `/normal` | Zurück in den Nur-Lese-Modus |
| `/inspect` | Zustand der Website als JSON ausgeben |
| `/reset` | Gesprächsverlauf löschen |
| `/hilfe` | Befehlsübersicht |

Tastenkürzel: **Strg/Cmd + K** öffnet das Widget, **Esc** schließt es.

## Vorschlagen und Bestätigen

Standardmäßig ändert der Agent **nichts direkt**. Eine Schreiboperation wird als
**Vorschlag** gespeichert und erscheint im Chat als Karte mit drei Schaltflächen:

- **Vorschau ansehen** — öffnet die vorgeschlagene Fassung unter
  `?wpaie_vorschau=<id>`. Nur für angemeldete Nutzer, die Live-Seite bleibt unberührt.
- **Live stellen** — übernimmt die Änderung, legt vorher eine Sicherung an.
- **Verwerfen** — löscht den Vorschlag, nichts passiert.

Der Agent **fragt nach**, wenn nicht klar ist, was gewünscht ist:

> Soll ich das direkt live stellen — oder möchtest du es dir erst ansehen?

Die Arbeitsweise lässt sich unter *Einstellungen → WP AI Edit → Arbeitsweise* umstellen:

- **Erst vorschlagen** (Standard) — jede Änderung wird vorgelegt
- **Sofort anwenden** — Änderungen gehen direkt live, Sicherung und Rücknahme bleiben

Unabhängig davon kann der Nutzer im Chat **„mach das direkt"** sagen. Dann setzt das
Modell `direkt: true` und die eine Änderung wird ohne Rückfrage angewendet. Umgekehrt
fragt der Agent bei großen Umbauten vorher.

### Der Weg dahin im Hintergrund

```
Nutzer: „Ändere die Öffnungszeiten auf 9-18 Uhr"
   ↓
kiedit/update-page  (ohne direkt=true)
   ↓  nichts geschrieben
Vorschlag 2b2c552b902f + Vorschau-Link
   ↓  Nutzer klickt „Vorschau ansehen"
?wpaie_vorschau=2b2c552b902f  → 403 für Fremde, Ansicht für Angemeldete
   ↓  Nutzer klickt „Live stellen"
Sicherung → wp_update_post() → live
```

Der Agent kann Vorschläge auch selbst verwalten:

- `kiedit/list-pending` — offene Vorschläge mit IDs
- `kiedit/apply-pending` — übernehmen (nur nach Zustimmung)
- `kiedit/discard-pending` — verwerfen

## Fähigkeiten

| Fähigkeit | Wirkung |
|---|---|
| `kiedit/inspect-site` | Titel, Theme, Seiten, Menüs, Plugin-Zustand |
| `kiedit/list-plugins` | Installierte Plugins und Zustand |
| `kiedit/fetch-design` | Fremde Seite einlesen: Farben, Schriften, Layout |
| `kiedit/create-page` | Seite anlegen (Vorschlag oder direkt) |
| `kiedit/update-page` | Seite ändern (Vorschlag oder direkt) |
| `kiedit/set-options` | Core-Optionen aus Weißliste |
| `kiedit/install-plugin` | Plugin aus dem Repository installieren |
| `kiedit/toggle-plugin` | Plugin aktivieren/deaktivieren |
| `kiedit/set-plugin-setting` | Plugin-Einstellung setzen |
| `kiedit/snapshot` | Sicherung eines Stands |
| `kiedit/rollback` | Sicherung zurücknehmen |
| `kiedit/list-pending` | Offene Vorschläge |
| `kiedit/apply-pending` | Vorschlag übernehmen |
| `kiedit/discard-pending` | Vorschlag verwerfen |
| `kiedit/get-page` | Titel, Status und vollständigen Block-Inhalt einer Seite lesen |
| `kiedit/replace-text` | Einzelne Textstelle ersetzen, alles andere bleibt |
| `kiedit/generate-image` | Bild aus Beschreibung erzeugen, in die Mediathek legen |
| `kiedit/image-status` | Laufenden Bildauftrag abholen |

## Seiten lesen und einzeln ändern

**Der wichtigste Grundsatz:** `update-page` ersetzt den **kompletten** Seiteninhalt. Ohne
den aktuellen Text vorher zu kennen, würde ein Schreibversuch die Seite leeren. Deshalb
gilt im Prompt: erst `get-page`, dann schreiben.

```
kiedit/get-page     →  Titel, Status, Link, vollständiger Block-Inhalt, Zeichenzahl,
                       Blockzahl, und ob die Seite überhaupt über WordPress
                       bearbeitbar ist
```

Für typische Kundenwünsche — Öffnungszeiten, Preise, Telefonnummer, ein Wort im Text —
ist `replace-text` das richtige Werkzeug:

```json
{ "id": 61, "suchen": "Unsere Speisekarte", "ersetzen": "Speisekarte 2026" }
```

Es zählt die Vorkommen, ersetzt standardmäßig nur das erste und lässt alles andere
unberührt. Findet es die Stelle nicht, bricht es mit einem klaren Hinweis ab, statt
irgendwo zu schreiben. Im Test: 1373 → 1371 Zeichen, weil der neue Text zwei Zeichen
kürzer war — und Tiramisù, Pizza Marinara und der restliche Inhalt blieben unverändert.

Beide Wege laufen durch den Vorschlagsmechanismus, nicht direkt auf die Seite.

## Seiten mit Elementor, Divi und anderen Seitenbauern

Seitenbauer speichern ihre Inhalte **nicht** im WordPress-Inhalt, sondern in eigenen
Feldern. Ein Schreibversuch dort wäre wirkungslos: die Datenbankänderung passiert, die
Seite sieht aber unverändert aus.

Das Plugin erkennt das und **verweigert die Arbeit** statt stumm zu versagen —
`get-page` meldet im Feld `bearbeitbar`, um welchen Seitenbauer es geht:

| Seitenbauer | Erkennungsmerkmal |
|---|---|
| Elementor | `_elementor_edit_mode` = `builder` |
| Divi | `_et_pb_use_builder` = `on` |
| WPBakery / Visual Composer | `_wpb_vc_js_status` = `true` |
| Bricks | `_bricks_page_content_2` gefüllt |
| Oxygen | `ct_builder_shortcodes` gefüllt |

`update-page` und `replace-text` liefern dann einen Fehler mit Klartext, und der Agent
sagt dem Nutzer, dass diese Seite nur im Seitenbauer selbst geändert werden kann.

## Bildgenerierung

Unter *Einstellungen → WP AI Edit → Bildgenerierung* lassen sich Dienst, Schlüssel,
Modell und Größe frei eintragen. Vorgabe ist **WaveSpeed** mit
`bytedance/seedream-v4.5` (Seedream 4.5, Text zu Bild) in 2048×2048.

```
Nutzer: „Mach mir ein Foto von unserer Pizza für die Speisekarte"
   ↓
kiedit/generate-image  →  Auftrag an WaveSpeed
   ↓  ~15–25 Sekunden
Bild-URL  →  Download  →  Mediathek (Anhang-ID)
   ↓
Agent zeigt die URL und baut sie über einen Vorschlag in die Seite ein
```

Der Schlüssel liegt ausschließlich in der WordPress-Option und geht nur an den
eingetragenen Dienst. Andere WaveSpeed-Modelle lassen sich direkt eintragen, etwa
`bytedance/seedream-v5.0-pro`, `bytedance/seedream-v4` oder
`wavespeed-ai/z-image/turbo`.

## Voraussetzungen

- WordPress **6.9+** (Abilities API; geprüft auf 7.1)
- PHP 8.0+
- Ein **OpenAI-kompatibler API-Zugang** mit Werkzeugaufrufen (tool calls).
  Basis-URL, Modell und Schlüssel werden im Plugin eingestellt, nicht im Core.

**Vorbelegung:** DeepSeek — Basis-URL `https://api.deepseek.com`, Modell `deepseek-flash`.
Frei änderbar, funktioniert genauso mit OpenAI (`https://api.openai.com/v1`, `gpt-4o`),
Mistral, einem eigenen Gateway oder lokalem Ollama.


## Einbau

### Aus dem Release (der bequeme Weg)

Fertiges Paket unter **Releases** — es entpackt nach `wp-ai-edit/` und lässt sich
direkt hochladen. Dieser Link bleibt gültig und zeigt immer auf die neueste Fassung:

```
https://github.com/livedialai/wp-ai-edit/releases/latest/download/wp-ai-edit.zip
```

Dieselbe Datei liegt zusätzlich mit Versionsnummer im Namen daneben
(`wp-ai-edit-1.1.1.zip`), wenn du eine bestimmte Fassung festhalten willst.

WordPress → **Plugins → Installieren → Plugin hochladen** → ZIP auswählen → aktivieren.

### Vom Quelltext

```bash
# Plugin-Ordner nach wp-content/plugins kopieren
wp plugin activate wp-ai-edit
```

### Achtung beim Quell-ZIP

GitHubs automatisches Archiv (`/archive/refs/heads/main.zip`) entpackt nach
`wp-ai-edit-main/`. WordPress würde das Plugin dann unter diesem Namen installieren —
es funktioniert, das Verzeichnis heißt aber anders als erwartet. Wer das vermeiden will,
nimmt das Release-Paket oder benennt vorher um.

### Aus einer laufenden Installation heraus

```bash
wp plugin install https://github.com/livedialai/wp-ai-edit/releases/latest/download/wp-ai-edit.zip --force
```

Dann unter **Einstellungen → WP AI Edit**:

| Feld | Bedeutung |
|---|---|
| Basis-URL | ohne `/chat/completions`, der Pfad wird angehängt |
| Modell | muss Werkzeugaufrufe unterstützen |
| API-Schlüssel | wird maskiert angezeigt, leer lassen behält den alten |
| Temperatur · max. Werkzeugrunden · Timeout | frei einstellbar |
| Denkmodus | schaltet `thinking` des Modells zu |
| **Verbindung testen** | sendet eine Testanfrage und zeigt die Antwort |

Darunter die beiden **Systemanweisungen** als editierbare Textfelder. Sie sind mit
einer vollständigen Gutenberg-Referenz vorbelegt (Block-Markup, Blockarten, Aufbau)
und können direkt im Backend geändert werden. Feld leeren und speichern stellt die
mitgelieferte Fassung wieder her.

Zuletzt die Sichtbarkeit: ab welcher Berechtigung das Widget erscheint.

## Funktionsweise der KI-Anbindung

Das Plugin spricht `/chat/completions` im OpenAI-Format selbst an — bewusst nicht
über den Core-KI-Client, damit Basis-URL, Modell und Schlüssel frei wählbar sind.

```php
// Werkzeugdefinitionen aus den registrierten Fähigkeiten
$tools = WP_AI_Edit_LLM::werkzeuge( $ability_names );

// Schleife: Modell antwortet mit tool_calls -> Fähigkeit ausführen -> Ergebnis zurück
$antwort = WP_AI_Edit_LLM::chat( $messages, $ability_names );
```

Ablauf pro Runde:

1. `POST {base_url}/chat/completions` mit `tools` und `tool_choice: auto`
2. Enthält die Antwort `tool_calls`, wird jeder Aufruf über
   `$ability->check_permissions()` und `$ability->execute()` ausgeführt
3. Das Ergebnis geht als `role: tool` zurück ins Gespräch
4. Wiederholung bis zur Textantwort oder `max_runden`

Fähigkeitsnamen werden für die API umgeschrieben: `kiedit/inspect-site` →
`kiedit_inspect_site`, mit Rückabbildung.

**Rechte je Modus:** im Chat nur lesende Fähigkeiten, im Bearbeitungsmodus alle.
Ein Redakteur kann denselben Chat nutzen und bekommt automatisch nur das, was seine
Rolle darf — die Prüfung passiert pro Fähigkeit in `permission_callback`.

## Design-Vorlagen fremder Seiten

`kiedit/fetch-design` lädt eine öffentliche URL und liefert kompaktes JSON:

```json
{
  "titel": "…",
  "farben": [ {"wert": "#0a0a0a", "anzahl": 42} ],
  "css_variablen": { "--brand-pink": "335 100% 50%" },
  "schriften": ["Space Grotesk", "Inter"],
  "google_fonts": ["Space Grotesk"],
  "struktur": { "header": 1, "nav": 1, "section": 9, "footer": 1, "grid": 7, "flex": 33, "karten": 19 },
  "ueberschriften": ["H1: …", "H2: …"],
  "cta": ["Kostenfrei starten →"],
  "bilder_alt": ["…"]
}
```

Private und reservierte IP-Bereiche sind gesperrt, damit der Server nicht als
Sonde ins interne Netz benutzt werden kann.

## Getestet

**test.pizzafamily.site** (WP 7.1, PHP 8.2):

| Prüfung | Ergebnis |
|---|---|
| 18 Fähigkeiten registriert | ✅ |
| Verbindung zu DeepSeek `deepseek-flash` | ✅ antwortet |
| Werkzeugkreislauf (Modell ruft Fähigkeit, Ergebnis zurück) | ✅ |
| `inspect-site`, `fetch-design` auf gofonia.de | ✅ Farben, Schriften, Aufbau |
| `create-page` mit Gutenberg-Markup | ✅ 12 Blöcke, fehlerfrei geparst |
| Schreibversuch ohne `direkt=true` | ✅ nur Vorschlag, Seite unverändert |
| Vorschau-Link ohne Anmeldung | ✅ HTTP 403 |
| Vorschau-Link mit Anmeldung | ✅ rendert die neue Fassung |
| Vorschlag übernehmen | ✅ Inhalt geändert, Vorschlagsliste leer |
| `replace-text` zeichengenau | ✅ 1373 → 1371, Rest erhalten |
| `generate-image` | ✅ 14 s, 2048×2048, Anhang in der Mediathek |
| Bild im Chat sichtbar | ✅ 413×413 gerendert |
| Seitenbauer-Erkennung (5 Systeme) | ✅ erkannt, Schreiben verweigert |
| Rechteprüfung ohne Anmeldung | ✅ abgelehnt |

**starfood.pizza** (WP 7.1, PHP 8.4, WooCommerce-Shop, 89 Produkte):

| Prüfung | Ergebnis |
|---|---|
| Plugin installiert und aktiv | ✅ |
| 18 Fähigkeiten registriert | ✅ |
| Frontend unberührt (Startseite, /shop/, /warenkorb/) | ✅ HTTP 200, 0 Vorkommen |
| Widget im Backend | ✅ sichtbar, `widget.js` v1.1.1 |
| Live-Frage über den Chat | ✅ 89 Gerichte gezählt, 8 aktive Plugins gelistet |

## Grenzen

- **Kein Ersatz für einen Seitenbauer.** Elementor, Divi und Verwandte bauen Seiten
  visuell mit sofortiger Rückmeldung. Ein Chat ist die falsche Oberfläche, um einen
  Rahmen um drei Pixel zu verschieben. Das Werkzeug ersetzt nicht den Baukasten, sondern
  **den Anruf bei der Agentur** für kleine Änderungen an bestehenden Seiten.
- **Seiten von Seitenbauern sind nicht bearbeitbar** (siehe oben). Das ist Absicht.
- **Die Gestaltung bleibt Handarbeit.** Strukturell korrektes Block-Markup ist nicht
  dasselbe wie gutes Design. Die Gutenberg-Referenz im Prompt ist wichtiger als die
  Modellwahl.

### Technische Grenzen von v1

- Das Theme auf der Testinstallation (`pizzafamily`) ist **kein Block-Theme**.
  Deshalb wirken Design-Änderungen über Seiteninhalte, Menüs und Theme-Optionen.
  Auf `theme.json`-Ebene (Global Styles, Templates) greift das Plugin nur bei
  einem Block-Theme – die entsprechenden Fähigkeiten fehlen in v1.
- Der Gesprächsverlauf wird als Text in den Prompt eingebettet, nicht als
  strukturierte Nachrichtenliste. Für längere Sitzungen wäre
  `PromptBuilder::with_history()` der nächste Schritt.
- Keine Streaming-Ausgabe; die Antwort kommt am Stück.
- `install-plugin` akzeptiert ausschließlich Repository-Slugs, keine URLs.
