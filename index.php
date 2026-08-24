<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *  SONY LIV PROXY  v13.2 POWER  –  Previous-Flow High-Concurrency Build
 * ══════════════════════════════════════════════════════════════════
 *
 *  v13.1 (FIX): Playlist URLs now use PATH_INFO by default so they
 *  work without .htaccess.  Set USE_CLEAN_URLS to true if you have
 *  an .htaccess rewrite rule in place.
 *
 *  WHY v12 BUFFERED AFTER 2-3 MIN:
 *   ✗ Sub-playlists cached 4s → live segments rotate every 2-3s
 *     → player got stale playlist → requested expired segments → 404 → buffer
 *   ✗ Cookie refresh only on 401/403 (reactive, not proactive)
 *     → all segment requests fail simultaneously during refresh gap
 *   ✗ No segment retry → one failure = immediate stall
 *
 *  v13 FIXES:
 *   ✅ Sub-playlists: ZERO cache for live (fetched fresh every time)
 *   ✅ Pre-emptive cookie refresh (10 min before expiry)
 *   ✅ Segment retry with fresh cookie on failure
 *   ✅ Master M3U8 cache: 5s (was 8s)
 *   ✅ Cache keys ignore auth params (survive cookie rotation)
 *   ✅ Zero-copy streaming + implicit_flush (same as v12)
 *
 *  URLS:
 *    domain.com/sony/sony.php              → M3U playlist
 *    domain.com/sony/sony.php/sab-hd.m3u8  → Raw M3U8 (any player)
 *    domain.com/sony/sony.php?url=...      → Proxy (internal)
 *    domain.com/sony/sony.php?debug        → Health check
 */

// ★★★ KILL ALL PHP BUFFERING ★★★
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) @ob_end_clean();
ob_implicit_flush(true);

// ── CONFIG ────────────────────────────────────────────────────
define('CACHE_DIR',   __DIR__ . '/cache');
define('COOKIE_FILE', CACHE_DIR . '/sony_cookie.json');
define('CDN_JAR',     CACHE_DIR . '/cdn_cookies.txt');
define('M3U8_TTL',    5);     // master M3U8 cache: 5 sec
define('CK_BUFFER',   600);   // refresh cookie 10 min before expiry
define('API_BASE',    'https://apiv2.sonyliv.com');
define('APP_VER',     '3.4.2');
define('SAMPLE_VODS', ['1090491205','1700001659','1700000121','1700000456']);
define('SEG_EXTS',    ['ts','m4s','m4v','m4a','mp4','aac','fmp4','cmfv','cmfa']);
define('SESSION_LOCK', CACHE_DIR . '/sony_session.lock');
define('MASTER_LOCK_PREFIX', CACHE_DIR . '/mst_lock_');
define('LOCK_WAIT_US', 50000); // 50ms between lock-contention checks

/**
 * Set to true if you have an .htaccess rewrite that maps
 *   /sony/slug.m3u8 → /sony/sony.php?slug=slug
 * When false, URLs will use the PATH_INFO form:
 *   /sony/sony.php/slug.m3u8
 */
define('USE_CLEAN_URLS', false);

if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);

