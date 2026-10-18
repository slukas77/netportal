#!/usr/bin/env bash
# =============================================================================
#  Network Portal – Einrichtung mit Caddy und PHP 8.5 (Debian 12/13, Ubuntu 24.04/26.04)
#
#  1. Werte im Abschnitt „ANPASSEN“ setzen und CONFIGURED="yes" eintragen
#  2. sudo ./install.sh
#
#  Erneut ausführen = Update: Programmdateien werden ersetzt, Daten bleiben
#  (config/, database/, .cache/, icons/ werden nie überschrieben).
#
#  © 2026 Lukas Geipel – MIT-Lizenz
# =============================================================================
set -euo pipefail

# ================================ ANPASSEN ===================================

# Adresse, unter der das Portal erreichbar ist (ohne https://)
DOMAIN="netportal.example.com"

# Zielordner des Portals
INSTALL_DIR="/var/www/netportal"

# PHP-Version und Benutzer, unter dem PHP-FPM das Portal ausführt
PHP_VERSION="8.5"
PHP_USER="www-data"

# Zertifikat:
#   auto     = Let's Encrypt / ZeroSSL (DOMAIN muss öffentlich auflösbar und erreichbar sein)
#   internal = Caddys eigene CA (Browser warnen, bis die CA vertraut wird)
#   acme     = eigener ACME-Server, z. B. step-ca (ACME_CA setzen)
#   files    = vorhandenes Zertifikat (TLS_CERT und TLS_KEY setzen)
TLS_MODE="auto"
ACME_EMAIL=""      # optional, Kontakt für auto/acme
ACME_CA=""         # bei acme: https://acme.example.internal/acme/acme/directory
ACME_CA_ROOT=""    # bei acme: Root-Zertifikat (PEM), falls nicht systemweit vertraut
TLS_CERT=""        # bei files: z. B. /etc/ssl/netportal/fullchain.pem
TLS_KEY=""         # bei files: z. B. /etc/ssl/netportal/privkey.pem

# Zugriff nur aus diesen Netzen (leer = aus allen Netzen).
# Standard: nur private Adressen – für ein Portal im LAN/VPN gedacht.
ALLOW_FROM="127.0.0.0/8 ::1/128 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 fc00::/7"

# Regelmäßige Prüfungen per cron (Integrität, Ping, Dienste, WAN)
SETUP_CRON="yes"

# root-Helfer für „System & Pakete“ (Pakete per Klick, PAM-Helfer bauen)
INSTALL_ADMIN_HELPER="no"

# Werkzeuge für Ping, SSH, iperf3, Erkennung, LDAP-Test (leer = keine)
OPTIONAL_PACKAGES="iputils-ping openssh-client iperf3 iproute2 snmp ldap-utils"

# Zusätzliche PHP-Module (ldap für Active Directory / LDAP)
PHP_EXTRA_MODULES="ldap"

# Erst nach dem Anpassen auf "yes" setzen
CONFIGURED="no"

