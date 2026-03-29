@if(!($is_preview ?? false))
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
@endif
@if(!($is_preview ?? false))
<style>
  @page { margin: 0px; }
  body { 
    margin: 0px; 
    padding: 0px; 
    background: #fff; 
    width: {{ $config['receipt_width'] ?? '80mm' }};
  }
</style>
@endif
<style>
  * { box-sizing: border-box; -webkit-box-sizing: border-box; margin: 0; padding: 0; }
  .receipt-wrap {
    font-family: 'Courier New', Courier, monospace;
    font-size: 11px;
    color: #000;
    width: auto;
    margin-left: 15px;
    margin-right: 15px;
    padding-top: 15px;
    padding-bottom: 15px;
    background: #fff;
    line-height: 1.4;
    overflow: hidden;
  }
  .receipt-wrap .center  { text-align: center; }
  .receipt-wrap .right   { text-align: right; }
  .receipt-wrap .bold    { font-weight: bold; }
  .receipt-wrap .large   { font-size: 14px; }
  .receipt-wrap .xlarge  { font-size: 16px; }
  .receipt-wrap .muted   { color: #555; }
  .receipt-wrap .divider { border-top: 1px dashed #000; margin: 8px 0; }
  .receipt-wrap .divider-solid { border-top: 1px solid #000; margin: 8px 0; }
  .receipt-wrap table    { width: 100%; border-collapse: collapse; }
  .receipt-wrap td       { padding: 2px 0; vertical-align: top; }
  .receipt-wrap .td-right { text-align: right; white-space: nowrap; padding-left: 6px; }
  .receipt-wrap .logo    { max-width: 100px; max-height: 80px; display: block; margin: 0 auto 6px; }
  .receipt-wrap .total-row td { font-weight: bold; font-size: 14px; border-top: 1.5px solid #000; padding-top: 5px; margin-top: 4px; }
  .receipt-wrap .mod-line { padding-left: 10px; color: #444; font-size: 10px; }
  .receipt-wrap .footer-msg { font-size: 12px; font-weight: bold; margin-top: 10px; }
</style>
@if(!($is_preview ?? false))
</head>
<body>
@endif

<div class="receipt-wrap">
  {{-- EN-TÊTE RESTAURANT --}}
  <div class="center">
    @if(($receipt['footer']['show_logo'] ?? true) && $receipt['restaurant']['logo'])
    <img src="{{ $receipt['restaurant']['logo'] }}" class="logo" alt="Logo">
  @endif
  <div class="bold xlarge">{{ $receipt['restaurant']['name'] }}</div>
  @if($receipt['restaurant']['address'])
    <div class="muted">{{ $receipt['restaurant']['address'] }}</div>
  @endif
  @if($receipt['restaurant']['phone'])
    <div class="muted">Tél : {{ $receipt['restaurant']['phone'] }}</div>
  @endif
  @if($receipt['restaurant']['vat_number'])
    <div class="muted">TVA : {{ $receipt['restaurant']['vat_number'] }}</div>
  @endif
</div>

<div class="divider"></div>

{{-- INFOS COMMANDE --}}
<table>
  <tr><td class="bold">TICKET DE CAISSE</td><td class="td-right bold">{{ $receipt['order']['number'] }}</td></tr>
  <tr><td class="muted">Date</td><td class="td-right">{{ $receipt['order']['date'] }} {{ $receipt['order']['time'] }}</td></tr>
  @if($receipt['order']['table_number'])
  <tr><td class="muted">Table</td><td class="td-right">{{ $receipt['order']['table_number'] }}</td></tr>
  @endif
  @if($receipt['order']['covers'])
  <tr><td class="muted">Couverts</td><td class="td-right">{{ $receipt['order']['covers'] }}</td></tr>
  @endif
  <tr><td class="muted">Type</td><td class="td-right">{{ $receipt['order']['type_label'] }}</td></tr>
  @if($receipt['order']['waiter'])
  <tr><td class="muted">Serveur</td><td class="td-right">{{ $receipt['order']['waiter'] }}</td></tr>
  @endif
  @if($receipt['order']['cashier'])
  <tr><td class="muted">Caissier</td><td class="td-right">{{ $receipt['order']['cashier'] }}</td></tr>
  @endif
</table>

<div class="divider"></div>

{{-- LIGNES ARTICLES --}}
<table>
  <thead><tr><td class="bold">Article</td><td class="td-right bold">Qté</td><td class="td-right bold">PU</td><td class="td-right bold">Total</td></tr></thead>
  <tbody>
    @foreach($receipt['lines'] as $line)
    <tr><td class="bold">{{ $line['name'] }}</td><td class="td-right">{{ $line['quantity'] }}</td><td class="td-right">{{ $line['unit_fmt'] }}</td><td class="td-right">{{ $line['total_fmt'] }}</td></tr>
    @foreach($line['modifiers'] as $mod)
    <tr class="mod-line"><td colspan="3">  + {{ $mod['name'] }}</td><td class="td-right muted">{{ $mod['extra_fmt'] }}</td></tr>
    @endforeach
    @if($line['notes'])
    <tr><td colspan="4" class="muted" style="padding-left:8px;font-style:italic">Note: {{ $line['notes'] }}</td></tr>
    @endif
    @endforeach
  </tbody>
</table>

<div class="divider"></div>

{{-- TOTAUX --}}
<table>
  <tr><td class="muted">Sous-total</td><td class="td-right">{{ $receipt['totals']['subtotal_fmt'] }}</td></tr>
  @if($receipt['totals']['discount'] > 0)
  <tr><td class="muted">Remise {{ $receipt['totals']['discount_reason'] ? '('.$receipt['totals']['discount_reason'].')' : '' }}</td><td class="td-right">{{ $receipt['totals']['discount_fmt'] }}</td></tr>
  @endif
  <tr><td class="muted">TVA ({{ $receipt['totals']['vat_rate'] }}%)</td><td class="td-right">{{ $receipt['totals']['vat_fmt'] }}</td></tr>
  <tr class="total-row"><td class="large">TOTAL</td><td class="td-right large">{{ $receipt['totals']['total_fmt'] }}</td></tr>
</table>

<div class="divider"></div>

{{-- PAIEMENTS --}}
<div class="bold" style="margin-bottom:3px">Règlement :</div>
<table>
  @foreach($receipt['payments'] as $payment)
  <tr><td>{{ $payment['method'] }} @if($payment['reference'])<span class="muted">({{ $payment['reference'] }})</span>@endif</td><td class="td-right">{{ $payment['amount_fmt'] }}</td></tr>
  @if($payment['amount_given'])
  <tr><td class="muted">  Reçu</td><td class="td-right muted">{{ number_format($payment['amount_given'], 0, '.', ' ') }} FCFA</td></tr>
  @endif
  @if($payment['change_given'])
  <tr><td class="muted">  Rendu</td><td class="td-right muted">{{ $payment['change_fmt'] }}</td></tr>
  @endif
  @endforeach
</table>

@if($receipt['totals']['change'] > 0)
<div class="divider-solid"></div>
<table><tr><td class="bold">Monnaie rendue</td><td class="td-right bold">{{ $receipt['totals']['change_fmt'] }}</td></tr></table>
@endif

<div class="divider"></div>

{{-- PIED DE PAGE --}}
<div class="center" style="margin-top:6px">
  <div class="footer-msg">{{ $receipt['footer']['message'] }}</div>
  @if($receipt['footer']['website'])
    <div class="muted" style="margin-top:3px">{{ $receipt['footer']['website'] }}</div>
  @endif
  <div class="muted" style="margin-top:6px;font-size:10px">Généré le {{ now()->format('d/m/Y à H:i') }}</div>
</div> {{-- .receipt-wrap --}}
@if(!($is_preview ?? false))
</body>
</html>
@endif