// ── CHANNELS ─────────────────────────────────────────────────
$CHANNELS = [
 ['slug'=>'set-hd',  'name'=>'SET HD',               'group'=>'Entertainment','url'=>'https://dishmt.slivcdn.com/hls/live/2011671-b/SETHD/master.m3u8'],
 ['slug'=>'sab-hd',  'name'=>'Sony SAB HD',          'group'=>'Entertainment','url'=>'https://dishmt.slivcdn.com/hls/live/2011749-b/SABHD/master.m3u8'],
 ['slug'=>'marathi', 'name'=>'Sony Marathi',         'group'=>'Entertainment','url'=>'https://dishmt.slivcdn.com/hls/live/2011740-b/SonyMarathi/master.m3u8'],
 ['slug'=>'pal',     'name'=>'Sony Pal',             'group'=>'Entertainment','url'=>'https://dishmt.slivcdn.com/hls/live/2011741-b/SonyPalSD/master.m3u8'],
 ['slug'=>'aath',    'name'=>'Sony Aath',            'group'=>'Entertainment','url'=>'https://dishmt.slivcdn.com/hls/live/2011641-b/SonyAathSD/master.m3u8'],
 ['slug'=>'yay',     'name'=>'Sony Yay',             'group'=>'Kids',         'url'=>'https://dishmt.slivcdn.com/hls/live/2011746-b/SonyYaySD/master.m3u8'],
 ['slug'=>'max-hd',  'name'=>'Sony MAX HD',          'group'=>'Movies',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011670-b/SonyMaxhd/master.m3u8'],
 ['slug'=>'max',     'name'=>'Sony MAX',             'group'=>'Movies',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011745-b/SonyMaxSD/master.m3u8'],
 ['slug'=>'max1',    'name'=>'Sony MAX 1',           'group'=>'Movies',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011745-b/SonyMaxSD/master.m3u8'],
 ['slug'=>'max2',    'name'=>'Sony MAX 2',           'group'=>'Movies',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011908-b/MAX2/master.m3u8'],
 ['slug'=>'wah',     'name'=>'Sony WAH',             'group'=>'Movies',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011906-b/SonyWah/master.m3u8'],
 ['slug'=>'pix-hd',  'name'=>'Sony PIX HD',          'group'=>'Movies',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011748/PIXHD/master.m3u8'],
 ['slug'=>'ten1-hd', 'name'=>'Sony Sports Ten 1 HD', 'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011747/TEN1HD/master.m3u8'],
 ['slug'=>'ten1',    'name'=>'Sony Sports Ten 1',    'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2011739/TEN1SD/master.m3u8'],
 ['slug'=>'ten2',    'name'=>'Sony Sports Ten 2',    'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020590/TEN2SD/master.m3u8'],
 ['slug'=>'ten3',    'name'=>'Sony Sports Ten 3',    'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020592/TEN3SD/master.m3u8'],
 ['slug'=>'ten4',    'name'=>'Sony Sports Ten 4',    'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020437/ten4sd/master.m3u8'],
 ['slug'=>'ten5',    'name'=>'Sony Sports Ten 5',    'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020594-b/SONYSIXSD/master.m3u8'],
 ['slug'=>'ten5-hd', 'name'=>'Sony Sports Ten 5 HD', 'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020593/SONYSIXHD/master.m3u8'],
 ['slug'=>'bbc-earth','name'=>'BBC Earth',             'group'=>'Infotainment',  'url'=>'https://dishmt.slivcdn.com/hls/live/2011907-b/SonyBBCEarthHD/master.m3u8'],
 ['slug'=>'ten2-hd', 'name'=>'Sony Sports Ten 2 HD', 'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020434/TEN2HD/master.m3u8'],
 ['slug'=>'ten3-hd', 'name'=>'Sony Sports Ten 3 HD', 'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020591/TEN3HD/master.m3u8'],
 ['slug'=>'ten4-hd', 'name'=>'Sony Sports Ten 4 HD', 'group'=>'Sports',       'url'=>'https://dishmt.slivcdn.com/hls/live/2020589/ten4hd/master.m3u8'],
];

// Optional authorized SD channel URLs. Set these in your hosting environment, for example:
// SONY_SET_SD_URL=https://your-authorized-cdn/.../master.m3u8
// SONY_SAB_SD_URL=https://your-authorized-cdn/.../master.m3u8
// They are added only when a non-empty HTTPS URL is supplied.
foreach ([
    ['env'=>'SONY_SET_SD_URL', 'slug'=>'set', 'name'=>'SET SD', 'group'=>'Entertainment'],
    ['env'=>'SONY_SAB_SD_URL', 'slug'=>'sab', 'name'=>'Sony SAB SD', 'group'=>'Entertainment'],
] as $opt) {
    $u = trim((string)getenv($opt['env']));
    if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL) && str_starts_with(strtolower($u), 'https://')) {
        $CHANNELS[] = ['slug'=>$opt['slug'], 'name'=>$opt['name'], 'group'=>$opt['group'], 'url'=>$u];
    }
}

// Optional AUTHORIZED internal SonyLIV channel catalog importer.
// This does NOT discover endpoints and does NOT bypass DRM. It only consumes an
// endpoint you explicitly configure and imports direct HTTPS .m3u8 URLs returned
// by that endpoint.
//
// Environment variables:
//   SONY_CHANNEL_CATALOG_URL=https://apiv2.sonyliv.com/<your-internal-catalog-endpoint>
//   SONY_CHANNEL_CATALOG_BEARER=<optional bearer token>
//
// Supported JSON shapes are intentionally flexible. Objects may use:
//   slug/id/channel_slug, name/title/channel_name, group/category/genre,
//   hls_url/master_url/m3u8_url/stream_url/url, logo/logo_url/image.
function import_authorized_catalog(array &$channels): void {
    $catalogUrl = trim((string)getenv('SONY_CHANNEL_CATALOG_URL'));
    if ($catalogUrl === '' || !filter_var($catalogUrl, FILTER_VALIDATE_URL)) return;

    $parts = parse_url($catalogUrl);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || $host !== 'apiv2.sonyliv.com') return;
    if (!function_exists('curl_init')) return;

    $headers = ['Accept: application/json', 'Expect:'];
    $bearer = trim((string)getenv('SONY_CHANNEL_CATALOG_BEARER'));
    if ($bearer !== '') $headers[] = 'Authorization: Bearer ' . $bearer;

    $ch = curl_init($catalogUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'SonyLIV-Internal-Catalog/1.0',
        CURLOPT_ENCODING => '',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) return;

    $json = json_decode($raw, true);
    if (!is_array($json)) return;

    $existing = [];
    foreach ($channels as $c) $existing[(string)$c['slug']] = true;

    $walk = function($node) use (&$walk, &$channels, &$existing) {
        if (!is_array($node)) return;

        // Candidate channel object.
        $url = '';
        foreach (['hls_url','master_url','m3u8_url','stream_url','url'] as $k) {
            if (isset($node[$k]) && is_string($node[$k])) {
                $u = trim($node[$k]);
                if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) {
                    $path = strtolower((string)(parse_url($u, PHP_URL_PATH) ?? ''));
                    if (str_ends_with($path, '.m3u8')) { $url = $u; break; }
                }
            }
        }
        if ($url !== '' && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https') {
            $name = '';
            foreach (['name','title','channel_name'] as $k) {
                if (isset($node[$k]) && is_string($node[$k]) && trim($node[$k]) !== '') { $name = trim($node[$k]); break; }
            }
            if ($name === '') $name = 'SonyLIV Channel';

            $slug = '';
            foreach (['slug','channel_slug','id'] as $k) {
                if (isset($node[$k]) && (is_string($node[$k]) || is_int($node[$k]))) { $slug = strtolower(trim((string)$node[$k])); break; }
            }
            if ($slug === '') $slug = strtolower($name);
            $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
            if ($slug === '') $slug = 'channel-' . substr(md5($url), 0, 8);

            $group = 'SonyLIV';
            foreach (['group','category','genre'] as $k) {
                if (isset($node[$k]) && is_string($node[$k]) && trim($node[$k]) !== '') { $group = trim($node[$k]); break; }
            }

            $logo = '';
            foreach (['logo','logo_url','image'] as $k) {
                if (isset($node[$k]) && is_string($node[$k]) && filter_var(trim($node[$k]), FILTER_VALIDATE_URL)) { $logo = trim($node[$k]); break; }
            }

            if (!isset($existing[$slug])) {
                $entry = ['slug'=>$slug, 'name'=>$name, 'group'=>$group, 'url'=>$url];
                if ($logo !== '') $entry['logo'] = $logo;
                $channels[] = $entry;
                $existing[$slug] = true;
            }
        }

        foreach ($node as $v) if (is_array($v)) $walk($v);
    };
    $walk($json);
}

import_authorized_catalog($CHANNELS);

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════
function find_chan(array $list, string $slug): ?array {
    foreach ($list as $c) if ($c['slug'] === $slug) return $c;
    return null;
}

function is_segment(string $url): bool {
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    return in_array(pathinfo($path, PATHINFO_EXTENSION), SEG_EXTS, true);
}

// Merge CDN auth query params into URL (deduped)
function with_ck(string $url, string $cq): string {
    if (!$cq) return $url;
    parse_str($cq, $new);
    $base = strtok($url, '?');
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $old);
    return $base . '?' . http_build_query(array_merge($old, $new));
}

// Strip auth query params → gives stable cache key across cookie rotations
function cache_key(string $url): string {
    return md5(strtok($url, '?'));
}

// ══════════════════════════════════════════════════════════════
// CURL — max speed
// ══════════════════════════════════════════════════════════════
function fast_curl(): array {
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_FORBID_REUSE   => false,
        CURLOPT_FRESH_CONNECT  => false,
        CURLOPT_TCP_NODELAY    => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '',
    ];
}

