/*
 * netportal-pam-check — kleiner, dediziert-privilegierter PAM-Auth-Helfer
 * für das Network Portal.
 *
 * Prüft Benutzername + Passwort (+ optional YubiKey-OTP) gegen einen von ZWEI
 * fest einkompilierten PAM-Diensten und meldet nur das Ergebnis per Exit-Code:
 *
 *   Dienst-Schlüssel "std"     -> PAM-Dienst "netportal"          (/etc/pam.d/netportal)
 *   Dienst-Schlüssel "yubikey" -> PAM-Dienst "netportal-yubikey"  (/etc/pam.d/netportal-yubikey)
 *
 * Die Webanwendung kann damit keinen anderen PAM-Dienst (sshd, sudo …) ansprechen.
 *
 * Aufrufprotokoll – ausschließlich stdin (nichts davon steht in `ps`):
 *   Zeile 1: Dienst-Schlüssel ("std" | "yubikey")
 *   Zeile 2: Benutzername
 *   Zeile 3: YubiKey-OTP (darf leer sein)
 *   Rest   : Passwort (abschließendes \n bzw. \r\n wird entfernt)
 *
 * Conversation:
 *   - Abfragen, die nach YubiKey/OTP/Token/U2F klingen, bekommen das OTP
 *     (pam_yubico fragt z. B. "YubiKey for `user':").
 *   - Alle anderen Abfragen bekommen das Passwort.
 *   - Info-Meldungen (pam_u2f "Please touch the device") werden ignoriert.
 *
 * Exit-Codes: 0 ok · 1 falsche Zugangsdaten · 2 Konto abgelehnt · 3 Eingabe ungültig · 4 interner Fehler
 */

#define _GNU_SOURCE
#include <security/pam_appl.h>
#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <unistd.h>
#include <sys/resource.h>
#include <sys/prctl.h>

#define SERVICE_STD     "netportal"
#define SERVICE_YUBIKEY "netportal-yubikey"
#define MAX_USERNAME_LEN 128
#define MAX_PASSWORD_LEN 512
#define MAX_OTP_LEN      128
#define MAX_KEY_LEN      16

extern char **environ;

static char g_password[MAX_PASSWORD_LEN + 1];
static char g_otp[MAX_OTP_LEN + 1];

static void wipe(void *p, size_t n)
{
    if (p) {
        explicit_bzero(p, n);
    }
}

static int looks_like_otp_prompt(const char *msg)
{
    static const char *needles[] = { "yubikey", "otp", "token", "u2f", "one-time", "einmal", "security key", NULL };
    if (!msg) {
        return 0;
    }
    for (int i = 0; needles[i]; i++) {
        if (strcasestr(msg, needles[i])) {
            return 1;
        }
    }
    return 0;
}

static int conv_fn(int num_msg, const struct pam_message **msg, struct pam_response **resp, void *appdata_ptr)
{
    (void)appdata_ptr;
    if (num_msg <= 0 || num_msg > 32) {
        return PAM_CONV_ERR;
    }
    struct pam_response *out = calloc((size_t)num_msg, sizeof(*out));
    if (!out) {
        return PAM_BUF_ERR;
    }
    for (int i = 0; i < num_msg; i++) {
        out[i].resp = NULL;
        out[i].resp_retcode = 0;
        if (msg[i]->msg_style == PAM_PROMPT_ECHO_OFF || msg[i]->msg_style == PAM_PROMPT_ECHO_ON) {
            const char *answer = g_password;
            if (g_otp[0] != '\0' && looks_like_otp_prompt(msg[i]->msg)) {
                answer = g_otp;
            }
            out[i].resp = strdup(answer);
            if (!out[i].resp) {
                for (int j = 0; j < i; j++) {
                    if (out[j].resp) {
                        wipe(out[j].resp, strlen(out[j].resp));
                        free(out[j].resp);
                    }
                }
                free(out);
                return PAM_BUF_ERR;
            }
        }
    }
    *resp = out;
    return PAM_SUCCESS;
}

