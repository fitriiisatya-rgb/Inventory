/* ============================================================================
   TRACE STOK AWAL — READ ONLY
   Cara pakai: buka inventory.html di browser (data Anda sudah termuat),
   tekan F12 -> tab Console -> tempel SELURUH isi file ini -> Enter.

   Tidak menulis apa pun: tidak memanggil persist(), tidak mengubah
   transactionLog / stockBatches / closings / officialOpenings / transfers.
   Hanya membaca dan mencetak.
   Hasil lengkap juga tersimpan di variabel window.__TRACE  (ketik: copy(__TRACE))
   ============================================================================ */
(function(){
  "use strict";
  const PERIODE = '2026-09';
  const CUT     = new Date(2026, 8, 1, 0,0,0,0).getTime();   // 1 Sep 2026 00:00
  const AGU_AKHIR = new Date(2026, 7, 31, 23,59,59,999).getTime();
  const L = [];
  const say = t => { L.push(t); console.log(t); };
  const rp  = v => (v==null||!isFinite(v)) ? '-' : 'Rp '+Math.round(v).toLocaleString('id-ID');
  const rp2 = v => (v==null||!isFinite(v)) ? '-' : 'Rp '+Number(v).toLocaleString('id-ID',{maximumFractionDigits:2});
  const num = v => (v==null||!isFinite(v)) ? '-' : Number(v).toLocaleString('id-ID',{maximumFractionDigits:3});
  const tgl = v => v ? new Date(v).toLocaleDateString('id-ID') : '-';
  const pad = (s,n) => String(s==null?'':s).padEnd(n).slice(0,n);
  const lpad= (s,n) => { s=String(s==null?'':s); return s.length>=n ? s : s.padStart(n); };
  const hr  = () => say('-'.repeat(170));
  const H   = t => { say(''); say('='.repeat(170)); say(t); say('='.repeat(170)); };

  if (typeof stokAwalRows !== 'function') { console.error('Jalankan ini di halaman inventory.html yang sudah terbuka.'); return; }

  // ---------------------------------------------------------------- 0. SUMBER
  H('0. SUMBER ANGKA YANG DIPAKAI DASHBOARD');
  const beku = (typeof officialOpenings!=='undefined' && officialOpenings) ? officialOpenings[PERIODE] : null;
  const dash = (typeof getOpeningSnapshot==='function') ? getOpeningSnapshot(PERIODE) : null;
  const ulang = getInventorySnapshotAt(CUT);                    // hasil hitung ulang mesin
  say('Opening Resmi beku tersimpan : ' + (beku ? 'YA — '+rp(beku.totalValue)+' · '+(beku.rows||[]).length+' baris · sumber "'+(beku.source||'-')+'" · dibuat '+(beku.createdAt?new Date(beku.createdAt).toLocaleString('id-ID'):'-') : 'TIDAK'));
  say('Dashboard Opening (KPI)      : ' + (dash ? (dash.ok ? rp(dash.totalValue)+'  · dasar: '+dash.dasar : 'TIDAK LENGKAP — '+dash.warning) : '-'));
  say('Hitung ulang dari mesin      : ' + (ulang.ok ? rp(ulang.totalValue)+'  · dasar: '+ulang.dasar : 'GAGAL — '+ulang.warning));
  say('lockBefore                   : ' + (typeof lockBefore!=='undefined' && lockBefore ? new Date(lockBefore).toLocaleString('id-ID') : '0 (tidak ada)'));
  say('Jumlah jangkar               : ' + (typeof daftarAnchor==='function' ? daftarAnchor().length : '-'));

  // baris yang dipakai: prioritas persis seperti yang dilihat user di layar
  const snapDash = (dash && dash.ok) ? dash : ulang;
  const rows = (typeof barisDariSnapshot==='function') ? barisDariSnapshot(snapDash) : stokAwalRows(CUT);

  // ------------------------------------------------- helper harga pembanding
  function masterPerBase(sku){
    const it = itemBySku(sku); if(!it) return {v:null, ket:'SKU tidak ada di Master'};
    if(!(it.lastBuyPrice>0)) return {v:null, ket:'lastBuyPrice kosong'};
    const isi = it.buyContent>0 ? it.buyContent : 1;
    return { v: it.lastBuyPrice/isi, ket:'Rp'+Math.round(it.lastBuyPrice).toLocaleString('id-ID')+' / '+num(isi)+' '+(it.baseUnit||''), isi, perKemasan:it.lastBuyPrice, kemasan:it.buyUnit||it.buyPack||'' };
  }
  function beliTerakhirSd(sku, gudang, ts){
    let best=null;
    (transactionLog||[]).forEach(l=>{
      if(l.sku!==sku || l.type!=='in') return;
      if(l.nonStockMovement || l.inventoryMovement===false) return;
      if((l.source||'beli')!=='beli') return;
      if(!(l.qty>0) || !(l.total>0)) return;
      if(effTs(l) > ts) return;
      if(!best || effTs(l)>effTs(best)) best=l;
    });
    if(!best) return {v:null};
    return { v: best.total/best.qty, at: effTs(best), ref: best.ref||'', gudang: best.gudang||DEFAULT_GUDANG };
  }

  // ------------------------------------------------------------- 1 + 2. TOP 50
  H('1+2. TOP 50 NILAI OPENING 1 SEP 2026  (sort DESC berdasarkan Nilai)');
  const kaya = rows.slice().sort((a,b)=>Math.abs(b.nilai)-Math.abs(a.nilai));
  const top50 = kaya.slice(0,50).map(r=>{
    const m = masterPerBase(r.sku);
    const b = beliTerakhirSd(r.sku, r.gudang, AGU_AKHIR);
    // PENTING: dibandingkan ke DUA-DUANYA. Kalau harga salah sudah masuk sejak log pembelian,
    // rasio terhadap log pembelian akan 1x dan kesalahannya lolos — hanya rasio terhadap Master
    // yang masih bisa memergokinya (dan sebaliknya kalau Master yang salah).
    const rBeli   = (b.v>0 && isFinite(r.cost)) ? r.cost/b.v : null;
    const rMaster = (m.v>0 && isFinite(r.cost)) ? r.cost/m.v : null;
    const buruk = x => x!=null && (x>5 || x<0.2);
    const flag = buruk(rBeli) || buruk(rMaster);
    const pembanding = (b.v>0) ? b.v : m.v;
    const asal = (b.v>0) ? 'beli<=31Agu' : (m.v>0 ? 'master' : '-');
    const ratio = (pembanding>0 && isFinite(r.cost)) ? r.cost/pembanding : null;
    return {...r, masterUnit:m.v, masterKet:m.ket, masterPerKemasan:m.perKemasan, isi:m.isi,
            beliUnit:b.v, beliAt:b.at, beliRef:b.ref, pembanding, asalPembanding:asal,
            ratio, rBeli, rMaster, flag};
  });
  say(pad('SKU',16)+pad('Nama',30)+pad('Gudang',16)+lpad('Qty',14)+lpad('Cost/unit',16)+lpad('Nilai',18)+'  '+pad('Cost Source',30)+pad('Cost Date',12));
  hr();
  top50.forEach(r=>{
    say(pad(r.sku,16)+pad(r.nama,30)+pad(r.gudangNama,16)+lpad(num(r.qty),14)+lpad(rp2(r.cost),16)+lpad(rp(r.nilai),18)+'  '
        +pad(r.costSource||(r.costUnknown?'TIDAK DIKETAHUI':'-'),30)+pad(tgl(r.costAt),12));
  });
  say('');
  say('TAMBAHAN — pembanding harga & rasio (FLAG bila rasio >5x atau <0,2x):');
  const rx = v => v==null ? '-' : (Math.round(v*100)/100)+'x';
  say(pad('SKU',16)+pad('Gudang',16)+lpad('Opening cost',16)+lpad('Master/base',16)+lpad('Beli<=31Agu',16)+lpad('Rasio/master',14)+lpad('Rasio/beli',14)+'  FLAG');
  hr();
  top50.forEach(r=>{
    say(pad(r.sku,16)+pad(r.gudangNama,16)+lpad(rp2(r.cost),16)+lpad(rp2(r.masterUnit),16)+lpad(rp2(r.beliUnit),16)
        +lpad(rx(r.rMaster),14)+lpad(rx(r.rBeli),14)+'  '+(r.flag?'🔴 FLAG':''));
  });
  const flagged = top50.filter(r=>r.flag);
  say('');
  say('Jumlah baris ber-FLAG di Top 50: ' + flagged.length);

  // cek khusus: apakah harga per kemasan dipakai sebagai harga per satuan dasar?
  const dugaanKemasan = top50.filter(r=>{
    if(!(r.masterPerKemasan>0) || !(r.isi>1) || !isFinite(r.cost)) return false;
    const rasioKeKemasan = r.cost / r.masterPerKemasan;
    return rasioKeKemasan > 0.8 && rasioKeKemasan < 1.25;     // cost/unit ~= harga SATU KEMASAN
  });
  say('');
  say('DUGAAN "harga per Ctn/Kg/Pack dipakai sebagai harga per satuan dasar": ' + dugaanKemasan.length + ' baris');
  if(dugaanKemasan.length){
    say(pad('SKU',16)+pad('Gudang',14)+lpad('Cost/unit',16)+lpad('Harga/kemasan',16)+lpad('Isi',12)+lpad('Seharusnya',16)+lpad('Salah x',10));
    hr();
    dugaanKemasan.forEach(r=>say(pad(r.sku,16)+pad(r.gudangNama,14)+lpad(rp2(r.cost),16)+lpad(rp2(r.masterPerKemasan),16)
      +lpad(num(r.isi),12)+lpad(rp2(r.masterPerKemasan/r.isi),16)+lpad(Math.round(r.isi)+'x',10)));
  }

  // ------------------------------------------------------------- 3. TRACE LAYER
  H('3. TRACE LAPISAN untuk setiap SKU ber-FLAG');
  const petaRow = {}; (snapDash.rows||[]).forEach(r=>{ petaRow[r.sku+'|'+r.gudang]=r; });
  if(!flagged.length) say('(tidak ada baris ber-FLAG di Top 50)');
  flagged.forEach(f=>{
    const src = petaRow[f.sku+'|'+f.gudang];
    say('');
    say('▸ '+f.sku+' — '+f.nama+' @ '+f.gudangNama+'   qty '+num(f.qty)+' '+(f.satuan||'')
        +'   cost '+rp2(f.cost)+'   nilai '+rp(f.nilai)+'   rasio '+(f.ratio!=null?Math.round(f.ratio*100)/100+'x':'-'));
    say('   master/base '+rp2(f.masterUnit)+' ('+f.masterKet+')  [rasio '+(f.rMaster!=null?Math.round(f.rMaster*100)/100+'x':'-')+']'
        +'   ·   beli terakhir <=31 Agu '+rp2(f.beliUnit)+(f.beliAt?' ('+tgl(f.beliAt)+' '+f.beliRef+')':'')+'  [rasio '+(f.rBeli!=null?Math.round(f.rBeli*100)/100+'x':'-')+']');
    if(f.masterPerKemasan>0 && f.isi>1 && Math.abs(f.cost/f.masterPerKemasan-1)<0.25){
      say('   🔴 cost/unit HAMPIR SAMA dengan harga SATU KEMASAN ('+rp2(f.masterPerKemasan)+' per '+num(f.isi)+' '+(f.satuan||'')
          +'). Harga per kemasan terpakai sebagai harga per satuan dasar — kelebihan '+Math.round(f.isi)+'x.');
    }
    say('   '+pad('qty',16)+lpad('price',18)+'  '+pad('dateIn',12)+pad('costSource',30)+lpad('value',18));
    (src && src.batches ? src.batches : []).forEach(b=>{
      say('   '+pad(num(b.qty),16)+lpad(rp2(b.price),18)+'  '+pad(tgl(b.dateIn),12)
          +pad(b.costSource||(b.costUnknown?'TIDAK DIKETAHUI':'-'),30)+lpad(rp(b.qty*(b.price||0)),18));
    });
    const jml=(src&&src.batches?src.batches:[]).reduce((a,b)=>a+b.qty,0);
    const nil=(src&&src.batches?src.batches:[]).reduce((a,b)=>a+b.qty*(b.price||0),0);
    say('   '+pad('TOTAL '+num(jml),16)+lpad('',18)+'  '+pad('',12)+pad('',30)+lpad(rp(nil),18));
    if(Math.abs(jml)<1e-6 && Math.abs(nil)>1){
      say('   ⚠️  qty bersih ~0 tapi nilai '+rp(nil)+' — lapisan plus & minus saling meniadakan di qty tapi TIDAK di nilai.');
    }
    if(Math.abs(jml)>1e-9 && Math.abs(nil/jml)>1e7){
      say('   ⚠️  cost/unit '+rp2(nil/jml)+' — tidak masuk akal; kemungkinan qty bersih nyaris nol jadi penyebut.');
    }
  });

  // --------------------------------------------------------- 4. OPENING PER AREA
  H('4. OPENING PER AREA (1 Sep 2026)');
  function perArea(rs, ambilNilai){
    const p={}; (rs||[]).forEach(r=>{ const g=r.gudang||DEFAULT_GUDANG; p[g]=(p[g]||0)+(ambilNilai?ambilNilai(r):(r.value!=null?r.value:r.nilai)); });
    return p;
  }
  const pa = perArea(rows, r=>r.nilai);
  const utama=['scm','cibadak','karangtengah'];
  const lain=Object.keys(pa).filter(g=>!utama.includes(g));
  utama.forEach(g=>say(pad(gudangName(g),24)+' = '+lpad(rp(pa[g]||0),24)));
  say(pad('Lain ('+lain.map(g=>gudangName(g)).join(', ')+')',24)+' = '+lpad(rp(lain.reduce((a,g)=>a+pa[g],0)),24));
  say(pad('TOTAL',24)+' = '+lpad(rp(Object.values(pa).reduce((a,v)=>a+v,0)),24));

  // ------------------------------------------------------------- 5. TRACE SCM
  H('5. TRACE SCM — SO fisik 29 Agu + mutasi 30/31 Agu');
  const G='scm';
  let soAnchor=null;
  try{
    const ab = bangunAnchor();
    (ab.anchors||[]).forEach(a=>{
      if(a.jenis!=='so') return;
      const d=new Date(a.ts);
      if(d.getFullYear()===2026 && d.getMonth()===7 && d.getDate()===29) soAnchor=a;   // 29 Agustus 2026
    });
    if(!soAnchor){ // ambil SO SCM terakhir sebelum cutoff
      (ab.anchors||[]).forEach(a=>{ if(a.jenis==='so' && a.ts<=CUT && (!soAnchor||a.ts>soAnchor.ts)) soAnchor=a; });
    }
  }catch(e){ say('Gagal membaca jangkar: '+e.message); }
  let soVal=null;
  if(soAnchor){
    soVal=0; let soQty=0;
    Object.values(soAnchor.state).forEach(r=>{ if(r.gudang!==G) return;
      r.layers.forEach(b=>{ soVal+=b.qty*(b.price||0); soQty+=b.qty; }); });
    say('Jangkar SO dipakai   : '+soAnchor.label+'  ('+new Date(soAnchor.ts).toLocaleString('id-ID')+')');
    say('SO fisik 29 Agu VALUE (SCM) = '+rp(soVal)+'   · qty total '+num(soQty));
  } else say('⚠️ Tidak ditemukan jangkar Stok Opname untuk SCM sebelum 1 Sep 2026.');

  const bataswal = soAnchor ? soAnchor.ts : new Date(2026,7,29,23,59,59,999).getTime();
  let inVal=0, inQty=0, outVal=0, outQty=0, rincian=[];
  (typeof mutasiStok==='function'?mutasiStok():transactionLog).forEach(l=>{
    if((l.gudang||DEFAULT_GUDANG)!==G) return;
    const t=effTs(l); if(!(t>bataswal && t<CUT)) return;
    if(l.type==='in'){ inVal+=(l.total||0); inQty+=l.qty; } else { outVal+=(l.total||0); outQty+=l.qty; }
    rincian.push({ tgl:tgl(t), jenis:l.type==='in'?'MASUK':'KELUAR', sku:l.sku, nama:l.itemName||'', qty:l.qty, total:l.total||0, ref:l.ref||'' });
  });
  say('+ IN  asli 30-31 Agu (SCM) = '+rp(inVal) +'   ('+rincian.filter(x=>x.jenis==='MASUK').length+' transaksi, qty '+num(inQty)+')');
  say('- OUT asli 30-31 Agu (SCM) = '+rp(outVal)+'   ('+rincian.filter(x=>x.jenis==='KELUAR').length+' transaksi, qty '+num(outQty)+')');
  const expectedSCM = (soVal!=null) ? soVal + inVal - outVal : null;
  say('= EXPECTED OPENING SCM     = '+rp(expectedSCM));
  if(rincian.length){
    say('');
    say('  rincian mutasi setelah SO s/d 31 Agu (SCM):');
    say('  '+pad('Tgl',12)+pad('Jenis',9)+pad('SKU',16)+pad('Nama',28)+lpad('Qty',12)+lpad('Nilai',16)+'  Ref');
    rincian.sort((a,b)=>a.tgl.localeCompare(b.tgl)).slice(0,80).forEach(x=>
      say('  '+pad(x.tgl,12)+pad(x.jenis,9)+pad(x.sku,16)+pad(x.nama,28)+lpad(num(x.qty),12)+lpad(rp(x.total),16)+'  '+x.ref));
    if(rincian.length>80) say('  ... +'+(rincian.length-80)+' baris lagi');
  }

  // ------------------------------------------------ 6. EXPECTED vs CLOSING vs DASHBOARD
  H('6. EXPECTED vs CLOSING AGUSTUS vs DASHBOARD OPENING');
  const clAug = (typeof closings!=='undefined'?closings:[]).filter(c=>c && (c.period==='2026-08' || (c.cutoff && Math.abs(c.cutoff-CUT)<86400000)))[0] || null;
  let clTotal=null, clSCM=null;
  if(clAug){
    clTotal = clAug.valueAtCutoff;
    clSCM = 0;
    (clAug.snapshotRows||[]).forEach(r=>{ if((r.gudang||DEFAULT_GUDANG)!==G) return;
      clSCM += (r.batches||[]).reduce((a,b)=>a+b.qty*(b.price||0),0); });
    say('Snapshot Closing Agustus   : '+rp(clTotal)+'   · engine "'+(clAug.snapshotEngineVersion||'(kosong = mesin lama)')+'" · '+(clAug.snapshotRows||[]).length+' baris');
    say('   (engine version TIDAK dipakai sebagai bukti benar — hanya dicatat)');
    say('   SCM di dalam snapshot    : '+rp(clSCM));
  } else say('Snapshot Closing Agustus   : TIDAK ADA');
  const dashTotal = snapDash.totalValue;
  const dashSCM = pa[G]||0;
  say('');
  say(pad('',34)+lpad('TOTAL',22)+lpad('SCM',22));
  hr();
  say(pad('Expected (SO + mutasi asli)',34)+lpad(expectedSCM!=null?'(SCM saja)':'-',22)+lpad(rp(expectedSCM),22));
  say(pad('Snapshot Closing Agustus',34)+lpad(rp(clTotal),22)+lpad(rp(clSCM),22));
  say(pad('Dashboard Opening September',34)+lpad(rp(dashTotal),22)+lpad(rp(dashSCM),22));
  say(pad('Hitung ulang mesin sekarang',34)+lpad(ulang.ok?rp(ulang.totalValue):'-',22)+lpad(rp(perArea(ulang.rows)[G]||0),22));
  hr();
  say(pad('GAP Dashboard - Expected (SCM)',34)+lpad('',22)+lpad(expectedSCM!=null?rp(dashSCM-expectedSCM):'-',22));
  say(pad('GAP Dashboard - Closing',34)+lpad(clTotal!=null?rp(dashTotal-clTotal):'-',22)+lpad(clSCM!=null?rp(dashSCM-clSCM):'-',22));
  say(pad('GAP Dashboard - Hitung ulang',34)+lpad(ulang.ok?rp(dashTotal-ulang.totalValue):'-',22)+lpad('',22));

  // --------------------------------------------- 7. TOP 20 PENYUMBANG GAP
  H('7. TOP 20 SKU PENYUMBANG GAP  (Dashboard Opening vs pembanding terbaik)');
  const pembandingRows = clAug ? (clAug.snapshotRows||[]).map(r=>({
        sku:r.sku, gudang:r.gudang||DEFAULT_GUDANG,
        qty:(r.batches||[]).reduce((a,b)=>a+b.qty,0),
        value:(r.batches||[]).reduce((a,b)=>a+b.qty*(b.price||0),0) }))
    : (ulang.ok?ulang.rows:[]);
  const labelPemb = clAug ? 'Snapshot Closing Agustus' : 'Hitung ulang mesin';
  const pE={}; pembandingRows.forEach(r=>{ pE[r.sku+'|'+r.gudang]={qty:r.qty, value:r.value}; });
  const pD={}; (snapDash.rows||[]).forEach(r=>{ pD[r.sku+'|'+r.gudang]={qty:r.qty, value:r.value}; });
  const kunci=new Set([...Object.keys(pE),...Object.keys(pD)]);
  const gap=[];
  kunci.forEach(k=>{
    const [sku,g]=k.split('|');
    const a=pE[k]||{qty:0,value:0}, b=pD[k]||{qty:0,value:0};
    const d=b.value-a.value;
    if(Math.abs(d)>1) gap.push({sku, g, qtyA:a.qty, qtyB:b.qty, vA:a.value, vB:b.value, d});
  });
  gap.sort((x,y)=>Math.abs(y.d)-Math.abs(x.d));
  say('Pembanding: '+labelPemb+'   ·   total selisih '+rp(gap.reduce((a,x)=>a+x.d,0))+'   ·   '+gap.length+' baris berbeda');
  say('');
  say(pad('SKU',16)+pad('Nama',28)+pad('Gudang',14)+lpad('Qty pemb.',12)+lpad('Qty dash',12)+lpad('Nilai pemb.',18)+lpad('Nilai dash',18)+lpad('SELISIH',18));
  hr();
  gap.slice(0,20).forEach(x=>{
    const it=itemBySku(x.sku)||{};
    say(pad(x.sku,16)+pad(it.name||'',28)+pad(gudangName(x.g),14)+lpad(num(x.qtyA),12)+lpad(num(x.qtyB),12)
        +lpad(rp(x.vA),18)+lpad(rp(x.vB),18)+lpad(rp(x.d),18));
  });
  const sisa = gap.slice(20).reduce((a,x)=>a+x.d,0);
  say('');
  say('Sisa '+Math.max(0,gap.length-20)+' baris lain menyumbang '+rp(sisa));

  // ---------------------------------------------------- 8. PEMERIKSAAN POLA RUSAK
  H('8. POLA HARGA TIDAK WAJAR DI SELURUH BARIS OPENING (bukan cuma Top 50)');
  let nMahal=0, nTipis=0, nUnknown=0, valMahal=0;
  const mahal=[], tipis=[];
  rows.forEach(r=>{
    if(r.costUnknown) nUnknown++;
    const m=masterPerBase(r.sku), b=beliTerakhirSd(r.sku, r.gudang, AGU_AKHIR);
    const pb=(b.v>0)?b.v:m.v;
    const rb=(b.v>0&&isFinite(r.cost))?r.cost/b.v:null;
    const rm=(m.v>0&&isFinite(r.cost))?r.cost/m.v:null;
    const ra=[rb,rm].filter(x=>x!=null).reduce((a,x)=>(a==null||Math.abs(Math.log(x||1e-9))>Math.abs(Math.log(a||1e-9)))?x:a, null);
    if(ra!=null){
      if(ra>5){ nMahal++; valMahal+=r.nilai; mahal.push({...r, ra, pb:(ra===rm?m.v:b.v)}); }
      else if(ra<0.2){ nTipis++; tipis.push({...r, ra, pb:(ra===rm?m.v:b.v)}); }
    }
    const src=petaRow[r.sku+'|'+r.gudang];
    if(src){
      const q=(src.batches||[]).reduce((a,x)=>a+x.qty,0);
      if(Math.abs(q)<1e-6 && Math.abs(r.nilai)>1) tipis.push({...r, ra:null, pb:null, qtyNol:true});
    }
  });
  say('Baris dengan cost >5x harga pembanding  : '+nMahal+'   · menyumbang nilai '+rp(valMahal));
  say('Baris dengan cost <0,2x harga pembanding: '+nTipis);
  say('Baris tanpa rujukan harga sama sekali   : '+nUnknown);
  if(mahal.length){
    say('');
    say('20 baris termahal secara rasio:');
    say(pad('SKU',16)+pad('Gudang',14)+lpad('Qty',12)+lpad('Cost/unit',18)+lpad('Pembanding',16)+lpad('Rasio',12)+lpad('Nilai',18));
    hr();
    mahal.sort((a,b)=>b.ra-a.ra).slice(0,20).forEach(r=>
      say(pad(r.sku,16)+pad(r.gudangNama,14)+lpad(num(r.qty),12)+lpad(rp2(r.cost),18)+lpad(rp2(r.pb),16)
          +lpad(Math.round(r.ra)+'x',12)+lpad(rp(r.nilai),18)));
  }

  H('9. POLA "QTY BERSIH NYARIS NOL JADI PENYEBUT HARGA"');
  say('Kalau sebuah SKU punya lapisan PLUS besar dan lapisan MINUS yang hampir sama besar, qty bersihnya');
  say('mendekati nol tapi NILAI bersihnya tidak. Saat Stok Opname menetapkan jumlah fisik, selisihnya');
  say('dihargai dengan nilai/qty -> pembagian oleh angka nyaris nol -> harga satuan meledak jadi miliaran.');
  say('');
  const meledak=[];
  rows.forEach(r=>{
    const src=petaRow[r.sku+'|'+r.gudang]; if(!src) return;
    const lay=src.batches||[];
    const plus =lay.filter(b=>b.qty>0).reduce((a,b)=>a+b.qty,0);
    const minus=lay.filter(b=>b.qty<0).reduce((a,b)=>a+b.qty,0);
    const ekstrem=lay.filter(b=>b.price>1e7);
    if(ekstrem.length || (plus>0 && Math.abs(plus+minus)/plus < 0.01 && Math.abs(r.nilai)>1e6)){
      meledak.push({...r, plus, minus, ekstrem:ekstrem.length,
        hargaMax:lay.reduce((a,b)=>Math.max(a,b.price||0),0)});
    }
  });
  if(!meledak.length) say('Tidak ditemukan pola ini.');
  else {
    say(pad('SKU',16)+pad('Gudang',16)+lpad('Qty plus',14)+lpad('Qty minus',14)+lpad('Harga tertinggi',22)+lpad('Nilai baris',24));
    hr();
    meledak.sort((a,b)=>Math.abs(b.nilai)-Math.abs(a.nilai)).forEach(r=>
      say(pad(r.sku,16)+pad(r.gudangNama,16)+lpad(num(r.plus),14)+lpad(num(r.minus),14)
          +lpad(rp2(r.hargaMax),22)+lpad(rp(r.nilai),24)));
    say('');
    say('Total nilai dari pola ini: '+rp(meledak.reduce((a,r)=>a+r.nilai,0))
        +'  ('+(dashTotal?Math.round(meledak.reduce((a,r)=>a+r.nilai,0)/dashTotal*1000)/10:0)+'% dari Opening)');
  }

  H('RINGKASAN');
  say('Dashboard Opening September : '+rp(dashTotal)+(beku?'  (angka BEKU dari Opening Resmi)':'  (hasil hitung ulang)'));
  say('Snapshot Closing Agustus    : '+rp(clTotal));
  say('Expected SCM (SO+mutasi)    : '+rp(expectedSCM)+'   vs Dashboard SCM '+rp(dashSCM)+'   GAP '+(expectedSCM!=null?rp(dashSCM-expectedSCM):'-'));
  say('Baris ber-rasio >5x         : '+nMahal+' baris senilai '+rp(valMahal)+'  ('+(dashTotal?Math.round(valMahal/dashTotal*1000)/10:0)+'% dari total opening)');
  say('Dugaan harga per kemasan    : '+dugaanKemasan.length+' baris di Top 50');
  say('Pola qty-nol-jadi-penyebut  : '+meledak.length+' baris senilai '+rp(meledak.reduce((a,r)=>a+r.nilai,0)));
  say('');
  say('Salin seluruh laporan ini dengan mengetik:   copy(__TRACE)');

  window.__TRACE = L.join('\n');
  window.__TRACE_DATA = { rows, top50, flagged, gap, perArea:pa, expectedSCM, clAug, dashTotal, ulang, soAnchor, rincian };
})();