function cdn_headers(): array {
    return [
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
        'Origin: https://www.sonyliv.com',
        'Referer: https://www.sonyliv.com/',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: cross-site',
        'Sec-Ch-Ua: "Chromium";v="126"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Expect:',
    ];
}

// ══════════════════════════════════════════════════════════════
// SONY SESSION COOKIE — with pre-emptive refresh
// ══════════════════════════════════════════════════════════════
function sony_api(string $url, array $h = []): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, fast_curl() + [CURLOPT_HTTPHEADER => $h ?: ['Expect:']]);
    $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r && $c < 400) ? (json_decode($r, true) ?: null) : null;
}

function get_token(): ?string {
    foreach (['1.6','1.4'] as $v) {
        $d = sony_api(API_BASE . "/AGL/$v/A/ENG/WEB/ALL/GETTOKEN");
        if (!$d) continue;
        $r = $d['resultObj'] ?? null;
        $t = is_array($r) ? ($r['token'] ?? $r['securityToken'] ?? null) : (is_string($r) ? $r : null);
        if ($t) return $t;
    }
    return null;
}

function get_vod_url(string $token): ?string {
    foreach (SAMPLE_VODS as $vod) {
        $d = sony_api(API_BASE . '/AGL/1.5/A/ENG/WEB/IN/CONTENT/VIDEOURL/VOD/' . $vod . '/freepreview', [
            'Content-Type: application/json', 'x-via-device: true',
            "security_token: $token", 'app_version: ' . APP_VER, 'Expect:',
        ]);
        $u = $d['resultObj']['videoURL'] ?? null;
        if ($u) return $u;
    }
    return null;
}

