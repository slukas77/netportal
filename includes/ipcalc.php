<?php
/**
 * IP-Rechner: CIDR (IPv4 und IPv6), Zugehörigkeit, Auslastung, freie Adressen.
 */

declare(strict_types=1);

function ip_valid(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function ip_is_v6(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

/** Sortierschlüssel: IPv4 vor IPv6, numerisch; leere Adressen ans Ende. */
function ip_sort_key(string $ip): string
{
    $bin = $ip !== '' ? @inet_pton($ip) : false;
    if ($bin === false) {
        return 'z';
    }
    return (strlen($bin) === 4 ? 'a' : 'b') . bin2hex($bin);
}

/** "10.0.20.0/24" -> ['net' => bin, 'prefix' => 24, 'bits' => 32, 'text' => normalisiert] oder null */
function cidr_parse(?string $cidr): ?array
{
    if ($cidr === null || !preg_match('#^\s*([0-9A-Fa-f:.]+)/(\d{1,3})\s*$#', $cidr, $m)) {
        return null;
    }
    $bin = @inet_pton($m[1]);
    if ($bin === false) {
        return null;
    }
    $bits = strlen($bin) * 8;
    $prefix = (int)$m[2];
    if ($prefix > $bits) {
        return null;
    }
    $mask = cidr_mask($prefix, strlen($bin));
    $net = $bin & $mask;
    return ['net' => $net, 'mask' => $mask, 'prefix' => $prefix, 'bits' => $bits, 'text' => inet_ntop($net) . '/' . $prefix, 'exact' => $net === $bin];
}

function cidr_mask(int $prefix, int $bytes): string
{
    $mask = str_repeat("\xff", intdiv($prefix, 8));
    if ($prefix % 8) {
        $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
    }
    return str_pad($mask, $bytes, "\0");
}

function ip_in_cidr(string $ip, ?string $cidr): bool
{
    $c = cidr_parse($cidr);
    $bin = $ip !== '' ? @inet_pton($ip) : false;
    if ($c === null || $bin === false || strlen($bin) * 8 !== $c['bits']) {
        return false;
    }
    return ($bin & $c['mask']) === $c['net'];
}

/** Kennzahlen eines Netzes (für die Anzeige). */
function cidr_info(?string $cidr): ?array
{
    $c = cidr_parse($cidr);
    if ($c === null) {
        return null;
    }
    $hostBits = $c['bits'] - $c['prefix'];
    if ($c['bits'] === 32) {
        $start = (int)unpack('N', $c['net'])[1];
        $size = 2 ** $hostBits;
        $end = $start + $size - 1;
        $usable = $hostBits >= 2 ? $size - 2 : $size;
        $first = $hostBits >= 2 ? $start + 1 : $start;
        $last = $hostBits >= 2 ? $end - 1 : $end;
        return [
            'v6' => false, 'text' => $c['text'], 'prefix' => $c['prefix'],
            'netmask' => long2ip((int)unpack('N', $c['mask'])[1]),
            'network' => long2ip($start), 'broadcast' => $hostBits >= 2 ? long2ip($end) : null,
            'first' => long2ip($first), 'last' => long2ip($last), 'usable' => $usable,
        ];
    }
    return [
        'v6' => true, 'text' => $c['text'], 'prefix' => $c['prefix'], 'netmask' => '/' . $c['prefix'],
        'network' => inet_ntop($c['net']), 'broadcast' => null, 'first' => inet_ntop($c['net']), 'last' => inet_ntop($c['net'] | ~$c['mask']),
        'usable' => $hostBits >= 63 ? INF : 2 ** $hostBits,
    ];
}

/** Erste freie IPv4-Adresse im Netz (Gateway und belegte übersprungen). */
function cidr_next_free(?string $cidr, array $used, ?string $gateway = null): ?string
{
    $i = cidr_info($cidr);
    if ($i === null || $i['v6']) {
        return null;
    }
    $taken = array_flip(array_filter([...$used, $gateway]));
    $a = ip2long($i['first']);
    $b = ip2long($i['last']);
    for ($x = $a; $x <= $b && $x - $a < 65536; $x++) {
        $ip = long2ip($x);
        if (!isset($taken[$ip])) {
            return $ip;
        }
    }
    return null;
}

function mac_normalize(string $mac): ?string
{
    $hex = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
    if (strlen($hex) !== 12) {
        return null;
    }
    return implode(':', str_split($hex, 2));
}

function hostname_valid(string $h): bool
{
    return strlen($h) <= 253 && (bool)preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?)*\.?$/', $h);
}