# =============================================================================

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CADDY_SITE="/etc/caddy/caddy.d/netportal.conf"
CADDY_MAIN="/etc/caddy/Caddyfile"
FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/netportal.conf"
FPM_SOCK="/run/php/php${PHP_VERSION}-fpm-netportal.sock"
CRON_FILE="/etc/cron.d/netportal"

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mHinweis:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mFehler:\033[0m %s\n' "$*" >&2; exit 1; }

# ------------------------------------------------------------------ Prüfungen
check_settings() {
    [ "$(id -u)" -eq 0 ] || die "bitte als root ausführen: sudo ./install.sh"
    [ "$CONFIGURED" = "yes" ] || die "zuerst die Werte im Abschnitt ANPASSEN in install.sh setzen und CONFIGURED=\"yes\" eintragen."
    if [ -z "$DOMAIN" ] || [ "$DOMAIN" = "netportal.example.com" ]; then die "DOMAIN ist noch nicht angepasst."; fi
    [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || die "DOMAIN ohne https:// und ohne Pfad angeben, z. B. netportal.firma.local"
    if [[ "$INSTALL_DIR" != /* ]] || [ "$INSTALL_DIR" = "/" ]; then die "INSTALL_DIR muss ein absoluter Pfad sein (nicht /)."; fi
    [[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]] || die "PHP_VERSION wie 8.5 angeben."
    id "$PHP_USER" >/dev/null 2>&1 || die "Benutzer $PHP_USER existiert nicht."
    if [ ! -f "$SRC_DIR/index.php" ] || [ ! -d "$SRC_DIR/includes" ]; then die "install.sh muss im Ordner des Portals liegen."; fi

    case "$TLS_MODE" in
        auto|internal) ;;
        acme)
            [[ "$ACME_CA" = https://* ]] || die "TLS_MODE=acme braucht ACME_CA (https://…/directory)."
            [ -z "$ACME_CA_ROOT" ] || [ -f "$ACME_CA_ROOT" ] || die "ACME_CA_ROOT nicht gefunden: $ACME_CA_ROOT" ;;
        files)
            [ -f "$TLS_CERT" ] || die "TLS_CERT nicht gefunden: $TLS_CERT"
            [ -f "$TLS_KEY" ] || die "TLS_KEY nicht gefunden: $TLS_KEY" ;;
        *) die "TLS_MODE muss auto, internal, acme oder files sein." ;;
    esac

    # shellcheck disable=SC1091
    . /etc/os-release
    OS_ID="${ID:-}"
    OS_CODENAME="${VERSION_CODENAME:-}"
    case "$OS_ID ${ID_LIKE:-}" in
        *debian*|*ubuntu*) ;;
        *) die "nur für Debian und Ubuntu gedacht (gefunden: ${PRETTY_NAME:-unbekannt})." ;;
    esac
}

# ------------------------------------------------------------------ Pakete
apt_has() { apt-cache show "$1" >/dev/null 2>&1; }

install_base() {
    say "Paketquellen aktualisieren"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -q
    apt-get install -y -q ca-certificates curl gnupg acl debian-keyring debian-archive-keyring apt-transport-https 2>/dev/null \
        || apt-get install -y -q ca-certificates curl gnupg acl
}

install_php() {
    if ! apt_has "php${PHP_VERSION}-fpm"; then
        say "PHP ${PHP_VERSION} ist nicht in den Paketquellen – Quelle von Ondřej Surý wird eingebunden"
        if [ "$OS_ID" = "ubuntu" ]; then
            apt-get install -y -q software-properties-common
            add-apt-repository -y ppa:ondrej/php
        else
            curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
            dpkg -i /tmp/debsuryorg-archive-keyring.deb
            rm -f /tmp/debsuryorg-archive-keyring.deb
            echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" \
                > /etc/apt/sources.list.d/php-sury.list
        fi
        apt-get update -q
        apt_has "php${PHP_VERSION}-fpm" || die "php${PHP_VERSION}-fpm ist auch dort nicht verfügbar."
    fi
    local pkgs=() m
    for m in fpm cli sqlite3 mbstring opcache ${PHP_EXTRA_MODULES}; do
        if apt_has "php${PHP_VERSION}-${m}"; then
            pkgs+=("php${PHP_VERSION}-${m}")
        elif [ "$m" != "opcache" ]; then   # ab PHP 8.5 ist OPcache fest eingebaut
            warn "php${PHP_VERSION}-${m} nicht gefunden – übersprungen."
        fi
    done
    say "PHP installieren: ${pkgs[*]}"
    apt-get install -y -q "${pkgs[@]}"
}

install_caddy() {
    if command -v caddy >/dev/null 2>&1; then
        say "Caddy ist bereits installiert ($(caddy version | cut -d' ' -f1))"
        return
    fi
    say "Caddy aus der offiziellen Paketquelle installieren"
    curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key \
        | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt \
        -o /etc/apt/sources.list.d/caddy-stable.list
    chmod o+r /usr/share/keyrings/caddy-stable-archive-keyring.gpg /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -q
    apt-get install -y -q caddy
}

install_optional() {
    [ -n "$OPTIONAL_PACKAGES" ] || return 0
    local pkgs=() p
    for p in $OPTIONAL_PACKAGES; do
        if apt_has "$p"; then pkgs+=("$p"); else warn "$p nicht gefunden – übersprungen."; fi
    done
    [ "${#pkgs[@]}" -eq 0 ] || { say "Werkzeuge installieren: ${pkgs[*]}"; apt-get install -y -q "${pkgs[@]}"; }
}

# ------------------------------------------------------------------ Dateien
copy_files() {
    mkdir -p "$INSTALL_DIR"
    if [ "$(cd "$INSTALL_DIR" && pwd -P)" != "$(cd "$SRC_DIR" && pwd -P)" ]; then
        say "Programmdateien nach $INSTALL_DIR kopieren (Daten bleiben unberührt)"
        tar -C "$SRC_DIR" \
            --exclude=./.git --exclude=./.github --exclude=./.gitignore --exclude=./.gitattributes \
            --exclude=./docs --exclude=./demo --exclude=./install.sh \
            --exclude=./config --exclude=./database --exclude=./.cache --exclude=./icons \
            --exclude=./manifest.sha256 \
            -cf - . | tar -C "$INSTALL_DIR" --no-same-owner -xf -
        local d f
        for d in config database .cache icons icons/brands icons/custom; do
            mkdir -p "$INSTALL_DIR/$d"
            for f in .htaccess .keep; do
                if [ -e "$SRC_DIR/$d/$f" ] && [ ! -e "$INSTALL_DIR/$d/$f" ]; then
                    cp "$SRC_DIR/$d/$f" "$INSTALL_DIR/$d/$f"
                fi
            done
        done
    else
        say "Portal liegt bereits in $INSTALL_DIR – nichts zu kopieren"
    fi
}

set_permissions() {
    say "Rechte setzen (Programm nur lesbar, Datenordner für $PHP_USER beschreibbar)"
    local grp
    grp="$(id -gn "$PHP_USER")"
    chown -R root:"$grp" "$INSTALL_DIR"
    find "$INSTALL_DIR" -type d -exec chmod 750 {} +
    find "$INSTALL_DIR" -type f -exec chmod 640 {} +
    local d
    for d in config database .cache icons icons/brands icons/custom; do
        chown -R "$PHP_USER":"$grp" "$INSTALL_DIR/$d"
        find "$INSTALL_DIR/$d" -type d -exec chmod 770 {} +
        find "$INSTALL_DIR/$d" -type f -exec chmod 660 {} +
    done
    [ ! -f "$INSTALL_DIR/config/integrity.key" ] || chmod 600 "$INSTALL_DIR/config/integrity.key"

    # Caddy darf nur assets/ lesen und index.php finden – sonst nichts
    setfacl -b "$INSTALL_DIR"
    setfacl -m u:caddy:x "$INSTALL_DIR"
    setfacl -R -m u:caddy:rX "$INSTALL_DIR/assets"
    setfacl -R -d -m u:caddy:rX "$INSTALL_DIR/assets"
}

write_manifest() {
    # Prüfsummen der Programmdateien (wie „php bin/netportal-cli.php manifest“)
    say "Prüfsummen der Programmdateien festhalten (manifest.sha256)"
    (
        cd "$INSTALL_DIR"
        { echo index.php
          find includes assets bin pam-helper system -type f \
              ! -name .htaccess ! -name '*.o' ! -name netportal-pam-check 2>/dev/null
        } | LC_ALL=C sort | while IFS= read -r f; do sha256sum "$f"; done
    ) > "$INSTALL_DIR/manifest.sha256"
    chown "$PHP_USER":"$(id -gn "$PHP_USER")" "$INSTALL_DIR/manifest.sha256"
    chmod 640 "$INSTALL_DIR/manifest.sha256"
}

# ------------------------------------------------------------------ PHP-FPM
write_fpm_pool() {
    say "Eigenen PHP-FPM-Pool anlegen ($FPM_SOCK)"
    cat > "$FPM_POOL" <<EOF
; Network Portal – erzeugt von install.sh
[netportal]
user = ${PHP_USER}
group = $(id -gn "$PHP_USER")
listen = ${FPM_SOCK}
listen.owner = caddy
listen.group = caddy
listen.mode = 0660
pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 16M
php_admin_value[memory_limit] = 256M
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
EOF
}

# ------------------------------------------------------------------ Caddy
tls_block() {
    case "$TLS_MODE" in
        auto)     [ -z "$ACME_EMAIL" ] || printf '\ttls %s\n' "$ACME_EMAIL" ;;
        internal) printf '\ttls internal\n' ;;
        files)    printf '\ttls %s %s\n' "$TLS_CERT" "$TLS_KEY" ;;
        acme)
            printf '\ttls%s {\n\t\tca %s\n' "${ACME_EMAIL:+ $ACME_EMAIL}" "$ACME_CA"
            [ -z "$ACME_CA_ROOT" ] || printf '\t\tca_root %s\n' "$ACME_CA_ROOT"
            printf '\t}\n' ;;
    esac
}

access_block() {
    [ -n "$ALLOW_FROM" ] || return 0
    printf '\t\t@extern not remote_ip %s\n\t\tabort @extern\n\n' "$ALLOW_FROM"
}

write_caddy_site() {
    say "Caddy-Konfiguration schreiben ($CADDY_SITE)"
    mkdir -p "$(dirname "$CADDY_SITE")"
    {
        echo "# Network Portal – erzeugt von install.sh"
        echo "https://${DOMAIN} {"
        tls_block
        printf '\troot * %s\n\tencode zstd gzip\n\n' "$INSTALL_DIR"
        printf '\trequest_body {\n\t\tmax_size 16MB\n\t}\n\n'
        printf '\troute {\n'
        access_block
        cat <<EOF
		@method not method GET HEAD POST
		respond @method 405

		@blocked path /config/* /database/* /includes/* /bin/* /pam-helper/* /server/* /system/* /icons/* /docs/* /demo/* /.cache/* /manifest.sha256 /README.md /CHANGELOG.md /LICENSE /router.php /install.sh /.*
		respond @blocked 404

		@otherphp {
			path *.php
			not path /index.php
		}
		respond @otherphp 404

		@assets path /assets/*
		header @assets Cache-Control "public, max-age=31536000, immutable"

		php_fastcgi unix/${FPM_SOCK}
		file_server {
			hide .*
		}
	}

	header {
		Strict-Transport-Security "max-age=31536000"
		-Server
		-X-Powered-By
	}
}
EOF
    } > "$CADDY_SITE"

    # Haupt-Caddyfile: Ordner caddy.d einbinden
    local imp="import ${CADDY_SITE%/*}/*.conf"
    if [ ! -f "$CADDY_MAIN" ]; then
        printf '%s\n' "$imp" > "$CADDY_MAIN"
    elif ! grep -qxF "$imp" "$CADDY_MAIN"; then
        if grep -q '/usr/share/caddy' "$CADDY_MAIN"; then
            # unveränderte Beispieldatei des Pakets ersetzen (Sicherung bleibt)
            cp "$CADDY_MAIN" "${CADDY_MAIN}.orig-$(date +%Y%m%d%H%M%S)"
            printf '# Caddyfile – Seiten liegen in %s\n%s\n' "${CADDY_SITE%/*}" "$imp" > "$CADDY_MAIN"
        else
            printf '\n%s\n' "$imp" >> "$CADDY_MAIN"
        fi
    fi

    say "Caddy-Konfiguration prüfen"
    local out
    if ! out="$(caddy validate --config "$CADDY_MAIN" --adapter caddyfile 2>&1)"; then
        printf '%s\n' "$out" | grep -v '"level":"info"' >&2
        die "caddy validate meldet einen Fehler – Datei: $CADDY_SITE"
    fi
    caddy fmt --overwrite "$CADDY_SITE" >/dev/null 2>&1 || true

    if [ "$TLS_MODE" = "files" ] && ! runuser -u caddy -- test -r "$TLS_KEY"; then
        warn "Caddy kann $TLS_KEY nicht lesen – z. B.: setfacl -m u:caddy:r \"$TLS_KEY\""
    fi
}

# ------------------------------------------------------------------ cron
write_cron() {
    [ "$SETUP_CRON" = "yes" ] || { rm -f "$CRON_FILE"; return 0; }
    say "Regelmäßige Prüfungen einrichten ($CRON_FILE)"
    local php="/usr/bin/php${PHP_VERSION}" cli="${INSTALL_DIR}/bin/netportal-cli.php"
    cat > "$CRON_FILE" <<EOF
# Network Portal – erzeugt von install.sh
*/30 * * * * ${PHP_USER} ${php} ${cli} check >/dev/null || logger -t netportal "Integritaet verletzt"
*/5  * * * * ${PHP_USER} ${php} ${cli} ping >/dev/null
*/15 * * * * ${PHP_USER} ${php} ${cli} services >/dev/null
*/5  * * * * ${PHP_USER} ${php} ${cli} wan:check >/dev/null || logger -t netportal "WAN-Anschluss ausgefallen"
EOF
    chmod 644 "$CRON_FILE"
}

# ------------------------------------------------------------------ Dienste
start_services() {
    say "Dienste starten"
    systemctl enable --now "php${PHP_VERSION}-fpm" caddy >/dev/null
    systemctl restart "php${PHP_VERSION}-fpm"
    systemctl reload caddy || systemctl restart caddy
}

admin_helper() {
    [ "$INSTALL_ADMIN_HELPER" = "yes" ] || return 0
    say 'root-Helfer für „System & Pakete“ einrichten'
    "/usr/bin/php${PHP_VERSION}" "${INSTALL_DIR}/system/install.php" "$PHP_USER"
}

# ------------------------------------------------------------------ Ablauf
main() {
    check_settings
    install_base
    install_php
    install_caddy
    install_optional
    id caddy >/dev/null 2>&1 || die "Benutzer caddy fehlt – ist Caddy richtig installiert?"
    copy_files
    set_permissions
    write_manifest
    write_fpm_pool
    write_caddy_site
    write_cron
    start_services
    admin_helper

    echo
    say "Fertig."
    # shellcheck disable=SC1111
    cat <<EOF
  Portal:      https://${DOMAIN}/
  Ordner:      ${INSTALL_DIR}
  Caddy:       ${CADDY_SITE}
  PHP-FPM:     ${FPM_POOL}

Nächste Schritte:
  1. https://${DOMAIN}/ öffnen – die Ersteinrichtung legt das Konto „Administrator“
     und den Integritätsschlüssel an.
  2. ${INSTALL_DIR}/config/ und ${INSTALL_DIR}/database/ regelmäßig sichern
     (integrity.key getrennt aufbewahren).
  3. Anmeldung über PAM/LDAP/YubiKey: im Portal unter Einstellungen – siehe README.
EOF
}

main "$@"
