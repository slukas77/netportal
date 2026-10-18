# Änderungen

Versionen tragen das Datum ihrer Veröffentlichung.

## 2026.10.18

- **Logos automatisch nach Betriebssystem:** Steht beim Icon „automatisch“, nimmt das Portal das
  Logo, dessen Name im Feld „Betriebssystem“ vorkommt (längster Treffer: „Windows Server 2008 R2
  Standard“ → Server 2008 R2, „Debian 13“ → Debian). Ein von Hand gewähltes Icon hat Vorrang.
- **Windows fest eingebaut** (`BRAND_BUILTIN` in `includes/icons.php`, von der Integritätsprüfung
  erfasst): eigene Symbole (kein Microsoft-Logo) für Windows 2000, XP, Vista, 7, 8, 8.1, 10, 11 und
  Windows 2000 Server bis Server 2025 (inkl. R2), mit Versionsplakette. Der Simple-Icons-Import
  ändert sie nie.

- **Audit-Log mit Aufbewahrung:** Einträge (und SSH-Aufzeichnungen), die älter als
  90 Tage sind, löscht das Portal automatisch einmal täglich – ohne eigenen cron-Job
  (nach der ersten Seitenanfrage des Tages bzw. beim nächsten cron-Befehl). Die Dauer
  steht unter *Einstellungen → Audit-Log* (0 = nie löschen).
  - Vor dem Löschen wird der betroffene Abschnitt vollständig geprüft. Ist er verfälscht,
    wird nichts gelöscht und die Verweigerung protokolliert – Beweise werden nie „weggeräumt“.
  - Der letzte gelöschte Eintrag bleibt als **signierter Anker** stehen; ab dort beginnt die
    Hash-Kette. Heimlich gelöschte oder eingeschobene Einträge und ein gefälschter Anker fallen
    weiterhin auf. Jede Bereinigung steht selbst im Audit-Log.
- **cron-Befehle schreiben weniger:** `ping`, `services` und `wan:check` protokollieren nur
  noch, wenn ein Gerät/Dienst/Anschluss ausfällt oder wieder erreichbar ist, plus einmal täglich
  eine Zusammenfassung (statt jeder Lauf je Netzwerk).
- **Schnelleres Audit-Log:** Die Seite prüft nur die seit der letzten Prüfung neuen Einträge
  („komplette Kette prüfen“ auf Klick; `check`, *Integrität* und `audit:verify` prüfen immer alles).
- **Master bei sehr vielen Einträgen:** „Master bearbeiten“ und die Master-Liste lesen nur den
  Host-Index (bei 160 000 Einträgen 1 s statt 7,5 s, 240 KB statt 10 MB); der Abgleich lässt
  Netzwerke ohne Bezug zum Master aus.
- Speicher je Aufruf begrenzt: höchstens 8 Netzwerke bleiben im Zwischenspeicher.
- Neu: `php bin/netportal-cli.php audit:prune [--days N] [--vacuum]`.

## 2026.10.13

- **Master vereinfacht:** Gerät eingeben, Netzwerke auswählen, speichern – das
  Gerät wird in jedem ausgewählten Netzwerk auf der ersten IP eingetragen. Die
  Vorschau zeigt je Netzwerk die IP und ob sie frei oder schon belegt ist.
  Name-Muster, Position, Dienste, Zugriff und Icon sind optional und eingeklappt.
  Ein neuer Master bekommt keine Dienste mehr vorbelegt; der Name im Netzwerk
  ist standardmäßig der Gerätename.
- Netzwerk-Auswahl des Masters auf dem Handy als kompakte Zeilen.

## 2026.10.12

Ab dieser Ausgabe ist die Versionsnummer das Datum der Veröffentlichung.

