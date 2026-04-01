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

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 12px; width: 58mm; padding: 4px; }
  .header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 6px; margin-bottom: 6px; }
  .destination { font-size: 16px; font-weight: bold; letter-spacing: 2px; }
  .order-num { font-size: 11px; margin-top: 2px; }
  .meta { font-size: 10px; color: #333; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  .item { display: flex; gap: 6px; padding: 3px 0; align-items: flex-start; }
  .qty { font-weight: bold; font-size: 14px; min-width: 22px; }
  .name { font-size: 12px; font-weight: bold; flex: 1; }
  .note { font-size: 10px; color: #555; padding-left: 28px; font-style: italic; }
  .customer { margin-top: 6px; font-size: 10px; border-top: 1px dashed #000; padding-top: 4px; }
  .order-note { font-size: 11px; font-style: italic; border-top: 1px dashed #000; padding-top: 4px; margin-top: 4px; }
  @media print { @page { margin: 0; } }
</style>
</head>
<body>
  <div class='header'>
    <div class='destination'>{$icon} {$label}</div>
    <div class='order-num'>#{$order->order_number}</div>
    <div class='meta'>{$table} | {$date} {$time}</div>
  </div>
  <div class='items'>
    {$itemsHtml}
  </div>
  {$orderNote}
  {$customerInfo}
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
        $vat         = number_format((float) $order->vat_amount, 0, ',', ' ');
        $total       = number_format((float) $order->total, 0, ',', ' ');
        $date        = now()->format('d/m/Y H:i');
        $cashier     = $order->waiter?->first_name ?? 'Caisse';
        $restoAddress = $restaurant->address ?? '';

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 11px; width: 58mm; padding: 4px; }
  .header { text-align: center; margin-bottom: 6px; }
  .resto-name { font-size: 14px; font-weight: bold; }
  .line { display: flex; justify-content: space-between; padding: 2px 0; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  .total-line { font-weight: bold; font-size: 13px; }
  .footer { text-align: center; margin-top: 6px; font-size: 10px; }
  @media print { @page { margin: 0; } }
</style>
</head>
<body>
  <div class='header'>
    <div class='resto-name'>{$restaurant->name}</div>
    <div>{$restoAddress}</div>
    <div>{$date}</div>
  </div>
  <div class='divider'></div>
  <div class='line'><span>{$table}</span><span>#{$order->order_number}</span></div>
  <div class='line'><span>Caissier: {$cashier}</span></div>
  <div class='divider'></div>
  {$itemsHtml}
  <div class='divider'></div>
  <div class='line'><span>Sous-total</span><span>{$subtotal} FCFA</span></div>
  <div class='line'><span>TVA (18%)</span><span>{$vat} FCFA</span></div>
  <div class='divider'></div>
  <div class='line total-line'><span>TOTAL</span><span>{$total} FCFA</span></div>
  <div class='divider'></div>
  {$paymentsHtml}
  <div class='footer'>Merci de votre visite !<br>Revenez nous voir !</div>
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

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; color: #222; margin: 20mm 15mm; }
  .header { display: flex; justify-content: space-between; margin-bottom: 20px; }
  .resto-info h1 { font-size: 22px; color: #1a1a2e; margin-bottom: 4px; }
  .invoice-info { text-align: right; }
  .invoice-info h2 { font-size: 18px; color: #16213e; }
  .divider { border: none; border-top: 2px solid #1a1a2e; margin: 10px 0; }
  .customer-block { background: #f5f5f5; padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th { background: #1a1a2e; color: white; padding: 8px; text-align: left; }
  td { padding: 7px 8px; border-bottom: 1px solid #eee; }
  .totals-table td { border: none; padding: 4px 8px; }
  .total-row td { font-weight: bold; font-size: 14px; border-top: 2px solid #1a1a2e; }
  .footer { margin-top: 30px; text-align: center; font-size: 11px; color: #666; }
  @media print { @page { size: A4; margin: 15mm; } }
</style>
</head>
<body>
  <div class='header'>
    <div class='resto-info'>
      <h1>{$restaurant->name}</h1>
      <div>{$restoAddr}</div>
      <div>{$restoPhone}</div>
    </div>
    <div class='invoice-info'>
      <h2>FACTURE</h2>
      <div>N° {$invoiceNum}</div>
      <div>Date: {$date}</div>
      <div>{$table}</div>
    </div>
  </div>
  <hr class='divider'>
  {$customerBlock}
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Article</th>
        <th style='text-align:center'>Qté</th>
        <th style='text-align:right'>P.U. (FCFA)</th>
        <th style='text-align:right'>Total (FCFA)</th>
      </tr>
    </thead>
    <tbody>
      {$itemsHtml}
    </tbody>
  </table>
  <table class='totals-table' style='width:40%; margin-left:60%; margin-top:10px;'>
    <tr><td>Sous-total</td><td style='text-align:right'>{$subtotal} FCFA</td></tr>
    {$discountRow}
    <tr><td>TVA (18%)</td><td style='text-align:right'>{$vat} FCFA</td></tr>
    <tr class='total-row'><td>TOTAL</td><td style='text-align:right'>{$total} FCFA</td></tr>
  </table>
  <div style='margin-top:20px;'>
    <strong>Mode(s) de paiement:</strong>
    <table style='width:40%;'>
      {$paymentsHtml}
    </table>
  </div>
  <div class='footer'>
    Merci pour votre confiance — {$restaurant->name}
  </div>
</body>
</html>";
    }
}
