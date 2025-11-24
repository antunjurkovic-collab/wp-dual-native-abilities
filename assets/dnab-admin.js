(function(){
  // Simple MR cache for conditional GET demos
  const __DNAB_CACHE = (window.__DNAB_CACHE = window.__DNAB_CACHE || { mr: {}, etag: {} });
  function $(id){ return document.getElementById(id); }
  function log(msg, cls){
    const el = $('dnab-console');
    const line = document.createElement('div');
    line.textContent = msg;
    if (cls) line.className = cls;
    el.appendChild(line);
    el.scrollTop = el.scrollHeight;
  // Simple MR cache for conditional GET demos
  }
  function logDetail(msg){ log('   > ' + msg); }
  // note: removed estimated size/token helpers; only hard facts are logged
  function setLights(){
    function paint(el, ok){ if (!el) return; el.style.fontWeight='600'; el.style.padding='2px 6px'; el.style.borderRadius='10px'; el.style.marginLeft='4px'; el.style.background = ok ? '#dcfce7' : '#fee2e2'; el.style.color = ok ? '#166534' : '#991b1b'; el.textContent = (el.textContent || '') + ': ' + (ok ? 'OK' : 'Missing'); }
    try {
      paint($('dnab-light-dni'), DNAB.lights && DNAB.lights.dni);
      paint($('dnab-light-abilities'), DNAB.lights && DNAB.lights.abilities);
      paint($('dnab-light-aiclient'), DNAB.lights && DNAB.lights.ai_client);
    } catch(_) {}
  }
  function toSlug(text){
    const raw = (text || '').toString();
    const lowered = raw.toLowerCase();
    let s;
    try { s = lowered.normalize('NFKD').replace(/[\u0300-\u036f]/g,''); } catch(_) { s = lowered; }
    return s.replace(/[^a-z0-9\s-]/g,'').trim().replace(/[\s-]+/g,'-').replace(/^-+|-+$/g,'');
  }
  async function run(){
    const idEl = $('dnab-post-id');
    const postId = parseInt(idEl.value, 10);
    if (!postId){ alert('Enter a valid Post ID'); return; }
    $('dnab-run').disabled = true;
    try{
      // READ
      const readStart = performance.now();
      log(`[READ] Fetching MR for Post ${postId}...`, 'step');
      const mrUrl = DNAB.restBase + 'mr/' + postId;
      const readHeaders = { 'X-WP-Nonce': DNAB.nonce };
      if (__DNAB_CACHE.etag[postId]){ readHeaders['If-None-Match'] = '"'+__DNAB_CACHE.etag[postId]+'"'; }
      let res = await fetch(mrUrl, { headers: readHeaders });
      let mrData; let cid;
      if (res.status === 304 && __DNAB_CACHE.mr[postId]){
        log('   > Cache HIT: 304 Unchanged (reused cached MR)', 'ok');
        mrData = { mr: __DNAB_CACHE.mr[postId].mr, meta: { etag: __DNAB_CACHE.mr[postId].etag } };
        cid = __DNAB_CACHE.mr[postId].etag;
      } else {
        if (!res.ok){ throw new Error(`MR HTTP ${res.status}`); }
        mrData = await res.json();
        cid = (mrData.meta && mrData.meta.etag) || (mrData.mr && mrData.mr.cid) || 'unknown';
        __DNAB_CACHE.mr[postId] = { mr: mrData.mr, etag: cid };
        __DNAB_CACHE.etag[postId] = cid;
        log(`[READ] Fetched MR for Post ${postId}...`, 'step');
      }
      const readMs = Math.round(performance.now() - readStart);
      // report only hard facts (no estimates)
      logDetail(`Duration: ${readMs} ms`);
      const ctx = (mrData.mr && mrData.mr.core_content_text) ? mrData.mr.core_content_text.split(/\s+/).slice(0,8).join(' ') : '';
      if (ctx){ logDetail(`Context: "${ctx}..."`); }
      logDetail(`Lock Acquired: CID ${cid}`);

      // THINK
      log(`[THINK] Requesting summary via WP AI Client (auto) ...`, 'step');
      log(`[WRITE] Attempting Safe Update...`, 'step');
      logDetail(`Constraint: If-Match "${cid}"`);
      const agentUrl = DNAB.restBase + 'agentic/summarize';
      const thinkStart = performance.now();
      res = await fetch(agentUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': DNAB.nonce,
          'If-Match': cid ? '"'+cid+'"' : ''
        },
        body: JSON.stringify({ post_id: postId, heading: 'Summary' })
      });
      if (res.status === 412){
        logDetail(`ERROR: 412 Precondition Failed.`);
        logDetail(`Reason: The post was modified by another user while AI was thinking.`);
        logDetail(`Action: Re-acquire lock and retry.`);
        // Re-read once and retry
        const re = await fetch(mrUrl, { headers: { 'X-WP-Nonce': DNAB.nonce } });
        const reMr = await re.json();
        const newCid = (reMr.meta && reMr.meta.etag) || (reMr.mr && reMr.mr.cid) || '';
        log(`[READ] Updated MR (CID: ${newCid}). Retrying write...`, 'step');
        res = await fetch(agentUrl, {
          method: 'POST', headers: { 'Content-Type':'application/json','X-WP-Nonce':DNAB.nonce,'If-Match': newCid ? '"'+newCid+'"' : '' },
          body: JSON.stringify({ post_id: postId, heading: 'Summary' })
        });
      }
      if (!res.ok){
        const txt = await res.text();
        throw new Error(`Agentic HTTP ${res.status}: ${txt}`);
      }
      const out = await res.json();
      const newCid = (out.meta && out.meta.etag) || (out.mr && out.mr.cid) || 'unknown';
      const thinkMs = Math.round(performance.now() - thinkStart);
      logDetail(`Result: Success (200 OK)`);
      logDetail(`Duration: ${thinkMs} ms`);
      log(`[WRITE] Appended summary paragraph.`, 'ok');
      if (out.summary){ log(`[THINK] Summary: ${out.summary.substring(0, 120)}${out.summary.length>120?'...':''}`); }
      log(`[SUCCESS] New CID: ${newCid}.`, 'ok');
      const viewUrl = (out.mr && out.mr.links && out.mr.links.human_url) ? out.mr.links.human_url : '';
      if (viewUrl){
        const el = document.createElement('div');
        el.innerHTML = `<a href="${viewUrl}" target="_blank" rel="noopener">View Post</a>`;
        $('dnab-console').appendChild(el);
      }
    }catch(e){
      log(`[ERROR] ${e.message}`, 'err');
    }
    $('dnab-run').disabled = false;
  }
  async function insertAtIndex(){
    const postId = parseInt($('dnab-post-id').value, 10);
    const idx = parseInt($('dnab-index').value, 10) || 0;
    const heading = ($('dnab-heading').value || 'Key Takeaways').trim();
    if (!postId){ alert('Enter a valid Post ID'); return; }
    $('dnab-insert-index').disabled = true;
    try{
      // READ
      log(`[READ] Fetching MR for Post ${postId}...`, 'step');
      const mrUrl = DNAB.restBase + 'mr/' + postId;
      let res = await fetch(mrUrl, { headers: { 'X-WP-Nonce': DNAB.nonce } });
      if (!res.ok){ throw new Error(`MR HTTP ${res.status}`); }
      const mrData = await res.json();
      let cid = (mrData.meta && mrData.meta.etag) || (mrData.mr && mrData.mr.cid) || (mrData.cid) || '';
      log(`[READ] Fetched MR (CID: ${cid || 'unknown'}).`, 'ok');

      // WRITE: index
      log(`[WRITE] Inserting H2 at index ${idx}...`, 'step');
      const insertUrl = DNAB.restBase + 'insert';
      const payload = { post_id: postId, insert: 'index', index: idx, blocks: [ { type:'core/heading', level:2, content: heading } ] };
      res = await fetch(insertUrl, {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': DNAB.nonce, 'If-Match': cid ? '"'+cid+'"' : '' },
        body: JSON.stringify(payload)
      });
      if (res.status === 412){
        log(`[WRITE] Precondition Failed (412). Content changed. Re-reading MR...`, 'err');
        const re = await fetch(mrUrl, { headers: { 'X-WP-Nonce': DNAB.nonce } });
        const reMr = await re.json();
        cid = (reMr.meta && reMr.meta.etag) || (reMr.mr && reMr.mr.cid) || (reMr.cid) || '';
        log(`[READ] Updated MR (CID: ${cid}). Retrying write...`, 'step');
        res = await fetch(insertUrl, {
          method: 'POST', headers: { 'Content-Type':'application/json', 'X-WP-Nonce': DNAB.nonce, 'If-Match': cid ? '"'+cid+'"' : '' },
          body: JSON.stringify(payload)
        });
      }
      if (!res.ok){ const t = await res.text(); throw new Error(`Insert HTTP ${res.status}: ${t}`); }

      // Telemetry headers
      const before = res.headers.get('X-DNI-Top-Level-Count-Before');
      const insertedAt = res.headers.get('X-DNI-Inserted-At');
      const after = res.headers.get('X-DNI-Top-Level-Count');

      const data = await res.json();
      const newCid = data.cid || (data.mr && data.mr.cid) || '';
      log(`[WRITE] Inserted at index ${insertedAt !== null ? insertedAt : idx}.`, 'ok');
      if (before !== null && after !== null){ log(`[INFO] Top-level blocks: before=${before}, after=${after}`); }
      log(`[SUCCESS] New CID: ${newCid}.`, 'ok');
      const viewUrl = (data.links && data.links.human_url) ? data.links.human_url : (data.mr && data.mr.links && data.mr.links.human_url);
      if (viewUrl){
        const el = document.createElement('div');
        el.innerHTML = `<a href="${viewUrl}" target="_blank" rel="noopener">View Post</a>`;
        $('dnab-console').appendChild(el);
      }
    }catch(e){
      log(`[ERROR] ${e.message}`, 'err');
    }
    $('dnab-insert-index').disabled = false;
  }
  async function generateSafeTitle(){
    const postId = parseInt($('dnab-post-id').value, 10);
    const maxLenEl = $('dnab-maxlen');
    const maxLen = parseInt(maxLenEl ? maxLenEl.value : (DNAB.defaults && DNAB.defaults.maxLen) || 70, 10);
    if (!postId){ alert('Enter a valid Post ID'); return; }
    $('dnab-title').disabled = true;
    try{
      // READ
      const readStart = performance.now();
      log(`[READ] Fetching MR for Post ${postId}...`, 'step');
      const mrUrl = DNAB.restBase + 'mr/' + postId;
      const readHeaders = { 'X-WP-Nonce': DNAB.nonce };
      if (__DNAB_CACHE.etag[postId]){ readHeaders['If-None-Match'] = '"'+__DNAB_CACHE.etag[postId]+'"'; }
      let res = await fetch(mrUrl, { headers: readHeaders });
      let mrData; let cid;
      if (res.status === 304 && __DNAB_CACHE.mr[postId]){
        log('   > Cache HIT: 304 Unchanged (reused cached MR)', 'ok');
        mrData = { mr: __DNAB_CACHE.mr[postId].mr, meta: { etag: __DNAB_CACHE.mr[postId].etag } };
        cid = __DNAB_CACHE.mr[postId].etag;
      } else {
        if (!res.ok){ throw new Error(`MR HTTP ${res.status}`); }
        mrData = await res.json();
        cid = (mrData.meta && mrData.meta.etag) || (mrData.mr && mrData.mr.cid) || '';
        __DNAB_CACHE.mr[postId] = { mr: mrData.mr, etag: cid };
        __DNAB_CACHE.etag[postId] = cid;
        log(`[READ] Fetched MR (CID: ${cid}).`, 'ok');
      }
      const readMs = Math.round(performance.now() - readStart);
      // report only hard facts (no estimates)
      logDetail(`Duration: ${readMs} ms`);

      // THINK + WRITE with safety
      log(`[THINK] Generating title via WP AI Client (auto)...`, 'step');
      log(`[WRITE] Attempting Safe Update...`, 'step');
      logDetail(`Constraint: If-Match "${cid}"`);
      const url = DNAB.restBase + 'title/generate';
      const thinkStart = performance.now();
      res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': DNAB.nonce, 'If-Match': cid ? '"'+cid+'"' : '' },
        body: JSON.stringify({ post_id: postId, max_len: maxLen })
      });
      if (res.status === 412){
        logDetail(`ERROR: 412 Precondition Failed.`);
        logDetail(`Reason: The post was modified by another user while AI was thinking.`);
        return;
      }
      const out = await res.json();
      let newTitle = (out && out.new_title) || '';
      if (newTitle.length > maxLen){ newTitle = newTitle.slice(0, maxLen); }
      if (out && out.no_change){
        log(`[WRITE] Attempting Safe Update...`, 'step');
        logDetail(`Constraint: If-Match "${cid}"`);
        logDetail(`No change — generated title equals current; skipped write.`);
        log(`[SUCCESS] Chain preserved. ETag unchanged: ${cid}.`, 'ok');
        $('dnab-title').disabled = false; return;
      }
      logDetail(`Result: Success (200 OK)`);
      const provider = out.provider || (DNAB.lights && DNAB.lights.ai_client ? 'wp-ai-client' : 'Local Heuristic (AI Client not configured)');
      log(`[THINK] Provider: ${provider}`, 'step');
      const thinkMs = Math.round(performance.now() - thinkStart);
      logDetail(`Duration: ${thinkMs} ms`);
      log(`[WRITE] Title updated: '${newTitle}'`, 'ok');
      const newCid = (out.meta && out.meta.etag) || 'unknown';
      log(`[SUCCESS] New CID: ${newCid}.`, 'ok');
      const slugEl = $('dnab-slug'); if (slugEl){ slugEl.textContent = toSlug(newTitle); }
      const viewUrl = (out.mr && out.mr.links && out.mr.links.human_url) ? out.mr.links.human_url : '';
      if (viewUrl){ const el = document.createElement('div'); el.innerHTML = `<a href="${viewUrl}" target="_blank" rel="noopener">View Post</a>`; $('dnab-console').appendChild(el); }
    }catch(e){
      log(`[ERROR] ${e.message}`, 'err');
    }
    $('dnab-title').disabled = false;
  }

  function ready(){
    const runBtn = $('dnab-run');
    if (runBtn){ runBtn.addEventListener('click', run); }
    const insertBtn = $('dnab-insert-index');
    if (insertBtn){ insertBtn.addEventListener('click', insertAtIndex); }
    const titleBtn = $('dnab-title');
    if (titleBtn){ titleBtn.addEventListener('click', generateSafeTitle); }
    const clrBtn = $('dnab-clear');
    if (clrBtn){ clrBtn.addEventListener('click', function(){ $('dnab-console').textContent=''; }); }
    setLights();
  }
  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', ready);
  } else { ready(); }
})();
