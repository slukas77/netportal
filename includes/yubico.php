<?php
/**
 * YubiKey per Yubico-OTP (ohne PAM) – zweiter Faktor für jede Anmeldeart.
 *
 * Der Key "tippt" beim Berühren ein Einmalpasswort (ModHex, 44 Zeichen). Die
 * ersten 12 Zeichen sind die feste Key-ID (public id); sie wird je Benutzer
 * registriert. Geprüft wird beim YubiCloud-Dienst (api.yubico.com) oder einem
 * eigenen Validierungsserver (yubikey-val) mit signierter Anfrage (HMAC-SHA1)
 * und signierter Antwort; Nonce und OTP müssen in der Antwort übereinstimmen.
 */

declare(strict_types=1);

function yubico_otp_valid_format(string $otp): bool
{
    return (bool)preg_match('/^[cbdefghijklnrtuv]{32,48}$/', $otp);
}

function yubico_public_id(string $otp): string
{
    return substr($otp, 0, strlen($otp) - 32);
}

function yubico_sign(array $params, string $secretB64): string
{
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return base64_encode(hash_hmac('sha1', implode('&', $parts), (string)base64_decode($secretB64, true), true));
}

/** Prüft ein OTP beim Validierungsserver. ['ok', 'error', 'public_id'] */
function yubico_otp_verify(string $otp): array
{
    $otp = strtolower(trim($otp));
    if (!yubico_otp_valid_format($otp)) {
        return ['ok' => false, 'error' => t('Kein gültiges YubiKey-OTP (Key berühren, während das Feld aktiv ist).')];
    }
    $pid = yubico_public_id($otp);
    $id = trim((string)setting('auth.yubico_otp.client_id', ''));
    $secret = trim((string)setting('auth.yubico_otp.secret_key', ''));
    $api = trim((string)setting('auth.yubico_otp.api_url', 'https://api.yubico.com/wsapi/2.0/verify'));
    if ($id === '' || $secret === '' || !preg_match('#^https://#', $api)) {
        // ohne Secret-Key ließe sich die Antwort nicht prüfen – dann lieber gar nicht
        return ['ok' => false, 'error' => t('Yubico-OTP ist nicht vollständig eingerichtet (Client-ID, Secret-Key, HTTPS-URL).'), 'public_id' => $pid];
    }
    $nonce = bin2hex(random_bytes(16));
    $params = ['id' => $id, 'nonce' => $nonce, 'otp' => $otp, 'timestamp' => '1'];
    $params['h'] = yubico_sign($params, $secret);
    $raw = http_fetch($api . '?' . http_build_query($params), 6);
    if ($raw === false) {
        return ['ok' => false, 'error' => t('Validierungsserver nicht erreichbar.'), 'public_id' => $pid];
    }
    $resp = [];
    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $resp[trim($k)] = trim($v);
        }
    }
    $h = $resp['h'] ?? '';
    $check = $resp;
    unset($check['h']);
    if ($h === '' || !hash_equals(yubico_sign($check, $secret), $h)) {
        return ['ok' => false, 'error' => t('Antwort des Validierungsservers nicht korrekt signiert.'), 'public_id' => $pid];
    }
    if (($resp['otp'] ?? '') !== $otp || ($resp['nonce'] ?? '') !== $nonce) {
        return ['ok' => false, 'error' => t('Antwort passt nicht zur Anfrage.'), 'public_id' => $pid];
    }
    $status = $resp['status'] ?? 'UNKNOWN';
    if ($status !== 'OK') {
        $msg = match ($status) {
            'REPLAYED_OTP' => t('Dieses OTP wurde bereits benutzt.'),
            'BAD_OTP' => t('OTP ungültig.'),
            'BAD_SIGNATURE' => t('Signatur abgelehnt (Secret-Key prüfen).'),
            'NO_SUCH_CLIENT' => t('Client-ID unbekannt.'),
            default => t('Validierung abgelehnt: {s}', ['s' => $status]),
        };
        return ['ok' => false, 'error' => $msg, 'public_id' => $pid];
    }
    return ['ok' => true, 'error' => '', 'public_id' => $pid];
}

/** OTP prüfen UND der Key muss dem Benutzer gehören. */
function yubico_otp_verify_for_user(int $uid, string $otp): array
{
    $otp = strtolower(trim($otp));
    if (!setting('auth.yubico_otp.enabled')) {
        return ['ok' => false, 'error' => t('Yubico-OTP ist deaktiviert.')];
    }
    if (!yubico_otp_valid_format($otp)) {
        return ['ok' => false, 'error' => t('Kein gültiges YubiKey-OTP.')];
    }
    $pid = yubico_public_id($otp);
    $key = null;
    foreach (user_keys($uid, 'yubico_otp_keys') as $k) {
        if ($k['public_id'] === $pid) {
            $key = $k;
        }
    }
    if ($key === null) {
        return ['ok' => false, 'error' => t('Dieser YubiKey ist für das Konto nicht registriert.'), 'public_id' => $pid];
    }
    $r = yubico_otp_verify($otp);
    if ($r['ok']) {
        try {
            users_write(static function (array &$st) use ($key): void {
                users_put($st, 'yubico_otp_keys', ['last_used' => now_str()] + $st['yubico_otp_keys'][(int)$key['id']]);
            });
        } catch (StoreWriteBlocked $e) {
        }
    }
    return $r;
}

/** Ist der Validierungsserver erreichbar? (10 Minuten zwischengespeichert; null = nicht eingeschaltet) */
function yubico_reachable(): ?bool
{
    if (!setting('auth.yubico_otp.enabled')) {
        return null;
    }
    $api = trim((string)setting('auth.yubico_otp.api_url', 'https://api.yubico.com/wsapi/2.0/verify'));
    $f = CACHE_DIR . '/yubico-reach.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($c) && ($c['api'] ?? '') === $api && (int)($c['ts'] ?? 0) > time() - 600) {
        return (bool)$c['ok'];
    }
    $ok = preg_match('#^https://#', $api) === 1 && http_reachable($api, 4);
    @file_put_contents($f, (string)json_encode(['api' => $api, 'ts' => time(), 'ok' => $ok]), LOCK_EX);
    return $ok;
}
