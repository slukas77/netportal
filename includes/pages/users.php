<?php
/**
 * Benutzerverwaltung (nur Portal-Admins) – Konten stehen in database/users.sql –
 * und "Mein Konto" (Passwort, YubiKeys).
 */

declare(strict_types=1);

function user_or_404(int $id): array
{
    $u = user_get($id);
    if ($u === null) {
        throw new NetNotFound(t('Benutzer nicht gefunden.'));
    }
    return $u;
}

function user_ctx(array $u): array
{
    return ['type' => 'user', 'id' => $u['id'], 'label' => $u['username']];
}

const ROLE_HINTS = [
    'leser' => 'nur lesen: ansehen und exportieren – kein Ping, kein iperf3, kein SSH, nichts anlegen, ändern oder löschen',
    'poweruser' => 'wie Leser, zusätzlich Werkzeuge: Dienste prüfen/erkennen, iperf3, SSH (Freigabeliste)',
    'admin' => 'alles: anlegen, bearbeiten, löschen, Einstellungen, Audit-Log, Benutzer, Integrität, System',
];

function page_users(): void
{
    $users = users_list();
    $st = users_store();
    page_start(t('Benutzer'), 'users', [[t('Benutzer'), null]]);
    heading(t('Benutzer'), th('{n} Konten · gespeichert in database/users.sql', ['n' => count($users)]), btn(t('Neuer Benutzer'), url(['p' => 'user_edit']), 'primary', 'plus'));
    [$ok] = sqlfile_seal_ok(USERS_SEAL, $st['sha256'], $st['parse']['body_hmac_ok']);
    if (!$ok) {
        echo '<div class="flash flash-error">' . th('users.sql wurde am Portal vorbei verändert – Änderungen sind gesperrt, bis die Datei unter „Integrität“ geprüft und neu versiegelt ist.') . '</div>';
    }
    echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>' . th('Benutzer') . '</th><th>' . th('Anmeldung') . '</th><th>' . th('Rolle') . '</th><th>YubiKey</th><th>' . th('Status') . '</th><th>' . th('Letzte Anmeldung') . '</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $href = url(['p' => 'user_edit', 'id' => $u['id']]);
        $super = auth_is_superadmin((string)$u['username'], (string)$u['auth_source']);
        $keys = count(user_keys((int)$u['id'], 'webauthn_credentials')) + count(user_keys((int)$u['id'], 'yubico_otp_keys'));
        $role = $u['role'] === 'operator' ? 'poweruser' : (string)$u['role'];
        echo '<tr data-href="' . h($href) . '"><td><div class="cell-title"><a href="' . h($href) . '">' . h($u['username']) . '</a>' . (user_row_ok($u) ? '' : ' <span class="badge bad">' . th('Eintrag verändert') . '</span>') . '</div><div class="cell-sub">' . h($u['display_name'] ?? '') . ($u['email'] ? ' · ' . h($u['email']) : '') . '</div></td>'
            . '<td>' . h(source_label((string)$u['auth_source'])) . '</td>'
            . '<td><span class="badge role-' . h($super ? 'admin' : $role) . '">' . h(role_label($super ? 'admin' : $role)) . '</span>' . ($super && $role !== 'admin' ? ' <span class="dim small">(' . th('fest') . ')</span>' : '') . '</td>'
            . '<td>' . ($keys > 0 ? '<span class="badge ok-soft">' . icon('key', 12) . $keys . '</span>' : ((int)$u['require_2fa'] ? '<span class="badge warn">' . th('Pflicht, fehlt') . '</span>' : '<span class="dim">–</span>')) . '</td>'
            . '<td>' . ((int)$u['active'] === 1 ? '<span class="badge ok-soft">' . th('aktiv') . '</span>' : '<span class="badge bad">' . th('gesperrt') . '</span>') . '</td>'
            . '<td class="small">' . h(fmt_datetime($u['last_login'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="card spaced-top"><h2 class="section-label">' . th('Rollen') . '</h2><dl class="kv">';
    foreach (ROLES as $r => $label) {
        echo '<dt>' . h(t($label)) . '</dt><dd>' . th(ROLE_HINTS[$r]) . '</dd>';
    }
    echo '</dl><p class="hint">' . th('„Administrator“, der per PAM angemeldete „root“ sowie administrator@… und root@… sind immer Portal-Admin. PAM- und LDAP-Benutzer werden beim ersten Login automatisch in users.sql angelegt, wenn das in den Einstellungen erlaubt ist.') . '</p></div>';
    page_end();
}

function page_user_edit(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $u = $id > 0 ? user_or_404($id) : ['id' => 0, 'username' => '', 'display_name' => '', 'email' => '', 'auth_source' => 'local', 'role' => 'leser', 'active' => 1, 'require_2fa' => 0, 'must_change_pw' => 1, 'created_at' => null, 'created_by' => null];
    if ($u['role'] === 'operator') {
        $u['role'] = 'poweruser';
    }
    page_start($id > 0 ? (string)$u['username'] : t('Neuer Benutzer'), 'users', [[t('Benutzer'), url(['p' => 'users'])], [$id > 0 ? (string)$u['username'] : t('Neu'), null]]);
    heading($id > 0 ? t('Benutzer {u}', ['u' => $u['username']]) : t('Neuer Benutzer'), $id > 0 ? th('angelegt {d} von {u}', ['d' => fmt_datetime($u['created_at']), 'u' => (string)$u['created_by']]) : '');
    $roles = array_map('t', ROLES);
    $sources = array_map('t', AUTH_SOURCES);
    echo '<form class="form-layout" method="post" action="' . h(url(['a' => 'user_save'])) . '" autocomplete="off">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">';
    echo '<section class="card"><div class="form-grid cols-4">'
        . f_text('username', t('Benutzername') . ' *', $u['username'], ['required' => true, 'maxlength' => 128, 'readonly' => $id > 0, 'hint' => $id > 0 ? th('nicht änderbar') : th('bei LDAP/AD: sAMAccountName, bei PAM: Systemname')])
        . f_text('display_name', t('Anzeigename'), $u['display_name'], ['maxlength' => 120])
        . f_text('email', t('E-Mail'), $u['email'], ['type' => 'email', 'maxlength' => 190])
        . f_select('auth_source', t('Anmeldeart'), $sources, $u['auth_source'])
        . f_select('role', t('Rolle'), $roles, $u['role'], ['hint' => th('Leser: nur lesen · Power-User: zusätzlich Werkzeuge · Portal-Admin: alles')])
        . f_text('password', $id > 0 ? t('Neues Passwort (nur lokal)') : t('Passwort (nur lokal)'), '', ['type' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 512, 'hint' => th('leer = unverändert; mind. 12 Zeichen')])
        . '<div class="field span2 check-field">' . f_check('active', t('Konto aktiv'), (int)$u['active'] === 1) . f_check('require_2fa', t('YubiKey Pflicht (2. Faktor)'), (int)$u['require_2fa'] === 1)
        . f_check('must_change_pw', t('Passwort beim nächsten Login ändern'), (int)$u['must_change_pw'] === 1) . '</div>'
        . '</div></section>';
    echo '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern') . '</button>' . btn(t('Abbrechen'), url(['p' => 'users']), 'secondary') . '</div></form>';
    if ($id > 0) {
        echo '<h2 class="section-label spaced">YubiKeys</h2>' . keys_list($u, 'user_key_delete');
        if ((int)$u['id'] !== (int)auth_user()['id']) {
            echo '<div class="action-row spaced">' . post_button(t('Benutzer löschen'), ['a' => 'user_delete'], 'danger', t('Benutzer „{u}“ endgültig löschen?', ['u' => $u['username']]), 'trash', ['id' => $u['id']]) . '</div>';
        }
    }
    page_end();
}

function keys_list(array $u, string $deleteAction): string
{
    $wa = user_keys((int)$u['id'], 'webauthn_credentials', false);
    $otp = user_keys((int)$u['id'], 'yubico_otp_keys', false);
    if ($wa === [] && $otp === []) {
        return '<p class="dim">' . th('Keine YubiKeys registriert.') . '</p>';
    }
    $o = '<div class="track-list compact">';
    foreach ([['webauthn', $wa, 'webauthn_credentials'], ['otp', $otp, 'yubico_otp_keys']] as [$type, $list, $table]) {
        foreach ($list as $k) {
            $valid = user_row_ok($k, $table);
            $o .= '<div class="track-list-row"><div class="tl-cover">' . icon('key', 16) . '</div><div class="tl-info"><div class="tl-title">' . h($k['name'])
                . ' <span class="badge dim">' . ($type === 'otp' ? 'Yubico-OTP · <span class="mono">' . h($k['public_id']) . '</span>' : 'FIDO2/WebAuthn') . '</span>'
                . ($valid ? '' : ' <span class="badge bad">' . th('ungültig (verändert)') . '</span>') . '</div>'
                . '<div class="tl-artist">' . th('registriert {a} · zuletzt {b}', ['a' => fmt_datetime($k['created_at']), 'b' => fmt_datetime($k['last_used'])]) . ($type === 'webauthn' ? ' · ' . th('Zähler') . ' ' . (int)$k['sign_count'] : '') . '</div></div>'
                . '<div class="tl-actions">' . post_button(t('Entfernen'), ['a' => $deleteAction], 'danger small', t('Key „{k}“ entfernen?', ['k' => $k['name']]), '', ['id' => $u['id'], 'type' => $type, 'kid' => $k['id']]) . '</div></div>';
        }
    }
    return $o . '</div>';
}

function action_user_save(): void
{
    $id = in_int('id');
    $old = $id > 0 ? user_or_404($id) : null;
    $me = auth_user();
    $row = [
        'display_name' => in_str('display_name', 120) ?: null,
        'email' => in_str('email', 190) ?: null,
        'auth_source' => isset(AUTH_SOURCES[in_str('auth_source', 20)]) ? in_str('auth_source', 20) : 'local',
        'role' => isset(ROLES[in_str('role', 20)]) ? in_str('role', 20) : 'leser',
        'active' => in_bool('active') ? 1 : 0,
        'require_2fa' => in_bool('require_2fa') ? 1 : 0,
        'must_change_pw' => in_bool('must_change_pw') ? 1 : 0,
        'updated_at' => now_str(),
        'updated_by' => (string)$me['username'],
    ];
    $pw = (string)(input()['password'] ?? '');
    $err = [];
    if ($old === null) {
        $row['username'] = in_str('username', 128);
        if (!auth_valid_username($row['username'])) {
            $err[] = t('Benutzername ungültig (Buchstaben, Ziffern, . _ - @).');
        } elseif (user_by_name($row['username']) !== null) {
            $err[] = t('Benutzername existiert bereits.');
        }
    }
    if ($row['email'] !== null && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = t('E-Mail-Adresse ungültig.');
    }
    if ($row['auth_source'] === 'local' && $pw === '' && ($old === null || ($old['password_hash'] ?? '') === '')) {
        $err[] = t('Für ein lokales Konto ist ein Passwort nötig.');
    }
    if ($pw !== '' && ($e = password_policy_error($pw)) !== null) {
        $err[] = $e;
    }
    if ($old !== null && (int)$old['id'] === (int)$me['id'] && ($row['active'] === 0 || ($old['role'] === 'admin' && $row['role'] !== 'admin' && !$me['super']))) {
        $err[] = t('Du kannst dich nicht selbst sperren oder dir die Rolle Portal-Admin entziehen.');
    }
    if ($old !== null && $old['role'] === 'admin' && ($row['role'] !== 'admin' || $row['active'] === 0)
        && count(array_filter(users_list(), static fn($x) => $x['role'] === 'admin' && (int)$x['active'] === 1 && (int)$x['id'] !== $id)) === 0) {
        $err[] = t('Es muss mindestens ein aktiver Portal-Admin bleiben.');
    }
    if ($err !== []) {
        foreach ($err as $e) {
            flash($e, 'error');
        }
        redirect(url(['p' => 'user_edit', 'id' => $id ?: null]));
    }
    if ($pw !== '') {
        $row['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    }
    if ($old === null) {
        $row['created_at'] = $row['updated_at'];
        $row['created_by'] = $row['updated_by'];
        $id = user_insert($row);
        $u = user_or_404($id);
        audit('user_create', ['daten' => array_diff_key($row, ['password_hash' => 1]), 'passwort_gesetzt' => $pw !== '', 'datei' => 'users.sql'], null, 'ok', user_ctx($u));
        flash(t('Benutzer „{u}“ angelegt.', ['u' => $u['username']]));
    } else {
        user_update($id, $row);
        $diff = audit_diff($old, array_diff_key($row, ['password_hash' => 1]));
        if ($pw !== '') {
            $diff['passwort'] = ['***', 'neu gesetzt'];
        }
        audit('user_update', ['aenderungen' => $diff], null, 'ok', user_ctx($old));
        flash(t('Benutzer gespeichert.'));
    }
    redirect(url(['p' => 'user_edit', 'id' => $id]));
}

function action_user_delete(): void
{
    $u = user_or_404(in_int('id'));
    if ((int)$u['id'] === (int)auth_user()['id']) {
        flash(t('Du kannst dich nicht selbst löschen.'), 'error');
        redirect(url(['p' => 'users']));
    }
    if ($u['role'] === 'admin' && count(array_filter(users_list(), static fn($x) => $x['role'] === 'admin' && (int)$x['active'] === 1 && (int)$x['id'] !== (int)$u['id'])) === 0) {
        flash(t('Der letzte aktive Portal-Admin kann nicht gelöscht werden.'), 'error');
        redirect(url(['p' => 'users']));
    }
    user_delete((int)$u['id']);
    audit('user_delete', ['datensatz' => array_diff_key($u, ['password_hash' => 1])], null, 'ok', user_ctx($u));
    flash(t('Benutzer „{u}“ gelöscht.', ['u' => $u['username']]));
    redirect(url(['p' => 'users']));
}

function key_delete_for(array $u): void
{
    $type = in_str('type', 10);
    $kid = in_int('kid');
    $table = $type === 'otp' ? 'yubico_otp_keys' : 'webauthn_credentials';
    $k = users_store()[$table][$kid] ?? null;
    if ($k === null || (int)$k['user_id'] !== (int)$u['id']) {
        flash(t('Key nicht gefunden.'), 'error');
        return;
    }
    users_write(static function (array &$st) use ($table, $kid): void {
        unset($st[$table][$kid]);
    });
    audit($type === 'otp' ? 'otp_delete' : 'key_delete', ['key' => $k['name'], 'key_id' => $kid, 'public_id' => $k['public_id'] ?? null], null, 'ok', user_ctx($u));
    flash(t('Key „{k}“ entfernt.', ['k' => $k['name']]));
}

function action_user_key_delete(): void
{
    $u = user_or_404(in_int('id'));
    key_delete_for($u);
    redirect(url(['p' => 'user_edit', 'id' => $u['id']]));
}

// --- Mein Konto --------------------------------------------------------------------------------

function page_account(): void
{
    $u = auth_user();
    $f = auth_user_factors((int)$u['id']);
    page_start(t('Mein Konto'), 'account', [[t('Mein Konto'), null]]);
    heading(t('Mein Konto'), h($u['username']) . ' · ' . h(source_label((string)$u['login_via'])) . ' · ' . h(role_label(auth_role())));
    if (!empty($_SESSION['must_enroll'])) {
        echo '<div class="flash flash-warn">' . th('Für dein Konto ist ein YubiKey Pflicht. Erst nach der Registrierung sind die anderen Seiten zugänglich.') . '</div>';
    }
    echo '<div class="grid-2">';
    echo '<section class="card"><h2 class="section-label">' . th('Passwort') . '</h2>';
    if ($u['auth_source'] === 'local') {
        echo '<form method="post" action="' . h(url(['a' => 'account_save'])) . '" autocomplete="off">' . csrf_field() . '<div class="form-grid">'
            . f_text('current', t('Aktuelles Passwort'), '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
            . f_text('new_password', t('Neues Passwort'), '', ['type' => 'password', 'required' => true, 'minlength' => 12, 'autocomplete' => 'new-password'])
            . f_text('new_password2', t('Wiederholen'), '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
            . '</div><div class="form-actions"><button class="pill-btn primary" type="submit">' . th('Passwort ändern') . '</button></div></form>';
    } else {
        echo '<p class="dim">' . th('Dein Passwort wird über {s} verwaltet.', ['s' => source_label((string)$u['auth_source'])]) . '</p>';
    }
    echo '<h2 class="section-label spaced">' . th('Sprache') . '</h2><p>' . lang_switch() . ' <span class="dim small">' . th('Vorwahl kommt aus der Browser-Sprache.') . '</span></p>';
    echo '</section>';

    echo '<section class="card"><h2 class="section-label">' . th('YubiKey per FIDO2 / WebAuthn') . '</h2>'
        . '<p class="hint">' . th('Key einstecken, Namen vergeben, „Registrieren“ und Key berühren. Danach wird er bei jeder Anmeldung abgefragt. Funktioniert nur über HTTPS (oder localhost).') . '</p>'
        . '<div class="wa-register" id="waRegister" data-options="' . h(url(['a' => 'wa_reg_options'])) . '" data-finish="' . h(url(['a' => 'wa_reg_finish'])) . '">'
        . '<input type="text" id="waName" maxlength="100" placeholder="' . th('z. B. YubiKey 5C blau') . '" aria-label="' . th('Name des Keys') . '">'
        . '<button type="button" class="pill-btn primary" id="waRegBtn">' . icon('key', 15) . th('Registrieren') . '</button></div>'
        . '<p class="login-error" id="waRegError" hidden></p></section>';
    echo '</div>';

    if (setting('auth.yubico_otp.enabled')) {
        echo '<section class="card"><h2 class="section-label">' . th('YubiKey per Yubico-OTP') . '</h2>'
            . '<form method="post" action="' . h(url(['a' => 'otp_register'])) . '" class="form-grid cols-4" autocomplete="off">' . csrf_field()
            . f_text('name', t('Name'), '', ['maxlength' => 100, 'placeholder' => t('z. B. YubiKey Schlüsselbund')])
            . '<label class="field span2"><span>' . th('OTP (ins Feld klicken, Key berühren)') . '</span><input type="password" name="otp" maxlength="64" required autocomplete="one-time-code"></label>'
            . '<div class="field"><span>&nbsp;</span><button class="pill-btn primary" type="submit">' . th('Registrieren') . '</button></div></form></section>';
    }
    echo '<h2 class="section-label spaced">' . th('Meine Keys ({n})', ['n' => $f['webauthn'] + $f['otp']]) . '</h2>' . keys_list($u, 'account_key_delete');

    echo '<h2 class="section-label spaced">' . th('Meine letzten Anmeldungen') . '</h2><div class="track-list compact">';
    foreach (sys_all("SELECT * FROM audit WHERE username = ? AND action IN ('login_success','login_failure','twofa_failure','logout') ORDER BY id DESC LIMIT 10", [$u['username']]) as $r) {
        echo audit_row_compact($r);
    }
    echo '</div>';
    page_end();
}

function action_account_save(): void
{
    $u = auth_user();
    if ($u['auth_source'] !== 'local') {
        redirect(url(['p' => 'account']));
    }
    $cur = (string)(input()['current'] ?? '');
    $new = (string)(input()['new_password'] ?? '');
    $new2 = (string)(input()['new_password2'] ?? '');
    if (!password_verify($cur, (string)$u['password_hash'])) {
        audit('account_password', ['grund' => 'aktuelles Passwort falsch'], null, 'fail', user_ctx($u));
        flash(t('Aktuelles Passwort falsch.'), 'error');
    } elseif (($e = password_policy_error($new)) !== null) {
        flash($e, 'error');
    } elseif ($new !== $new2) {
        flash(t('Die neuen Passwörter stimmen nicht überein.'), 'error');
    } elseif (password_verify($new, (string)$u['password_hash'])) {
        flash(t('Das neue Passwort muss sich vom alten unterscheiden.'), 'error');
    } else {
        user_update((int)$u['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_pw' => 0, 'updated_at' => now_str(), 'updated_by' => (string)$u['username']]);
        session_regenerate_id(true);
        audit('account_password', [], null, 'ok', user_ctx($u));
        flash(t('Passwort geändert.'));
    }
    redirect(url(['p' => 'account']));
}

function action_account_key_delete(): void
{
    $u = auth_user();
    $f = auth_user_factors((int)$u['id']);
    if (auth_requires_2fa($u) && $f['webauthn'] + $f['otp'] <= 1) {
        flash(t('Für dein Konto ist ein YubiKey Pflicht – der letzte Key kann nicht entfernt werden.'), 'error');
        redirect(url(['p' => 'account']));
    }
    key_delete_for($u);
    redirect(url(['p' => 'account']));
}

function action_otp_register(): void
{
    $u = auth_user();
    if (!setting('auth.yubico_otp.enabled')) {
        redirect(url(['p' => 'account']));
    }
    $name = in_str('name', 100) ?: 'YubiKey (OTP)';
    $r = yubico_otp_verify(strtolower(in_str('otp', 64)));
    if (!$r['ok']) {
        audit('otp_register', ['grund' => $r['error'], 'public_id' => $r['public_id'] ?? null], null, 'fail', user_ctx($u));
        flash(t('Registrierung fehlgeschlagen: {e}', ['e' => $r['error']]), 'error');
        redirect(url(['p' => 'account']));
    }
    if (array_filter(users_store()['yubico_otp_keys'], static fn($k) => $k['public_id'] === $r['public_id']) !== []) {
        flash(t('Dieser YubiKey ist bereits registriert.'), 'error');
        redirect(url(['p' => 'account']));
    }
    users_write(static fn(array &$st) => users_put($st, 'yubico_otp_keys', ['user_id' => (int)$u['id'], 'public_id' => $r['public_id'], 'name' => $name, 'created_at' => now_str()]));
    audit('otp_register', ['public_id' => $r['public_id'], 'name' => $name], null, 'ok', user_ctx($u));
    $_SESSION['must_enroll'] = false;
    flash(t('YubiKey „{n}“ ({p}) registriert.', ['n' => $name, 'p' => $r['public_id']]));
    redirect(url(['p' => 'account']));
}

function action_wa_reg_options(): void
{
    json_out(webauthn_registration_options(auth_user()));
}

function action_wa_reg_finish(): void
{
    $u = auth_user();
    try {
        $r = webauthn_registration_finish($u, input());
    } catch (Throwable $e) {
        audit('key_register', ['grund' => $e->getMessage()], null, 'fail', user_ctx($u));
        json_out(['error' => $e->getMessage()], 400);
    }
    $_SESSION['must_enroll'] = false;
    audit('key_register', ['name' => $r['name'], 'alg' => $r['alg'], 'credential' => $r['credential'] . '…', 'key_id' => $r['id']], null, 'ok', user_ctx($u));
    json_out(['ok' => true, 'name' => $r['name']]);
}