function extract_ck(string $url): ?array {
    $q = parse_url($url, PHP_URL_QUERY);
    if (!$q) return null;
    $exp = time() + 7200;
    if (preg_match('/[?&]exp=(\d+)/', $url, $m)) $exp = (int)$m[1];
    return ['query' => $q, 'expiry' => $exp];
}

function load_ck(): ?array {
    if (!file_exists(COOKIE_FILE)) return null;
    $d = json_decode(file_get_contents(COOKIE_FILE), true);
    // Cookie must be valid for at least CK_BUFFER seconds from now
    return ($d && ($d['expiry'] ?? 0) > time() + CK_BUFFER) ? $d : null;
}

function save_ck(array $c): void {
    $tmp = COOKIE_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode($c), LOCK_EX) !== false) {
        @rename($tmp, COOKIE_FILE);
    } else {
        @unlink($tmp);
    }
}

function get_ck(bool $force = false): ?array {
    if (!$force) { $c = load_ck(); if ($c) return $c; }

    // Single-flight refresh: only one PHP worker refreshes the Sony session at once.
    $fh = @fopen(SESSION_LOCK, 'c+');
    if ($fh) {
        $locked = @flock($fh, LOCK_EX);
        if ($locked) {
            // Another worker may have refreshed while we were waiting.
            if (!$force) {
                $c = load_ck();
                if ($c) { @flock($fh, LOCK_UN); @fclose($fh); return $c; }
            }
            for ($i = 0; $i < 4; $i++) {
                $t = get_token();      if (!$t) { usleep(250000); continue; }
                $v = get_vod_url($t);  if (!$v) { usleep(250000); continue; }
                $ck = extract_ck($v);
                if ($ck) {
                    save_ck($ck);
                    @flock($fh, LOCK_UN); @fclose($fh);
                    return $ck;
                }
                usleep(250000);
            }
            @flock($fh, LOCK_UN);
        }
        @fclose($fh);
    }

    // Fallback if locking is unavailable on the host.
    for ($i = 0; $i < 2; $i++) {
        $t = get_token();      if (!$t) { usleep(250000); continue; }
        $v = get_vod_url($t);  if (!$v) { usleep(250000); continue; }
        $ck = extract_ck($v);  if ($ck) { save_ck($ck); return $ck; }
    }
    return null;
}

