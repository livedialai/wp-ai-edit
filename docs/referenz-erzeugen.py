#!/usr/bin/env python3
"""
Erzeugt docs/funktionen.md aus der laufenden Installation.

Quellen:
  /root/wpaie-doku.json      von wp eval-file (Fähigkeiten, Routen, Optionen)
  /root/wp-AI-edit/**/*.php  für Klassen, Methoden und Hooks

Damit entsteht die Dokumentation aus dem, was WordPress tatsächlich
registriert — nicht aus dem Gedächtnis.
"""

import json
import re
from pathlib import Path

import os
import sys

QUELLE = Path(os.environ.get("WPAIE_QUELLE", "/root/wp-AI-edit"))
DOKU_PFAD = Path(os.environ.get("WPAIE_DATEN", "/root/wpaie-doku.json"))
if len(sys.argv) > 1:
    QUELLE = Path(sys.argv[1])
if len(sys.argv) > 2:
    DOKU_PFAD = Path(sys.argv[2])

DOKU = json.load(open(DOKU_PFAD, encoding="utf-8"))

_ziel_env = os.environ.get("WPAIE_ZIEL", "")
if len(sys.argv) > 3:
    ZIEL = Path(sys.argv[3])
elif _ziel_env:
    ZIEL = Path(_ziel_env)
else:
    ZIEL = QUELLE / "docs" / "funktionen.md"

# --------------------------------------------------------------------------
# Modi aus class-rest.php auslesen (maßgeblich, nicht geraten)
# --------------------------------------------------------------------------
rest_php = (QUELLE / "includes/class-rest.php").read_text(encoding="utf-8")


def modus_liste(funktion: str) -> list[str]:
    m = re.search(rf"function {funktion}\(\): array \{{(.*?)\n\t\}}", rest_php, re.S)
    if not m:
        return []
    return re.findall(r"'(kiedit/[a-z\-]+)'", m.group(1))


NUR_LESEN = modus_liste("abilities_chat")
MIT_SCHREIBEN = modus_liste("abilities_edit")


# --------------------------------------------------------------------------
# Klassen, Methoden, Docblocks aus dem Quelltext
# --------------------------------------------------------------------------
def klassen_doku(pfad: Path) -> dict | None:
    text = pfad.read_text(encoding="utf-8")
    m = re.search(r"/\*\*(.*?)\*/\s*class\s+(\w+)", text, re.S)
    if not m:
        return None
    zusammenfassung = " ".join(
        z.strip(" *\t") for z in m.group(1).strip().splitlines() if z.strip(" *\t")
    )
    klasse = m.group(2)

    methoden = []
    # Docblock + optionale Modifier + function
    for mm in re.finditer(
        r"/\*\*(.*?)\*/\s*(public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)",
        text,
        re.S,
    ):
        doc, sicht, name, args = mm.groups()
        if name.startswith("__"):
            continue
        zeilen = [z.strip(" *\t") for z in doc.strip().splitlines() if z.strip(" *\t")]
        kurz = zeilen[0] if zeilen else ""
        params = re.findall(r"\$(\w+)", args)
        methoden.append(
            {"name": name, "sicht": sicht, "kurz": kurz, "params": params}
        )
    return {"datei": pfad.name, "klasse": klasse, "kurz": zusammenfassung, "methoden": methoden}


klassen = []
for p in sorted((QUELLE / "includes").glob("class-*.php")) + [QUELLE / "wp-ai-edit.php"]:
    k = klassen_doku(p)
    if k:
        klassen.append(k)

# Hooks aus wp-ai-edit.php
hooks = re.findall(
    r"add_action\(\s*'([^']+)'\s*,\s*(?:array\(\s*)?(?:\\\$this,\s*'(\w+)'|array\(\s*__CLASS__,\s*'(\w+)')",
    (QUELLE / "wp-ai-edit.php").read_text(encoding="utf-8"),
)
hook_liste = []
for h in hooks:
    ziel = h[1] or h[2] or "?"
    hook_liste.append((h[0], ziel))

