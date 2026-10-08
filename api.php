<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(int $max = 1048576): array {
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > $max) json_response(['error' => 'PAYLOAD_TOO_LARGE'], 413);
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS nav_data (
        user_key VARCHAR(64) NOT NULL PRIMARY KEY,
        data_json LONGTEXT NOT NULL,
        updated_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS nav_backups (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_key VARCHAR(64) NOT NULL,
        data_json LONGTEXT NOT NULL,
        created_at INT NOT NULL,
        KEY user_created (user_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS nav_theme (
        user_key VARCHAR(64) NOT NULL PRIMARY KEY,
        theme_json LONGTEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS nav_meta (
        meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
        meta_value TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string|false {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function current_keygen(): int {
    $stmt = db()->prepare('SELECT meta_value FROM nav_meta WHERE meta_key=?');
    $stmt->execute(['keygen']);
    $row = $stmt->fetch();
    if (!$row) {
        db()->prepare('INSERT INTO nav_meta(meta_key,meta_value) VALUES (?,?)')->execute(['keygen', '1']);
        return 1;
    }
    return max(1, (int)$row['meta_value']);
}

function bump_keygen(): void {
    $next = current_keygen() + 1;
    $stmt = db()->prepare('INSERT INTO nav_meta(meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');
    $stmt->execute(['keygen', (string)$next]);
}

function make_token(string $type, int $ttl): string {
    $header = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $payload = b64url_encode(json_encode([
        'type' => $type,
        'sub' => NAV_DEFAULT_USER,
        'kg' => current_keygen(),
        'iat' => time(),
        'exp' => time() + $ttl,
    ], JSON_UNESCAPED_SLASHES));
    $sig = b64url_encode(hash_hmac('sha256', $header . '.' . $payload, NAV_JWT_SECRET, true));
    return $header . '.' . $payload . '.' . $sig;
}

function read_token(?string $token, ?string $expectType = null): ?array {
    if ($token === null || $token === '') return null;
    $token = trim($token);
    if (str_starts_with($token, 'Bearer ')) $token = trim(substr($token, 7));
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', $header . '.' . $payload, NAV_JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $raw = b64url_decode($payload);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (($data['exp'] ?? 0) < time()) return null;
    if (($data['kg'] ?? 0) !== current_keygen()) return null;
    if ($expectType !== null && ($data['type'] ?? '') !== $expectType) return null;
    return $data;
}

function bearer(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === 'authorization') {
                $header = (string)$value;
                break;
            }
        }
    }
    return $header !== '' ? $header : null;
}

function require_auth(): array {
    $payload = read_token(bearer(), 'access');
    if (!$payload) json_response(['error' => 'Unauthorized'], 401);
    return $payload;
}

function set_refresh_cookie(string $token, int $expires): void {
    setcookie('refreshToken', $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function load_data(): array {
    $stmt = db()->prepare('SELECT data_json FROM nav_data WHERE user_key=?');
    $stmt->execute([NAV_DEFAULT_USER]);
    $row = $stmt->fetch();
    if (!$row) return ['categories' => new stdClass()];
    $data = json_decode((string)$row['data_json'], true);
    if (!is_array($data) || !isset($data['categories']) || !is_array($data['categories'])) {
        return ['categories' => new stdClass()];
    }
    return ['categories' => $data['categories']];
}

function save_data(array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = db()->prepare('INSERT INTO nav_data(user_key,data_json,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data_json=VALUES(data_json), updated_at=VALUES(updated_at)');
    $stmt->execute([NAV_DEFAULT_USER, $json, time()]);
    create_backup(false);
}

function public_data(array $data): array {
    $categories = $data['categories'] ?? [];
    if ($categories instanceof stdClass) $categories = [];
    $out = [];
    foreach ($categories as $name => $cat) {
        if (!is_array($cat)) continue;
        $links = [];
        foreach (($cat['links'] ?? []) as $link) {
            if (!is_array($link) || !empty($link['isPrivate'])) continue;
            $links[] = $link;
        }
        $out[$name] = [
            'isHidden' => !empty($cat['isHidden']),
            'links' => $links,
        ];
    }
    return ['categories' => $out ?: new stdClass()];
}

function to_bool(mixed $value): bool {
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return $value != 0;
    if (is_string($value)) return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    return false;
}

function normalize_link_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (str_starts_with($url, '//')) return 'https:' . $url;
    if (preg_match('/^(javascript|vbscript|data|about|chrome|edge|blob|magnet|file):/i', $url)) return $url;
    if (preg_match('~^(?:[A-Za-z0-9._-]+|\d{1,3}(?:\.\d{1,3}){3})(?::\d{1,5})?(?:[/?#].*)?$~', $url)) {
        return 'https://' . $url;
    }
    return $url;
}

function is_allowed_link_url(string $url): bool {
    $url = normalize_link_url($url);
    if ($url === '' || mb_strlen($url) > 2000) return false;
    if (!preg_match('#^https?://#i', $url)) return false;
    $parts = parse_url($url);
    return is_array($parts) && !empty($parts['host']);
}

function validate_categories($categories, ?string &$error = null): bool {
    $error = null;
    if (!is_array($categories)) {
        $error = '导入数据缺少分类列表';
        return false;
    }
    if (count($categories) > 200) {
        $error = '分类数量超过限制';
        return false;
    }
    foreach ($categories as $name => $cat) {
        if (!is_string($name) || $name === '' || mb_strlen($name) > 80) {
            $error = '分类名称无效';
            return false;
        }
        if (!is_array($cat) || !isset($cat['links']) || !is_array($cat['links'])) {
            $error = '分类「' . $name . '」缺少链接列表';
            return false;
        }
        if (count($cat['links']) > 500) {
            $error = '分类「' . $name . '」链接数量超过限制';
            return false;
        }
        foreach ($cat['links'] as $link) {
            if (!is_array($link)) {
                $error = '分类「' . $name . '」存在无效链接';
                return false;
            }
            $title = trim((string)($link['name'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($title === '' || mb_strlen($title) > 120) {
                $error = '分类「' . $name . '」存在无效链接名称';
                return false;
            }
            if ($url === '' || mb_strlen($url) > 2000 || !is_allowed_link_url($url)) {
                $error = '分类「' . $name . '」中「' . $title . '」的地址无效';
                return false;
            }
        }
    }
    return true;
}

function sanitize_categories(array $categories): array {
    $out = [];
    foreach ($categories as $name => $cat) {
        $name = mb_substr(trim((string)$name), 0, 80);
        $links = [];
        foreach (($cat['links'] ?? []) as $link) {
            if (!is_array($link)) continue;
            $url = mb_substr(normalize_link_url((string)($link['url'] ?? '')), 0, 2000);
            $item = [
                'name' => mb_substr(trim((string)($link['name'] ?? '')), 0, 120),
                'url' => $url,
                'tips' => mb_substr(trim((string)($link['tips'] ?? '')), 0, 500),
                'icon' => mb_substr(trim((string)($link['icon'] ?? '')), 0, 2000),
                'isPrivate' => to_bool($link['isPrivate'] ?? false),
                'isDirect' => to_bool($link['isDirect'] ?? false),
                'category' => $name,
            ];
            $links[] = $item;
        }
        $out[$name] = [
            'isHidden' => to_bool($cat['isHidden'] ?? false),
            'links' => $links,
        ];
    }
    return $out;
}

function create_backup(bool $force = false): bool {
    $now = time();
    if (!$force) {
        $stmt = db()->prepare('SELECT created_at FROM nav_backups WHERE user_key=? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([NAV_DEFAULT_USER]);
        $row = $stmt->fetch();
        if ($row && ($now - (int)$row['created_at']) < AUTO_BACKUP_INTERVAL) return false;
    }
    $data = load_data();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ins = db()->prepare('INSERT INTO nav_backups(user_key,data_json,created_at) VALUES (?,?,?)');
    $ins->execute([NAV_DEFAULT_USER, $json, $now]);
    $ids = db()->prepare('SELECT id FROM nav_backups WHERE user_key=? ORDER BY created_at DESC');
    $ids->execute([NAV_DEFAULT_USER]);
    $all = $ids->fetchAll();
    if (count($all) > 10) {
        $del = db()->prepare('DELETE FROM nav_backups WHERE id=?');
        foreach (array_slice($all, 10) as $row) $del->execute([(int)$row['id']]);
    }
    return true;
}

$routedPath = isset($_GET['path']) ? (string)$_GET['path'] : (string)($_SERVER['REQUEST_URI'] ?? '/');
if (isset($_GET['path']) && str_contains($routedPath, '?')) {
    $embedded = parse_url($routedPath, PHP_URL_QUERY) ?: '';
    parse_str($embedded, $extraQuery);
    $_GET = array_merge($_GET, $extraQuery);
}
$path = parse_url($routedPath, PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function icon_url_resolve(string $base, string $href): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || preg_match('/^(javascript|vbscript|about|chrome|edge):/i', $href)) return null;
    if (str_starts_with(strtolower($href), 'data:image/')) return $href;
    if (preg_match('#^https?://#i', $href)) return $href;
    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return null;
    $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
    if (str_starts_with($href, '//')) return $bp['scheme'] . ':' . $href;
    if (str_starts_with($href, '/')) return $origin . $href;
    $path = $bp['path'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return $origin . ($dir ? $dir . '/' : '/') . $href;
}

function icon_fetch(string $url, int $timeout = 8): ?array {
    if (str_starts_with(strtolower($url), 'data:image/')) {
        if (!preg_match('#^data:(image/(?:svg\+xml|png|jpeg|gif|webp|x-icon|bmp));(?:base64,([A-Za-z0-9+/=]+)|,([\s\S]*))$#i', $url, $m)) return null;
        $body = isset($m[2]) && $m[2] !== '' ? base64_decode($m[2], true) : rawurldecode($m[3] ?? '');
        return ($body !== false && $body !== '') ? ['body' => $body, 'type' => strtolower($m[1])] : null;
    }
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) return null;
    $host = strtolower((string)$parts['host']);
    if (preg_match('/(^|\.)(localhost|local|internal)$/i', $host) || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.)/', $host) || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) || $host === '::1' || str_contains($host, 'metadata.google')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHP-Nav/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'],
    ]);
    $body = curl_exec($ch);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 400 || strlen($body) > 2 * 1024 * 1024) return null;
    $isSvg = str_contains($type, 'svg') || preg_match('/^\s*<(?:svg|\?xml)/i', $body);
    $isRaster = str_starts_with($type, 'image/') || str_starts_with($type, 'application/octet-stream') || (strlen($body) >= 4 && (substr($body, 0, 4) === "\x89PNG" || substr($body, 0, 3) === "\xFF\xD8\xFF" || substr($body, 0, 3) === 'GIF' || substr($body, 0, 2) === "\x00\x00"));
    if (!$isSvg && !$isRaster) return null;
    if ($isSvg) $type = 'image/svg+xml';
    if (!str_starts_with($type, 'image/')) $type = 'image/png';
    return ['body' => $body, 'type' => $type];
}

function icon_json_fetch(string $url): ?array {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHP-Nav/1.0)', CURLOPT_HTTPHEADER => ['Accept: application/manifest+json, application/json, */*']]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 400 || strlen($body) > 512 * 1024) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function icon_page_html(string $url): ?string {
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!$host || preg_match('/(^|\.)(localhost|local|internal)$/i', $host) || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.)/', $host) || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) || $host === '::1' || str_contains($host, 'metadata.google')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHP-Nav/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
    ]);
    $body = curl_exec($ch);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    if ($body === false || strlen($body) > 1 * 1024 * 1024 || (!str_contains($type, 'html') && !preg_match('/<html|<head|<link\b/i', $body))) return null;
    return $body;
}

function discover_icon(string $target): ?array {
    $parts = parse_url($target);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) return null;
    $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

    // 与 Cloudflare 版保持一致：先尝试 Xinac 图标代理，失败后再解析站点自身的图标。
    $apiIcon = icon_fetch('https://api.xinac.net/icon/?url=' . rawurlencode($target), 2);
    if ($apiIcon) return $apiIcon;

    $page = icon_page_html($target);
    $candidates = [];
    $manifestUrls = [];
    if ($page !== null && preg_match_all('/<link\b[^>]*>/i', $page, $tags)) {
        foreach ($tags[0] as $tag) {
            $attrs = [];
            preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:]*)\s*=\s*["\']([^"\']*)["\']/i', $tag, $am, PREG_SET_ORDER);
            foreach ($am as $a) $attrs[strtolower($a[1])] = $a[2];
            $rel = strtolower($attrs['rel'] ?? '');
            if (str_contains($rel, 'manifest') && !empty($attrs['href'])) {
                $manifest = icon_url_resolve($target, $attrs['href']);
                if ($manifest) $manifestUrls[] = $manifest;
            }
            if (!str_contains($rel, 'icon') || empty($attrs['href'])) continue;
            $href = icon_url_resolve($target, $attrs['href']);
            if (!$href) continue;
            $size = 0;
            if (preg_match('/(\d+)x(\d+)/i', $attrs['sizes'] ?? '', $sm)) $size = max((int)$sm[1], (int)$sm[2]);
            $score = (str_contains($rel, 'apple') ? 30 : 0) + min($size, 512) / 10 + (str_contains(strtolower($attrs['type'] ?? ''), 'svg') ? 5 : 0);
            array_unshift($candidates, ['url' => $href, 'score' => $score]);
        }
    }
    if ($page !== null && preg_match_all('/<meta\b[^>]*>/i', $page, $metaTags)) {
        foreach ($metaTags[0] as $tag) {
            $attrs = [];
            preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:]*)\s*=\s*["\']([^"\']*)["\']/i', $tag, $am, PREG_SET_ORDER);
            foreach ($am as $a) $attrs[strtolower($a[1])] = $a[2];
            $key = strtolower($attrs['property'] ?? ($attrs['name'] ?? ''));
            if (!in_array($key, ['og:image', 'twitter:image', 'msapplication-tileimage'], true) || empty($attrs['content'])) continue;
            $metaIcon = icon_url_resolve($target, $attrs['content']);
            if ($metaIcon) $candidates[] = ['url' => $metaIcon, 'score' => 2];
        }
    }
    foreach ($manifestUrls as $manifestUrl) {
        $manifest = icon_json_fetch($manifestUrl);
        if (!empty($manifest['icons']) && is_array($manifest['icons'])) {
            foreach ($manifest['icons'] as $entry) {
                if (!is_array($entry) || empty($entry['src'])) continue;
                $iconUrl = icon_url_resolve($manifestUrl, (string)$entry['src']);
                if (!$iconUrl) continue;
                $size = 0;
                if (preg_match('/(\d+)x(\d+)/i', (string)($entry['sizes'] ?? ''), $sm)) $size = max((int)$sm[1], (int)$sm[2]);
                $candidates[] = ['url' => $iconUrl, 'score' => 20 + min($size, 1024) / 10];
            }
        }
    }
    foreach (['/favicon.ico', '/favicon.svg', '/favicon.png', '/favicon-32x32.png', '/favicon-16x16.png', '/apple-touch-icon.png', '/apple-touch-icon-precomposed.png', '/static/favicon.ico', '/assets/favicon.ico', '/images/favicon.ico', '/img/favicon.ico'] as $path) $candidates[] = ['url' => $base . $path, 'score' => 1];
    usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
    $seen = [];
    foreach (array_slice($candidates, 0, 8) as $candidate) {
        $url = $candidate['url'];
        if (isset($seen[$url])) continue;
        $seen[$url] = true;
        $icon = icon_fetch($url, 2);
        if ($icon) return $icon;
    }
    // 没有真实图标时返回 null，前端会显示原有的地球占位图。
    return null;
}

try {
    if ($path === '/api/login' && $method === 'POST') {
        $body = request_json();
        $password = (string)($body['password'] ?? '');
        if (!hash_equals(NAV_ADMIN_PASSWORD, $password)) json_response(['valid' => false, 'remaining' => 4], 403);
        $access = make_token('access', 7200);
        $refresh = make_token('refresh', 2592000);
        set_refresh_cookie($refresh, time() + 2592000);
        json_response(['valid' => true, 'token' => 'Bearer ' . $access]);
    }
    if ($path === '/api/refreshToken' && $method === 'POST') {
        $payload = read_token($_COOKIE['refreshToken'] ?? null, 'refresh');
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') json_response(['error' => 'Refresh token expired'], 401);
        $access = make_token('access', 7200);
        $refresh = make_token('refresh', 2592000);
        set_refresh_cookie($refresh, time() + 2592000);
        json_response(['accessToken' => 'Bearer ' . $access]);
    }
    if ($path === '/api/validateToken') { require_auth(); json_response(['valid' => true]); }
    if ($path === '/api/logout' && $method === 'POST') {
        require_auth(); bump_keygen(); set_refresh_cookie('', time() - 3600); json_response(['success' => true]);
    }
    if ($path === '/api/getLinks') {
        $data = load_data();
        $authed = read_token(bearer()) !== null;
        json_response($authed ? $data : public_data($data));
    }
    if (in_array($path, ['/api/saveData', '/api/importData'], true) && $method === 'POST') {
        require_auth(); $body = request_json(); $categories = $body['categories'] ?? null;
        $error = null;
        if (!validate_categories($categories, $error)) json_response(['error' => 'INVALID_DATA', 'message' => $error ?: '导入数据无效'], 422);
        save_data(['categories' => sanitize_categories($categories)]); json_response(['success' => true, 'rev' => (string)time()]);
    }
    if ($path === '/api/backupData' && $method === 'POST') {
        require_auth(); $created = create_backup(true); json_response(['success' => true, 'created' => $created]);
    }
    if ($path === '/api/exportData' && $method === 'POST') { require_auth(); json_response(load_data()); }
    if ($path === '/api/getTheme') {
        $stmt = db()->prepare('SELECT theme_json FROM nav_theme WHERE user_key=?'); $stmt->execute([NAV_DEFAULT_USER]); $row = $stmt->fetch();
        json_response(['ok' => true, 'theme' => $row ? json_decode($row['theme_json'], true) : null]);
    }
    if ($path === '/api/saveTheme' && $method === 'POST') {
        require_auth(); $body = request_json(65536); $theme = $body['themeData'] ?? null;
        $record = ['themeData' => is_array($theme) ? $theme : null, 'name' => substr((string)($body['name'] ?? ''), 0, 80), 'kind' => substr((string)($body['kind'] ?? 'custom'), 0, 20), 'source' => substr((string)($body['source'] ?? ''), 0, 500), 'updatedAt' => (int)(microtime(true) * 1000)];
        $stmt = db()->prepare('INSERT INTO nav_theme(user_key,theme_json) VALUES (?,?) ON DUPLICATE KEY UPDATE theme_json=VALUES(theme_json)'); $stmt->execute([NAV_DEFAULT_USER, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]); json_response(['ok' => true, 'updatedAt' => $record['updatedAt']]);
    }
    if ($path === '/api/theme-proxy') {
        $id = (string)($_GET['id'] ?? ''); if ($id === '') json_response(['error' => 'Missing id'], 400);
        $url = 'https://tweakcn.com/r/themes/' . rawurlencode($id); $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: php-nav/1.0']]); $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); if ($body === false || $status >= 400) json_response(['error' => 'upstream ' . $status], 502); header('Content-Type: application/json; charset=utf-8'); echo $body; exit;
    }
    if ($path === '/api/icon') {
        $target = (string)($_GET['url'] ?? '');
        if (!filter_var($target, FILTER_VALIDATE_URL)) { http_response_code(400); exit('Missing URL'); }
        $icon = discover_icon($target);
        if (!$icon) { http_response_code(404); exit; }
        header('Content-Type: ' . $icon['type']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=604800');
        echo $icon['body']; exit;
    }
    http_response_code(404); echo 'Not Found';
} catch (Throwable $e) {
    error_log('nav api: ' . $e->getMessage());
    json_response(['error' => 'INTERNAL'], 500);
}
