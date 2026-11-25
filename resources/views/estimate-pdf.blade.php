<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Смета № {{ $number }}</title>
<style>
    @page { margin: 18mm 17mm; }
    html, body, table { font-family: "DejaVu Sans"; }
    body { font-size: 10pt; color: #22352e; }
    h1 { font-size: 23pt; margin: 5pt 0 7pt; }
    h2 { font-size: 11pt; margin: 15pt 0 6pt; }
    p { margin: 4pt 0; overflow-wrap: break-word; }
    .heading { border-top: 3pt solid #235e4f; padding-top: 10pt; }
    .meta { color: #486057; font-size: 9pt; }
    .party-details { margin-left: 10pt; color: #486057; }
    table { width: 100%; border-collapse: collapse; }
    .lines { margin-top: 13pt; table-layout: fixed; }
    .lines th { background: #e7efe9; }
    .lines td, .lines th { border: .5pt solid #bfcdc4; padding: 6pt; text-align: left; vertical-align: top; overflow-wrap: break-word; }
    .lines .money { text-align: right; }
    .lines :is(th, td):first-child { white-space: nowrap; }
    thead { display: table-header-group; }
    .totals { width: 52%; margin: 10pt 0 0 auto; }
    .totals td { padding: 3pt 6pt; }
    .totals td:last-child { text-align: right; }
    .total { border-top: .7pt solid #235e4f; font-weight: bold; }
    .notes { white-space: pre-wrap; }
    .signatures { margin-top: 18pt; page-break-inside: avoid; }
    .signatures td { width: 50%; padding-right: 14pt; }
    .signature { padding-bottom: 16pt; border-bottom: .5pt solid #73857b; }
    .approval { margin-top: 10pt; font-size: 9pt; }
</style>
</head>
<body>
@php
    $data = $proposal['snapshot'];
    $money = fn ($value) => number_format($value / 100, 2, ',', ' ');
    $date = fn ($value) => \Illuminate\Support\Carbon::parse($value)->timezone('Europe/Moscow')->format('d.m.Y');
    $dateOnly = fn ($value) => \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $value, 'Europe/Moscow')->format('d.m.Y');
    $groups = collect($data['stages'] ?? [])->map(fn ($stage) => $stage + [
        'lines' => array_filter($data['lines'], fn ($line) => ($line['stage'] ?? null) === $stage['key']),
    ]);
    $unassigned = array_filter($data['lines'], fn ($line) => !$groups->contains('key', $line['stage'] ?? null));
    if ($unassigned) {
        $groups->push(['name' => $groups->isEmpty() ? '' : 'Работы вне этапов', 'lines' => $unassigned]);
    }
    $position = 0;
@endphp
<div class="heading">
    <p class="meta">№ {{ $number }} от {{ $date($proposal['created_at']) }} · Валюта: российский рубль (RUB)</p>
    <h1>Смета на выполнение работ</h1>
    @if($proposal['revision_of'])<p class="meta">Версия сметы № {{ str_pad((string) $proposal['revision_of'], 4, '0', STR_PAD_LEFT) }}</p>@endif
    @if($proposal['status'] === 'withdrawn')<p><strong>Предложение отозвано {{ $date($proposal['withdrawn_at']) }}</strong></p>@endif
    <p><strong>Проект:</strong> {{ $data['title'] }}</p>
    <p><strong>Подрядчик:</strong> {{ $data['sender'] }}</p>
    @if(!empty($data['sender_details']))<p class="party-details">{{ $data['sender_details'] }}</p>@endif
    @if(!empty($data['sender_contact']))<p class="party-details">{{ $data['sender_contact'] }}</p>@endif
    <p><strong>Заказчик:</strong> {{ $data['client'] }}</p>
    @if(!empty($data['client_details']))<p class="party-details">{{ $data['client_details'] }}</p>@endif
    @if(!empty($data['basis']))<p><strong>Основание:</strong> {{ $data['basis'] }}</p>@endif
    @if(!empty($data['price_type']))<p><strong>Цена:</strong> {{ $data['price_type'] === 'approximate' ? 'приблизительная' : 'твёрдая' }}</p>@endif
    @if(!empty($data['starts_on']) && !empty($data['ends_on']))
        <p><strong>Срок выполнения работ:</strong> с {{ $dateOnly($data['starts_on']) }} по {{ $dateOnly($data['ends_on']) }}</p>
    @endif
    @if(!empty($data['valid_until']))<p><strong>Предложение действительно по:</strong> {{ $dateOnly($data['valid_until']) }} включительно</p>@endif
</div>
<table class="lines">
    <thead><tr><th style="width:7%; white-space: nowrap">№</th><th style="width:33%">Работы</th><th style="width:10%">Ед.</th><th style="width:12%">Кол-во</th><th class="money" style="width:19%">Цена, руб.</th><th class="money" style="width:19%">Сумма, руб.</th></tr></thead>
    <tbody>
    @foreach($groups as $group)
        @if($group['name'])
            <tr><th colspan="6" scope="rowgroup">{{ $group['name'] }}@if(!empty($group['due_on'])) · по {{ $dateOnly($group['due_on']) }}@endif @isset($group['subtotal']) · {{ $money($group['subtotal']) }} руб. до скидки @endisset</th></tr>
        @endif
        @foreach($group['lines'] as $line)
            <tr><td>{{ ++$position }}</td><td>{{ $line['name'] }}</td><td>{{ $line['unit'] ?? 'ед.' }}</td><td>{{ $line['quantity'] }}</td><td class="money">{{ $money($line['price_cents'] ?? $line['price'] * 100) }}</td><td class="money">{{ $money($line['amount']) }}</td></tr>
        @endforeach
    @endforeach
    </tbody>
</table>
<table class="totals">
    <tr><td>Подытог</td><td>{{ $money($data['subtotal']) }}</td></tr>
    @if($data['discount_amount'])<tr><td>Скидка {{ $data['discount'] }}%</td><td>-{{ $money($data['discount_amount']) }}</td></tr>@endif
    @if(in_array($data['vat_mode'] ?? null, ['added', 'included'], true))
        <tr><td>База без НДС</td><td>{{ $money($data['vat_base']) }}</td></tr>
        <tr><td>{{ $data['vat_mode'] === 'included' ? 'В том числе НДС' : 'НДС' }} {{ $data['vat_rate'] }}%</td><td>{{ $money($data['vat_amount']) }}</td></tr>
    @elseif(($data['vat_mode'] ?? null) === 'none')
        <tr><td>НДС</td><td>Без НДС</td></tr>
    @endif
    <tr class="total"><td>Итого, руб.</td><td>{{ $money($data['total']) }}</td></tr>
    @if(!empty($data['advance_percent']))
        <tr><td>Аванс по договору, {{ $data['advance_percent'] }}%</td><td>{{ $money($data['advance']) }}</td></tr>
        <tr><td>Остаток, руб.</td><td>{{ $money($data['balance']) }}</td></tr>
    @endif
</table>
@if(!empty($data['payments']))
    <h2>График платежей</h2>
    <table class="lines">
        <thead><tr><th style="width:62%">Условие</th><th style="width:13%">Доля</th><th class="money" style="width:25%">Сумма, руб.</th></tr></thead>
        <tbody>@foreach($data['payments'] as $payment)
            <tr><td style="white-space: normal">{{ $payment['label'] }}@if(!empty($payment['due_on']))<br><span class="meta">По {{ $dateOnly($payment['due_on']) }}</span>@endif</td><td>{{ $payment['percent'] }}%</td><td class="money">{{ $money($payment['amount']) }}</td></tr>
        @endforeach</tbody>
    </table>
@endif
@if(!empty($data['notes']))
<h2>Условия</h2>
<p class="notes">{{ $data['notes'] }}</p>
@endif
@if($proposal['accepted_at'])
    <p class="approval"><strong>Отклик в сервисе:</strong> {{ $proposal['accepted_by'] }}, {{ \Illuminate\Support\Carbon::parse($proposal['accepted_at'])->timezone('Europe/Moscow')->format('d.m.Y H:i') }} (МСК). Имя указано пользователем. Подписание сметы оформляется отдельно.</p>
@endif
<table class="signatures">
<tr><td><div class="signature">Подрядчик</div><p class="meta">Подпись, ФИО, дата</p></td><td><div class="signature">Заказчик</div><p class="meta">Подпись, ФИО, дата</p></td></tr>
</table>
</body>
</html>
