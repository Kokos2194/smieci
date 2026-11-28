<?php
/*
 * Protokół usterek – serwer na NAS Synology (Web Station + PHP + SQLite).
 * Jeden plik: logowanie, wspólna baza usterek, zdjęcia, użytkownicy, dziennik zmian, codzienna kopia bazy.
 * Wymaga PHP 7.4+ z rozszerzeniem pdo_sqlite. Instrukcja: INSTALACJA.md.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const KINDS = ['projects', 'floors', 'units', 'defects'];
const TOKEN_DAYS = 120;
const MAX_DOC = 65536;
const MAX_PHOTO = 12 * 1024 * 1024;
const KEEP_SNAPSHOTS = 14;

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): void
{
    out(['ok' => false, 'error' => $msg], $code);
}
function validId($id): bool
{
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
}
function u_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int) preg_match_all('/./us', $s);
}
function u_cut(string $s, int $max): string
{
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max, 'UTF-8');
    preg_match('/^.{0,' . $max . '}/us', $s, $m);
    return $m[0] ?? '';
}
function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

/* ---------- storage folder (random name, blocked for direct web access) ---------- */
$dir = __DIR__;
$cfgFile = $dir . '/config.local.php';
if (!is_file($cfgFile)) {
    if (!is_writable($dir)) {
        fail('Serwer nie może zapisywać w folderze aplikacji. W File Station nadaj grupie „http” prawo odczytu i zapisu do folderu odbiory (INSTALACJA.md, krok 4).', 500);
    }
    $name = 'data-' . bin2hex(random_bytes(12));
    file_put_contents($cfgFile, "<?php return ['data' => '" . $name . "'];\n");
}
$cfg = require $cfgFile;
$dataDir = $dir . '/' . $cfg['data'];
if (!is_dir($dataDir . '/photos')) {
    @mkdir($dataDir . '/photos', 0770, true);
    @mkdir($dataDir . '/backup', 0770, true);
    @file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
    @file_put_contents($dataDir . '/index.html', '');
    if (!is_dir($dataDir . '/photos')) {
        fail('Nie udało się utworzyć folderu na dane. Sprawdź uprawnienia grupy „http” do folderu odbiory.', 500);
    }
}

/* ---------- database ---------- */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fail('PHP na NAS-ie nie ma włączonej obsługi SQLite. W Web Station włącz rozszerzenie pdo_sqlite w profilu PHP (INSTALACJA.md, krok 2).', 500);
}
$db = new PDO('sqlite:' . $dataDir . '/odbiory.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA busy_timeout = 8000');
$db->exec('CREATE TABLE IF NOT EXISTS docs (kind TEXT NOT NULL, id TEXT NOT NULL, data TEXT, deleted INTEGER NOT NULL DEFAULT 0, seq INTEGER NOT NULL, updated_at INTEGER, updated_by INTEGER, PRIMARY KEY (kind, id))');
$db->exec('CREATE INDEX IF NOT EXISTS docs_seq ON docs (seq)');
$db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, login TEXT NOT NULL UNIQUE COLLATE NOCASE, name TEXT NOT NULL, pass TEXT NOT NULL, role TEXT NOT NULL DEFAULT \'user\', active INTEGER NOT NULL DEFAULT 1, created_at INTEGER)');
$db->exec('CREATE TABLE IF NOT EXISTS tokens (hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires INTEGER NOT NULL, created_at INTEGER)');
$db->exec('CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, user_id INTEGER, kind TEXT, doc_id TEXT, action TEXT, info TEXT)');
$db->exec('CREATE TABLE IF NOT EXISTS photos (id TEXT PRIMARY KEY, ext TEXT NOT NULL, size INTEGER, user_id INTEGER, at INTEGER)');
$db->exec("INSERT OR IGNORE INTO meta (k, v) VALUES ('seq', '0')");

