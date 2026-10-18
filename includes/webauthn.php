<?php
/**
 * YubiKey per WebAuthn/FIDO2 als zweiter Faktor – für JEDE Anmeldeart
 * (lokal, PAM, LDAP/AD). Reines PHP (openssl; sodium für Ed25519-Keys).
 *
 * Funktioniert mit YubiKey 5/Bio/Security Key und anderen FIDO2-Keys. Der
 * Browser verlangt HTTPS (oder http://localhost).
 * Attestation wird bewusst nicht verlangt ("none"): Geprüft wird, dass
 * derselbe Key, der registriert wurde, die Anmeldung signiert.
 */

declare(strict_types=1);

final class Cbor
{
    public static function decode(string $data, int &$offset = 0): mixed
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException(t('CBOR: unerwartetes Ende'));
        }
        $ib = ord($data[$offset++]);
        $major = $ib >> 5;
        $info = $ib & 0x1F;
        if ($major === 7) {
            return match ($info) {
                20 => false, 21 => true, 22, 23 => null,
                25 => self::half(self::bytes($data, $offset, 2)),
                26 => unpack('G', self::bytes($data, $offset, 4))[1],
                27 => unpack('E', self::bytes($data, $offset, 8))[1],
                default => throw new RuntimeException(t('CBOR: nicht unterstützter Simple-Wert')),
            };
        }
        $val = self::length($data, $offset, $info);
        switch ($major) {
            case 0:
                return $val;
            case 1:
                return -1 - $val;
            case 2:
            case 3:
                return self::bytes($data, $offset, $val);
            case 4:
                $arr = [];
                for ($i = 0; $i < $val; $i++) {
                    $arr[] = self::decode($data, $offset);
                }
                return $arr;
            case 5:
                $map = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = self::decode($data, $offset);
                    $map[is_int($k) ? $k : (string)$k] = self::decode($data, $offset);
                }
                return $map;
            case 6:
                return self::decode($data, $offset);
        }
        throw new RuntimeException(t('CBOR: unbekannter Typ'));
    }

    private static function length(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        return match ($info) {
            24 => ord(self::bytes($data, $offset, 1)),
            25 => unpack('n', self::bytes($data, $offset, 2))[1],
            26 => unpack('N', self::bytes($data, $offset, 4))[1],
            27 => unpack('J', self::bytes($data, $offset, 8))[1],
            default => throw new RuntimeException(t('CBOR: unbestimmte Längen nicht unterstützt')),
        };
    }

    private static function bytes(string $data, int &$offset, int $n): string
    {
        if ($n < 0 || $offset + $n > strlen($data)) {
            throw new RuntimeException(t('CBOR: Länge außerhalb der Daten'));
        }
        $s = substr($data, $offset, $n);
        $offset += $n;
        return $s;
    }

    private static function half(string $b): float
    {
        $h = unpack('n', $b)[1];
        $exp = ($h >> 10) & 0x1F;
        $mant = $h & 0x3FF;
        $v = $exp === 0 ? $mant * 2 ** -24 : ($exp !== 31 ? ($mant + 1024) * 2 ** ($exp - 25) : ($mant === 0 ? INF : NAN));
        return ($h & 0x8000) ? -$v : $v;
    }
}

function webauthn_rp_id(): string
{
    $cfg = trim((string)setting('auth.webauthn.rp_id', ''));
    return $cfg !== '' ? $cfg : (string)parse_url(app_base_url(), PHP_URL_HOST);
}

function webauthn_origin(): string
{
    $p = parse_url(app_base_url());
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost') . (!empty($p['port']) ? ':' . $p['port'] : '');
}

function webauthn_user_keys(int $uid): array
{
    return user_keys($uid, 'webauthn_credentials');
}

function der_len(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $s = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($s)) . $s;
}

function der_tlv(int $tag, string $content): string
{
    return chr($tag) . der_len(strlen($content)) . $content;
}

function der_uint(string $bin): string
{
    $bin = ltrim($bin, "\0");
    if ($bin === '' || (ord($bin[0]) & 0x80)) {
        $bin = "\0" . $bin;
    }
    return der_tlv(0x02, $bin);
}

function pem_from_der(string $der): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** COSE-Schlüssel -> [alg, gespeicherte Form]. ES256, RS256, EdDSA. */
function webauthn_cose_to_key(array $cose): array
{
    $kty = $cose[1] ?? null;
    $alg = (int)($cose[3] ?? 0);
    if ($kty === 2 && $alg === -7) {
        $x = (string)($cose[-2] ?? '');
        $y = (string)($cose[-3] ?? '');
        if (($cose[-1] ?? null) !== 1 || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException(t('Ungültiger EC-Schlüssel'));
        }
        return [-7, pem_from_der(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y)];
    }
    if ($kty === 3 && $alg === -257) {
        $rsa = der_tlv(0x30, der_uint((string)($cose[-1] ?? '')) . der_uint((string)($cose[-2] ?? '')));
        $algId = der_tlv(0x30, hex2bin('06092a864886f70d010101') . "\x05\x00");
        return [-257, pem_from_der(der_tlv(0x30, $algId . der_tlv(0x03, "\x00" . $rsa)))];
    }
    if ($kty === 1 && $alg === -8) {
        $x = (string)($cose[-2] ?? '');
        if (strlen($x) !== 32) {
            throw new RuntimeException(t('Ungültiger Ed25519-Schlüssel'));
        }
        return [-8, 'ed25519:' . base64_encode($x)];
    }
    throw new RuntimeException(t('Schlüsseltyp nicht unterstützt (alg {a})', ['a' => $alg]));
}