static int read_line(char *buf, size_t cap, int allow_empty)
{
    size_t len = 0;
    int c;
    while ((c = getchar()) != EOF && c != '\n') {
        if (c == '\0' || len + 1 >= cap) {
            return -1;
        }
        buf[len++] = (char)c;
    }
    if (c == EOF) {
        return -1;
    }
    if (len > 0 && buf[len - 1] == '\r') {
        len--;
    }
    buf[len] = '\0';
    return (len == 0 && !allow_empty) ? -1 : 0;
}

static int read_password(void)
{
    size_t plen = 0;
    int c;
    while ((c = getchar()) != EOF) {
        if (c == '\0' || plen + 1 >= sizeof(g_password)) {
            return -1;
        }
        g_password[plen++] = (char)c;
    }
    if (plen > 0 && g_password[plen - 1] == '\n') plen--;
    if (plen > 0 && g_password[plen - 1] == '\r') plen--;
    g_password[plen] = '\0';
    return plen == 0 ? -1 : 0;
}

/* Buchstabe/_ am Anfang, danach alnum . _ - @ (user@domain bei SSSD/AD) */
static int username_looks_sane(const char *u)
{
    size_t len = strlen(u);
    if (len == 0 || len >= MAX_USERNAME_LEN || !(isalpha((unsigned char)u[0]) || u[0] == '_')) {
        return 0;
    }
    for (size_t i = 0; i < len; i++) {
        unsigned char c = (unsigned char)u[i];
        if (!(isalnum(c) || c == '_' || c == '-' || c == '.' || c == '@')) {
            return 0;
        }
    }
    return 1;
}

static int otp_looks_sane(const char *o)
{
    for (size_t i = 0; o[i]; i++) {
        if (!isgraph((unsigned char)o[i])) {
            return 0;
        }
    }
    return 1;
}

int main(void)
{
    static char *safe_environ[] = { "PATH=/usr/sbin:/usr/bin:/sbin:/bin", NULL };
    environ = safe_environ;
    prctl(PR_SET_DUMPABLE, 0);
    struct rlimit rl = { 0, 0 };
    setrlimit(RLIMIT_CORE, &rl);

    if (geteuid() != 0) {
        fprintf(stderr, "netportal-pam-check: nicht setuid-root installiert\n");
        return 4;
    }

    char key[MAX_KEY_LEN];
    char username[MAX_USERNAME_LEN];
    int rc_in = read_line(key, sizeof(key), 0);
    if (rc_in == 0) rc_in = read_line(username, sizeof(username), 0);
    if (rc_in == 0) rc_in = read_line(g_otp, sizeof(g_otp), 1);
    if (rc_in == 0) rc_in = read_password();
    if (rc_in != 0 || !username_looks_sane(username) || !otp_looks_sane(g_otp)) {
        wipe(g_password, sizeof(g_password));
        wipe(g_otp, sizeof(g_otp));
        return 3;
    }

    const char *service;
    if (strcmp(key, "std") == 0) {
        service = SERVICE_STD;
    } else if (strcmp(key, "yubikey") == 0) {
        service = SERVICE_YUBIKEY;
    } else {
        wipe(g_password, sizeof(g_password));
        wipe(g_otp, sizeof(g_otp));
        return 3;
    }

    struct pam_conv conv = { conv_fn, NULL };
    pam_handle_t *pamh = NULL;
    int rc = pam_start(service, username, &conv, &pamh);
    if (rc != PAM_SUCCESS || pamh == NULL) {
        wipe(g_password, sizeof(g_password));
        wipe(g_otp, sizeof(g_otp));
        return 4;
    }
    int result;
    rc = pam_authenticate(pamh, PAM_DISALLOW_NULL_AUTHTOK);
    if (rc != PAM_SUCCESS) {
        result = 1;
    } else {
        rc = pam_acct_mgmt(pamh, PAM_DISALLOW_NULL_AUTHTOK);
        result = (rc == PAM_SUCCESS) ? 0 : 2;
    }
    pam_end(pamh, rc);
    wipe(g_password, sizeof(g_password));
    wipe(g_otp, sizeof(g_otp));
    wipe(username, sizeof(username));
    return result;
}