- **Switch-Verwaltung** (Menü „Infrastruktur → Switches“, `database/switches.sql`)
  - **Original-Vorlagen** nach Herstellerangaben, fest eingebaut und nicht änderbar:
    HP ProCurve **2610-24 (J9085A)** – 24 × 10/100, 2 × 10/100/1000, 2 × mini-GBIC,
    Software R.11 –, HP ProCurve **2510-24 (J9019B)** Rev. B, Software Q.11, und
    **2510-24 (J9019A)** Rev. A, Software Q.10 – je 24 × 10/100 und 2 Dual-Personality-Ports
    (RJ-45 10/100/1000 oder mini-GBIC). Werks-Hostname und Werkseinstellungen wie ab Werk.
  - **Eigene Vorlagen** anlegen oder ein Original kopieren: Port-Aufbau, Frontansicht,
    Einstellungen (Hostname, Gateway, SNTP, Zeitzone, SNMP, Syslog, SSH/Telnet/Web, STP,
    erlaubte Manager) und **Port-Vorgaben** je Zeile `Ports;untagged;tagged;Name;an/aus;Trunk`.
  - **Vorlage anwenden** auf einen Switch: Port-Aufbau anpassen, Port-Vorgaben und/oder
    Einstellungen übernehmen. Angeschlossene Geräte und Notizen bleiben erhalten.
  - **Je Port Netzwerke zuweisen**: ein Netzwerk *untagged* und beliebige Netzwerke
    *tagged* (Auswahl direkt aus den Netzwerken/VLANs des Portals), dazu Name, an/aus,
    Geschwindigkeit, Trunk (LACP) und das **angeschlossene Gerät** (Eintrag aus einem
    Netzwerk). Die **Frontansicht** zeigt den Switch wie am Gerät – Farbe = Netzwerk,
    gestreift = tagged, grüne LED = Gerät zugeordnet –, die Port-Tabelle zeigt Port,
    Netzwerke und Gerät. Mehrere Ports auf einmal bearbeiten (z. B. `1-12,15`).
  - Auf der Seite eines Eintrags steht, **an welchem Switch-Port** er hängt und in
    welchen Netzwerken dieser Port ist.
  - **Konfiguration erzeugen** im ProCurve-Format (running-config: hostname, interface,
    trunk, vlan untagged/tagged, ip address, sntp, snmp-server, logging, ip ssh,
    authorized-managers …) zum Kopieren oder als `.cfg`-Download, und eine vorhandene
    `show running-config` **einlesen**. Passwörter werden nie in die Datei geschrieben.
- **WAN-Verwaltung** (Menü „Infrastruktur → WAN-Anschlüsse“, `database/wan.sql`)
  - Anschlüsse je Standort: Anbieter, Produkt, Zugangsart (VDSL, Glasfaser, Kabel,
    LTE/5G, Satellit, Standleitung …), Anmeldung (PPPoE/DHCP/statisch), WAN-VLAN, MTU,
    IPv4 (dynamisch, feste IP/Netz, CGNAT, DS-Lite), IPv6 (Prefix Delegation, festes
    Präfix), DNS, Bandbreite, Priorität (Failover), Router aus dem Portal, Modem/ONT,
    Vertrag (Kunden-/Vertragsnummer, Leitungs-ID, Hotline, Laufzeit, Kündigungsfrist,
    Kosten, SLA).
  - **Eingebaute Vorlagen**: Telekom VDSL und Glasfaser (PPPoE, VLAN 7, MTU 1492, /56),
    Glasfaser IPoE, Kabel DOCSIS 3.1 (DS-Lite), LTE/5G-Backup, Business mit festen IPs
    (/29) und Starlink – plus **eigene Vorlagen** (neu oder als Kopie). Vorlage beim
    Anlegen wählen (füllt die Felder) oder später anwenden (optional nur leere Felder).
  - **PPPoE-Passwort verschlüsselt** (AES-256-GCM, Schlüssel aus dem Integritätsschlüssel);
    anzeigen nur für Portal-Admins, jede Anzeige steht im Audit-Log.
  - **Leitungsweg** Router → Modem → Anbieter → Internet, **Erreichbarkeit per Ping**
    (einzeln oder alle), Hinweise zu Vertragsende und Kündigungsfrist,
    **Router-Einstellungen** als Klartext und Linux-Vorschlag (systemd-networkd/pppd),
    CSV-Export.