function webauthn_verify_signature(int $alg, string $key, string $data, string $sig): bool
{
    if ($alg === -8) {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $raw = base64_decode(substr($key, 8), true);
        return $raw !== false && strlen($sig) === 64 && sodium_crypto_sign_verify_detached($sig, $data, $raw);
    }
    return openssl_verify($data, $sig, $key, OPENSSL_ALGO_SHA256) === 1;
}

function webauthn_check_client_data(string $json, string $type, string $challenge): array
{
    $cd = json_decode($json, true);
    if (!is_array($cd)) {
        throw new RuntimeException(t('clientDataJSON ungültig'));
    }
    if (($cd['type'] ?? '') !== $type) {
        throw new RuntimeException(t('Falscher Vorgangstyp'));
    }
    if (!hash_equals($challenge, (string)($cd['challenge'] ?? ''))) {
        throw new RuntimeException(t('Challenge stimmt nicht – bitte erneut versuchen'));
    }
    if (($cd['origin'] ?? '') !== webauthn_origin()) {
        throw new RuntimeException(t('Herkunft ({o}) passt nicht zu {e} – ggf. „Basis-URL“ in den Einstellungen setzen', ['o' => $cd['origin'] ?? '?', 'e' => webauthn_origin()]));
    }
    return $cd;
}

function webauthn_parse_auth_data(string $ad, bool $expectCredential): array
{
    if (strlen($ad) < 37) {
        throw new RuntimeException(t('authenticatorData zu kurz'));
    }
    $out = ['rpIdHash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'signCount' => unpack('N', substr($ad, 33, 4))[1]];
    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $out['rpIdHash'])) {
        throw new RuntimeException(t('rpId passt nicht ({r})', ['r' => webauthn_rp_id()]));
    }
    if (!($out['flags'] & 0x01)) {
        throw new RuntimeException(t('Key wurde nicht berührt (User Presence fehlt)'));
    }
    if ($expectCredential) {
        if (!($out['flags'] & 0x40) || strlen($ad) < 55) {
            throw new RuntimeException(t('Keine Schlüsseldaten in der Antwort'));
        }
        $idLen = unpack('n', substr($ad, 53, 2))[1];
        $out['credentialId'] = substr($ad, 55, $idLen);
        $off = 55 + $idLen;
        $cose = Cbor::decode($ad, $off);
        if (!is_array($cose)) {
            throw new RuntimeException(t('Öffentlicher Schlüssel unlesbar'));
        }
        $out['cose'] = $cose;
    }
    return $out;
}

function webauthn_registration_options(array $user): array
{
    $challenge = random_bytes(32);
    $_SESSION['wa_reg'] = ['challenge' => b64url_encode($challenge), 'ts' => time(), 'uid' => (int)$user['id']];
    return [
        'challenge' => b64url_encode($challenge),
        'rp' => ['name' => (string)setting('auth.webauthn.rp_name', 'Network Portal'), 'id' => webauthn_rp_id()],
        'user' => [
            'id' => b64url_encode(hash('sha256', 'netportal-user-' . $user['id'], true)),
            'name' => (string)$user['username'],
            'displayName' => (string)($user['display_name'] ?: $user['username']),
        ],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -8], ['type' => 'public-key', 'alg' => -257]],
        'timeout' => 60000,
        'attestation' => 'none',
        'excludeCredentials' => array_map(static fn($k) => ['type' => 'public-key', 'id' => $k['credential_id']], webauthn_user_keys((int)$user['id'])),
        'authenticatorSelection' => ['userVerification' => 'discouraged', 'residentKey' => 'discouraged'],
    ];
}

