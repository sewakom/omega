<?php

namespace App\Services;

use App\Models\Order;
use App\Models\CashSession;
use App\Services\OrderRoutingService;

/**
 * TicketPrintService
 * Génère le HTML des tickets d'impression:
 * - Ticket cuisine/bar/pizza (SANS prix) format 58mm
 * - Reçu client (AVEC prix) format 58mm
 * - Facture A4 normalisée
 */
class TicketPrintService
{
    public function __construct(
        protected OrderRoutingService $routing
    ) {}

    /**
     * Ticket destination (cuisine/bar/pizza) — SANS PRIX
     * Format 58mm pour imprimante thermique
     */
    public function kitchenTicketHtml(Order $order, string $destination = 'kitchen'): string
    {
        $order->loadMissing([
            'items.product.category',
            'table',
            'waiter',
        ]);

        // Filtrer uniquement les items de cette destination
        $allGroups = $this->routing->groupByDestination(
            $order->items->whereNotIn('status', ['cancelled'])
        );
        $items = $allGroups[$destination] ?? collect();

        if ($items->isEmpty()) return '';

        $label = $this->routing->destinationLabel($destination);
        $icon  = $this->routing->destinationIcon($destination);
        $table = $order->table ? "TABLE {$order->table->number}" : strtoupper($order->type === 'gozem' ? 'GOZEM' : ($order->type === 'takeaway' ? 'À EMPORTER' : ''));
        $time  = now()->format('H:i');
        $date  = now()->format('d/m/Y');

        $itemsHtml = '';
        foreach ($items as $item) {
            $note = $item->notes ? "<div class='note'>⚠ {$item->notes}</div>" : '';
            $itemsHtml .= "
            <div class='item'>
                <span class='qty'>x{$item->quantity}</span>
                <span class='name'>{$item->product->name}</span>
                {$note}
            </div>";
        }

        $customerInfo = '';
        if ($order->type === 'gozem' && $order->customer_name) {
            $customerInfo = "<div class='customer'>Client: {$order->customer_name} | {$order->customer_phone}</div>";
        }

        $orderNote = $order->notes
            ? "<div class='order-note'>📝 {$order->notes}</div>"
            : '';

        $restaurant = $order->restaurant;
        $logoHtml = $restaurant->logo 
            ? "<img src='{$restaurant->logo}' style='max-width:40mm;max-height:15mm;display:block;margin:0 auto 4px'>" 
            : "<div style='font-size:16px;font-weight:900;text-transform:uppercase;opacity:0.2;transform:rotate(-15deg);position:absolute;z-index:-1;top:50%;left:10%;right:10%;text-align:center;'>{$restaurant->name}</div>";

        $restaurant = $order->restaurant;
        $restoPhone = $restaurant->phone ? " | Tél: {$restaurant->phone}" : '';
        
        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 13px; width: 58mm; padding: 4px; overflow: hidden; position: relative; }
  .header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 6px; margin-bottom: 6px; position: relative; }
  .destination { font-size: 18px; font-weight: bold; letter-spacing: 2px; }
  .order-num { font-size: 12px; margin-top: 2px; font-weight: bold; }
  .meta { font-size: 11px; color: #000; font-weight: 800; border: 1px solid #000; display: inline-block; padding: 2px 8px; margin-top: 4px; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  .item { display: flex; gap: 8px; padding: 5px 0; align-items: flex-start; border-bottom: 0.5px solid #eee; }
  .qty { font-weight: 900; font-size: 18px; min-width: 25px; }
  .name { font-size: 14px; font-weight: 900; flex: 1; text-transform: uppercase; }
  .note { font-size: 11px; color: #000; padding-left: 33px; font-weight: bold; font-style: italic; }
  .customer { margin-top: 8px; font-size: 10px; border-top: 1px dashed #000; padding-top: 4px; }
  .order-note { font-size: 12px; font-weight: bold; border: 2px solid #000; padding: 4px; margin-top: 6px; }
  .footer-name { text-align: center; font-size: 10px; font-weight: bold; margin-top: 10px; text-transform: uppercase; border-top: 1px solid #000; padding-top: 4px; }
  @media print { @page { margin: 0; } }
</style>
</head>
<body>
  {$logoHtml}
  <div class='header'>
    <div class='destination'>{$icon} {$label}</div>
    <div class='order-num'>#{$order->order_number}</div>
    <div class='meta'>LOCATION: {$table}{$restoPhone}</div>
  </div>
  <div class='items'>
    {$itemsHtml}
  </div>
  {$orderNote}
  {$customerInfo}
  <div class='footer-name'>{$restaurant->name} — Omega POS</div>
</body>
</html>";
    }

    /**
     * Reçu client avec prix — format 58mm
     */
    public function receiptHtml(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $restaurant = $order->restaurant;
        $items = $order->items->whereNotIn('status', ['cancelled']);

        $itemsHtml = '';
        foreach ($items as $item) {
            $subtotal  = number_format($item->quantity * $item->unit_price, 0, ',', ' ');
            $itemsHtml .= "
            <div class='line'>
                <span>{$item->quantity}x {$item->product->name}</span>
                <span>{$subtotal}</span>
            </div>";
        }

        $paymentsHtml = '';
        foreach ($order->payments as $pmt) {
            $method = strtoupper($pmt->method);
            $amt    = number_format($pmt->amount, 0, ',', ' ');
            $paymentsHtml .= "<div class='line'><span>{$method}</span><span>{$amt}</span></div>";
            if ($pmt->change_given) {
                $change = number_format($pmt->change_given, 0, ',', ' ');
                $paymentsHtml .= "<div class='line'><span>Monnaie rendue</span><span>{$change}</span></div>";
            }
        }

        $table       = $order->table ? "Table {$order->table->number}" : ucfirst($order->type);
        $subtotal    = number_format((float) $order->subtotal, 0, ',', ' ');
        $vatRate     = $restaurant->settings['default_vat_rate'] ?? 18;
        $vat         = number_format((float) $order->vat_amount, 0, ',', ' ');
        $total       = number_format((float) $order->total, 0, ',', ' ');
        $date        = now()->format('d/m/Y H:i');
        $cashier     = $order->waiter?->first_name ?? 'Caisse';
        $restoAddress = $restaurant->address ?? '';

        $logoHtml = $restaurant->logo 
            ? "<img src='{$restaurant->logo}' style='max-width:40mm;max-height:15mm;display:block;margin:0 auto 4px'>" 
            : "<div style='font-size:16px;font-weight:900;text-transform:uppercase;opacity:0.1;transform:rotate(-15deg);position:absolute;z-index:-1;top:50%;left:10%;right:10%;text-align:center;'>{$restaurant->name}</div>";


        $thanksMsg   = $restaurant->settings['thank_you_message'] ?? 'Merci de votre visite !';
        $restoPhoneHtml = $restaurant->phone ? "<div>Tél: {$restaurant->phone}</div>" : '';

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 11px; width: 58mm; padding: 4px; position: relative; overflow: hidden; }
  .header { text-align: center; margin-bottom: 6px; border-bottom: 1px dashed #000; padding-bottom: 4px; }
  .resto-name { font-size: 15px; font-weight: bold; text-transform: uppercase; }
  .line { display: flex; justify-content: space-between; padding: 2px 0; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  .total-line { font-weight: bold; font-size: 14px; }
  .footer { text-align: center; margin-top: 10px; font-size: 10px; font-weight: bold; border-top: 1px dashed #000; padding-top: 4px; }
  .table-box { border: 2px solid #000; font-weight: 900; text-align: center; padding: 4px; margin: 4px 0; font-size: 14px; }
  @media print { @page { margin: 0; } }
</style>
</head>
<body>
  {$logoHtml}
  <div class='header'>
    <div class='resto-name'>{$restaurant->name}</div>
    <div style='font-size:9px'>{$restoAddress}</div>
    <div style='font-size:9px'>{$restoPhoneHtml}</div>
    <div style='font-size:9px'>{$date}</div>
  </div>
  <div class='table-box'>{$table}</div>
  <div class='line'><span>Ticket</span><span>#{$order->order_number}</span></div>
  <div class='line'><span>Caissier: {$cashier}</span></div>
  <div class='divider'></div>
  {$itemsHtml}
  <div class='divider'></div>
  <div class='line'><span>Sous-total</span><span>{$subtotal} FCFA</span></div>
  <div class='line'><span>TVA ({$vatRate}%)</span><span>{$vat} FCFA</span></div>
  <div class='divider'></div>
  <div class='line total-line'><span>TOTAL</span><span>{$total} FCFA</span></div>
  <div class='divider'></div>
  {$paymentsHtml}
  <div class='footer'>{$thanksMsg}<br>{$restaurant->name}</div>
</body>
</html>";
    }

    /**
     * Facture A4 normalisée
     */
    public function invoiceA4Html(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $restaurant = $order->restaurant;
        $items      = $order->items->whereNotIn('status', ['cancelled']);

        $itemsHtml = '';
        $i = 1;
        foreach ($items as $item) {
            $subtotal = number_format($item->quantity * $item->unit_price, 0, ',', ' ');
            $price    = number_format($item->unit_price, 0, ',', ' ');
            $itemsHtml .= "
            <tr>
                <td>{$i}</td>
                <td>{$item->product->name}</td>
                <td style='text-align:center'>{$item->quantity}</td>
                <td style='text-align:right'>{$price}</td>
                <td style='text-align:right'>{$subtotal}</td>
            </tr>";
            $i++;
        }

        $paymentsHtml = '';
        foreach ($order->payments as $pmt) {
            $method = strtoupper($pmt->method);
            $amt    = number_format($pmt->amount, 0, ',', ' ');
            $paymentsHtml .= "<tr><td>{$method}</td><td style='text-align:right'>{$amt} FCFA</td></tr>";
        }

        $table       = $order->table ? "Table {$order->table->number}" : ucfirst(str_replace('_', ' ', $order->type));
        $subtotal    = number_format((float) $order->subtotal, 0, ',', ' ');
        $vatRate     = $restaurant->settings['default_vat_rate'] ?? 18;
        $vat         = number_format((float) $order->vat_amount, 0, ',', ' ');
        $discount    = $order->discount_amount > 0 ? number_format((float) $order->discount_amount, 0, ',', ' ') : null;
        $total       = number_format((float) $order->total, 0, ',', ' ');
        $date        = now()->format('d/m/Y H:i');
        $invoiceNum  = 'INV-' . $order->order_number;
        $restoAddr   = $restaurant->address ?? '';
        $restoPhone  = $restaurant->phone ?? '';

        $discountRow = $discount
            ? "<tr><td>Remise</td><td colspan='3'>{$order->discount_reason}</td><td style='text-align:right'>- {$discount}</td></tr>"
            : '';

        $customerBlock = '';
        if ($order->customer_name) {
            $customerBlock = "<div class='customer-block'><strong>Client:</strong> {$order->customer_name} | {$order->customer_phone}</div>";
        }

        $logoHtml = $restaurant->logo 
            ? "<img src='{$restaurant->logo}' style='max-height:25mm;'>" 
            : "<div style='font-size:40px;font-weight:900;text-transform:uppercase;opacity:0.05;transform:rotate(-30deg);position:absolute;z-index:-1;top:40%;left:5%;right:5%;text-align:center;'>{$restaurant->name}</div>";

        $thanksMsg   = $restaurant->settings['thank_you_message'] ?? 'Merci pour votre confiance';

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; color: #222; margin: 20mm 15mm; position: relative; overflow: hidden; }
  .header { display: flex; justify-content: space-between; margin-bottom: 20px; }
  .resto-info h1 { font-size: 24px; color: #1a1a2e; margin-bottom: 4px; text-transform: uppercase; }
  .invoice-info { text-align: right; }
  .invoice-info h2 { font-size: 20px; color: #16213e; margin-bottom: 10px; }
  .divider { border: none; border-top: 2px solid #1a1a2e; margin: 10px 0; }
  .customer-block { background: #f5f5f5; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid #1a1a2e; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th { background: #1a1a2e; color: white; padding: 10px; text-align: left; }
  td { padding: 8px 10px; border-bottom: 1px solid #eee; }
  .totals-table td { border: none; padding: 5px 10px; }
  .total-row td { font-weight: bold; font-size: 16px; border-top: 2px solid #1a1a2e; padding-top: 10px; }
  .footer { margin-top: 50px; text-align: center; font-size: 11px; color: #666; border-top: 1px solid #eee; padding-top: 15px; }
  .table-badge { background: #1a1a2e; color: white; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 14px; }
  @media print { @page { size: A4; margin: 15mm; } }
</style>
</head>
<body>
  {$logoHtml}
  <div class='header'>
    <div class='resto-info'>
      <h1>{$restaurant->name}</h1>
      <div>{$restoAddr}</div>
      <div>Tél: {$restaurant->phone}</div>
      <div>{$restaurant->email}</div>
    </div>
    <div class='invoice-info'>
      <h2>FACTURE</h2>
      <div style='margin-bottom:8px'><span class='table-badge'>{$table}</span></div>
      <div>N° {$invoiceNum}</div>
      <div>Date: {$date}</div>
    </div>
  </div>
  <hr class='divider'>
  {$customerBlock}
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Article / Description</th>
        <th style='text-align:center'>Qté</th>
        <th style='text-align:right'>P.U. (FCFA)</th>
        <th style='text-align:right'>Total (FCFA)</th>
      </tr>
    </thead>
    <tbody>
      {$itemsHtml}
    </tbody>
  </table>
  <table class='totals-table' style='width:45%; margin-left:55%; margin-top:20px;'>
    <tr><td>Sous-total</td><td style='text-align:right'>{$subtotal} FCFA</td></tr>
    {$discountRow}
    <tr><td>TVA ({$vatRate}%)</td><td style='text-align:right'>{$vat} FCFA</td></tr>
    <tr class='total-row'><td>TOTAL À PAYER</td><td style='text-align:right'>{$total} FCFA</td></tr>
  </table>
  <div style='margin-top:40px;'>
    <h3 style='border-bottom:1px solid #eee; padding-bottom:5px; font-size:12px; margin-bottom:10px;'>DÉTAILS PAIEMENT</h3>
    <table style='width:50%;'>
      {$paymentsHtml}
    </table>
  </div>
  <div class='footer'>
    {$thanksMsg} — {$restaurant->name} — Document certifié
  </div>
</body>
</html>";
    }
}