- Beide Dateien sind wie alle anderen gesichert (Hash je Zeile, Hash je Datei, Siegel)
  und erscheinen unter „Integrität“ und „Diagnose & Debug“.

## 1.2

- **SSH-Terminal im Browser** (Menü „SSH-Terminal“, Knopf „SSH-Terminal“ oben auf
  jeder Host-Seite, Terminal-Symbol in jeder Netzwerk-Tabelle):
  - Die Verbindung baut **der Portal-Server** auf (PHP startet `ssh` in einem
    Pseudo-Terminal über `script`). Auf dem Rechner des Benutzers wird nichts
    installiert oder gestartet; der Browser zeigt nur das Terminal an. Kein
    Node, kein npm, keine fremden Bibliotheken.
  - **Host und Benutzer auswählbar**: alle Einträge nach Netzwerk gruppiert, mit
    Suche. Vorschläge für den Benutzer, „Zuletzt verbunden“-Knöpfe und mehrere
    Sitzungen als Tabs. Dazu Vollbild, eigenes Fenster und Schriftgröße.
  - Farben, Vollbild-Programme (htop, vim, mc), Größenänderung, Strg-Tasten,
    Kopieren/Einfügen.
  - Anmeldung am Ziel mit Passwort (wird im Terminal getippt, nie gespeichert
    oder protokolliert) oder mit dem Portal-Schlüssel (nur Portal-Admins, für
    Power-User per Einstellung).
  - Jede Sitzung wird mit Ziel, Benutzer, Dauer und Ausgabe-Hash im Audit-Log
    festgehalten. Die Bildschirmausgabe lässt sich optional aufzeichnen.
  - Der alte „SSH öffnen“-Link (lokaler SSH-Client) ist entfallen.
- **Master** (Menüpunkt „Master“): in zwei Schritten.
  1. Gerät eingeben (z. B. „OPNsense“, Typ Firewall).
  2. Netzwerke auswählen.

  Das Portal trägt das Gerät in jedem ausgewählten Netzwerk auf der ersten
  nutzbaren IP ein (z. B. 10.0.20.1 in 10.0.20.0/24). Die Tabelle zeigt vor dem
  Speichern die IP und den Zustand je Netzwerk. Alles Weitere ist optional und
  eingeklappt („Weitere Angaben“):
  - Position: statt der ersten IP auch Gateway-IP, letzte nutzbare IP oder
    Netzadresse + Versatz.
  - Abweichender Name im Netzwerk (Muster), Dienste, Hersteller/Modell,
    SSH/iperf3, Icon.
  - Ist die IP schon belegt, wird das Netzwerk übersprungen. Alternativ kann der
    vorhandene Eintrag übernommen werden.
  - Beim Speichern wird abgeglichen: Felder und Dienste der Vorlage werden in
    alle Netzwerke übertragen. MAC und Hardware-Kennung bleiben, von Hand
    ergänzte Dienste ebenso.
  - Entfernt man das Häkchen, verschwindet der Eintrag aus diesem Netzwerk.
  - Optional bekommen **neue Netzwerke** den Master automatisch.
  - Gespeichert in `database/masters.sql` mit derselben Integritätssicherung.
- **Caddyfile-Generator** (Menüpunkt „Caddyfile-Generator“): Optionen
  anhaken, die saubere Caddy-Konfiguration erscheint als Vorschau (rechnet in
  PHP auf dem Server, funktioniert auch ohne JavaScript). Download als `.conf`.
  Umfang:
  - Globale Optionen: E-Mail, Admin-API, Ports, auto_https, ACME-Server,
    interne CA, Protokolle h1/h2/h3, vertrauenswürdige Proxys, Timeouts, Log
  - beliebig viele Sites: Reverse-Proxy (mehrere Ziele, Lastverteilung, Health-
    Checks, Pfad), PHP-FPM, statische Dateien/SPA, Weiterleitung, feste Antwort
  - TLS: automatisch, intern, eigene Dateien, DNS-Challenge, Mindestversion, mTLS
  - Kompression, Sicherheits-Header (HSTS, CSP …), CORS, Basic-Auth (bcrypt),
    IP-Freigaben, Upload-Limit, Zugriffslog mit Rotation, www-Umleitung,
    Fehlerseiten
  - Ziele lassen sich direkt aus den Diensten des Portals übernehmen.
  - Vorlagen speichern (Portal-Admins). Basic-Auth-Passwörter stehen nie im
    Klartext in Datei oder Vorlage.
  - Alle Eingaben werden geprüft; eingeschleuste Direktiven sind nicht möglich.