# --------------------------------------------------------------------------
# Markdown schreiben
# --------------------------------------------------------------------------
def typ_text(schema: dict) -> str:
    t = schema.get("type", "?")
    if t == "array" and "items" in schema:
        it = schema["items"]
        aufzaehlung = it.get("enum")
        if aufzaehlung:
            return "array&lt;" + " \\| ".join(aufzaehlung) + "&gt;"
        return f"array&lt;{it.get('type','?')}&gt;"
    if "enum" in schema:
        return " \\| ".join(f"`{e}`" for e in schema["enum"])
    return {"integer": "Ganzzahl", "string": "Text", "boolean": "Wahrheitswert",
            "object": "Objekt", "number": "Zahl"}.get(t, t)


T = []
A = T.append

A("# Funktionen — vollständige Referenz")
A("")
A(f"Stand: {DOKU['stand']} · WordPress {DOKU['wp']} · PHP {DOKU['php']} · Plugin {DOKU['plugin']}")
A("")
A("Diese Datei wird aus einer **laufenden Installation** erzeugt: die Fähigkeiten kommen")
A("aus `wp_get_abilities()`, die Routen aus dem REST-Server, die Modi aus `class-rest.php`.")
A("Sie beschreibt also, was tatsächlich registriert ist — nicht, was geplant war. Die")
A("Zahlen oben stammen von der Referenzinstallation; das Plugin selbst verlangt nur")
A("WordPress 6.9+ und PHP 8.0+.")
A("")
A("---")
A("")

# --- Übersicht -----------------------------------------------------------
A("## Fähigkeiten im Überblick")
A("")
A("| Fähigkeit | Bezeichnung | Nur-Lese-Chat | Bearbeitungsmodus |")
A("|---|---|:--:|:--:|")
for f in DOKU["faehigkeiten"]:
    n = f["name"]
    A(f"| `{n}` | {f['label']} | {'✅' if n in NUR_LESEN else '—'} | "
      f"{'✅' if n in MIT_SCHREIBEN else '—'} |")
A("")
A(f"Im Nur-Lese-Modus gibt der Agent {len(NUR_LESEN)} Fähigkeiten dieses Plugins frei — dazu "
  f"kommt `core/get-site-info` aus dem WordPress-Kern. Im Bearbeitungsmodus stehen alle "
  f"{len(MIT_SCHREIBEN)} bereit.")
A("")
A("---")
A("")

# --- Jede Fähigkeit ------------------------------------------------------
A("## Die einzelnen Fähigkeiten")
A("")
for f in DOKU["faehigkeiten"]:
    n = f["name"]
    A(f"### `{n}` — {f['label']}")
    A("")
    A(f["beschreibung"])
    A("")
    schema = f.get("schema") or {}
    props = schema.get("properties") or {}
    pflicht = schema.get("required") or []

    if props:
        A("| Parameter | Typ | Pflicht | Bedeutung |")
        A("|---|:--:|:--:|---|")
        for pname, pd in props.items():
            beschr = str(pd.get("description", "")).replace("|", "\\|")
            A(f"| `{pname}` | {typ_text(pd)} | {'✅' if pname in pflicht else '—'} | {beschr} |")
    else:
        A("*Keine Parameter.*")
    A("")

    merkmale = []
    meta = f.get("meta") or {}
    if meta.get("readonly"):
        merkmale.append("nur lesend")
    if meta.get("destructive"):
        merkmale.append("verändernd")
    if meta.get("idempotent"):
        merkmale.append("wiederholbar ohne Zusatzwirkung")
    if merkmale:
        A(f"Natur: {', '.join(merkmale)}.")
        A("")

    modi = []
    if n in NUR_LESEN:
        modi.append("Nur-Lese-Chat")
    if n in MIT_SCHREIBEN:
        modi.append("Bearbeitungsmodus")
    A(f"Verfügbar im: {', '.join(modi) if modi else 'in keinem Modus'}.")
    A("")

# --- Routen --------------------------------------------------------------
A("---")
A("")
A("## REST-Schnittstelle")
A("")
A("Namensraum: `wp-ai-edit/v1`. Alle Routen außer der Übersicht verlangen eine")
A("angemeldete Sitzung mit dem Mindestrecht; ohne Anmeldung antworten sie mit HTTP 401.")
A("")
A("| Methode | Pfad | Parameter | Geschützt |")
A("|---|:--:|---|:--:|")
for r in DOKU["routen"]:
    pfad = r["route"].replace("/wp-ai-edit/v1", "")
    if not pfad:
        pfad = "/"
    args = ", ".join(f"`{a}`" for a in r["args"]) or "—"
    if r["route"] == "/wp-ai-edit/v1":
        A(f"| {r['method']} | `/` | {args} | — (Übersicht) |")
    else:
        A(f"| {r['method']} | `{pfad}` | {args} | {'✅' if r['geschuetzt'] else '—'} |")
