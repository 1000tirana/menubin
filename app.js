const $ = (id)=>document.getElementById(id);

// Sot (etiketë)
const now = new Date();
$("today").textContent = now.toLocaleDateString("sq-AL",{day:"2-digit",month:"short",year:"numeric"});

// Parazgjedhje: lista popullore nga TIA (ndrysho kur të duash)
const DEFAULT_DESTS = [
  "ATH","SKG","BEG","SOF","BUD","VIE","MXP","FCO","BGY","VCE",
  "PRG","BER","IST","SAW","STN","LGW","BCN","MAD","PSA","FRA","MUC"
];
const STORAGE_KEY = "tia_dests_v1";

function getDests(){
  const s = localStorage.getItem(STORAGE_KEY);
  let arr = s ? s.split(",").map(x=>x.trim().toUpperCase()).filter(Boolean) : DEFAULT_DESTS;
  arr = Array.from(new Set(arr)); // unik
  return arr;
}
function setDests(list){
  localStorage.setItem(STORAGE_KEY, list.join(","));
}

function updateChips(){
  const dests = getDests();
  $("chips").innerHTML = dests.map(d=><span class="chip">${d}</span>).join("");
  $("destCount").textContent = dests.length;
}

// Lidhje pa data: fokus “from today onwards”
function gFlightsLink(from,to){
  const q = encodeURIComponent(flights from ${from} to ${to} from today);
  return https://www.google.com/travel/flights?q=${q}&hl=sq;
}
function skyscannerLink(from,to){
  return https://www.skyscanner.net/transport/flights/${from.toLowerCase()}/${to.toLowerCase()}/?locale=sq-AL&currency=EUR;
}
function kiwiLink(from,to){
  return https://www.kiwi.com/en/search/results/${from}-anytime/${to}-anytime?sortBy=price;
}

function buildLinks(){
  const from = "TIA";
  const dests = getDests();
  const out = [];
  dests.forEach(to=>{
    out.push({label:${from} → ${to} · Google Flights, url:gFlightsLink(from,to)});
    out.push({label:${from} → ${to} · Skyscanner,     url:skyscannerLink(from,to)});
    out.push({label:${from} → ${to} · Kiwi,            url:kiwiLink(from,to)});
  });
  return out;
}

function renderLinks(){
  const links = buildLinks();
  const list = $("list");
  list.innerHTML = "";
  links.forEach(l=>{
    const a = document.createElement("a");
    a.href = l.url; a.target = "_blank"; a.rel = "noopener"; a.textContent = l.label;
    list.appendChild(a);
  });
  $("count").textContent = ${links.length} lidhje;
  $("results").style.display = "block";
}

// Sheet controls
function openSheet(open){ $("sheet").classList.toggle("open", !!open); }
$("editBtn").addEventListener("click", ()=>{
  $("destsBox").value = getDests().join(", ");
  openSheet(true);
});
$("closeSheet").addEventListener("click", ()=>openSheet(false));
$("saveDests").addEventListener("click", ()=>{
  const raw = $("destsBox").value.split(",").map(s=>s.trim().toUpperCase()).filter(Boolean);
  const uniq = Array.from(new Set(raw));
  if(uniq.length===0){ alert("Vendos të paktën një destinacion."); return; }
  setDests(uniq);
  updateChips();
  openSheet(false);
});

// Buttons
$("goBtn").addEventListener("click", renderLinks);
$("fabGo").addEventListener("click", renderLinks);

$("openAllBtn").addEventListener("click", ()=>{
  const anchors = Array.from(document.querySelectorAll("#list a"));
  if(anchors.length===0){ renderLinks(); }
  const links = Array.from(document.querySelectorAll("#list a")).map(a=>a.href);
  if(links.length===0){ alert('Së pari shtyp “Kërko fluturime të lira”.'); return; }
  if(!confirm(Do të hapen ${links.length} dritare/sekme. Vazhdon?)) return;
  links.forEach((u,i)=> setTimeout(()=>window.open(u,"_blank"), i*160));
});

$("copyBtn").addEventListener("click", async ()=>{
  const anchors = Array.from(document.querySelectorAll("#list a"));
  if(anchors.length===0){ renderLinks(); }
  const text = Array.from(document.querySelectorAll("#list a")).map(a=>a.href).join("\n");
  try{ await navigator.clipboard.writeText(text); alert("Lidhjet u kopjuan ✅"); }
  catch{ alert("Nuk u kopjua. Kopjoji manualisht nga lista."); }
});

// Init
updateChips();
$("fromTag").textContent = "TIA";

// (Opsionale) faqja të gjenerojë menjëherë listën kur hapet:
// window.addEventListener("load", renderLinks);
