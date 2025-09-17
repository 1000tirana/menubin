<?php
/* video_finder_ios.php
 * iOS/Safari/Chrome uyumlu: URL yapıştır → sunucu sayfayı çeker → video linklerini listeler.
 * Arar: <video/src>, <source>, <a href=*.mp4…>, <meta og:video>, preload linkleri, JSON-LD (contentUrl/embedUrl/url),
 * ayrıca <iframe src> içine tek seviye derin tarama (isteğe bağlı) yapabilir.
 */

/* ---------- Güvenlik: yalnıza http/https & SSRF koruması ---------- */
function safe_url($u){
  $u = trim($u ?? '');
  if($u === '') return null;
  if(!preg_match('~^https?://~i', $u)) $u = 'http://'.$u;
  $p = parse_url($u);
  if(!$p || empty($p['scheme']) || empty($p['host'])) return null;
  $scheme = strtolower($p['scheme']);
  if(!in_array($scheme, ['http','https'])) return null;
  // Host IP ise, özel aralıkları engelle
  $ip = @gethostbyname($p['host']);
  if($ip && filter_var($ip, FILTER_VALIDATE_IP)){
    $long = sprintf('%u', ip2long($ip));
    // Özel/yerel aralıklar
    $blocked = [
      ['0.0.0.0','2.255.255.255'],
      ['10.0.0.0','10.255.255.255'],
      ['127.0.0.0','127.255.255.255'],
      ['169.254.0.0','169.254.255.255'],
      ['172.16.0.0','172.31.255.255'],
      ['192.168.0.0','192.168.255.255'],
      ['224.0.0.0','255.255.255.255'],
      // CGNAT
      ['100.64.0.0','100.127.255.255'],
      // Loopback v6 vb. için basit kontrol (host adı IPv6 ise buraya gelmez)
    ];
    foreach($blocked as $r){
      $a=sprintf('%u', ip2long($r[0])); $b=sprintf('%u', ip2long($r[1]));
      if($long >= $a && $long <= $b) return null;
    }
  }
  return $u;
}

/* ---------- HTTP GET ---------- */
function http_get($url){
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (VideoFinder iOS/1.1)',
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_ENCODING       => '', // gzip/deflate
    CURLOPT_HEADER         => false,
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $final= curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);
  if($err || $code>=400 || !$body) return [null, null];
  return [$body, $final ?: $url];
}

/* ---------- Yardımcılar ---------- */
function abs_url($base, $rel){
  if(!$rel) return null;
  $rel = html_entity_decode($rel, ENT_QUOTES|ENT_HTML5, 'UTF-8');
  // Tam URL?
  if(preg_match('~^https?://~i', $rel)) return $rel;
  // //example.com
  if(strpos($rel, '//')===0){
    $bp = parse_url($base);
    return ($bp['scheme'] ?? 'https').':'.$rel;
  }
  $bp = parse_url($base);
  if(!$bp) return null;
  $scheme = $bp['scheme'] ?? 'https';
  $host   = $bp['host'] ?? '';
  $port   = isset($bp['port']) ? ':'.$bp['port'] : '';
  $path   = $bp['path'] ?? '/';
  $baseDir= preg_replace('/[^/]*$','/',$path);
  if(substr($rel,0,1) === '/') $abs = "$scheme://$host$port$rel";
  else $abs = "$scheme://$host$port$baseDir$rel";
  // normalize /./ and /../
  $abs = preg_replace('/\./','/',$abs);
  while(strpos($abs, '/../') !== false){
    $abs = preg_replace('/(?!\.\.)[^/]+/\.\./','/',$abs,1);
  }
  return $abs;
}
function is_video_ext($u){
  return (bool)preg_match('~\.(mp4|webm|ogg|ogv|m4v|mov|mp3)(\?|#|$)~i', $u);
}
function is_stream_ext($u){
  return (bool)preg_match('~\.(m3u8|mpd)(\?|#|$)~i', $u);
}
function add_result(&$arr, &$seen, $src, $from){
  if(!$src) return;
  if(isset($seen[$src])) return;
  $seen[$src] = 1;
  $arr[] = ['src'=>$src, 'from'=>$from];
}