A("")
A("Vom Chatfenster benutzt werden `/status`, `/chat`, `/reset` und die drei")
A("`/pending`-Routen; `/test` gehört zur Einstellungsseite.")
A("")
A("---")
A("")

# --- Einstellungen -------------------------------------------------------
A("## Einstellungen")
A("")
A("### KI-Zugang und Bildgenerierung")
A("")
A("| Schlüssel | Vorgabe | Bedeutung |")
A("|---|---|---|")
KI = {
    "base_url": "Ohne `/chat/completions` — der Pfad wird angehängt",
    "modell": "Muss Werkzeugaufrufe unterstützen",
    "schluessel": "Wird maskiert angezeigt; leer lassen behält den alten Wert",
    "temperatur": "0 = deterministisch, 1 = erfindungsfreudig",
    "max_runden": "Wie oft das Modell Werkzeuge aufrufen darf, bevor abgebrochen wird",
    "timeout": "Sekunden pro Anfrage",
    "thinking": "Schaltet den Denkmodus des Modells zu, wo vorhanden",
}
for k, v in DOKU["vorgaben"]["WP_AI_Edit_LLM"].items():
    if k == "schluessel":
        A(f"| `{k}` | *(wird beim Einrichten eingetragen)* | {KI.get(k, '')} |")
        continue
    A(f"| `{k}` | `{v}` | {KI.get(k, '')} |")
A("")
PLUG = {
    "enabled": "Widget im Backend ein- oder ausschalten",
    "admin_pages": "Leer = auf allen Backend-Seiten sichtbar",
    "min_capability": "Ab welchem Recht das Widget erscheint",
    "history_limit": "Wie viele Nachrichten der Gesprächsverlauf behält",
    "prompt_chat": "Eigene Systemanweisung für den Nur-Lese-Modus; leer = mitgelieferte Datei",
    "prompt_edit": "Eigene Systemanweisung für den Bearbeitungsmodus; leer = mitgelieferte Datei",
    "workflow": "`stage` = erst vorschlagen, `direct` = sofort anwenden",
    "bild_key": "Schlüssel des Bilddienstes (WaveSpeed: `wsk_live_…`)",
    "bild_modell": "Vorgabe: Seedream 4.5, Text zu Bild",
    "bild_base": "Adresse der Bildschnittstelle",
    "bild_groesse": "Maße als BREITE*HÖHE",
}
A("### Verhalten und Bildgenerierung")
A("")
A("| Schlüssel | Vorgabe | Bedeutung |")
A("|---|---|---|")
for k, v in DOKU["vorgaben"]["WP_AI_Edit"].items():
    if isinstance(v, list):
        zeige = "(leer)" if not v else str(v)
    elif v == "":
        zeige = "*(leer)*" if k not in ("bild_key",) else "*(wird beim Einrichten eingetragen)*"
    else:
        zeige = str(v)
    A(f"| `{k}` | {('`' + zeige + '`') if not zeige.startswith('*(') else zeige} | {PLUG.get(k, '')} |")
A("")
A("---")
A("")

# --- Optionen ------------------------------------------------------------
A("## Datenbank")
A("")
A("| Option | Inhalt |")
A("|---|---|")
OPT = {
    "wp_ai_edit": "Alle Einstellungen oben, als ein Feld",
    "wp_ai_edit_llm": "KI-Zugang (Basis-URL, Modell, Schlüssel)",
    "wp_ai_edit_pending": "Offene Vorschläge samt vorgeschlagener Fassung",
    "wp_ai_edit_snapshots": "Sicherungen vor Änderungen (höchstens 100)",
    "wp_ai_edit_log": "Protokoll der Werkzeugaufrufe (höchstens 200)",
}
for k, v in DOKU["optionen"].items():
    A(f"| `{k}` | {OPT.get(k,'')} |")
A("")
A("Schlüssel liegen ausschließlich in `wp_ai_edit_llm` und `wp_ai_edit` und werden nur an")
A("die dort eingetragenen Adressen gesendet. Im Plugin-Quelltext stehen sie nicht.")
A("")
A("---")
A("")