// Get fresh cookie query string — auto-refreshes if nearing expiry
function fresh_cq(): string {
    $ck = get_ck();
    if (!$ck) $ck = get_ck(true); // force refresh
    return $ck['query'] ?? '';
}

// ══════════════════════════════════════════════════════════════
// URL RESOLVER (RFC 3986)
// ══════════════════════════════════════════════════════════════
function resolve(string $base, string $rel): string {
    $sc = parse_url($rel, PHP_URL_SCHEME);
    if ($sc !== null && $sc !== '') return $rel;
    if (str_starts_with($rel, '//'))
        return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $rel;
    $rq = ''; $rf = '';
    if (($f = strpos($rel, '#')) !== false) { $rf = substr($rel, $f); $rel = substr($rel, 0, $f); }
    if (($q = strpos($rel, '?')) !== false) { $rq = substr($rel, $q); $rel = substr($rel, 0, $q); }
    $p    = parse_url($base);
    $path = ($rel === '' || $rel[0] === '/')
          ? $rel : rtrim(dirname($p['path'] ?? '/'), '/') . '/' . ltrim($rel, '/');
    $segs = [];
    foreach (explode('/', $path) as $s) {
        if ($s === '..') array_pop($segs);
        elseif ($s !== '.' && $s !== '') $segs[] = $s;
    }
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')
         . (isset($p['port']) ? ':' . $p['port'] : '')
         . '/' . implode('/', $segs) . $rq . $rf;
}

// ══════════════════════════════════════════════════════════════
// ★★★ ZERO-COPY SEGMENT STREAMING WITH RETRY ★★★
//
// Streams CDN → client chunk-by-chunk.
// On failure (403/404): refreshes cookie and retries ONCE.
// ══════════════════════════════════════════════════════════════
function stream_segment(string $url, bool $isRetry = false): void {
    $headersSent = false;
    $statusCode  = 200;
    $respHeaders = [];
    $upHeaders   = cdn_headers();
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $upHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }
    if (!empty($_SERVER['HTTP_IF_NONE_MATCH'])) {
        $upHeaders[] = 'If-None-Match: ' . $_SERVER['HTTP_IF_NONE_MATCH'];
    }
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $upHeaders[] = 'If-Modified-Since: ' . $_SERVER['HTTP_IF_MODIFIED_SINCE'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_FORBID_REUSE   => false,
        CURLOPT_TCP_NODELAY    => true,
        CURLOPT_HTTPHEADER     => $upHeaders,
        CURLOPT_COOKIEFILE     => CDN_JAR,
        CURLOPT_COOKIEJAR      => CDN_JAR,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_BUFFERSIZE     => 131072,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_NOBODY         => (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'),

        CURLOPT_HEADERFUNCTION => function($ch, $h) use (&$statusCode, &$respHeaders) {
            $line = trim($h);
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                $statusCode  = (int)$m[1];
                $respHeaders = [];
            } elseif ($line !== '') {
                $respHeaders[] = $line;
            }
            return strlen($h);
        },

        CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$headersSent, &$statusCode, &$respHeaders) {
            if (!$headersSent) {
                $headersSent = true;
                http_response_code($statusCode);
                foreach ($respHeaders as $h) {
                    $hl = strtolower($h);
                    if (str_starts_with($hl, 'content-type:')
                     || str_starts_with($hl, 'content-length:')
                     || str_starts_with($hl, 'accept-ranges:')
                     || str_starts_with($hl, 'content-range:')
                     || str_starts_with($hl, 'cache-control:')
                     || str_starts_with($hl, 'etag:')
                     || str_starts_with($hl, 'last-modified:'))
                        header($h);
                }
                header('Access-Control-Allow-Origin: *');
                header('X-Accel-Buffering: no');
            }
            echo $chunk;
            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // ★ RETRY LOGIC: if segment failed and we haven't retried yet
    if (!$headersSent && !$isRetry) {
        // Refresh cookie and retry with new auth
        $ck = get_ck(true);
        if ($ck) {
            $newUrl = with_ck(strtok($url, '?'), $ck['query']);
            stream_segment($newUrl, true);
            return;
        }
    }

    // If auth failed (403/401) and not retried yet
    if (!$isRetry && in_array($statusCode, [401, 403])) {
        $ck = get_ck(true);
        if ($ck) {
            $newUrl = with_ck(strtok($url, '?'), $ck['query']);
            stream_segment($newUrl, true);
            return;
        }
    }

    if (!$headersSent) {
        http_response_code(502);
        header('Content-Type: text/plain');
        echo '# Segment error: ' . $curlErr;
    }
}