- **Diagnose & Debug** (Portal-Admins):
  - Selbsttest aller Funktionen mit Ampel und Lösungshinweis
  - Debug-Modus mit Protokoll der Anfragen, PHP-Warnungen und JavaScript-Fehler
    aus dem Browser
  - Bericht zum Herunterladen (ohne Geheimnisse); auf der Kommandozeile mit
    `php bin/netportal-cli.php diagnose`

## 1.1

- **Farbige Dienste** je Eintrag, mit **Legende** in jedem Netzwerk und auf jeder Host-Seite:
  - grün: verfügbar / online
  - rot: nicht verfügbar / offline
  - blau: unbekannt (noch nicht geprüft)
  - orange: ohne Statusprüfung angelegt
  Die Statusprüfung lässt sich je Dienst und je Host beim Anlegen/Bearbeiten
  ein- oder ausschalten. „Dienste prüfen“ gibt es je Host und für ein ganzes
  Netzwerk.
- **Sprache DE / EN (en_US)**: Die Browser-Sprache entscheidet die Vorwahl
  (Accept-Language). Umschalten jederzeit in der Fußzeile oder
  auf der Login-Seite; die Wahl wird im Cookie gemerkt.
- **iperf3 je Eintrag**: TCP- oder UDP-Test vom Portal-Server zur IP des
  Eintrags. Port-Feld mit Standardwert 5201 (hineinklicken zum Ändern), dazu
  Dauer, Richtung (Upload / Download `-R`), UDP-Bandbreite und parallele
  Streams. Der Standard-Port lässt sich je Eintrag und global ändern.
- **Icons je Eintrag**:
  - mitgelieferte Symbole: Server, VM, Container, NAS, Router, Switch,
    Firewall, Access Point, PC, Drucker, Kamera …
  - Steuerungstechnik: SPS, Bedienpanel/HMI, Sensor, Maschine, USV, BMC
  - Logos von Betriebssystemen und Software (Ubuntu, Linux Mint, Debian,
    Fedora, Arch, OPNsense, pfSense, Caddy, nginx, Proxmox, TrueNAS, Docker …)
    aus **Simple Icons (Lizenz CC0 1.0)**
  - eigene Icons hochladen (PNG, JPG, WebP, SVG ohne Skripte, max. 256 KB)
- **MAC-Adresse und Hardware-Kennung automatisch ermitteln** beim Anlegen oder
  auf Knopfdruck. Quellen:
  - eigene Schnittstellen des Servers
  - Nachbartabelle (ARP/NDP)
  - NetBIOS (`nmblookup`)
  - Hersteller aus der OUI-Liste
  - optional SNMP sowie SSH (DMI-UUID, machine-id)

  Klappt es nicht, steht im Feld **`not found`** zum manuellen Eintragen.
- **Benutzer in `database/users.sql`**, mit Zeilen-Hash, Datei-Hash und Siegel
  wie die Netzwerk-Dateien. Ein am Portal vorbei geänderter Benutzer-Eintrag
  kann sich nicht mehr anmelden.
- **Rollen**: Leser · Power-User · Portal-Admin (siehe unten).
- **System & Pakete** (nur Portal-Admins):
  - zeigt, was fehlt und was schon funktioniert: PAM, PAM + YubiKey,
    YubiKey (FIDO2), Yubico-OTP, LDAP/AD, Werkzeuge, MAC-Erkennung
  - prüft die Pakete unter Debian/Ubuntu
  - installiert fehlende PHP-Module und Pakete per Klick
  - Tester für PAM, LDAP/AD und YubiKey-OTP