# --- Befehle -------------------------------------------------------------
A("## Befehle im Chat")
A("")
A("| Befehl | Wirkung |")
A("|---|---|")
for b, w in [
    ("/editsite", "Bearbeitungsmodus: alle Fähigkeiten, Bearbeitungs-Prompt"),
    ("/normal", "Zurück in den Nur-Lese-Modus"),
    ("/inspect", "Zustand der Website als JSON ausgeben"),
    ("/reset", "Gesprächsverlauf löschen"),
    ("/hilfe", "Befehlsübersicht"),
]:
    A(f"| `{b}` | {w} |")
A("")
A("Tastenkürzel: **Strg/Cmd + K** öffnet das Widget, **Esc** schließt es.")
A("")
A("---")
A("")

# --- Klassen -------------------------------------------------------------
A("## Aufbau des Quelltexts")
A("")
for k in klassen:
    A(f"### `{k['datei']}` — `{k['klasse']}`")
    A("")
    if k["kurz"]:
        A(k["kurz"])
        A("")
    if k["methoden"]:
        A("| Methode | Sichtbar | Zweck |")
        A("|---|:--:|---|")
        for m in k["methoden"]:
            if m["sicht"] != "public":
                continue
            A(f"| `{m['name']}()` | {m['sicht']} | {m['kurz'] or '—'} |")
        A("")
        A(f"*Dazu {sum(1 for m in k['methoden'] if m['sicht'] != 'public')} interne Methoden.*")
        A("")

A("### Hooks")
A("")
A("| Hook | Ruf | Zweck |")
A("|---|---|---|")
HOOK_ZWECK = {
    "wp_abilities_api_categories_init": "Kategorie `kiedit` anlegen",
    "wp_abilities_api_init": "alle Fähigkeiten registrieren",
    "rest_api_init": "REST-Routen anlegen",
    "init": "Vorschau vorgeschlagener Änderungen ausgeben",
    "admin_menu": "Einstellungsseite",
    "admin_enqueue_scripts": "Widget laden — nur im Backend",
    "admin_footer": "Widget-Markup — nur im Backend",
    "admin_notices": "Hinweis bei fehlendem Zugang",
    "admin_init": "Verbindungstest des Bilddienstes",
}
gesehen = set()
for hook, ziel in hook_liste:
    if hook in gesehen:
        continue
    gesehen.add(hook)
    A(f"| `add_action( '{hook}' )` | `{ziel}` | {HOOK_ZWECK.get(hook,'')} |")
A("")
A("Kein `wp_enqueue_scripts`, kein `wp_ajax_nopriv_*` — deshalb existiert das Widget")
A("auf der öffentlichen Website nicht.")
A("")

# --- Seitenbauer ---------------------------------------------------------
A("---")
A("")
A("## Seitenbauer-Erkennung")
A("")
A("`WP_AI_Edit_Abilities::seitenbauer()` prüft je Seite, ob ein Seitenbauer sie")
A("verwaltet. `update-page` und `replace-text` verweigern dann die Arbeit.")
A("")
A("| Seitenbauer | Merkmal |")
A("|---|---|")
for name, meta in [
    ("Elementor", "`_elementor_edit_mode` = `builder`"),
    ("Divi", "`_et_pb_use_builder` = `on`"),
    ("WPBakery", "`_wpb_vc_js_status` = `true`"),
    ("Bricks", "`_bricks_page_content_2` gefüllt (Array)"),
    ("Oxygen", "`ct_builder_shortcodes` gefüllt"),
]:
    A(f"| {name} | {meta} |")
A("")
A("`get-page` meldet das Ergebnis im Feld `bearbeitbar`, damit der Agent den Nutzer")
A("informieren kann, statt stumm wirkungslos zu schreiben.")
A("")

ZIEL.parent.mkdir(parents=True, exist_ok=True)
ZIEL.write_text("\n".join(T) + "\n", encoding="utf-8")
print(f"geschrieben: {ZIEL}")
print(f"  {len(T)} Zeilen · {ZIEL.stat().st_size} Bytes")
print(f"  Fähigkeiten: {len(DOKU['faehigkeiten'])} · Routen: {len(DOKU['routen'])} · Klassen: {len(klassen)}")
print(f"  Nur-Lese-Modus: {len(NUR_LESEN)} · Bearbeitungsmodus: {len(MIT_SCHREIBEN)}")
