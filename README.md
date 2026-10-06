# L.net MCP-Erweiterungen

WordPress-Plugin von [L.net Web Solutions](https://www.l-net.biz/). Es ergänzt den [WP MCP Connector](https://github.com/remymazmanian/wp-mcp-connector-plugin) um Werkzeuge, mit denen ein KI-Assistent Inhalte pflegen kann, ohne dass Änderungen ungeprüft live gehen.

## Funktionen

**Beaver Builder**
- `lnet_bb_get_layout`: liest die Module einer Beaver-Builder-Seite in Lesereihenfolge aus.
- `lnet_bb_update_module`: ändert ein Modul ausschließlich im Beaver-Builder-Entwurf. Die Live-Seite bleibt unverändert, bis im Builder „Veröffentlichen“ geklickt wird.

**Geplante Aktualisierungen** (veröffentlichte Inhalte ohne Beaver Builder)
- `lnet_schedule_update`: merkt eine Änderung an Titel, Inhalt oder Auszug vor. Die Seite bleibt bis zum Stichtag (Standard: 7 Tage) unverändert online und wird dann automatisch aktualisiert, mit Revision.
- `lnet_get_pending_update`: zeigt geplante Änderungen an.
- Prüfen im Backend: Kasten „Geplante Änderung“ auf der Bearbeiten-Seite und **Werkzeuge → Geplante Änderungen**, jeweils mit Gegenüberstellung, „Jetzt übernehmen“ und „Verwerfen“.
- Frontend-Vorschau mit `?lnet_preview=1`. Sie ist nur für angemeldete Benutzer sichtbar, die den Inhalt bearbeiten dürfen, und wird nicht zwischengespeichert.
- Wurde ein Inhalt nach der Planung manuell geändert, wird die geplante Änderung nicht automatisch übernommen, sondern wartet auf eine Entscheidung.

## Voraussetzungen

- WordPress 6.4+, PHP 7.4+
- [WP MCP Connector](https://github.com/remymazmanian/wp-mcp-connector-plugin) (für die MCP-Werkzeuge)
- Beaver Builder (nur für die Beaver-Builder-Werkzeuge)

## Installation und Updates mit WP Pusher

1. In WP Pusher **Install Plugin** wählen.
2. Repository: `cleuenberg/lnet-mcp-erweiterungen`, Branch: `main`.
3. **Push-to-Deploy** aktivieren, damit neue Versionen automatisch eingespielt werden.

Geplante Änderungen werden als Metadaten gespeichert und bleiben bei Updates und beim Deaktivieren erhalten.

## Lizenz

GPL-2.0-or-later
