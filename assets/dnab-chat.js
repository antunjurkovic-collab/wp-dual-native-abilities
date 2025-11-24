(function(){
  const __DNAB_CACHE = (window.__DNAB_CACHE = window.__DNAB_CACHE || { mr:{}, etag:{} });
  function $(id){ return document.getElementById(id); }
  function log(msg, cls){ const el=$('dnab-chat'); const div=document.createElement('div'); div.textContent=msg; if(cls) div.className=cls; el.appendChild(div); el.scrollTop = el.scrollHeight; }
  function logDetail(msg){ log('   > '+msg); }
  function paintLights(){
    function paint(el, ok){ if(!el) return; el.style.fontWeight='600'; el.style.padding='2px 6px'; el.style.borderRadius='10px'; el.style.marginLeft='4px'; el.style.background = ok ? '#dcfce7' : '#fee2e2'; el.style.color = ok ? '#166534' : '#991b1b'; el.textContent = (el.textContent||'') + ': ' + (ok ? 'OK' : 'Missing'); }
    try { paint($('dnab-light-dni'), DNAB.lights && DNAB.lights.dni); paint($('dnab-light-abilities'), DNAB.lights && DNAB.lights.abilities); paint($('dnab-light-aiclient'), DNAB.lights && DNAB.lights.ai_client); } catch(_){}
  }
  // removed estimated size/token helpers; only hard facts are logged
  function toSlug(text){ const raw=(text||'').toString().toLowerCase(); let s; try { s=raw.normalize('NFKD').replace(/[\u0300-\u036f]/g,''); } catch(_) { s=raw; } return s.replace(/[^a-z0-9\s-]/g,'').trim().replace(/[\s-]+/g,'-').replace(/^-+|-+$/g,''); }

  async function readMR(postId){
    const readStart = performance.now();
    const mrUrl = DNAB.restBase + 'mr/' + postId;
    const headers = { 'X-WP-Nonce': DNAB.nonce };
    if (__DNAB_CACHE.etag[postId]) headers['If-None-Match'] = '"'+__DNAB_CACHE.etag[postId]+'"';
    let res = await fetch(mrUrl, { headers });
    let mrData; let cid;
    if (res.status === 304 && __DNAB_CACHE.mr[postId]){
      log('   > Cache HIT: 304 Unchanged (reused cached MR)','ok');
      mrData = { mr: __DNAB_CACHE.mr[postId].mr, meta:{ etag: __DNAB_CACHE.mr[postId].etag } };
      cid = __DNAB_CACHE.mr[postId].etag;
    } else {
      if (!res.ok){ throw new Error('MR HTTP '+res.status); }
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
    if (ctx) logDetail(`Context: "${ctx}..."`);
    logDetail(`Lock Acquired: CID ${cid}`);
    return { mrData, cid };
  }

  async function doSummarize(postId){
    log(`[READ] Fetching MR for Post ${postId}...`, 'step');
    const { mrData, cid } = await readMR(postId);
    const thinkStart = performance.now();
    const res = await fetch(DNAB.restBase + 'agentic/summarize', { method:'POST', headers:{ 'Content-Type':'application/json','X-WP-Nonce':DNAB.nonce,'If-Match': cid ? '"'+cid+'"' : '' }, body: JSON.stringify({ post_id: postId, heading: 'Summary' }) });
    if (res.status === 412){
      logDetail(`ERROR: 412 Precondition Failed.`);
      logDetail(`Reason: The post was modified by another user while AI was thinking.`);
      logDetail(`Action: Re-acquire lock and retry.`);
      const reread = await readMR(postId);
      const res2 = await fetch(DNAB.restBase + 'agentic/summarize', { method:'POST', headers:{ 'Content-Type':'application/json','X-WP-Nonce':DNAB.nonce,'If-Match': '"'+reread.cid+'"' }, body: JSON.stringify({ post_id: postId, heading: 'Summary' }) });
      if (!res2.ok){ const t = await res2.text(); throw new Error(`Agentic HTTP ${res2.status}: ${t}`); }
      const out2 = await res2.json();
      const thinkMs2 = Math.round(performance.now() - thinkStart);
      logDetail(`Result: Success (200 OK)`);
      logDetail(`Duration: ${thinkMs2} ms`);
      log(`[WRITE] Appended summary paragraph.`, 'ok');
      log(`[SUCCESS] New CID: ${(out2.meta && out2.meta.etag) || (out2.mr && out2.mr.cid) || 'unknown'}.`, 'ok');
      return;
    }
    if (!res.ok){ const t = await res.text(); throw new Error(`Agentic HTTP ${res.status}: ${t}`); }
    const out = await res.json(); const thinkMs = Math.round(performance.now() - thinkStart);
    logDetail(`Result: Success (200 OK)`); logDetail(`Duration: ${thinkMs} ms`);
    log(`[WRITE] Appended summary paragraph.`, 'ok');
    if (out.summary){ log(`[THINK] Summary: ${out.summary.substring(0,120)}${out.summary.length>120?'...':''}`); }
    log(`[SUCCESS] New CID: ${(out.meta && out.meta.etag) || (out.mr && out.mr.cid) || 'unknown'}.`, 'ok');
  }

  async function doTitle(postId, maxLen){
    log(`[READ] Fetching MR for Post ${postId}...`, 'step');
    const { cid } = await readMR(postId);
    const thinkStart = performance.now();
    const res = await fetch(DNAB.restBase + 'title/generate', { method:'POST', headers:{ 'Content-Type':'application/json','X-WP-Nonce':DNAB.nonce,'If-Match': cid ? '"'+cid+'"' : '' }, body: JSON.stringify({ post_id: postId, max_len: maxLen }) });
    if (res.status === 412){ logDetail(`ERROR: 412 Precondition Failed.`); logDetail(`Reason: The post was modified by another user while AI was thinking.`); return; }
    if (!res.ok){ const t = await res.text(); throw new Error(`Title HTTP ${res.status}: ${t}`); }
    const out = await res.json(); let newTitle = (out && out.new_title) || '';
    if (out && out.no_change){ log(`[WRITE] Attempting Safe Update...`, 'step'); logDetail(`Constraint: If-Match "${cid}"`); logDetail(`No change — generated title equals current; skipped write.`); log(`[SUCCESS] Chain preserved. ETag unchanged: ${cid}.`, 'ok'); return; }
    if (newTitle.length > maxLen) newTitle = newTitle.slice(0, maxLen);
    const thinkMs = Math.round(performance.now() - thinkStart);
    logDetail(`Result: Success (200 OK)`);
    const provider = out.provider || (DNAB.lights && DNAB.lights.ai_client ? 'wp-ai-client' : 'Local Heuristic (AI Client not configured)');
    log(`[THINK] Provider: ${provider}`, 'step'); logDetail(`Duration: ${thinkMs} ms`);
    log(`[WRITE] Title updated: '${newTitle}'`, 'ok');
    log(`[SUCCESS] New CID: ${(out.meta && out.meta.etag) || 'unknown'}.`, 'ok');
  }

  async function doInsert(postId, index){
    log(`[READ] Fetching MR for Post ${postId}...`, 'step');
    const { cid } = await readMR(postId);
    log(`[WRITE] Inserting H2 at index ${index}...`, 'step');
    const payload = { post_id: postId, insert:'index', index, blocks:[{ type:'core/heading', level:2, content:'Key Takeaways' }] };
    const res = await fetch(DNAB.restBase + 'insert', { method:'POST', headers:{ 'Content-Type':'application/json','X-WP-Nonce':DNAB.nonce,'If-Match': cid ? '"'+cid+'"' : '' }, body: JSON.stringify(payload) });
    if (res.status === 412){ log(`[WRITE] Precondition Failed (412). Content changed. Re-reading MR...`, 'err'); const reread = await readMR(postId); const res2 = await fetch(DNAB.restBase+'insert', { method:'POST', headers:{ 'Content-Type':'application/json','X-WP-Nonce':DNAB.nonce,'If-Match':'"'+reread.cid+'"' }, body: JSON.stringify(payload) }); if (!res2.ok){ const t=await res2.text(); throw new Error(`Insert HTTP ${res2.status}: ${t}`);} const data2=await res2.json(); log(`[WRITE] Inserted at index ${data2.meta && data2.meta.inserted_at ? data2.meta.inserted_at : index}.`, 'ok'); log(`[SUCCESS] New CID: ${(data2.meta && data2.meta.etag) || (data2.mr && data2.mr.cid) || 'unknown'}.`, 'ok'); return; }
    if (!res.ok){ const t=await res.text(); throw new Error(`Insert HTTP ${res.status}: ${t}`); }
    const data = await res.json(); log(`[WRITE] Inserted at index ${(data.meta && data.meta.inserted_at) || index}.`, 'ok'); log(`[SUCCESS] New CID: ${(data.meta && data.meta.etag) || (data.mr && data.mr.cid) || 'unknown'}.`, 'ok');
  }

  function ready(){
    paintLights();
    const postEl = $('dnab-chat-post'); const idxEl = $('dnab-chat-index'); const maxEl = $('dnab-chat-max');
    const chipRead = $('dnab-chip-read'); if (chipRead){ chipRead.addEventListener('click', async ()=>{ const id=parseInt(postEl.value,10); if(!id) return; try{ await readMR(id);}catch(e){ log('[ERROR] '+e.message,'err'); } }); }
    const chipSum = $('dnab-chip-summarize'); if (chipSum){ chipSum.addEventListener('click', async ()=>{ const id=parseInt(postEl.value,10); if(!id) return; try{ await doSummarize(id);}catch(e){ log('[ERROR] '+e.message,'err'); } }); }
    const chipTitle = $('dnab-chip-title'); if (chipTitle){ chipTitle.addEventListener('click', async ()=>{ const id=parseInt(postEl.value,10); const max=parseInt(maxEl.value,10)||70; if(!id) return; try{ await doTitle(id,max);}catch(e){ log('[ERROR] '+e.message,'err'); } }); }
    const chipInsert = $('dnab-chip-insert'); if (chipInsert){ chipInsert.addEventListener('click', async ()=>{ const id=parseInt(postEl.value,10); const idx=parseInt(idxEl.value,10)||0; if(!id) return; try{ await doInsert(id,idx);}catch(e){ log('[ERROR] '+e.message,'err'); } }); }
    const sendBtn = $('dnab-chat-send'); if (sendBtn){ sendBtn.addEventListener('click', ()=>{ const t=$('dnab-chat-input'); if (t) t.value=''; }); }
    const clr = $('dnab-chat-clear'); if (clr){ clr.addEventListener('click', ()=>{ const c=$('dnab-chat'); if(c) c.textContent=''; }); }
  }
  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', ready); } else { ready(); }
})();
