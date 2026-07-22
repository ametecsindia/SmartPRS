<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Self-Onboarding — Verification Console</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{--navy:#0c1929;--navy3:#1a3350;--accent:#f97316;--accent-soft:rgba(249,115,22,.1);
    --blue:#3b82f6;--green:#10b981;--amber:#f59e0b;--red:#ef4444;
    --bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--text1:#0f172a;--text2:#475569;--text3:#94a3b8;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'Plus Jakarta Sans',system-ui,Segoe UI,sans-serif;background:var(--bg);color:var(--text1)}
  .topbar{background:var(--navy);color:#fff;display:flex;align-items:center;gap:12px;padding:11px 18px;position:sticky;top:0;z-index:10}
  .mark{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),#ea580c);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800}
  .topbar b{font-weight:800} .topbar b span{color:var(--accent)}
  .topbar .t{font-size:13px;color:rgba(255,255,255,.65);font-family:'DM Sans',sans-serif}
  .topbar a{margin-left:auto;color:rgba(255,255,255,.8);font-size:12px;text-decoration:none;border:1px solid rgba(255,255,255,.2);padding:6px 12px;border-radius:8px}
  .layout{display:flex;gap:16px;max-width:1180px;margin:16px auto;padding:0 14px;align-items:flex-start}
  .list{width:340px;flex-shrink:0}
  .detail{flex:1;min-height:200px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 26px rgba(12,25,41,.05)}
  .list .card{padding:8px}
  .row{padding:11px 12px;border-radius:10px;cursor:pointer;border:1px solid transparent}
  .row:hover{background:#f8fafc} .row.on{background:var(--accent-soft);border-color:#f7d9bd}
  .row .nm{font-weight:700;font-size:14px} .row .meta{font-size:11.5px;color:var(--text2);font-family:'DM Sans',sans-serif;margin-top:3px;display:flex;gap:8px;flex-wrap:wrap}
  .pill{font-size:10.5px;font-weight:800;padding:2px 9px;border-radius:20px}
  .p-sub{background:#fff4e5;color:#b7791f} .p-cor{background:#fdecec;color:#c0392b} .p-ver{background:#eafaf0;color:#0b8a4b} .p-app{background:#e8f0fe;color:#2b62c9}
  .empty{padding:40px 16px;text-align:center;color:var(--text3);font-size:13px}
  .dhead{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .dhead h2{margin:0;font-size:18px} .dhead .code{font-size:12px;color:var(--text3);font-family:'DM Sans',sans-serif}
  .badges{display:flex;gap:6px;margin-left:auto;flex-wrap:wrap}
  .badge{font-size:11px;font-weight:800;padding:3px 9px;border-radius:20px}
  .badge.ok{background:#eafaf0;color:#0b8a4b} .badge.no{background:#f1f5f9;color:#94a3b8}
  .dbody{padding:16px 18px;display:flex;gap:18px;flex-wrap:wrap}
  .col{flex:1;min-width:240px}
  h3{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin:14px 0 8px}
  .kv{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
  .kv .k{color:var(--text2)} .kv .v{font-weight:600;text-align:right;max-width:60%}
  .selfie{width:120px;height:150px;border-radius:12px;object-fit:cover;border:1px solid var(--border);background:#f1f5f9}
  .doc{display:flex;align-items:center;gap:8px;border:1px solid var(--border);border-radius:9px;padding:8px 10px;margin-bottom:7px;font-size:13px}
  .doc a{margin-left:auto;color:var(--accent);font-weight:700;text-decoration:none;font-size:12px}
  .actions{padding:14px 18px;border-top:1px solid var(--border);display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:11px 16px;border-radius:10px;border:none;font-weight:700;font-size:13px;font-family:inherit;cursor:pointer}
  .btn.primary{background:linear-gradient(135deg,var(--accent),#ea580c);color:#fff}
  .btn.green{background:linear-gradient(135deg,#10b981,#059669);color:#fff}
  .btn.ghost{background:#fff;color:var(--text2);border:1.5px solid var(--border)}
  .corr{padding:14px 18px;border-top:1px solid var(--border);display:none}
  .corr.on{display:block}
  textarea{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:13px}
  .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--navy);color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;opacity:0;transition:opacity .2s;z-index:50}
  .toast.show{opacity:1}
</style>
</head>
<body>
<div class="topbar"><div class="mark">S</div><b>Smart<span>PRS</span></b><span class="t">Self-Onboarding · Verification Console</span><a href="{{ url('/') }}">← Back to app</a></div>
<div class="layout">
  <div class="list"><div class="card" id="listCard"><div class="empty">Loading…</div></div></div>
  <div class="detail"><div class="card" id="detailCard"><div class="empty">Select a submission on the left to review.</div></div></div>
</div>
<div class="toast" id="toast"></div>

<script>
(function(){
  var CSRF=document.querySelector('meta[name=csrf-token]').content;
  var curId=null;
  function $(s,r){return (r||document).querySelector(s);}
  function toast(m){var t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(t._h);t._h=setTimeout(function(){t.classList.remove('show');},2600);}
  function esc(s){return (s==null?'':String(s)).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
  function pill(s){var m={submitted:['p-sub','Pending verify'],correction:['p-cor','Correction sent'],verified:['p-ver','Verified'],approved:['p-app','Approved']};var x=m[s]||['p-sub',s];return '<span class="pill '+x[0]+'">'+x[1]+'</span>';}
  function api(path,opt){opt=opt||{};opt.headers=Object.assign({'X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},opt.headers||{});return fetch(path,opt).then(function(r){return r.json().catch(function(){return{ok:false,error:'Server error'};});});}

  function loadList(){
    api('{{ route('app.selfonboard.list') }}').then(function(r){
      var host=$('#listCard');
      if(!r.ok){host.innerHTML='<div class="empty">'+(r.error||'Could not load')+'</div>';return;}
      if(!r.rows.length){host.innerHTML='<div class="empty">No submissions yet.</div>';return;}
      host.innerHTML=r.rows.map(function(x){
        return '<div class="row" data-id="'+x.id+'"><div class="nm">'+esc(x.name||'—')+' '+pill(x.status)+'</div>'+
          '<div class="meta"><span>'+esc(x.temp_emp_code)+'</span><span>E '+(x.email_verified?'✔':'—')+' · M '+(x.mobile_verified?'✔':'—')+'</span><span>'+x.docs+' docs</span><span>'+(x.selfie?'selfie ✔':'no selfie')+'</span></div></div>';
      }).join('');
      Array.prototype.forEach.call(host.querySelectorAll('.row'),function(el){el.onclick=function(){select(el.getAttribute('data-id'));};});
      if(curId){var c=host.querySelector('.row[data-id="'+curId+'"]');if(c)c.classList.add('on');}
    });
  }

  function select(id){
    curId=id;
    Array.prototype.forEach.call(document.querySelectorAll('.list .row'),function(el){el.classList.toggle('on',el.getAttribute('data-id')===id);});
    api('/app/self-onboarding/'+id).then(function(r){
      if(!r.ok){toast(r.error||'Load failed');return;}
      renderDetail(r.rec);
    });
  }

  function kvBlock(title,obj,map){
    if(!obj)obj={};
    var rows=map.map(function(m){return '<div class="kv"><span class="k">'+m[1]+'</span><span class="v">'+esc(obj[m[0]]||'—')+'</span></div>';}).join('');
    return '<h3>'+title+'</h3>'+rows;
  }

  function renderDetail(rec){
    var d=rec.data||{};
    var badges='<span class="badge '+(rec.email_verified?'ok':'no')+'">Email '+(rec.email_verified?'✔':'—')+'</span>'+
      '<span class="badge '+(rec.mobile_verified?'ok':'no')+'">Mobile '+(rec.mobile_verified?'✔':'—')+'</span>'+
      '<span class="badge '+(rec.wa_verified?'ok':'no')+'">WhatsApp '+(rec.wa_verified?'✔':'—')+'</span>';
    var docs=(rec.docs||[]).length? rec.docs.map(function(x){return '<div class="doc"><span>'+esc(x.kind)+'</span><a href="'+x.url+'" target="_blank">View</a></div>';}).join('') : '<div style="color:#94a3b8;font-size:12px">No documents uploaded.</div>';
    var selfie=rec.selfie? '<img class="selfie" src="'+rec.selfie+'">' : '<div class="selfie"></div>';
    var flags=(rec.flags&&rec.flags.length)? '<div style="background:#fdecec;color:#c0392b;border-radius:9px;padding:8px 11px;font-size:12px;margin-bottom:8px">Awaiting correction: '+rec.flags.map(esc).join('; ')+'</div>' : '';

    var html='<div class="dhead"><h2>'+esc(rec.name||'—')+'</h2><span class="code">'+esc(rec.temp_emp_code)+' · '+pill(rec.status)+'</span><div class="badges">'+badges+'</div></div>'+
      '<div class="dbody"><div class="col">'+flags+
        kvBlock('Personal',d.personal,[['full_name','Full name'],['dob','Date of birth'],['gender','Gender'],['father_name','Father/Guardian'],['nationality','Nationality']])+
        kvBlock('Contact',d.contact,[['current_address','Current address'],['permanent_address','Permanent address'],['emergency_name','Emergency name'],['emergency_phone','Emergency phone']])+
        kvBlock('Statutory',d.statutory,[['pan','PAN'],['uan','UAN'],['aadhaar','Aadhaar/National ID']])+
        kvBlock('Bank',d.bank,[['acc_name','A/c name'],['acc_no','A/c number'],['ifsc','IFSC'],['bank_name','Bank']])+
      '</div><div class="col" style="max-width:200px"><h3>Selfie</h3>'+selfie+'<h3>Documents</h3>'+docs+'</div></div>'+
      '<div class="actions"><button class="btn ghost" id="askCorr">Request Correction</button><button class="btn green" id="doVerify">Mark Verified</button></div>'+
      '<div class="corr" id="corrBox"><h3>Items to correct (one per line)</h3><textarea id="corrItems" rows="3" placeholder="e.g. Education certificate is unreadable&#10;PAN does not match"></textarea>'+
        '<h3 style="margin-top:10px">Note (optional)</h3><textarea id="corrNote" rows="2"></textarea>'+
        '<div style="margin-top:10px;display:flex;gap:10px"><button class="btn primary" id="sendCorr">Send &amp; notify candidate</button><button class="btn ghost" id="cancelCorr">Cancel</button></div></div>';

    var host=$('#detailCard');host.innerHTML=html;
    if(rec.status==='verified'||rec.status==='approved'){$('#doVerify').textContent='Verified ✔';$('#doVerify').disabled=true;$('#doVerify').style.opacity=.6;}
    $('#askCorr').onclick=function(){$('#corrBox').classList.add('on');};
    $('#cancelCorr').onclick=function(){$('#corrBox').classList.remove('on');};
    $('#sendCorr').onclick=function(){
      var items=$('#corrItems').value.split('\n').map(function(s){return s.trim();}).filter(Boolean);
      if(!items.length){toast('Add at least one item');return;}
      this.disabled=true;
      api('/app/self-onboarding/'+rec.id+'/correction',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:items,note:$('#corrNote').value})}).then(function(r){
        if(!r.ok){toast(r.error||'Failed');return;}
        toast('Correction sent to candidate');loadList();select(rec.id);
      });
    };
    $('#doVerify').onclick=function(){
      this.disabled=true;
      api('/app/self-onboarding/'+rec.id+'/verify',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}).then(function(r){
        if(!r.ok){toast(r.error||'Failed');return;}
        toast('Marked verified');loadList();select(rec.id);
      });
    };
  }

  loadList();
})();
</script>
</body>
</html>
