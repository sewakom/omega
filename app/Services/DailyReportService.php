<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\CustomerTab;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DailyReportService
 * Génère le rapport journalier HTML ultra-professionnel (format A4) et l'envoie par email au boss.
 */
class DailyReportService
{
    /**
     * Génère le HTML du rapport A4 de clôture de session (Version PRO)
     */
    public function generateSessionReportHtml(CashSession $session): string
    {
        $session->loadMissing(['user', 'restaurant']);
        $restaurant = $session->restaurant;

        // 1. Totaux par méthode de paiement
        $payments = Payment::where('cash_session_id', $session->id)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get();

        // 2. Statistiques des produits vendus (Performance)
        $productStats = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('payments.cash_session_id', $session->id)
            ->select('products.name', DB::raw('SUM(order_items.quantity) as total_qty'), DB::raw('SUM(order_items.subtotal) as total_amount'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->get();

        // 3. Commandes
        $orders = Order::whereHas('payments', fn($q) => $q->where('cash_session_id', $session->id))->get();
        $totalRevenue = $orders->sum('total');

        // 4. Dépenses
        $expenses = Expense::where('cash_session_id', $session->id)->get();
        $totalExpenses = $expenses->sum('amount');
        
        // 5. Nouveaux Crédits (Ardoises) - Commandes passées en ardoise durant cette session
        $newCredits = Order::whereHas('customerTabs')
            ->whereBetween('paid_at', [$session->opened_at, $session->closed_at ?? now()])
            ->get();
        $totalCredits = $newCredits->sum('total');

        // 5. Préparation des éléments HTML
        $paymentsHtml = '';
        foreach ($payments as $p) {
            $methodMapping = ['cash' => 'ESPÈCES', 'card' => 'CARTE BANCAIRE', 'wave' => 'WAVE', 'orange_money' => 'ORANGE MONEY', 'momo' => 'MTN MOMO'];
            $method = $methodMapping[$p->method] ?? strtoupper($p->method);
            $total  = number_format((float)$p->total, 0, ',', ' ');
            $paymentsHtml .= "<tr>
                <td style='padding: 10px; border-bottom: 1px solid #eee;'>{$method}</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align:center;'>{$p->count}</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align:right;'><strong>{$total} FCFA</strong></td>
            </tr>";
        }

        $productsHtml = '';
        foreach ($productStats as $stat) {
            $amt = number_format($stat->total_amount, 0, ',', ' ');
            $productsHtml .= "<tr>
                <td style='padding: 8px; border-bottom: 1px solid #f9f9f9;'>{$stat->name}</td>
                <td style='padding: 8px; border-bottom: 1px solid #f9f9f9; text-align:center;'>{$stat->total_qty}</td>
                <td style='padding: 8px; border-bottom: 1px solid #f9f9f9; text-align:right;'>{$amt} FCFA</td>
            </tr>";
        }

        $expensesHtml = '';
        if ($expenses->isEmpty()) {
            $expensesHtml = "<tr><td colspan='3' style='padding: 20px; text-align:center; color: #999; font-style: italic;'>Aucune dépense enregistrée</td></tr>";
        } else {
            foreach ($expenses as $e) {
                $amt = number_format((float)$e->amount, 0, ',', ' ');
                $expensesHtml .= "<tr>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>{$e->description}</td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>{$e->category}</td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee; text-align:right; color: #e11d48;'>-{$amt} FCFA</td>
                </tr>";
            }
        }

        $creditsHtml = '';
        if ($newCredits->isEmpty()) {
            $creditsHtml = "<tr><td colspan='3' style='padding: 20px; text-align:center; color: #999; font-style: italic;'>Aucun crédit accordé</td></tr>";
        } else {
            foreach ($newCredits as $c) {
                $tab = $c->customerTabs->first();
                $client = $tab ? ($tab->first_name . ' ' . $tab->last_name) : 'Client Inconnu';
                $amt = number_format((float)$c->total, 0, ',', ' ');
                $creditsHtml .= "<tr>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>#{$c->order_number}</td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>{$client}</td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee; text-align:right; font-weight:bold;'>{$amt} FCFA</td>
                </tr>";
            }
        }

        $netEncaisse = $totalRevenue - $totalExpenses;
        $diffColor = ($session->difference ?? 0) >= 0 ? '#10b981' : '#e11d48';

        // URL pour le PDF (via API car la route est protégée)
        $pdfUrl = config('app.url') . "/api/cash-sessions/{$session->id}/report-preview";

        return "
<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #334155; line-height: 1.5; margin: 0; padding: 0; background-color: #f8fafc; }
        .container { max-width: 800px; margin: 20px auto; background: #fff; padding: 40px; border-radius: 8px; shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .header { border-bottom: 4px solid #f97316; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .logo-box h1 { margin: 0; color: #0f172a; font-size: 28px; font-weight: 800; text-transform: uppercase; letter-spacing: -1px; }
        .session-info { text-align: right; font-size: 11px; color: #64748b; font-weight: bold; text-transform: uppercase; }
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #f1f5f9; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .stat-card.primary { background: #fff7ed; border-color: #ffedd5; }
        .stat-label { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; }
        .stat-value { font-size: 22px; font-weight: 900; color: #0f172a; }
        .section-title { font-size: 14px; font-weight: 800; text-transform: uppercase; color: #1e293b; margin: 40px 0 15px; border-left: 4px solid #f97316; padding-left: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { text-align: left; padding: 12px 10px; background: #f8fafc; color: #64748b; text-transform: uppercase; font-size: 10px; font-weight: 800; }
        .footer { margin-top: 50px; padding-top: 20px; border-top: 1px solid #e2e8f0; text-align: center; }
        .download-btn { display: inline-block; background: #f97316; color: #fff; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: 800; font-size: 13px; text-transform: uppercase; margin-top: 20px; box-shadow: 0 10px 15px -3px rgba(249, 115, 22, 0.3); }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <div class='logo-box'>
                <h1 style='color: #f97316;'>" . strtoupper($restaurant->name) . "</h1>
                " . (data_get($restaurant->settings, 'receipt_subtitle') ? "<p style='margin: 2px 0 0; font-size: 13px; font-weight: bold; color: #64748b;'>" . strtoupper(data_get($restaurant->settings, 'receipt_subtitle')) . "</p>" : "") . "
                <p style='margin: 5px 0 0; font-size: 11px; font-weight: bold; color: #94a3b8; text-transform: uppercase;'>RAPPORT DE CLÔTURE DE CAISSE</p>
            </div>
            <div class='session-info'>
                Date: {$session->opened_at->format('d/m/Y')}<br>
                Session #{$session->id}<br>
                Caissier: " . strtoupper($session->user->first_name) . "
            </div>
        </div>

        <div class='summary-grid'>
            <div class='stat-card primary'>
                <div class='stat-label'>Encaissé (Réel)</div>
                <div class='stat-value'>" . number_format($totalRevenue, 0, ',', ' ') . " FCFA</div>
            </div>
            <div class='stat-card'>
                <div class='stat-label'>Dépenses</div>
                <div class='stat-value' style='color: #e11d48;'>-" . number_format($totalExpenses, 0, ',', ' ') . " FCFA</div>
            </div>
            <div class='stat-card'>
                <div class='stat-label'>Ventes à Crédit</div>
                <div class='stat-value' style='color: #f97316;'>" . number_format($totalCredits, 0, ',', ' ') . " FCFA</div>
            </div>
        </div>

        <div class='section-title'>Détail des Encaissements</div>
        <table>
            <thead><tr><th>Mode de Paiement</th><th style='text-align:center;'>Transactions</th><th style='text-align:right;'>Total HT</th></tr></thead>
            <tbody>{$paymentsHtml}</tbody>
        </table>

        <div class='section-title'>Analyse des Ventes (Top Produits)</div>
        <table>
            <thead><tr><th>Désignation</th><th style='text-align:center;'>Qté</th><th style='text-align:right;'>Total</th></tr></thead>
            <tbody>{$productsHtml}</tbody>
        </table>

        <div class='section-title'>Journal des Dépenses</div>
        <table>
            <thead><tr><th>Motif</th><th>Catégorie</th><th style='text-align:right;'>Montant</th></tr></thead>
            <tbody>{$expensesHtml}</tbody>
        </table>

        <div class='section-title'>Ventes à Crédit (Ardoises)</div>
        <table>
            <thead><tr><th>Commande</th><th>Client</th><th style='text-align:right;'>Montant</th></tr></thead>
            <tbody>{$creditsHtml}</tbody>
        </table>

        <div class='section-title'>Rapprochement de Caisse</div>
        <div style='background: #f8fafc; padding: 25px; border-radius: 12px; border: 1px dashed #cbd5e1;'>
            <table style='font-size: 14px;'>
                <tr><td style='padding: 8px 0; color: #64748b;'>Fond d'ouverture :</td><td style='text-align:right; font-weight: bold;'>" . number_format((float)$session->opening_amount, 0, ',', ' ') . " FCFA</td></tr>
                <tr><td style='padding: 8px 0; color: #64748b;'>Attendu en caisse (Espèces) :</td><td style='text-align:right; font-weight: bold;'>" . number_format((float)$session->expected_amount, 0, ',', ' ') . " FCFA</td></tr>
                <tr style='border-top: 1px solid #e2e8f0;'><td style='padding: 8px 0; color: #64748b; font-style: italic;'>Réel compté (Total) :</td><td style='text-align:right; font-weight: bold; color: #0f172a;'>" . number_format((float)($session->closing_amount ?? 0), 0, ',', ' ') . " FCFA</td></tr>
                <tr><td style='padding: 8px 0; color: #64748b; padding-left: 20px;'>• Montant remis au banquier :</td><td style='text-align:right; font-weight: bold; color: #f97316;'>" . number_format((float)($session->amount_to_bank ?? 0), 0, ',', ' ') . " FCFA</td></tr>
                <tr><td style='padding: 8px 0; color: #64748b; padding-left: 20px;'>• Fonds de caisse resté :</td><td style='text-align:right; font-weight: bold; color: #64748b;'>" . number_format((float)($session->remaining_amount ?? 0), 0, ',', ' ') . " FCFA</td></tr>
                <tr style='border-top: 2px solid #e2e8f0;'><td style='padding: 15px 0 0; font-weight: 900; color: #0f172a; text-transform: uppercase;'>Écart de Caisse :</td><td style='padding: 15px 0 0; text-align:right; font-weight: 900; font-size: 18px; color: {$diffColor};'>" . number_format((float)($session->difference ?? 0), 0, ',', ' ') . " FCFA</td></tr>
            </table>
            " . ($session->closing_notes ? "<div style='margin-top: 20px; font-size: 11px; padding: 10px; background: #fff; border-radius: 6px; color: #64748b;'><strong>Note:</strong> {$session->closing_notes}</div>" : "") . "
        </div>

        <div class='footer'>
            <p style='font-size: 14px; font-weight: bold; color: #0f172a; margin-bottom: 5px;'>Besoin d'archiver ce document ?</p>
            <p style='color: #64748b; font-size: 12px; margin-bottom: 25px;'>Cliquez sur le bouton ci-dessous pour générer et télécharger la version PDF complète de ce rapport de caisse.</p>
            <a href='{$pdfUrl}' target='_blank' class='download-btn'>📥 Télécharger le Rapport PDF</a>
            <p style='margin-top: 40px; font-size: 10px; color: #94a3b8;'>Document généré par SmartFlow POS — " . date('d/m/Y H:i') . "</p>
        </div>
    </div>
</body>
</html>";
    }

    /**
     * Envoie le rapport par email au boss (Email de luxe)
     */
    public function sendReportByEmail(CashSession $session, string $toEmail): bool
    {
        try {
            $html = $this->generateSessionReportHtml($session);
            $subject = "📊 RAPPORT DE CAISSE — {$session->restaurant->name} — " . ($session->opened_at?->format('d/m/Y') ?? date('d/m/Y'));

            Mail::html($html, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            $session->update([
                'report_sent_at' => now(),
                'report_email'   => $toEmail,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send cash session report: ' . $e->getMessage());
            return false;
        }
    }
}