/* ---------- auth ---------- */
function cookiePath(): string
{
    $p = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $p . '/';
}
function setTokenCookie(string $value, int $expires): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('pu_token', $value, ['expires' => $expires, 'path' => cookiePath(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
}
function issueToken(PDO $db, int $uid): void
{
    $t = bin2hex(random_bytes(32));
    $exp = time() + TOKEN_DAYS * 86400;
    $db->prepare('INSERT INTO tokens (hash, user_id, expires, created_at) VALUES (?, ?, ?, ?)')->execute([hash('sha256', $t), $uid, $exp, time()]);
    $db->prepare('DELETE FROM tokens WHERE expires < ?')->execute([time()]);
    setTokenCookie($t, $exp);
}
function currentUser(PDO $db): ?array
{
    $t = $_COOKIE['pu_token'] ?? '';
    if (!is_string($t) || strlen($t) < 32) {
        return null;
    }
    $st = $db->prepare('SELECT u.id, u.login, u.name, u.role FROM tokens t JOIN users u ON u.id = t.user_id WHERE t.hash = ? AND t.expires > ? AND u.active = 1');
    $st->execute([hash('sha256', $t), time()]);
    $u = $st->fetch();
    if (!$u) {
        return null;
    }
    $u['id'] = (int) $u['id'];
    return $u;
}
function audit(PDO $db, ?int $uid, string $kind, string $docId, string $action, string $info = ''): void
{
    $db->prepare('INSERT INTO audit (at, user_id, kind, doc_id, action, info) VALUES (?, ?, ?, ?, ?, ?)')->execute([now_ms(), $uid, $kind, $docId, $action, u_cut($info, 300)]);
}
function cleanName($s, int $max = 60): string
{
    return trim(u_cut((string) preg_replace('/\s+/u', ' ', (string) $s), $max));
}

/* ---------- daily consistent copy of the database (for Hyper Backup) ---------- */
function snapshot(PDO $db, string $dataDir): void
{
    try {
        $day = date('Y-m-d');
        $last = $db->query("SELECT v FROM meta WHERE k = 'snapshot'")->fetchColumn();
        if ($last === $day) {
            return;
        }
        $db->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('snapshot', ?)")->execute([$day]);
        $file = $dataDir . '/backup/odbiory-' . $day . '.sqlite';
        if (!is_file($file)) {
            $db->exec('VACUUM INTO ' . $db->quote($file));
        }
        $all = glob($dataDir . '/backup/odbiory-*.sqlite') ?: [];
        sort($all);
        while (count($all) > KEEP_SNAPSHOTS) {
            @unlink(array_shift($all));
        }
    } catch (Throwable $e) {
        // older SQLite without VACUUM INTO: Hyper Backup still copies the live file
    }
}

/* ---------- request ---------- */
$a = (string) ($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pu') {
    fail('Nieprawidłowe żądanie.', 400);
}
$body = [];
if ($method === 'POST' && strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
}
$usersCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$me = currentUser($db);

try {
    switch ($a) {
        case 'me':
            out(['ok' => true, 'app' => 'protokol-usterek', 'setup' => $usersCount === 0, 'user' => $me, 'time' => now_ms()]);

        case 'setup':
            if ($method !== 'POST') fail('Użyj POST.');
            if ($usersCount > 0) fail('Konto administratora już istnieje. Zaloguj się.', 403);
            $login = cleanName($body['login'] ?? '', 40);
            $name = cleanName($body['name'] ?? '');
            $pass = (string) ($body['password'] ?? '');
            if ($login === '' || $name === '') fail('Podaj imię i nazwisko oraz login.');
            if (u_len($pass) < 8) fail('Hasło musi mieć co najmniej 8 znaków.');
            $db->prepare('INSERT INTO users (login, name, pass, role, active, created_at) VALUES (?, ?, ?, \'admin\', 1, ?)')->execute([$login, $name, password_hash($pass, PASSWORD_DEFAULT), time()]);
            $uid = (int) $db->lastInsertId();
            audit($db, $uid, 'users', (string) $uid, 'setup', $login);
            issueToken($db, $uid);
            out(['ok' => true, 'user' => ['id' => $uid, 'login' => $login, 'name' => $name, 'role' => 'admin']]);

        case 'login':
            if ($method !== 'POST') fail('Użyj POST.');
            $login = cleanName($body['login'] ?? '', 40);
            $pass = (string) ($body['password'] ?? '');
            $st = $db->prepare('SELECT id, login, name, role, pass, active FROM users WHERE login = ?');
            $st->execute([$login]);
            $u = $st->fetch();
            if (!$u || !(int) $u['active'] || !password_verify($pass, $u['pass'])) {
                usleep(900000);
                fail('Nieprawidłowy login lub hasło.', 401);
            }
            if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) {
                $db->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            issueToken($db, (int) $u['id']);
            audit($db, (int) $u['id'], 'users', (string) $u['id'], 'login', '');
            out(['ok' => true, 'user' => ['id' => (int) $u['id'], 'login' => $u['login'], 'name' => $u['name'], 'role' => $u['role']]]);

        case 'logout':
            if ($method !== 'POST') fail('Użyj POST.');
            $t = $_COOKIE['pu_token'] ?? '';
            if (is_string($t) && $t !== '') {
                $db->prepare('DELETE FROM tokens WHERE hash = ?')->execute([hash('sha256', $t)]);
            }
            setTokenCookie('', time() - 3600);
            out(['ok' => true]);
    }

    if (!$me) {
        fail('Zaloguj się ponownie.', 401);
    }
    $isAdmin = $me['role'] === 'admin';

    switch ($a) {
        case 'pull':
            snapshot($db, $dataDir);
            $since = max(0, (int) ($_GET['since'] ?? 0));
            $st = $db->prepare('SELECT d.kind, d.id, d.data, d.deleted, d.seq FROM docs d WHERE d.seq > ? ORDER BY d.seq LIMIT 1000');
            $st->execute([$since]);
            $rows = $st->fetchAll();
            $docs = [];
            $seq = $since;
            foreach ($rows as $r) {
                $seq = (int) $r['seq'];
                $docs[] = ['kind' => $r['kind'], 'id' => $r['id'], 'deleted' => (int) $r['deleted'] === 1, 'data' => (int) $r['deleted'] === 1 ? null : json_decode((string) $r['data'], true)];
            }
            out(['ok' => true, 'docs' => $docs, 'seq' => $seq, 'more' => count($rows) === 1000]);

        case 'push':
            if ($method !== 'POST') fail('Użyj POST.');
            $ops = $body['ops'] ?? [];
            if (!is_array($ops) || count($ops) > 500) fail('Za dużo zmian naraz.');
            $db->exec('BEGIN IMMEDIATE');
            $seq = (int) $db->query("SELECT v FROM meta WHERE k = 'seq'")->fetchColumn();
            $get = $db->prepare('SELECT data, deleted FROM docs WHERE kind = ? AND id = ?');
            $put = $db->prepare('INSERT OR REPLACE INTO docs (kind, id, data, deleted, seq, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $applied = 0;
            foreach ($ops as $op) {
                $kind = $op['kind'] ?? '';
                $id = $op['id'] ?? '';
                if (!in_array($kind, KINDS, true) || !validId($id)) continue;
                $get->execute([$kind, $id]);
                $prevRow = $get->fetch();
                $prev = ($prevRow && !(int) $prevRow['deleted']) ? json_decode((string) $prevRow['data'], true) : null;
                $seq++;
                if (!empty($op['deleted'])) {
                    $put->execute([$kind, $id, null, 1, $seq, now_ms(), $me['id']]);
                    audit($db, $me['id'], $kind, $id, 'delete', is_array($prev) ? (string) ($prev['title'] ?? $prev['name'] ?? '') : '');
                    $applied++;
                    continue;
                }
                $data = $op['data'] ?? null;
                if (!is_array($data)) continue;
                unset($data['id']);
                // who did what is stamped by the server, from the signed-in account
                if ($kind === 'defects') {
                    if (!is_array($prev)) {
                        $data['createdBy'] = $me['name'];
                        $data['createdById'] = $me['id'];
                    } else {
                        $data['createdBy'] = $prev['createdBy'] ?? ($data['createdBy'] ?? null);
                        $data['createdById'] = $prev['createdById'] ?? ($data['createdById'] ?? null);
                    }
                    $wasDone = is_array($prev) && !empty($prev['done']);
                    if (!empty($data['done'])) {
                        if ($wasDone) {
                            $data['doneBy'] = $prev['doneBy'] ?? $me['name'];
                            $data['doneById'] = $prev['doneById'] ?? $me['id'];
                        } else {
                            $data['doneBy'] = $me['name'];
                            $data['doneById'] = $me['id'];
                        }
                    } else {
                        $data['doneBy'] = null;
                        $data['doneById'] = null;
                    }
                    $data['updatedBy'] = $me['name'];
                }
                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false || strlen($json) > MAX_DOC) continue;
                $put->execute([$kind, $id, $json, 0, $seq, now_ms(), $me['id']]);
                $action = !is_array($prev) ? 'create' : 'edit';
                if ($kind === 'defects' && is_array($prev) && empty($prev['done']) !== empty($data['done'])) {
                    $action = empty($data['done']) ? 'reopen' : 'done';
                }
                audit($db, $me['id'], $kind, $id, $action, (string) ($data['title'] ?? $data['name'] ?? ''));
                $applied++;
            }
            $db->prepare("UPDATE meta SET v = ? WHERE k = 'seq'")->execute([(string) $seq]);
            $db->exec('COMMIT');
            out(['ok' => true, 'applied' => $applied, 'seq' => $seq]);

        case 'photo':
            if ($method === 'POST') {
                $id = $_POST['id'] ?? '';
                if (!validId($id)) fail('Nieprawidłowe zdjęcie.');
                $f = $_FILES['file'] ?? null;
                if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fail('Zdjęcie nie dotarło na serwer (limit rozmiaru w PHP?).');
                if ((int) $f['size'] > MAX_PHOTO) fail('Zdjęcie jest za duże.');
                $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 12);
                $ext = '';
                if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) $ext = 'jpg';
                elseif (strncmp($head, "\x89PNG", 4) === 0) $ext = 'png';
                elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') $ext = 'webp';
                if ($ext === '') fail('To nie jest zdjęcie JPG, PNG ani WebP.');
                $sub = $dataDir . '/photos/' . substr($id, 0, 2);
                if (!is_dir($sub)) @mkdir($sub, 0770, true);
                if (!move_uploaded_file($f['tmp_name'], $sub . '/' . $id . '.' . $ext)) fail('Nie udało się zapisać zdjęcia na NAS-ie.', 500);
                $db->prepare('INSERT OR REPLACE INTO photos (id, ext, size, user_id, at) VALUES (?, ?, ?, ?, ?)')->execute([$id, $ext, (int) $f['size'], $me['id'], now_ms()]);
                out(['ok' => true]);
            }
            $id = $_GET['id'] ?? '';
            if (!validId($id)) fail('Nieprawidłowe zdjęcie.');
            $st = $db->prepare('SELECT ext FROM photos WHERE id = ?');
            $st->execute([$id]);
            $ext = $st->fetchColumn();
            $file = $ext ? $dataDir . '/photos/' . substr($id, 0, 2) . '/' . $id . '.' . $ext : '';
            if (!$ext || !is_file($file)) fail('Brak zdjęcia.', 404);
            header_remove('Cache-Control');
            header('Content-Type: ' . ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg')));
            header('Cache-Control: private, max-age=31536000, immutable');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;

        case 'photo_del':
            if ($method !== 'POST') fail('Użyj POST.');
            $id = $body['id'] ?? '';
            if (!validId($id)) fail('Nieprawidłowe zdjęcie.');
            $st = $db->prepare('SELECT ext FROM photos WHERE id = ?');
            $st->execute([$id]);
            $ext = $st->fetchColumn();
            if ($ext) {
                @unlink($dataDir . '/photos/' . substr($id, 0, 2) . '/' . $id . '.' . $ext);
                $db->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
            }
            out(['ok' => true]);

        case 'password':
            if ($method !== 'POST') fail('Użyj POST.');
            $st = $db->prepare('SELECT pass FROM users WHERE id = ?');
            $st->execute([$me['id']]);
            if (!password_verify((string) ($body['old'] ?? ''), (string) $st->fetchColumn())) fail('Obecne hasło jest nieprawidłowe.', 403);
            $new = (string) ($body['new'] ?? '');
            if (u_len($new) < 8) fail('Nowe hasło musi mieć co najmniej 8 znaków.');
            $db->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            audit($db, $me['id'], 'users', (string) $me['id'], 'password', '');
            out(['ok' => true]);

        case 'users':
            if (!$isAdmin) fail('Tylko administrator.', 403);
            $rows = $db->query('SELECT id, login, name, role, active FROM users ORDER BY active DESC, name COLLATE NOCASE')->fetchAll();
            foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['active'] = (int) $r['active'] === 1; }
            out(['ok' => true, 'users' => $rows]);

        case 'user_save':
            if ($method !== 'POST') fail('Użyj POST.');
            if (!$isAdmin) fail('Tylko administrator.', 403);
            $id = (int) ($body['id'] ?? 0);
            $login = cleanName($body['login'] ?? '', 40);
            $name = cleanName($body['name'] ?? '');
            $role = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $active = !isset($body['active']) || !empty($body['active']);
            $pass = (string) ($body['password'] ?? '');
            if ($login === '' || $name === '') fail('Podaj imię i nazwisko oraz login.');
            if ($pass !== '' && u_len($pass) < 8) fail('Hasło musi mieć co najmniej 8 znaków.');
            if ($id === $me['id'] && (!$active || $role !== 'admin')) fail('Nie możesz odebrać sobie uprawnień administratora ani wyłączyć własnego konta.');
            $dup = $db->prepare('SELECT id FROM users WHERE login = ? AND id != ?');
            $dup->execute([$login, $id]);
            if ($dup->fetchColumn()) fail('Taki login już istnieje.');
            if ($id) {
                $db->prepare('UPDATE users SET login = ?, name = ?, role = ?, active = ? WHERE id = ?')->execute([$login, $name, $role, $active ? 1 : 0, $id]);
                if ($pass !== '') $db->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                if (!$active || $pass !== '') $db->prepare('DELETE FROM tokens WHERE user_id = ?')->execute([$id]);
                audit($db, $me['id'], 'users', (string) $id, 'edit', $login);
            } else {
                if ($pass === '') fail('Nadaj hasło nowemu użytkownikowi.');
                $db->prepare('INSERT INTO users (login, name, pass, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')->execute([$login, $name, password_hash($pass, PASSWORD_DEFAULT), $role, time()]);
                $id = (int) $db->lastInsertId();
                audit($db, $me['id'], 'users', (string) $id, 'create', $login);
            }
            out(['ok' => true, 'id' => $id]);

        case 'audit':
            if (!$isAdmin) fail('Tylko administrator.', 403);
            $rows = $db->query('SELECT a.at, a.kind, a.doc_id, a.action, a.info, u.name AS user FROM audit a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300')->fetchAll();
            out(['ok' => true, 'entries' => $rows]);
    }
    fail('Nieznana akcja.', 404);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->exec('ROLLBACK');
    error_log('protokol-usterek: ' . $e->getMessage());
    fail('Błąd serwera. Spróbuj ponownie za chwilę.', 500);
}
