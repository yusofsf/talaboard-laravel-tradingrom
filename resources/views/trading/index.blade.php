<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>اتاق معاملات طلابورد</title>
    <style>
        :root{--navy:#102a43;--gold:#c69214;--paper:#f5f7fb;--line:#e5eaf0;--muted:#64748b}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:#172033;font-family:Tahoma,"Segoe UI",sans-serif}.wrap{max-width:1180px;margin:auto;padding:28px 18px 55px}.hero{background:linear-gradient(120deg,#102a43,#1d4e65);border-radius:20px;color:#fff;padding:28px;box-shadow:0 12px 26px #102a4326}.hero h1{margin:0 0 8px;font-size:1.7rem}.hero p{margin:0;color:#d5e8f4}.section{margin-top:20px}.section-title{font-size:1.15rem;margin:0 0 10px}.prices{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:11px}.price{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px;min-height:105px}.price-icon{font-size:24px;float:left;background:#fff7df;border-radius:10px;padding:5px 9px}.price small{display:block;color:var(--muted);margin-bottom:8px}.price strong{font-size:1rem}.price em{font-style:normal;font-size:.75rem;color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px}.card h2{font-size:1.08rem;margin:0 0 8px}.hint{color:var(--muted);font-size:.86rem;line-height:1.8}.notice{background:#fff8e1;border:1px solid #f0d98f;color:#755900;padding:11px;border-radius:10px;font-size:.86rem;margin:12px 0}label{font-size:.86rem;font-weight:bold;display:block;margin:11px 0 5px}input,select,button{font:inherit;border-radius:9px}input,select{width:100%;border:1px solid #cbd5e1;padding:10px;background:#fff}button{border:0;padding:11px 15px;cursor:pointer;background:var(--navy);color:#fff;margin-top:15px}button.secondary{background:#fff;color:var(--navy);border:1px solid var(--navy)}.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.hidden{display:none}.result{font-size:.86rem;margin-top:10px}.bank{background:#f0f7fb;border-radius:10px;padding:10px;line-height:1.9;font-size:.88rem}.bank code{direction:ltr;display:inline-block}.tables{display:grid;grid-template-columns:1fr 1fr;gap:18px}table{width:100%;border-collapse:collapse;font-size:.86rem}td,th{padding:.65rem;border-bottom:1px solid var(--line);text-align:right}.buy{color:#087f23}.sell{color:#b42318}@media(max-width:800px){.grid,.tables{grid-template-columns:1fr}.wrap{padding:16px}.hero{border-radius:14px}.two{grid-template-columns:1fr}}
    </style>
</head>
<body>
@php
    $labels = ['gold_995_gram'=>'گرم طلای ۹۹۵','gold_995_mesghal'=>'مثقال طلای ۹۹۵','gold_9999_gram'=>'گرم طلای ۹۹۹.۹','gold_9999_mesghal'=>'مثقال طلای ۹۹۹.۹','silver_995_gram'=>'گرم نقره ۹۹۵','silver_995_mesghal'=>'مثقال نقره ۹۹۵','silver_9999_gram'=>'گرم نقره ۹۹۹.۹','silver_9999_mesghal'=>'مثقال نقره ۹۹۹.۹','quarter_coin'=>'ربع سکه','half_coin'=>'نیم سکه','full_coin'=>'تمام سکه'];
    $icons = ['gold_995_gram'=>'🥇','gold_995_mesghal'=>'⚖️','gold_9999_gram'=>'✨','gold_9999_mesghal'=>'⚖️','silver_995_gram'=>'🥈','silver_995_mesghal'=>'⚖️','silver_9999_gram'=>'🌕','silver_9999_mesghal'=>'⚖️','quarter_coin'=>'🪙','half_coin'=>'🪙','full_coin'=>'🪙'];
    $priceBySymbol = $prices->keyBy('symbol');
@endphp
<main class="wrap">
    <header class="hero"><h1>اتاق معاملات طلابورد</h1><p>قیمت‌های لحظه‌ای، ثبت معامله و مدیریت موجودی طلا، نقره و سکه</p></header>

    <section class="section"><h2 class="section-title">قیمت لحظه‌ای (ریال)</h2><div class="prices">
        @foreach($labels as $symbol=>$label) @php($p=$priceBySymbol->get($symbol))
        <article class="price" data-symbol="{{ $symbol }}" data-price="{{ $p?->price ?? '' }}"><span class="price-icon">{{ $icons[$symbol] }}</span><small>{{ $label }}</small><strong>{{ $p ? number_format($p->price) : '—' }}</strong> <em>ریال</em></article>
        @endforeach
    </div><p class="hint">قیمت‌ها از سایت طلابورد دریافت می‌شوند و در فرم معامله به‌صورت پیش‌فرض قرار می‌گیرند.</p></section>

    <section class="section grid">
        <article class="card"><h2>ثبت معامله</h2><p class="hint">دارایی، واحد و مقدار را انتخاب کنید. قیمت واحد را می‌توانید تغییر دهید؛ قیمت پیش‌فرض، قیمت سایت است.</p>
            <form id="trade-form"><div class="two"><div><label for="side">نوع معامله</label><select id="side" name="side"><option value="sell">خرید از سایت</option><option value="buy">فروش به سایت</option></select></div><div><label for="trade-asset">دارایی</label><select id="trade-asset" name="asset"><option value="gold_995">طلای ۹۹۵</option><option value="gold_9999">طلای ۹۹۹.۹</option><option value="silver_995">نقره ۹۹۵</option><option value="silver_9999">نقره ۹۹۹.۹</option><option value="full_coin">تمام سکه</option><option value="half_coin">نیم سکه</option><option value="quarter_coin">ربع سکه</option></select></div></div><div class="two"><div><label for="trade-unit">واحد</label><select id="trade-unit" name="unit"></select></div><div><label for="trade-quantity">مقدار</label><input id="trade-quantity" name="quantity" type="number" min="0.001" step="0.001" placeholder="مثلاً ۱۰"></div></div><label for="trade-price">قیمت واحد (ریال)</label><input id="trade-price" name="unit_price" type="number" min="1" step="1" placeholder="قیمت پیش‌فرض سایت"><button type="submit">ثبت معامله</button><div class="result" id="trade-result"></div></form>
        </article>
        <article class="card"><h2>افزایش موجودی</h2><p class="hint">برای افزایش موجودی، ابتدا دارایی را به فروشگاه تحویل دهید.</p><button type="button" class="secondary" id="delivered-toggle">تحویل دادم</button>
            <form id="delivery-form" class="hidden"><div class="notice">پس از ثبت، درخواست تحویل شما برای بررسی فروشگاه ارسال می‌شود.</div><label for="delivery-asset">دارایی تحویلی</label><select id="delivery-asset" name="asset"><option value="gold_995">طلای ۹۹۵</option><option value="gold_9999">طلای ۹۹۹.۹</option><option value="silver_995">نقره ۹۹۵</option><option value="silver_9999">نقره ۹۹۹.۹</option><option value="full_coin">تمام سکه</option><option value="half_coin">نیم سکه</option><option value="quarter_coin">ربع سکه</option></select><label for="delivery-unit">واحد</label><select id="delivery-unit" name="unit"></select><label for="delivery-quantity">مقدار</label><input id="delivery-quantity" name="quantity" type="number" min="0.001" step="0.001"><button type="submit">ثبت تحویل به فروشگاه</button><div class="result" id="delivery-result"></div></form>
        </article>
        <article class="card"><h2>واریز وجه و فیش</h2><p class="hint">ابتدا مبلغ را به حساب زیر واریز کنید.</p><div class="bank">شماره حساب: <code>{{ config('trading.account_number') }}</code><br>شماره شبا: <code>{{ config('trading.iban') }}</code><br>به نام: {{ config('trading.account_holder') }}</div><button type="button" class="secondary" id="paid-toggle">واریز کردم</button>
            <form id="deposit-form" class="hidden" enctype="multipart/form-data"><label for="deposit-amount">مبلغ واریزی (ریال)</label><input id="deposit-amount" name="amount" type="number" min="10000" step="1"><label for="receipt">تصویر فیش واریزی</label><input id="receipt" name="receipt" type="file" accept="image/*"><button type="submit">ارسال فیش</button><div class="result" id="deposit-result"></div></form>
        </article>
    </section>

    <section class="section tables"><article class="card"><h2 class="buy">لیست خریدها</h2>@include('trading.table',['trades'=>$buys])</article><article class="card"><h2 class="sell">لیست فروش‌ها</h2>@include('trading.table',['trades'=>$sells])</article></section>
</main>
<script>
const assets = {gold_995:'طلای ۹۹۵',gold_9999:'طلای ۹۹۹.۹',silver_995:'نقره ۹۹۵',silver_9999:'نقره ۹۹۹.۹',full_coin:'تمام سکه',half_coin:'نیم سکه',quarter_coin:'ربع سکه'};
const coinAssets=['full_coin','half_coin','quarter_coin'];
function setUnits(assetEl, unitEl, quantityEl, priceEl=null) { const coin=coinAssets.includes(assetEl.value); unitEl.innerHTML=coin?'<option value="count">تعداد</option>':'<option value="gram">گرم</option><option value="mesghal">مثقال</option>'; quantityEl.placeholder=coin?'تعداد سکه':'مقدار'; if(priceEl) setPrice(assetEl,unitEl,priceEl); }
function symbol(asset,unit){return coinAssets.includes(asset)?asset:asset+'_'+unit}
function setPrice(assetEl,unitEl,priceEl){const card=document.querySelector(`[data-symbol="${symbol(assetEl.value,unitEl.value)}"]`); priceEl.value=card?.dataset.price||'';priceEl.placeholder=priceEl.value?'قیمت پیش‌فرض سایت':'قیمت واحد را وارد کنید'}
const tradeAsset=document.querySelector('#trade-asset'),tradeUnit=document.querySelector('#trade-unit'),tradeQty=document.querySelector('#trade-quantity'),tradePrice=document.querySelector('#trade-price');
setUnits(tradeAsset,tradeUnit,tradeQty,tradePrice);tradeAsset.onchange=()=>setUnits(tradeAsset,tradeUnit,tradeQty,tradePrice);tradeUnit.onchange=()=>setPrice(tradeAsset,tradeUnit,tradePrice);
const deliveryAsset=document.querySelector('#delivery-asset'),deliveryUnit=document.querySelector('#delivery-unit'),deliveryQty=document.querySelector('#delivery-quantity');setUnits(deliveryAsset,deliveryUnit,deliveryQty);deliveryAsset.onchange=()=>setUnits(deliveryAsset,deliveryUnit,deliveryQty);
document.querySelector('#delivered-toggle').onclick=()=>document.querySelector('#delivery-form').classList.toggle('hidden');document.querySelector('#paid-toggle').onclick=()=>document.querySelector('#deposit-form').classList.toggle('hidden');
async function send(form,url,result){const r=await fetch(url,{method:'POST',body:new FormData(form),headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}});const data=await r.json().catch(()=>({}));result.textContent=r.ok?'درخواست شما با موفقیت ثبت شد.':(Object.values(data.errors||{}).flat().join(' ')||data.message||'ثبت درخواست ناموفق بود.');result.style.color=r.ok?'#087f23':'#b42318'}
document.querySelector('#trade-form').onsubmit=e=>{e.preventDefault();send(e.target,'/api/trades',document.querySelector('#trade-result'))};document.querySelector('#delivery-form').onsubmit=e=>{e.preventDefault();send(e.target,'/api/inventory-deliveries',document.querySelector('#delivery-result'))};document.querySelector('#deposit-form').onsubmit=e=>{e.preventDefault();send(e.target,'/api/deposits',document.querySelector('#deposit-result'))};
</script>
</body></html>