function webauthn_registration_finish(array $user, array $in): array
{
    $st = $_SESSION['wa_reg'] ?? null;
    unset($_SESSION['wa_reg']);
    if (!is_array($st) || (int)$st['uid'] !== (int)$user['id'] || time() - (int)$st['ts'] > 120) {
        throw new RuntimeException(t('Registrierung abgelaufen – bitte erneut starten'));
    }
    $clientJson = b64url_decode((string)($in['clientDataJSON'] ?? ''));
    webauthn_check_client_data($clientJson, 'webauthn.create', (string)$st['challenge']);
    $attObj = Cbor::decode(b64url_decode((string)($in['attestationObject'] ?? '')));
    if (!is_array($attObj) || !isset($attObj['authData'])) {
        throw new RuntimeException(t('attestationObject ungültig'));
    }
    $ad = webauthn_parse_auth_data((string)$attObj['authData'], true);
    [$alg, $key] = webauthn_cose_to_key($ad['cose']);
    $credId = b64url_encode($ad['credentialId']);
    if (array_filter(users_store()['webauthn_credentials'], static fn($k) => $k['credential_id'] === $credId) !== []) {
        throw new RuntimeException(t('Dieser Key ist bereits registriert'));
    }
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100) ?: 'YubiKey';
    $id = users_write(static fn(array &$st): int => users_put($st, 'webauthn_credentials', [
        'user_id' => (int)$user['id'], 'credential_id' => $credId, 'public_key' => $key, 'alg' => $alg,
        'sign_count' => (int)$ad['signCount'], 'name' => $name, 'created_at' => now_str(),
    ]));
    return ['id' => $id, 'name' => $name, 'alg' => $alg, 'credential' => substr($credId, 0, 16)];
}

function webauthn_login_options(array $user): array
{
    $challenge = random_bytes(32);
    $_SESSION['wa_login'] = ['challenge' => b64url_encode($challenge), 'ts' => time(), 'uid' => (int)$user['id']];
    return [
        'challenge' => b64url_encode($challenge),
        'rpId' => webauthn_rp_id(),
        'timeout' => 60000,
        'userVerification' => 'discouraged',
        'allowCredentials' => array_map(static fn($k) => ['type' => 'public-key', 'id' => $k['credential_id']], webauthn_user_keys((int)$user['id'])),
    ];
}

function webauthn_login_finish(array $user, array $in): array
{
    $st = $_SESSION['wa_login'] ?? null;
    unset($_SESSION['wa_login']);
    if (!is_array($st) || (int)$st['uid'] !== (int)$user['id'] || time() - (int)$st['ts'] > 120) {
        throw new RuntimeException(t('Anmeldung abgelaufen – bitte erneut versuchen'));
    }
    $cred = null;
    foreach (webauthn_user_keys((int)$user['id']) as $k) {
        if (hash_equals((string)$k['credential_id'], (string)($in['id'] ?? ''))) {
            $cred = $k;
        }
    }
    if ($cred === null) {
        throw new RuntimeException(t('Unbekannter Key'));
    }
    $clientJson = b64url_decode((string)($in['clientDataJSON'] ?? ''));
    webauthn_check_client_data($clientJson, 'webauthn.get', (string)$st['challenge']);
    $authData = b64url_decode((string)($in['authenticatorData'] ?? ''));
    $ad = webauthn_parse_auth_data($authData, false);
    if (!webauthn_verify_signature((int)$cred['alg'], (string)$cred['public_key'], $authData . hash('sha256', $clientJson, true), b64url_decode((string)($in['signature'] ?? '')))) {
        throw new RuntimeException(t('Signatur ungültig'));
    }
    $old = (int)$cred['sign_count'];
    $new = (int)$ad['signCount'];
    if (($old > 0 || $new > 0) && $new <= $old) {
        throw new RuntimeException(t('Signaturzähler zu niedrig – möglicherweise geklonter Key. Bitte Portal-Admin informieren.'));
    }
    try {
        users_write(static function (array &$st) use ($cred, $new): void {
            users_put($st, 'webauthn_credentials', ['sign_count' => $new, 'last_used' => now_str()] + $st['webauthn_credentials'][(int)$cred['id']]);
        });
    } catch (StoreWriteBlocked $e) {
        // Zähler kann nicht gespeichert werden (users.sql gesperrt) – Anmeldung trotzdem gültig
    }
    return $cred;
}

// --- Routen (JSON) ----------------------------------------------------------------------------

/** 2FA-Schritt beim Login: Optionen holen bzw. Antwort prüfen. */
function action_webauthn_login(string $a): never
{
    $pending = auth_pending_2fa();
    if ($pending === null) {
        json_out(['error' => t('Anmeldung abgelaufen – bitte neu anmelden.'), 'restart' => true], 401);
    }
    $user = $pending['user'];
    if (!is_post() || !csrf_valid((string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        json_out(['error' => t('Sitzung abgelaufen – Seite neu laden.')], 419);
    }
    if ($a === 'wa_login_options') {
        json_out(webauthn_login_options($user));
    }
    try {
        $cred = webauthn_login_finish($user, input());
    } catch (Throwable $e) {
        $_SESSION['pending_2fa']['tries'] = (int)($_SESSION['pending_2fa']['tries'] ?? 0) + 1;
        auth_register_failure(client_ip());
        audit('twofa_failure', ['methode' => 'webauthn', 'grund' => $e->getMessage()], (string)$user['username'], 'fail');
        json_out(['error' => $e->getMessage()], 400);
    }
    audit('twofa_success', ['methode' => 'webauthn', 'key' => $cred['name'], 'key_id' => (int)$cred['id']], (string)$user['username']);
    auth_complete_login($user, $pending['via'], ['factor' => 'webauthn', 'key' => $cred['name'], 'dn' => $pending['dn']]);
}
