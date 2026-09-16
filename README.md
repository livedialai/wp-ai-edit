# WP AI Edit (v1)

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
| Lesen | `inspect-site` – Identität, Theme, Seiten, Menüs, Plugins |

## Aufbau

```
wp-ai-edit.php              Hauptdatei: Hooks, Backend-Only, Einstellungen
includes/class-abilities.php  13 Fähigkeiten über die WordPress Abilities API
includes/class-rest.php       REST-Routen /chat /reset /status
includes/class-inspector.php  Design-Extraktion fremder Seiten
assets/js/widget.js           Chat-Oberfläche
assets/css/widget.css         Sprechblase und Panel
prompts/chat.md               Systemanweisung Nur-Lese-Modus
prompts/editsite.md           Systemanweisung Bearbeitungsmodus
```

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

## Voraussetzungen

- WordPress **6.9+** (Abilities API; geprüft auf 7.1)
- PHP 8.0+
- Ein **OpenAI-kompatibler API-Zugang** mit Werkzeugaufrufen (tool calls).
  Basis-URL, Modell und Schlüssel werden im Plugin eingestellt, nicht im Core.

**Vorbelegung:** DeepSeek — Basis-URL `https://api.deepseek.com`, Modell `deepseek-flash`.
Frei änderbar, funktioniert genauso mit OpenAI (`https://api.openai.com/v1`, `gpt-4o`),
Mistral, einem eigenen Gateway oder lokalem Ollama.

## Einbau

```bash
# Plugin-Ordner nach wp-content/plugins kopieren
wp plugin activate wp-ai-edit
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

## Getestet auf test.pizzafamily.site (WP 7.1, PHP 8.2)

| Prüfung | Ergebnis |
|---|---|
| 11 Fähigkeiten registriert | ✅ |
| Verbindung zu DeepSeek `deepseek-flash` | ✅ antwortet |
| Werkzeugkreislauf (Modell ruft Fähigkeit, Ergebnis zurück) | ✅ |
| `inspect-site` über den Chat | ✅ Titel, Theme, 31 Seiten, Plugins |
| `fetch-design` auf gofonia.de | ✅ Farben, Schriften, Aufbau |
| `create-page` über den Chat | ✅ Seite 2642 als Entwurf angelegt |
| Rechteprüfung ohne Anmeldung | ✅ abgelehnt |
| Widget nur im Backend | ✅ Frontend nicht sichtbar |

## Grenzen von v1

- Das Theme auf der Testinstallation (`pizzafamily`) ist **kein Block-Theme**.
  Deshalb wirken Design-Änderungen über Seiteninhalte, Menüs und Theme-Optionen.
  Auf `theme.json`-Ebene (Global Styles, Templates) greift das Plugin nur bei
  einem Block-Theme – die entsprechenden Fähigkeiten fehlen in v1.
- Der Gesprächsverlauf wird als Text in den Prompt eingebettet, nicht als
  strukturierte Nachrichtenliste. Für längere Sitzungen wäre
  `PromptBuilder::with_history()` der nächste Schritt.
- Keine Streaming-Ausgabe; die Antwort kommt am Stück.
- `install-plugin` akzeptiert ausschließlich Repository-Slugs, keine URLs.
