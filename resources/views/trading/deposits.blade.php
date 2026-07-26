<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مدیریت فیش‌های واریزی</title>
    <style>
        body{font-family:Tahoma,"Segoe UI",sans-serif;background:#f5f7fb;color:#172033;margin:0;padding:28px}main{max-width:1100px;margin:auto;background:#fff;border:1px solid #e5eaf0;border-radius:14px;padding:22px}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #e5eaf0;text-align:right}button{border:0;border-radius:7px;padding:8px 12px;cursor:pointer;color:#fff}.approve{background:#087f23}.reject{background:#b42318}.pending{color:#a16207}.approved{color:#087f23}.rejected{color:#b42318}.actions{display:flex;gap:7px}.note{width:130px;padding:7px;border:1px solid #cbd5e1;border-radius:7px}.flash{background:#e8f5ec;color:#087f23;padding:10px;border-radius:8px;margin:10px 0}
    </style>
</head>
<body>
<main>
    <h1>فیش‌های واریزی</h1>
    @if(session('status'))<p class="flash">{{ session('status') }}</p>@endif
    <table>
        <tr><th>کاربر</th><th>مبلغ (ریال)</th><th>فیش</th><th>وضعیت</th><th>اقدام</th></tr>
        @forelse($deposits as $d)
            <tr>
                <td>{{ $d->user->name }}</td>
                <td>{{ number_format($d->amount) }}</td>
                <td><a href="{{ asset('storage/'.$d->receipt_path) }}" target="_blank" rel="noopener">مشاهده فیش</a></td>
                <td class="{{ $d->status }}">{{ ['pending'=>'در انتظار بررسی','approved'=>'تأیید شده','rejected'=>'رد شده'][$d->status] }}</td>
                <td>
                    @if($d->status === 'pending')
                        <div class="actions">
                            <form class="review-form" method="post" action="{{ route('admin.deposits.approve', $d) }}">@csrf<input class="note" name="note" placeholder="یادداشت اختیاری"><button class="approve">تأیید</button></form>
                            <form class="review-form" method="post" action="{{ route('admin.deposits.reject', $d) }}">@csrf<input class="note" name="note" placeholder="دلیل رد"><button class="reject">رد</button></form>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5">فیشی برای بررسی وجود ندارد.</td></tr>
        @endforelse
    </table>
</main>
<script>
document.querySelectorAll('.review-form').forEach(form=>form.addEventListener('submit',async event=>{event.preventDefault();const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'}});if(response.ok) location.reload();else alert((await response.json().catch(()=>({}))).message||'ثبت نتیجه ناموفق بود.')}));
</script>
</body>
</html>