// ══════════════════════════════════════════════════════════════
// BUFFERED FETCH (playlists — small text, needs rewriting)
// ══════════════════════════════════════════════════════════════
function pfetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, fast_curl() + [
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => cdn_headers(),
        CURLOPT_COOKIEFILE     => CDN_JAR,
        CURLOPT_COOKIEJAR      => CDN_JAR,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
    ]);
    $r    = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $eff  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    $err  = curl_error($ch);
    curl_close($ch);
    if ($r === false) return ['error' => $err, 'code' => 0];
    return ['body' => substr($r, $hsz), 'hdr' => substr($r, 0, $hsz), 'code' => $code, 'eff' => $eff];
}

// ══════════════════════════════════════════════════════════════
// M3U8 REWRITER
// ══════════════════════════════════════════════════════════════
function rewrite_m3u8(string $body, string $base, string $cq, string $px): string {
    $out = [];
    foreach (explode("\n", str_replace("\r\n", "\n", $body)) as $ln) {
        $ln = rtrim($ln);
        if ($ln === '') { $out[] = ''; continue; }
        if (preg_match('/^(#EXT-X-(?:KEY|MAP|MEDIA|SESSION-DATA|I-FRAME-STREAM-INF|STREAM-INF)):(.+)$/i', $ln, $m)) {
            $out[] = $m[1] . ':' . preg_replace_callback('/URI="([^"]+)"/', function($x) use ($base, $cq, $px) {
                return 'URI="' . $px . '?url=' . urlencode(with_ck(resolve($base, $x[1]), $cq)) . '"';
            }, $m[2]);
            continue;
        }
        if ($ln[0] === '#') { $out[] = $ln; continue; }
        $out[] = $px . '?url=' . urlencode(with_ck(resolve($base, $ln), $cq));
    }
    return implode("\n", $out);
}

