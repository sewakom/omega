<?php

namespace App\Services;

use App\Models\Order;
use App\Models\CashSession;
use App\Services\OrderRoutingService;
use App\Libraries\Fpdf;

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
        $filteredItems = $order->items->whereNotIn('status', ['cancelled']);
        $allGroups = $this->routing->groupByDestination($filteredItems);
        $items = $allGroups[$destination] ?? collect();

        if ($items->isEmpty()) return '';

        $label = $this->routing->destinationLabel($destination);
        $icon  = $this->routing->destinationIcon($destination);
        
        $locationLabel = '';
        if ($order->table instanceof \App\Models\Table) {
            $locationLabel = "TABLE " . $order->table->number;
        } else {
            $locationLabel = strtoupper($order->type === 'gozem' ? 'GOZEM' : ($order->type === 'takeaway' ? 'À EMPORTER' : 'VENTE DIRECTE'));
        }
        
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
    <div class='meta'>LOCATION: {$locationLabel}{$restoPhone}</div>
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

        $tableLabel  = $order->table ? "Table {$order->table->number}" : ucfirst($order->type);
        $subtotal    = number_format((float) ($order->subtotal ?? 0), 0, ',', ' ');
        $vatRate     = $restaurant->settings['default_vat_rate'] ?? 18;
        $vat         = number_format((float) ($order->vat_amount ?? 0), 0, ',', ' ');
        $total       = number_format((float) ($order->total ?? 0), 0, ',', ' ');
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
  <div class='table-box'>{$tableLabel}</div>
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

        $locationLabel = $order->table ? "Table {$order->table->number}" : ucfirst(str_replace('_', ' ', $order->type));
        $subtotal    = number_format((float) ($order->subtotal ?? 0), 0, ',', ' ');
        $vatRate     = $restaurant->settings['default_vat_rate'] ?? 18;
        $vat         = number_format((float) ($order->vat_amount ?? 0), 0, ',', ' ');
        $discount    = ($order->discount_amount ?? 0) > 0 ? number_format((float) $order->discount_amount, 0, ',', ' ') : null;
        $total       = number_format((float) ($order->total ?? 0), 0, ',', ' ');
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
  body { font-family: Arial, sans-serif; font-size: 11px; color: #222; margin: 10mm 12mm; position: relative; overflow: hidden; }
  .header { display: flex; justify-content: space-between; margin-bottom: 10px; }
  .resto-info h1 { font-size: 20px; color: #1a1a2e; margin-bottom: 2px; text-transform: uppercase; }
  .invoice-info { text-align: right; }
  .invoice-info h2 { font-size: 18px; color: #16213e; margin-bottom: 6px; }
  .divider { border: none; border-top: 2px solid #1a1a2e; margin: 6px 0; }
  .customer-block { background: #f5f5f5; padding: 8px; border-radius: 6px; margin-bottom: 10px; border-left: 4px solid #1a1a2e; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th { background: #1a1a2e; color: white; padding: 6px 8px; text-align: left; font-size: 10px; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; }
  .totals-table td { border: none; padding: 3px 8px; }
  .total-row td { font-weight: bold; font-size: 14px; border-top: 2px solid #1a1a2e; padding-top: 6px; }
  .footer { margin-top: 15px; text-align: center; font-size: 10px; color: #666; border-top: 1px solid #eee; padding-top: 8px; }
  .table-badge { background: #1a1a2e; color: white; padding: 3px 10px; border-radius: 20px; font-weight: bold; font-size: 12px; }
  @media print { @page { size: A4; margin: 10mm; } }
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
      <div style='margin-bottom:8px'><span class='table-badge'>{$locationLabel}</span></div>
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
  <div style='margin-top:10px;'>
    <h3 style='border-bottom:1px solid #eee; padding-bottom:3px; font-size:11px; margin-bottom:6px;'>DÉTAILS PAIEMENT</h3>
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

    /**

     * Génère la facture A4 en PDF via FPDF
     */
    public function generateInvoiceA4Pdf(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $restaurant = $order->restaurant;
        $items      = $order->items->whereNotIn('status', ['cancelled']);

        $pdf = new Fpdf('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        
        // Header
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetTextColor(26, 26, 46);
        $pdf->Cell(100, 10, utf8_decode(strtoupper($restaurant->name)), 0, 0);
        
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(50, 50, 100);
        $pdf->Cell(80, 10, 'FACTURE', 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);

        // Restaurant Info
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(100, 5, utf8_decode($restaurant->address ?? ''), 0, 0);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(80, 5, 'Ref : INV-' . $order->order_number, 0, 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(100, 5, 'Tel : ' . $restaurant->phone, 0, 0);
        $pdf->Cell(80, 5, 'Date : ' . $order->created_at->format('d/m/Y H:i'), 0, 1, 'R');

        if ($restaurant->vat_number) {
            $pdf->Cell(100, 5, 'TVA : ' . $restaurant->vat_number, 0, 1, 'L');
        }

        $pdf->Ln(6);

        // Two Columns: Order Details & Customer
        $yBefore = $pdf->GetY();
        
        // Info Commande
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(90, 6, utf8_decode('DÉTAILS COMMANDE'), 'B', 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(45, 6, 'Type:', 0, 0); $pdf->Cell(45, 6, strtoupper($order->type), 0, 1);
        if ($order->table) {
            $pdf->Cell(45, 6, 'Table:', 0, 0); $pdf->Cell(45, 6, $order->table->number, 0, 1);
        }
        if ($order->waiter) {
            $pdf->Cell(45, 6, 'Serveur:', 0, 0); $pdf->Cell(45, 6, utf8_decode($order->waiter->name), 0, 1);
        }
        
        // Info Client (Positionné à droite)
        $pdf->SetY($yBefore);
        $pdf->SetX(110);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(85, 6, utf8_decode('INFORMATIONS CLIENT'), 'B', 1, 'L');
        $pdf->SetX(110);
        $pdf->SetFont('Helvetica', '', 11);
        if ($order->customer_name) {
            $pdf->SetX(110);
            $pdf->Cell(85, 8, utf8_decode(strtoupper($order->customer_name)), 0, 1, 'L');
            $pdf->SetX(110);
            $pdf->Cell(85, 5, 'Tel : ' . $order->customer_phone, 0, 1, 'L');
        } else {
            $pdf->SetX(110);
            $pdf->Cell(85, 8, 'CLIENT DE PASSAGE', 0, 1, 'L');
        }

        $pdf->Ln(6);

        // Table Header
        $pdf->SetFillColor(26, 26, 46);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(10, 10, '#', 0, 0, 'C', true);
        $pdf->Cell(100, 10, 'Article / Description', 0, 0, 'L', true);
        $pdf->Cell(20, 10, 'Qt', 0, 0, 'C', true);
        $pdf->Cell(25, 10, 'P.U.', 0, 0, 'R', true);
        $pdf->Cell(25, 10, 'Total', 0, 1, 'R', true);

        // Table Body
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $i = 1;
        foreach ($items as $item) {
            $subtotal = $item->quantity * $item->unit_price;
            $pdf->Cell(10, 8, $i++, 'B', 0, 'C');
            $pdf->Cell(100, 8, utf8_decode($item->product->name), 'B', 0, 'L');
            $pdf->Cell(20, 8, $item->quantity, 'B', 0, 'C');
            $pdf->Cell(25, 8, number_format($item->unit_price, 0, '.', ' '), 'B', 0, 'R');
            $pdf->Cell(25, 8, number_format($subtotal, 0, '.', ' '), 'B', 1, 'R');
            
            if ($item->notes) {
                $pdf->SetFont('Helvetica', 'I', 8);
                $pdf->Cell(10, 6, '', 'B');
                $pdf->Cell(170, 6, 'Note: ' . utf8_decode($item->notes), 'B', 1, 'L');
                $pdf->SetFont('Helvetica', '', 10);
            }
        }

        $pdf->Ln(4);

        // Totals
        $pdf->SetX(120);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(40, 8, 'Sous-total', 0, 0, 'L');
        $pdf->Cell(30, 8, number_format((float) $order->subtotal, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        
        if ($order->discount_amount > 0) {
            $pdf->SetX(120);
            $pdf->Cell(40, 8, 'Remise', 0, 0, 'L');
            $pdf->Cell(30, 8, '-' . number_format((float) $order->discount_amount, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        }

        $pdf->SetX(120);
        $pdf->Cell(40, 8, 'TVA (' . ($restaurant->settings['default_vat_rate'] ?? 18) . '%)', 0, 0, 'L');
        $pdf->Cell(30, 8, number_format((float) $order->vat_amount, 0, '.', ' ') . ' FCFA', 0, 1, 'R');

        $pdf->SetX(120);
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetFillColor(26, 26, 46);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(40, 10, 'TOTAL', 0, 0, 'L', true);
        $pdf->Cell(30, 10, number_format((float) $order->total, 0, '.', ' ') . ' FCFA', 0, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);

        // Payments
        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(100, 5, utf8_decode('DÉTAILS PAIEMENT'), 'B', 1, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        foreach ($order->payments as $pmt) {
            $pdf->Cell(50, 5, utf8_decode(strtoupper($pmt->method)), 0, 0, 'L');
            $pdf->Cell(50, 5, number_format($pmt->amount, 0, '.', ' ') . ' FCFA', 0, 1, 'L');
        }

        // Footer — positionné après le contenu (pas en absolu)
        $pdf->Ln(8);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->Cell(0, 5, utf8_decode($restaurant->settings['thank_you_message'] ?? 'Merci pour votre confiance'), 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(0, 4, utf8_decode($restaurant->name . ' — Document certifié par Omega POS'), 0, 1, 'C');

        return $pdf->Output('S');
    }

    /**
     * Génère le reçu client en PDF (format ticket thermique 58mm-ish)
     */
    public function generateReceiptPdf(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $restaurant = $order->restaurant;
        $items      = $order->items->whereNotIn('status', ['cancelled']);

        $pdf = new Fpdf('P', 'mm', array(58, 200 + ($items->count() * 15)));
        $pdf->SetMargins(4, 5, 4);
        $pdf->AddPage();
        
        $pdf->SetFont('Courier', 'B', 12);
        if ($restaurant->logo) {
            // Optionnel: logiqe pour le logo si nécessaire
        }
        $pdf->Cell(0, 6, utf8_decode(strtoupper($restaurant->name)), 0, 1, 'C');
        
        $pdf->SetFont('Courier', '', 8);
        if ($restaurant->address) $pdf->Cell(0, 4, utf8_decode($restaurant->address), 0, 1, 'C');
        if ($restaurant->phone)   $pdf->Cell(0, 4, 'Tel : ' . $restaurant->phone, 0, 1, 'C');
        if ($restaurant->vat_number) $pdf->Cell(0, 4, 'TVA : ' . $restaurant->vat_number, 0, 1, 'C');
        
        $pdf->Cell(0, 4, '----------------------------------', 0, 1, 'C');
        
        $pdf->SetFont('Courier', 'B', 9);
        $pdf->Cell(30, 5, 'TICKET DE CAISSE', 0, 0);
        $pdf->Cell(20, 5, $order->order_number, 0, 1, 'R');
        
        $pdf->SetFont('Courier', '', 8);
        $pdf->Cell(30, 4, 'Date', 0, 0);
        $pdf->Cell(20, 4, $order->created_at->format('d/m/Y H:i'), 0, 1, 'R');
        
        if ($order->table) {
            $pdf->Cell(30, 4, 'Table', 0, 0);
            $tableNum = ($order->table instanceof \App\Models\Table) ? $order->table->number : 'N/A';
            $pdf->Cell(20, 4, $tableNum, 0, 1, 'R');
        }
        
        $pdf->Cell(30, 4, 'Type', 0, 0);
        $pdf->Cell(20, 4, strtoupper($order->type), 0, 1, 'R');

        if ($order->waiter) {
            $pdf->Cell(30, 4, 'Serveur', 0, 0);
            $pdf->Cell(20, 4, utf8_decode($order->waiter->name), 0, 1, 'R');
        }

        $pdf->Cell(0, 4, '----------------------------------', 0, 1, 'C');

        // LIGNES ARTICLES
        $pdf->SetFont('Courier', 'B', 8);
        $pdf->Cell(24, 5, 'Article', 0, 0);
        $pdf->Cell(6, 5, 'Qt', 0, 0, 'C');
        $pdf->Cell(10, 5, 'PU', 0, 0, 'R');
        $pdf->Cell(10, 5, 'Total', 0, 1, 'R');
        $pdf->SetFont('Courier', '', 8);

        foreach ($items as $item) {
            $lineTotal = $item->quantity * $item->unit_price;
            $pdf->Cell(24, 4, utf8_decode(substr($item->product->name, 0, 14)), 0, 0);
            $pdf->Cell(6, 4, $item->quantity, 0, 0, 'C');
            $pdf->Cell(10, 4, number_format($item->unit_price, 0, '', ''), 0, 0, 'R');
            $pdf->Cell(10, 4, number_format($lineTotal, 0, '', ''), 0, 1, 'R');

            if ($item->notes) {
                $pdf->SetFont('Courier', 'I', 7);
                $pdf->Cell(5, 3);
                $pdf->Cell(45, 3, 'Note: ' . utf8_decode($item->notes), 0, 1);
                $pdf->SetFont('Courier', '', 8);
            }
        }

        $pdf->Cell(0, 4, '----------------------------------', 0, 1, 'C');

        // TOTAUX
        $pdf->Cell(35, 4, 'Sous-total', 0, 0);
        $pdf->Cell(15, 4, number_format((float) $order->subtotal, 0, '.', ' '), 0, 1, 'R');
        
        if ($order->discount_amount > 0) {
            $pdf->Cell(35, 4, 'Remise', 0, 0);
            $pdf->Cell(15, 4, '-' . number_format((float) $order->discount_amount, 0, '.', ' '), 0, 1, 'R');
        }

        $pdf->Cell(35, 4, 'TVA (' . ($restaurant->settings['default_vat_rate'] ?? 18) . '%)', 0, 0);
        $pdf->Cell(15, 4, number_format((float) $order->vat_amount, 0, '.', ' '), 0, 1, 'R');

        $pdf->SetFont('Courier', 'B', 10);
        $pdf->Cell(35, 6, 'TOTAL', 0, 0);
        $pdf->Cell(15, 6, number_format((float) $order->total, 0, '.', ' '), 0, 1, 'R');
        $pdf->SetFont('Courier', '', 8);

        $pdf->Cell(0, 4, '----------------------------------', 0, 1, 'C');

        // PAIEMENTS
        $pdf->SetFont('Courier', 'B', 8);
        $pdf->Cell(0, 5, utf8_decode('Règlement :'), 0, 1);
        $pdf->SetFont('Courier', '', 8);
        
        foreach ($order->payments as $pmt) {
            $pdf->Cell(35, 4, utf8_decode(strtoupper($pmt->method)), 0, 0);
            $pdf->Cell(15, 4, number_format($pmt->amount, 0, '.', ' '), 0, 1, 'R');
            
            if ($pmt->amount_given) {
                $pdf->SetFont('Courier', '', 7);
                $pdf->Cell(35, 3, utf8_decode('  Recu'), 0, 0);
                $pdf->Cell(15, 3, number_format($pmt->amount_given, 0, '.', ' '), 0, 1, 'R');
                $pdf->SetFont('Courier', '', 8);
            }
            if ($pmt->change_given) {
                $pdf->SetFont('Courier', '', 7);
                $pdf->Cell(35, 3, utf8_decode('  Rendu'), 0, 0);
                $pdf->Cell(15, 3, number_format($pmt->change_given, 0, '.', ' '), 0, 1, 'R');
                $pdf->SetFont('Courier', '', 8);
            }
        }

        $pdf->Cell(0, 4, '----------------------------------', 0, 1, 'C');

        // PIED DE PAGE
        $pdf->SetFont('Courier', 'B', 9);
        $pdf->MultiCell(0, 4, utf8_decode($restaurant->settings['thank_you_message'] ?? 'Merci pour votre confiance'), 0, 'C');
        
        $pdf->SetFont('Courier', '', 7);
        $pdf->Cell(0, 4, 'Generé le ' . now()->format('d/m/Y à H:i'), 0, 1, 'C');
        $pdf->Cell(0, 4, 'Certifié par Omega POS', 0, 1, 'C');

        return $pdf->Output('S');
    }

    /**
     * Génère la facture A4 PDF pour une Ardoise (Customer Tab)
     */
    public function generateTabInvoicePdf(\App\Models\CustomerTab $tab): string
    {
        $tab->load(['orders.items.product', 'orders.table', 'restaurant', 'creator']);
        $restaurant = $tab->restaurant;
        
        $pdf = new Fpdf('P', 'mm', 'A4');
        $pdf->AddPage();
        
        // Polices
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(26, 26, 46);
        
        // Header
        $pdf->Cell(120, 10, utf8_decode(strtoupper($restaurant->name)), 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, utf8_decode('FACTURE ARDOISE'), 0, 1, 'R');
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(120, 5, utf8_decode($restaurant->address ?? 'ADRESSE NON DEFINIE'), 0, 0);
        $pdf->Cell(0, 5, 'Date: ' . now()->format('d/m/Y'), 0, 1, 'R');
        
        $pdf->Cell(120, 5, utf8_decode('Tél: ' . $restaurant->phone), 0, 0);
        $pdf->Cell(0, 5, utf8_decode('Réf: ARD-' . $tab->id), 0, 1, 'R');
        
        $pdf->Ln(10);
        
        // Bloc Client
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(0);
        $pdf->Cell(0, 8, utf8_decode('  INFORMATIONS CLIENT'), 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(95, 7, utf8_decode('Nom: ' . $tab->full_name), 0, 0);
        $pdf->Cell(0, 7, utf8_decode('Téléphone: ' . $tab->phone), 0, 1);
        $pdf->Cell(0, 7, utf8_decode('Ouverte le: ' . $tab->opened_at->format('d/m/Y à H:i')), 0, 1);
        
        $pdf->Ln(5);
        
        // Tableau des articles
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(26, 26, 46);
        $pdf->SetTextColor(255);
        
        $pdf->Cell(10, 10, '#', 0, 0, 'C', true);
        $pdf->Cell(85, 10, utf8_decode('Désignation'), 0, 0, 'L', true);
        $pdf->Cell(15, 10, utf8_decode('Qté'), 0, 0, 'C', true);
        $pdf->Cell(35, 10, 'P.U. (FCFA)', 0, 0, 'R', true);
        $pdf->Cell(35, 10, 'Total (FCFA)', 0, 1, 'R', true);
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0);
        
        $i = 1;
        $totalVat = 0;
        foreach ($tab->orders as $order) {
            $totalVat += (float) $order->vat_amount;
            foreach ($order->items->whereNotIn('status', ['cancelled']) as $item) {
                $pdf->Cell(10, 8, $i, 'B', 0, 'C');
                $pdf->Cell(85, 8, utf8_decode($item->product->name), 'B', 0, 'L');
                $pdf->Cell(15, 8, $item->quantity, 'B', 0, 'C');
                $pdf->Cell(35, 8, number_format($item->unit_price, 0, '.', ' '), 'B', 0, 'R');
                $pdf->Cell(35, 8, number_format($item->quantity * $item->unit_price, 0, '.', ' '), 'B', 1, 'R');
                $i++;
                
                if ($pdf->GetY() > 270) {
                    $pdf->AddPage();
                    $pdf->SetFont('Arial', 'B', 10);
                    $pdf->Cell(10, 10, '#', 0, 0, 'C', true);
                    $pdf->Cell(85, 10, 'Suite...', 0, 0, 'L', true);
                    $pdf->Ln();
                    $pdf->SetFont('Arial', '', 9);
                }
            }
        }
        
        $pdf->Ln(5);
        
        // Totaux
        $pdf->SetX(120);
        $pdf->SetFont('Arial', 'B', 10);
        $total = $tab->total_amount;
        $paid = $tab->paid_amount;
        $remaining = $tab->remainingAmount();
        $vatRate = $restaurant->settings['default_vat_rate'] ?? 18;
        
        $pdf->Cell(45, 8, 'Total HT', 0, 0);
        $pdf->Cell(35, 8, number_format($total - $totalVat, 0, '.', ' '), 0, 1, 'R');
        
        $pdf->SetX(120);
        $pdf->Cell(45, 8, utf8_decode('TVA (' . $vatRate . '%)'), 0, 0);
        $pdf->Cell(35, 8, number_format($totalVat, 0, '.', ' '), 0, 1, 'R');
        
        $pdf->SetX(120);
        $pdf->SetFillColor(26, 26, 46);
        $pdf->SetTextColor(255);
        $pdf->Cell(45, 10, 'TOTAL TTC', 0, 0, 'L', true);
        $pdf->Cell(35, 10, number_format($total, 0, '.', ' ') . ' FCFA', 0, 1, 'R', true);
        
        $pdf->Ln(2);
        $pdf->SetX(120);
        $pdf->SetTextColor(0);
        $pdf->Cell(45, 8, utf8_decode('Déjà payé'), 0, 0);
        $pdf->Cell(35, 8, number_format($paid, 0, '.', ' '), 0, 1, 'R');
        
        $pdf->SetX(120);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(45, 10, 'RESTE A PAYER', 0, 0);
        $pdf->Cell(35, 10, number_format($remaining, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        
        // Footer
        $pdf->SetY(-30);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(150);
        $pdf->Cell(0, 5, utf8_decode('Certifié par Omega POS — Document original'), 0, 1, 'C');
        $pdf->Cell(0, 5, utf8_decode('Merci pour votre confiance chez ' . $restaurant->name), 0, 1, 'C');
        
        return $pdf->Output('S');
    }
}
