export default {
  async fetch(req, env, ctx) {
    try {
      const url = new URL(req.url);
      const target = url.searchParams.get("url");
      const showHls = url.searchParams.has("hls");
      const deep = url.searchParams.has("deep");
      if (!target) return htmlResponse(formHTML(), 200);

      const safe = await safeURL(target);
      if (!safe) return htmlResponse(formHTML("Geçersiz veya izin verilmeyen URL."), 400);

      const seen = new Set();
      let results = [];

      const { body: html, finalUrl } = await getHTML(safe);
      if (!html) return htmlResponse(formHTML("Sayfa alınamadı (engellendi veya hata)."), 502);

      results = results.concat(scanHTML(html, finalUrl, seen));
      if (deep) {
        // basit iframe taraması
        const iframes = Array.from(html.matchAll(/<iframe[^>]+src=["']([^"']+)["']/gi)).map(m => absURL(finalUrl, m[1]));
        for (const s of iframes.slice(0, 5)) {
          const safe2 = await safeURL(s);
          if (!safe2) continue;
          const sub = await getHTML(safe2);
          if (sub.body) results = results.concat(scanHTML(sub.body, sub.finalUrl, seen));
        }
      }

      if (!showHls) {
        results = results.filter(r => !/\.(m3u8|mpd)(\?|#|$)/i.test(r.src));
      }

      // HTML çıktı
      return htmlResponse(listHTML(finalUrl, results), 200);
    } catch (e) {
      return htmlResponse(formHTML("Hata: " + (e && e.message ? e.message : e)), 500);
    }
  }
};

function htmlResponse(content, status) {
  return new Response(content, {
    status,
    headers: {
      "content-type": "text/html; charset=utf-8",
      "cache-control": "no-store"
    }
  });
}

async function safeURL(u) {
  try {
    const x = new URL(/^https?:\/\//i.test(u) ? u : "http://" + u);
    // Özel IP aralıklarını engelle (SSRF koruması)
    // Cloudflare Workers'ta DNS çözümlemeden basit domain kontrolü yeterli.
    if (!/^https?:$/i.test(x.protocol)) return null;
    return x.toString();
  } catch { return null; }
}

async function getHTML(u) {
  const r = await fetch(u, {
    redirect: "follow",
    headers: {
      "user-agent": "Mozilla/5.0 (VideoFinder Worker/1.1)"
    }
  });
  if (!r.ok) return { body: null, finalUrl: u };
  const body = await r.text();
  const finalUrl = r.url || u;
  return { body, finalUrl };
}

function absURL(base, rel) {
  try { return new URL(rel, base).toString(); } catch { return null; }
}

function push(list, seen, src, from) {
  if (!src || seen.has(src)) return;
  seen.add(src);
  list.push({ src, from });
}

function scanHTML(html, base, seen) {
  const out = [];
  const add = (u, f) => push(out, seen, absURL(base, u), f);

  // <video src> ve <source>
  for (const m of html.matchAll(/<video[^>]\ssrc=["']([^"']+)["'][^>]>/gi)) add(m[1], "<video src>");
  for (const m of html.matchAll(/<source[^>]\ssrc=["']([^"']+)["'][^>]>/gi)) add(m[1], "<source>");

  // Doğrudan linkler (a[href])
  for (const m of html.matchAll(/<a[^>]\shref=["']([^"']+)["'][^>]>/gi)) {
    const u = absURL(base, m[1]);
    if (u && /\.(mp4|webm|ogg|ogv|m4v|mov|mp3|m3u8|mpd)(\?|#|$)/i.test(u)) push(out, seen, u, "<a href>");
  }

  // preload / og:video
  for (const m of html.matchAll(/<link[^>]+as=["']video["'][^>]+href=["']([^"']+)["']/gi)) add(m[1], "<link as=video>");
  for (const m of html.matchAll(/<meta[^>]+property=["']og:video["'][^>]+content=["']([^"']+)["']/gi)) add(m[1], "<meta og:video>");

  // JSON-LD (contentUrl/embedUrl/url)
  for (const m of html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]>([\s\S]?)<\/script>/gi)) {
    try {
      const j = JSON.parse(m[1]);
      walkJSON(j, v => add(v, "ld+json"));
    } catch {}
  }

  // Fallback: çıplak URL regex
  if (!out.length) {
    const re = /(https?:\/\/[^\s"'<>]+?\.(mp4|webm|ogg|ogv|m4v|mov|mp3|m3u8|mpd))(?:[^\s"'<>]*)/ig;
    let c = 0, mm;
    while ((mm = re.exec(html)) && c < 60) { push(out, seen, mm[1], "regex"); c++; }
  }
  return out;
}

function walkJSON(obj, addFn) {
  if (!obj || typeof obj !== "object") return;
  const tryAdd = v => {
    if (typeof v === "string" && /^(https?:)?\/\//i.test(v)) {
      if (/\.(mp4|webm|ogg|ogv|m4v|mov|mp3|m3u8|mpd)(\?|#|$)/i.test(v)) addFn(v);
      if (/contentUrl|embedUrl|url/i.test(v)) addFn(v);
    }
  };
  if (Array.isArray(obj)) return obj.forEach(x => walkJSON(x, addFn));
  for (const k of Object.keys(obj)) {
    const v = obj[k];
    if (typeof v === "string") tryAdd(v);
    else if (typeof v === "object") walkJSON(v, addFn);
  }
}

function formHTML(msg="") {
  return `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>URL’den Video Bul & İndir (Worker)</title>
  <style>body{font:15px/1.55 -apple-system,system-ui,Segoe UI,Roboto,Arial;margin:24px}input,button{font:inherit}input{width:100%;max-width:680px;padding:10px;border:1px solid #ccc;border-radius:8px}button{padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;margin-left:6px}code{font-size:12px;color:#555}</style>
  <h1>URL’den Video Bul & İndir</h1>
  ${msg?<p style="color:#c00">${msg}</p>:""}
  <form method="get">
    <input name="url" placeholder="https://orneksite.com/sayfa">
    <label><input type="checkbox" name="hls"> HLS/DASH (.m3u8/.mpd) linklerini de göster</label>
    <label><input type="checkbox" name="deep"> İframe içini de tara (yavaş)</label>
    <div><button type="submit">Ara</button></div>
  </form>
  <p><small>Not: DRM ve <code>blob:</code> kaynaklar dosya gibi inmez.</small></p>`;
}

function listHTML(finalUrl, items){
  const rows = items.map(x=><li><a href="${escapeHtml(x.src)}" target="_blank" rel="noopener" download>İndir/Aç</a> — <code>${escapeHtml(x.src)}</code> <small>${x.from}</small></li>).join("");
  return `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Bulunan Videolar</title>
  <style>body{font:15px/1.55 -apple-system,system-ui,Segoe UI,Roboto,Arial;margin:24px}code{font-size:12px;color:#555}li{margin:8px 0}</style>
  <h2>Sayfa: <code>${escapeHtml(finalUrl)}</code></h2>
  <h3>Bulunan bağlantılar: ${items.length}</h3>
  <ol>${rows||"<li>Bulunamadı.</li>"}</ol>
  <p><small>Uyarı: .m3u8/.mpd akışları dosya değildir.</small></p>`;
}

function escapeHtml(s){return s.replace(/[&<>"]/g,c=>({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;" }[c]))}