/* ---------- Bir HTML sayfayı tara ---------- */
function scan_html($html, $baseUrl, &$results, &$seen){
  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $dom->loadHTML($html);
  $xp = new DOMXPath($dom);

  // <video src> ve <source>
  foreach($xp->query('//video[@src]') as $n){
    add_result($results,$seen, abs_url($baseUrl, $n->getAttribute('src')), '<video src>');
  }
  foreach($xp->query('//video//source[@src]') as $n){
    add_result($results,$seen, abs_url($baseUrl, $n->getAttribute('src')), '<source>');
  }

  // <a href="*.mp4 …">
  foreach($xp->query('//a[@href]') as $n){
    $u = abs_url($baseUrl, $n->getAttribute('href'));
    if($u && (is_video_ext($u) || is_stream_ext($u))){
      add_result($results,$seen, $u, '<a href>');
    }
  }

  // preload/og:video
  foreach($xp->query('//link[@as="video" and @href] | //link[@rel="preload" and @as="video" and @href]') as $n){
    add_result($results,$seen, abs_url($baseUrl, $n->getAttribute('href')), '<link as=video>');
  }
  foreach($xp->query('//meta[@property="og:video" and @content]') as $n){
    add_result($results,$seen, abs_url($baseUrl, $n->getAttribute('content')), '<meta og:video>');
  }

  // JSON-LD
  foreach($xp->query('//script[@type="application/ld+json"]') as $n){
    $txt = $n->textContent;
    if(!$txt) continue;
    $json = json_decode($txt, true);
    if(!$json) continue;
    $stack = [$json];
    while($stack){
      $cur = array_pop($stack);
      if(is_array($cur)){
        if(isset($cur['contentUrl'])) add_result($results,$seen, abs_url($baseUrl, $cur['contentUrl']), 'ld+json contentUrl');
        if(isset($cur['embedUrl']))   add_result($results,$seen, abs_url($baseUrl, $cur['embedUrl']),   'ld+json embedUrl');
        if(isset($cur['url'])){
          $u = abs_url($baseUrl, $cur['url']);
          if($u && (is_video_ext($u) || is_stream_ext($u))) add_result($results,$seen, $u, 'ld+json url');
        }
        foreach($cur as $v){ if(is_array($v)) $stack[] = $v; }
      }
    }
  }

  // Fallback regex (kaçan doğrudan linkler için, sınırlı sayıda)
  if(empty($results)){
    if(preg_match_all('~https?://[^\s"\'<>]+?\.(mp4|webm|ogg|ogv|m4v|mov|mp3|m3u8|mpd)(\?[^\s"\'<>]*)?~i', $html, $m)){
      $c=0; foreach($m[0] as $u){ add_result($results,$seen,$u,'regex'); if(++$c>60) break; }
    }
  }
}

/* ---------- İframe içeriğini de (opsiyonel) tara ---------- */
function deep_scan_iframes($html, $baseUrl, &$results, &$seen, $enabled){
  if(!$enabled) return;
  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $dom->loadHTML($html);
  $xp = new DOMXPath($dom);
  foreach($xp->query('//iframe[@src]') as $n){
    $src = abs_url($baseUrl, $n->getAttribute('src'));
    $safe = safe_url($src);
    if(!$safe) continue;
    list($h2,$final2) = http_get($safe);
    if($h2){
      scan_html($h2, $final2, $results, $seen);
    }
  }
}

/* ---------- İş akışı ---------- */
$url    = isset($_POST['url']) ? safe_url($_POST['url']) : null;
$deep   = !empty($_POST['deep']);
$showHLS= !empty($_POST['hls']);
$results = []; $seen = [];
$errMsg = null;
$final  = null;
$html   = null;