// ══════════════════════════════════════════════════════════════
// M3U8 CACHE — master only (5s), sub-playlists: NO CACHE (live!)
// ══════════════════════════════════════════════════════════════
function cached_master(string $chanUrl, string $cq, string $px): ?string {
    $hash = cache_key($chanUrl);
    $key  = CACHE_DIR . '/mst_' . $hash . '.cache';
    if (is_file($key) && (time() - filemtime($key)) < M3U8_TTL)
        return @file_get_contents($key) ?: null;

    $lock = @fopen(MASTER_LOCK_PREFIX . $hash, 'c+');
    if ($lock && @flock($lock, LOCK_EX)) {
        // Recheck after waiting for the worker already fetching this master.
        if (is_file($key) && (time() - filemtime($key)) < M3U8_TTL) {
            $body = @file_get_contents($key) ?: null;
            @flock($lock, LOCK_UN); @fclose($lock);
            return $body;
        }

        $res = pfetch(with_ck($chanUrl, $cq));
        if (!isset($res['error']) && in_array($res['code'], [401, 403])) {
            $ck = get_ck(true); $cq = $ck['query'] ?? $cq;
            $res = pfetch(with_ck($chanUrl, $cq));
        }
        if (isset($res['error']) || $res['code'] >= 400) {
            @flock($lock, LOCK_UN); @fclose($lock);
            return null;
        }
        $rewritten = rewrite_m3u8($res['body'], $res['eff'], $cq, $px);
        $tmp = $key . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $rewritten, LOCK_EX) !== false) @rename($tmp, $key); else @unlink($tmp);
        @flock($lock, LOCK_UN); @fclose($lock);
        return $rewritten;
    }

    // Fallback for filesystems without flock support.
    $res = pfetch(with_ck($chanUrl, $cq));
    if (isset($res['error']) || $res['code'] >= 400) return null;
    return rewrite_m3u8($res['body'], $res['eff'], $cq, $px);
}

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$httpHost   = $_SERVER['HTTP_HOST'];
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/sony.php';
$scriptDir  = rtrim(dirname($scriptPath), '/\\');
$proxyUrl   = "$scheme://$httpHost$scriptPath";
$baseUrl    = "$scheme://$httpHost$scriptDir";

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, *');
header('Access-Control-Expose-Headers: Content-Length, Content-Type, Content-Range, Accept-Ranges');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// ══════════════════════════════════════════════════════════════
// ROUTE: ?url= → THE PROXY
// ══════════════════════════════════════════════════════════════
if (isset($_GET['url'])) {
    $cq = fresh_cq();
    if (!$cq) { http_response_code(500); die('# Cookie error'); }

    $tgt = with_ck(urldecode($_GET['url']), $cq);

    // ── SEGMENT: zero-copy stream with retry ──────────
    if (is_segment($tgt)) {
        stream_segment($tgt);
        exit;
    }

    // ── SUB-PLAYLIST: fetch FRESH every time (live!) ──
    //    Live playlists change every 2-3 seconds.
    //    Caching them = stale segments = 404 = buffering.
    $res = pfetch($tgt);

    if (!isset($res['error']) && in_array($res['code'], [401, 403])) {
        $ck  = get_ck(true);
        $cq  = $ck['query'] ?? $cq;
        $tgt = with_ck(strtok($tgt, '?'), $cq);
        $res = pfetch($tgt);
    }

    if (isset($res['error'])) { http_response_code(502); die('# ERROR: ' . $res['error']); }

    $ct = 'application/octet-stream';
    if (preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $res['hdr'], $m)) $ct = trim($m[1]);
    $body = $res['body'];
    $eff  = $res['eff'];

    $isM3U = stripos($ct, 'mpegurl') !== false
          || stripos($eff, '.m3u8') !== false
          || (strlen($body) > 7 && substr(ltrim($body), 0, 7) === '#EXTM3U');

    if ($isM3U) {
        $body = rewrite_m3u8($body, $eff, $cq, $proxyUrl);
        $ct   = 'application/vnd.apple.mpegurl';
    }

    http_response_code($res['code']);
    header("Content-Type: $ct");
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

// ══════════════════════════════════════════════════════════════
// DETECT CHANNEL SLUG
// ══════════════════════════════════════════════════════════════
$slug = null;
if (!empty($_GET['slug']))
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'])));
if (!$slug) {
    $uri = strtok($_SERVER['REQUEST_URI'], '?');
    if (preg_match('#/([a-z0-9-]+)\.m3u8$#i', $uri, $m))
        $slug = strtolower($m[1]);
}

