@php
    $assetLabels = ['gold' => 'طلا', 'silver_995' => 'نقره ۹۹۵', 'silver_9999' => 'نقره ۹۹۹.۹', 'full_coin' => 'تمام سکه', 'half_coin' => 'نیم سکه', 'quarter_coin' => 'ربع سکه'];
    $unitLabels = ['gram' => 'گرم', 'mesghal' => 'مثقال', 'count' => 'تعداد'];
@endphp
<table><tr><th>دارایی</th><th>واحد</th><th>مقدار</th><th>قیمت واحد</th><th>قیمت کل</th><th>تاریخ و ساعت</th></tr>
@forelse($trades as $t)
<tr><td>{{ $assetLabels[$t->asset] ?? 'طلا' }}</td><td>{{ $unitLabels[$t->unit] ?? $t->unit }}</td><td>{{ $t->quantity }}</td><td>{{ number_format($t->unit_price) }}</td><td>{{ number_format($t->total_price) }}</td><td>{{ \Morilog\Jalali\Jalalian::fromCarbon($t->traded_at->timezone('Asia/Tehran'))->format('Y/m/d H:i') }}</td></tr>
@empty
<tr><td colspan="6">معامله‌ای وجود ندارد.</td></tr>
@endforelse</table>