if($url){
  list($html, $final) = http_get($url);
  if(!$html) $errMsg = 'Sayfa alınamadı veya engellendi.';
  else{
    scan_html($html, $final, $results, $seen);
    deep_scan_iframes($html, $final, $results, $seen, $deep);
    // HLS/DASH gösterme tercihi
    if(!$showHLS){
      $results = array_values(array_filter($results, function($r){
        return !is_stream_ext($r['src']);
      }));
    }
  }
}

?>
<!doctype html>
<meta charset="utf-8">
<title>URL’den Video Bul &amp; İndir (iOS Uyumlu)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{--bg:#0f1115;--fg:#e8e8ea;--muted:#9aa0a6;--card:#151922;--bd:#263143;--accent:#8ab4ff}
  body{background:var(--bg);color:var(--fg);font:15px/1.55 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;margin:0;padding:24px}
  .wrap{max-width:920px;margin:0 auto}
  h1{margin:0 0 12px}
  .card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:16px}
  input[type=text]{width:100%;padding:12px;border-radius:10px;border:1px solid var(--bd);background:#0c0f14;color:#eaeaf0}
  .row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
  .btn{padding:10px 14px;border:0;background:#1f2840;color:#fff;border-radius:10px;cursor:pointer}
  label{font-size:13px;color:var(--muted)}
  ol{padding-left:20px}
  li{margin:10px 0}
  a.dl{color:var(--accent);text-decoration:none}
  code{font-size:12px;background:#0c0f14;border:1px solid #1b2130;padding:2px 5px;border-radius:6px}
  .note{color:var(--muted);font-size:13px;margin-top:8px}
</style>

<div class="wrap">
  <h1>URL’den Video Bul &amp; İndir</h1>
  <form method="post" class="card">
    <input type="text" name="url" placeholder="https://orneksite.com/sayfa" value="<?= htmlspecialchars($url ?? '',ENT_QUOTES|ENT_HTML5) ?>">
    <div class="row">
      <label><input type="checkbox" name="deep" <?= !empty($deep)?'checked':''; ?>> İframe içeriğini de tara (yavaş olabilir)</label>
      <label><input type="checkbox" name="hls" <?= !empty($showHLS)?'checked':''; ?>> HLS/DASH (.m3u8/.mpd) linklerini de göster</label>
      <button class="btn" type="submit">Ara</button>
    </div>
    <div class="note">Destek: mp4, webm, ogg/ogv, m4v, mov (+ isteğe bağlı m3u8/mpd). DRM/şifrelemeli veya <code>blob:</code> kaynaklı yayınlar indirilmez.</div>
  </form>

  <?php if($url && $errMsg): ?>
    <p class="note">Hata: <?= htmlspecialchars($errMsg,ENT_QUOTES|ENT_HTML5) ?></p>
  <?php endif; ?>

  <?php if($url && !$errMsg): ?>
    <div class="card" style="margin-top:12px">
      <b>Sayfa:</b> <code><?= htmlspecialchars($final ?? $url,ENT_QUOTES|ENT_HTML5) ?></code><br>
      <b>Bulunan bağlantılar:</b> <?= count($results) ?>
      <?php if($results): ?>
        <ol>
          <?php foreach($results as $r): 
            $ext = strtoupper(pathinfo(parse_url($r['src'],PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'DOSYA');
          ?>
          <li>
            <a class="dl" href="<?= htmlspecialchars($r['src'],ENT_QUOTES|ENT_HTML5) ?>" target="_blank" rel="noopener" download>İndir/Aç (<?= $ext ?>)</a>
            <div class="note"><code><?= htmlspecialchars($r['src'],ENT_QUOTES|ENT_HTML5) ?></code> — Kaynak: <?= htmlspecialchars($r['from'],ENT_QUOTES|ENT_HTML5) ?></div>
          </li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?>
        <div class="note">Video linki bulunamadı. Sayfa <code>blob:</code> kaynağı, sıkı CSP veya sadece DRM/HLS kullanıyor olabilir.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="note" style="margin-top:10px">
    İpucu: Bazı siteler “hotlink” engeli uygular; indirme bağlantısını yeni sekmede açman veya sağ tıklayıp “Bağlantıyı kopyala” demen gerekebilir.
  </div>
</div>