// ══════════════════════════════════════════════════════════════
// ROUTE: ?health=1 → lightweight local health check (no upstream call)
// ══════════════════════════════════════════════════════════════
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    $ck = is_file(COOKIE_FILE) ? (json_decode((string)@file_get_contents(COOKIE_FILE), true) ?: []) : [];
    echo json_encode([
        'ok' => true,
        'version' => '13.2-power',
        'cache_writable' => is_writable(CACHE_DIR),
        'cached_session_expires_in' => max(0, (int)(($ck['expiry'] ?? 0) - time())),
        'channels' => count($CHANNELS),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ══════════════════════════════════════════════════════════════
// ROUTE: ?debug → Health check
// ══════════════════════════════════════════════════════════════
if (isset($_GET['debug'])) {
    header('Content-Type: application/json');
    $cq = fresh_cq();
    if (!$cq) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'session unavailable']); exit; }
    $ck = is_file(COOKIE_FILE) ? (json_decode(file_get_contents(COOKIE_FILE), true) ?: []) : [];
    $out = [
        'cookie_valid'      => !!load_ck(),
        'cookie_expiry'     => date('Y-m-d H:i:s', $ck['expiry'] ?? 0),
        'expires_in_min'    => round((($ck['expiry'] ?? 0) - time()) / 60, 1),
        'refresh_buffer_min'=> CK_BUFFER / 60,
        'proxy_base'        => $proxyUrl,
        'cache_writable'    => is_writable(CACHE_DIR),
        'channels'          => [],
    ];

    // Helper to build correct channel URL based on config
    $getChanUrl = function ($slug) use ($proxyUrl, $baseUrl) {
        return USE_CLEAN_URLS
            ? $baseUrl . '/' . $slug . '.m3u8'
            : $proxyUrl . '/' . $slug . '.m3u8';
    };

    foreach ($CHANNELS as $c) {
        $r = pfetch(with_ck($c['url'], $cq));
        $out['channels'][] = [
            'slug'      => $c['slug'],
            'name'      => $c['name'],
            'url'       => $getChanUrl($c['slug']),
            'http_code' => $r['code'] ?? 0,
            'ok'        => ($r['code'] ?? 0) === 200,
        ];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ══════════════════════════════════════════════════════════════
// ROUTE: /<slug>.m3u8 → Raw M3U8 (any player)
// ══════════════════════════════════════════════════════════════
if ($slug && $slug !== 'sony') {
    $chan = find_chan($CHANNELS, $slug);
    if (!$chan) {
        http_response_code(404);
        header('Content-Type: text/plain');
        die("# 404: '$slug' not found.\n# Valid: " . implode(', ', array_column($CHANNELS, 'slug')));
    }

    $cq = fresh_cq();
    if (!$cq) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('# ERROR: Cannot get Sony session. Ensure cache/ is writable.');
    }

    $body = cached_master($chan['url'], $cq, $proxyUrl);
    if (!$body) {
        // Retry with fresh cookie
        $ck = get_ck(true);
        if ($ck) $body = cached_master($chan['url'], $ck['query'], $proxyUrl);
    }
    if (!$body) {
        http_response_code(502);
        header('Content-Type: text/plain');
        die('# Stream fetch failed for: ' . $chan['name']);
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('Content-Length: ' . strlen($body));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
    exit;
}

// ══════════════════════════════════════════════════════════════
// DEFAULT: M3U Playlist
// ══════════════════════════════════════════════════════════════
$playlist = "#EXTM3U\n# Sony LIV Live POWER — " . date('Y-m-d H:i:s') . "\n\n";

$getChanUrl = function ($slug) use ($proxyUrl, $baseUrl) {
    return USE_CLEAN_URLS
        ? $baseUrl . '/' . $slug . '.m3u8'
        : $proxyUrl . '/' . $slug . '.m3u8';
};

foreach ($CHANNELS as $ch) {
    $playlist .= '#EXTINF:-1 group-title="' . $ch['group'] . '" tvg-name="' . $ch['name'] . '",' . $ch['name'] . "\n";
    $playlist .= $getChanUrl($ch['slug']) . "\n";
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="sonyliv.m3u"');
header('Cache-Control: public, max-age=10');
header('Content-Length: ' . strlen($playlist));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $playlist;
