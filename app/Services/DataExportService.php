<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Cancellation;
use App\Models\CakeOrder;
use Carbon\Carbon;
use ZipArchive;
use Illuminate\Support\Facades\Storage;

class DataExportService
{
    public function exportToZip(string $fromDate, string $toDate, int $restaurantId): string
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to   = Carbon::parse($toDate)->endOfDay();

        $tempDir = storage_path('app/exports_' . uniqid());
        if (!file_exists($tempDir)) mkdir($tempDir, 0777, true);

        // 1. Orders
        $this->exportOrders($from, $to, $restaurantId, $tempDir . '/commandes.csv');
        
        // 2. Items
        $this->exportItems($from, $to, $restaurantId, $tempDir . '/articles_vendus.csv');

        // 3. Payments
        $this->exportPayments($from, $to, $restaurantId, $tempDir . '/paiements.csv');

        // 4. Cancellations
        $this->exportCancellations($from, $to, $restaurantId, $tempDir . '/annulations.csv');

        // 5. Cake Orders
        $this->exportCakeOrders($from, $to, $restaurantId, $tempDir . '/commandes_gateaux.csv');

        // Create ZIP
        $zipPath = storage_path('app/export_' . $from->format('Ymd') . '_to_' . $to->format('Ymd') . '.zip');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $files = glob($tempDir . '/*.csv');
            foreach ($files as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        }

        // Cleanup temp files
        foreach (glob($tempDir . '/*.csv') as $file) unlink($file);
        rmdir($tempDir);

        return $zipPath;
    }

    private function exportOrders($from, $to, $restaurantId, $path)
    {
        $handle = fopen($path, 'w');
        fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        fputcsv($handle, ['ID', 'Numero', 'Type', 'Statut', 'Client', 'Couverts', 'Sous-total', 'Remise', 'TVA', 'TOTAL', 'Cree le', 'Paye le']);

        Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$from, $to])
            ->chunk(100, function ($orders) use ($handle) {
                foreach ($orders as $o) {
                    fputcsv($handle, [
                        $o->id,
                        $o->order_number,
                        $o->type,
                        $o->status,
                        $o->customer_name,
                        $o->covers,
                        $o->subtotal,
                        $o->discount_amount,
                        $o->vat_amount,
                        $o->total,
                        $o->created_at->format('d/m/Y H:i'),
                        $o->paid_at ? $o->paid_at->format('d/m/Y H:i') : ''
                    ]);
                }
            });

        fclose($handle);
    }

    private function exportItems($from, $to, $restaurantId, $path)
    {
        $handle = fopen($path, 'w');
        fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        fputcsv($handle, ['ID', 'Commande #', 'Article', 'Categorie', 'Destination', 'Quantite', 'P.U.', 'Sous-total', 'Statut', 'Date']);

        OrderItem::with(['order', 'product.category'])
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereBetween('created_at', [$from, $to]))
            ->chunk(100, function ($items) use ($handle) {
                foreach ($items as $item) {
                    fputcsv($handle, [
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

        fclose($handle);
    }

    private function exportPayments($from, $to, $restaurantId, $path)
    {
        $handle = fopen($path, 'w');
        fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        fputcsv($handle, ['ID', 'Commande #', 'Gateau #', 'Mode', 'Montant', 'Reference', 'Date']);

        Payment::with(['order', 'cakeOrder'])
            ->where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereBetween('created_at', [$from, $to])
            ->chunk(100, function ($payments) use ($handle) {
                foreach ($payments as $p) {
                    fputcsv($handle, [
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

        fclose($handle);
    }

    private function exportCancellations($from, $to, $restaurantId, $path)
    {
        $handle = fopen($path, 'w');
        fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        fputcsv($handle, ['ID', 'Type', 'ID Objet', 'Motif', 'Par', 'Approuve par', 'Date']);

        Cancellation::with(['requester', 'approver'])
            ->where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$from, $to])
            ->chunk(100, function ($cancellations) use ($handle) {
                foreach ($cancellations as $c) {
                    fputcsv($handle, [
                        $c->id,
                        $c->cancellable_type,
                        $c->cancellable_id,
                        $c->reason,
                        ($c->requester->first_name ?? '') . ' ' . ($c->requester->last_name ?? ''),
                        ($c->approver->first_name ?? '') . ' ' . ($c->approver->last_name ?? ''),
                        $c->created_at->format('d/m/Y H:i')
                    ]);
                }
            });

        fclose($handle);
    }

    private function exportCakeOrders($from, $to, $restaurantId, $path)
    {
        $handle = fopen($path, 'w');
        fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        fputcsv($handle, ['ID', 'Numero', 'Client', 'Telephone', 'Date Recuperation', 'TOTAL', 'Encaisse', 'Statut', 'Cree le']);

        CakeOrder::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$from, $to])
            ->chunk(100, function ($cakes) use ($handle) {
                foreach ($cakes as $cake) {
                    fputcsv($handle, [
                        $cake->id,
                        $cake->order_number,
                        $cake->customer_name,
                        $cake->customer_phone,
                        ($cake->delivery_date ? $cake->delivery_date->format('d/m/Y') : '') . ' ' . ($cake->delivery_time ?? ''),
                        $cake->total,
                        $cake->advance_paid,
                        $cake->status,
                        $cake->created_at->format('d/m/Y H:i')
                    ]);
                }
            });

        fclose($handle);
    }
}
