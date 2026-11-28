const C='usterki-v2';
const ASSETS=['./','index.html','manifest.webmanifest','icon-192.png','icon-512.png','apple-touch-icon.png','vendor/pdfmake.min.js','vendor/vfs_fonts.js'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(ASSETS)).then(()=>self.skipWaiting()))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(x=>x.startsWith('usterki-')&&x!==C).map(x=>caches.delete(x)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',e=>{
  const r=e.request;if(r.method!=='GET')return;
  const u=new URL(r.url);
  // the NAS server (api.php) always goes straight to the network: shared data must never come from a cache
  if(u.pathname.endsWith('/api.php'))return;
  if(u.origin===location.origin){
    // network first for the app itself, cache as fallback (works offline on site)
    e.respondWith(fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(C).then(c=>c.put(r,cp))}return res})
      .catch(()=>caches.match(r).then(m=>m||caches.match('index.html'))));
  }else if(u.hostname==='fonts.googleapis.com'||u.hostname==='fonts.gstatic.com'){
    e.respondWith(caches.match(r).then(m=>m||fetch(r).then(res=>{const cp=res.clone();caches.open(C).then(c=>c.put(r,cp));return res})));
  }
});
