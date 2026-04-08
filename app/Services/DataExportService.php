<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Cancellation;
use App\Models\CakeOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DataExportService
{
    private $xmlHeader = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
  </Style>
  <Style ss:ID="sHeader">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#4F81BD" ss:Pattern="Solid"/>
  </Style>
 </Styles>';

    public function exportToExcel(string $fromDate, string $toDate, int $restaurantId): string
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to   = Carbon::parse($toDate)->endOfDay();

        $fileName = 'Export_Omega_' . $from->format('Ymd') . '_au_' . $to->format('Ymd') . '.xls';
        $filePath = storage_path('app/' . $fileName);
        
        $handle = fopen($filePath, 'w');
        fwrite($handle, $this->xmlHeader);

        // 1. Sheet Orders
        $this->addWorksheet($handle, 'Commandes', ['ID', 'Numero', 'Type', 'Statut', 'Client', 'Couverts', 'Sous-total', 'Remise', 'TVA', 'TOTAL', 'Cree le', 'Paye le'], function($chunkCallback) use ($restaurantId, $from, $to) {
            Order::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($orders) use ($chunkCallback) {
                    foreach ($orders as $o) {
                        $chunkCallback([
                            $o->id, $o->order_number, $o->type, $o->status, $o->customer_name, $o->covers,
                            $o->subtotal, $o->discount_amount, $o->vat_amount, $o->total,
                            $o->created_at->format('d/m/Y H:i'),
                            $o->paid_at ? $o->paid_at->format('d/m/Y H:i') : ''
                        ]);
                    }
                });
        });

        // 2. Sheet Items
        $this->addWorksheet($handle, 'Articles Vendus', ['ID', 'Commande #', 'Article', 'Categorie', 'Destination', 'Quantite', 'P.U.', 'Sous-total', 'Statut', 'Date'], function($chunkCallback) use ($restaurantId, $from, $to) {
            OrderItem::with(['order', 'product.category'])
                ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereBetween('created_at', [$from, $to]))
                ->chunk(100, function($items) use ($chunkCallback) {
                    foreach ($items as $item) {
                        $chunkCallback([
                            $item->id,
                            $item->order->order_number ?? 'N/A',
                            $item->product->name ?? 'N/A',
                            $item->product->category->name ?? 'N/A',
                            $item->product->category->destination ?? 'N/A',
                            $item->quantity,
                            $item->unit_price,
                            $item->subtotal,
                            $item->status,
                            $item->created_at->format('d/m/Y H:i')
                        ]);
                    }
                });
        });

        // 3. Sheet Payments
        $this->addWorksheet($handle, 'Paiements', ['ID', 'Commande #', 'Gateau #', 'Mode', 'Montant', 'Reference', 'Date'], function($chunkCallback) use ($restaurantId, $from, $to) {
            Payment::with(['order', 'cakeOrder'])
                ->where(function($q) use ($restaurantId) {
                    $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                      ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
                })
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($payments) use ($chunkCallback) {
                    foreach ($payments as $p) {
                        $chunkCallback([
                            $p->id,
                            $p->order->order_number ?? '',
                            $p->cakeOrder->order_number ?? '',
                            $p->method,
                            $p->amount,
                            $p->reference,
                            $p->created_at->format('d/m/Y H:i')
                        ]);
                    }
                });
        });

        // 4. Sheet Cancellations
        $this->addWorksheet($handle, 'Annulations', ['ID', 'Type', 'ID Objet', 'Motif', 'Par', 'Approuve par', 'Date'], function($chunkCallback) use ($restaurantId, $from, $to) {
            Cancellation::with(['requester', 'approver'])
                ->where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($cancellations) use ($chunkCallback) {
                    foreach ($cancellations as $c) {
                        $chunkCallback([
                            $c->id, $c->cancellable_type, $c->cancellable_id, $c->reason,
                            ($c->requester->first_name ?? '') . ' ' . ($c->requester->last_name ?? ''),
                            ($c->approver->first_name ?? '') . ' ' . ($c->approver->last_name ?? ''),
                            $c->created_at->format('d/m/Y H:i')
                        ]);
                    }
                });
        });

        // 5. Sheet Cake Orders
        $this->addWorksheet($handle, 'Gâteaux', ['ID', 'Numero', 'Client', 'Telephone', 'Date Recuperation', 'TOTAL', 'Encaisse', 'Statut', 'Cree le'], function($chunkCallback) use ($restaurantId, $from, $to) {
            CakeOrder::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($cakes) use ($chunkCallback) {
                    foreach ($cakes as $cake) {
                        $chunkCallback([
                            $cake->id, $cake->order_number, $cake->customer_name, $cake->customer_phone,
                            ($cake->delivery_date ? $cake->delivery_date->format('d/m/Y') : '') . ' ' . ($cake->delivery_time ?? ''),
                            $cake->total, $cake->advance_paid, $cake->status,
                            $cake->created_at->format('d/m/Y H:i')
                        ]);
                    }
                });
        });

        fwrite($handle, '</Workbook>');
        fclose($handle);

        return $filePath;
    }

    private function addWorksheet($handle, $name, $headers, $queryCallback)
    {
        fwrite($handle, '<Worksheet ss:Name="' . $this->escapeXml($name) . '"><Table>');
        
        // Header
        fwrite($handle, '<Row>');
        foreach ($headers as $h) {
            fwrite($handle, '<Cell ss:StyleID="sHeader"><Data ss:Type="String">' . $this->escapeXml($h) . '</Data></Cell>');
        }
        fwrite($handle, '</Row>');

        // Data
        $queryCallback(function($row) use ($handle) {
            fwrite($handle, '<Row>');
            foreach ($row as $value) {
                $type = is_numeric($value) ? 'Number' : 'String';
                fwrite($handle, '<Cell><Data ss:Type="' . $type . '">' . $this->escapeXml((string)$value) . '</Data></Cell>');
            }
            fwrite($handle, '</Row>');
        });

        fwrite($handle, '</Table></Worksheet>');
    }

    private function escapeXml($text)
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
