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
        
        // 5. Nouveaux Crédits (Ardoises)
        $newCredits = Order::whereHas('customerTabs')
            ->whereBetween('paid_at', [$session->opened_at, $session->closed_at ?? now()])
            ->get();
        $totalCredits = $newCredits->sum('total');

        // 6. Logs d'activité de la session (uniquement pour ce restaurant)
        $logs = \App\Models\ActivityLog::where('restaurant_id', $session->restaurant_id)
            ->where(function($q) use ($session) {
                $q->where(function($sq) use ($session) {
                    $sq->where('subject_type', CashSession::class)
                       ->where('subject_id', $session->id);
                })
                ->orWhere(function($sq) use ($session) {
                    $sq->where('module', 'cash')
                      ->whereBetween('created_at', [$session->opened_at, $session->closed_at ?? now()]);
                });
            })
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->limit(100) // Sécurité : pas plus de 100 logs dans l'e-mail
            ->get();

        // Préparation des éléments HTML
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

        $logsHtml = '';
        foreach ($logs as $log) {
            $time = $log->created_at->format('H:i');
            $user = $log->user ? strtoupper($log->user->first_name) : 'SYSTEM';
            $logsHtml .= "<tr>
                <td style='padding: 6px 10px; border-bottom: 1px solid #f1f5f9; font-size: 11px; color: #64748b;'>{$time}</td>
                <td style='padding: 6px 10px; border-bottom: 1px solid #f1f5f9; font-size: 11px; color: #0f172a; font-weight: 700;'>{$user}</td>
                <td style='padding: 6px 10px; border-bottom: 1px solid #f1f5f9; font-size: 11px; color: #475569;'>{$log->description}</td>
            </tr>";
        }

        $diffColor = ($session->difference ?? 0) >= 0 ? '#10b981' : '#ef4444';
        $pdfUrl = config('app.url') . "/api/cash-sessions/{$session->id}/report-preview";

        return "
<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; color: #1e293b; line-height: 1.6; margin: 0; padding: 0; background-color: #f1f5f9; }
        .container { max-width: 850px; margin: 40px auto; background: #ffffff; padding: 50px; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #e2e8f0; padding-bottom: 30px; margin-bottom: 40px; }
        .logo-box h1 { margin: 0; color: #f97316; font-size: 32px; font-weight: 800; letter-spacing: -1px; }
        .session-info { text-align: right; }
        .session-badge { display: inline-block; background: #f1f5f9; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 8px; }
        
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px; }
        .stat-card { padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .stat-card.highlight { background: #0f172a; color: #fff; border: none; }
        .stat-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.8; margin-bottom: 8px; }
        .stat-value { font-size: 24px; font-weight: 800; }
        
        .reconciliation-box { background: #f8fafc; padding: 30px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 40px; }
        .reconciliation-title { font-size: 14px; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 20px; display: flex; align-items: center; }
        .reconciliation-title::before { content: ''; display: inline-block; width: 12px; height: 12px; background: #f97316; border-radius: 3px; margin-right: 10px; }
        
        .row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        .row.total { border-bottom: none; padding-top: 20px; font-size: 18px; font-weight: 800; color: #0f172a; }
        .row.sub { padding-left: 20px; color: #64748b; font-size: 13px; }
        
        .section-header { font-size: 16px; font-weight: 800; color: #0f172a; margin: 40px 0 20px; display: flex; align-items: center; justify-content: space-between; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; background: #f8fafc; color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; border-bottom: 2px solid #e2e8f0; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        
        .footer { margin-top: 60px; text-align: center; border-top: 2px solid #f1f5f9; padding-top: 40px; }
        .btn-pdf { display: inline-block; background: #0f172a; color: #fff; padding: 16px 32px; border-radius: 12px; text-decoration: none; font-weight: 700; transition: transform 0.2s; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <div class='logo-box'>
                <h1>" . strtoupper($restaurant->name) . "</h1>
                <div style='color: #64748b; font-weight: 500; font-size: 14px;'>" . data_get($restaurant->settings, 'receipt_subtitle') . "</div>
                <div style='margin-top: 15px; font-size: 12px; color: #94a3b8; font-weight: 700; text-transform: uppercase;'>Rapport Financier Journalier</div>
            </div>
            <div class='session-info'>
                <div class='session-badge'>SESSION #{$session->id}</div>
                <div style='font-size: 18px; font-weight: 800; color: #0f172a;'>{$session->opened_at->format('d F Y')}</div>
                <div style='font-size: 13px; color: #64748b;'>Caissier : <strong>" . strtoupper($session->user->first_name) . "</strong></div>
            </div>
        </div>

        <div class='summary-grid'>
            <div class='stat-card highlight'>
                <div class='stat-label'>Recette Totale</div>
                <div class='stat-value'>" . number_format($totalRevenue, 0, ',', ' ') . " FCFA</div>
            </div>
            <div class='stat-card'>
                <div class='stat-label'>Dépenses</div>
                <div class='stat-value' style='color: #ef4444;'>-" . number_format($totalExpenses, 0, ',', ' ') . " FCFA</div>
            </div>
            <div class='stat-card'>
                <div class='stat-label'>Crédits Client</div>
                <div class='stat-value' style='color: #f97316;'>" . number_format($totalCredits, 0, ',', ' ') . " FCFA</div>
            </div>
        </div>

        <div class='reconciliation-box'>
            <div class='reconciliation-title'>Synthèse des Flux de Trésorerie</div>
            
            <div class='row'>
                <span style='font-weight:700'>ARGENT DE L'OUVERTURE (Début)</span>
                <span><strong>" . number_format((float)$session->opening_amount, 0, ',', ' ') . " FCFA</strong></span>
            </div>
            <div class='row'>
                <span>Encaissé en Espèces (Ventes)</span>
                <span>+" . number_format((float)($session->expected_amount - $session->opening_amount + $totalExpenses), 0, ',', ' ') . " FCFA</span>
            </div>
            <div class='row' style='color: #ef4444;'>
                <span>Dépenses déduites de la caisse</span>
                <span>-" . number_format((float)$totalExpenses, 0, ',', ' ') . " FCFA</span>
            </div>
            <div class='row' style='background: #fff; padding: 15px; margin-top: 10px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                <span style='font-weight: 700;'>Montant Attendu en Caisse</span>
                <span style='font-weight: 800; color: #0f172a;'>" . number_format((float)$session->expected_amount, 0, ',', ' ') . " FCFA</span>
            </div>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 2px dashed #cbd5e1;'>
                <div class='row sub'>
                    <span>Montant réellement compté</span>
                    <span>" . ($session->closing_amount ? number_format((float)$session->closing_amount, 0, ',', ' ') . " FCFA" : "<em>Non clôturé</em>") . "</span>
                </div>
                <div class='row sub'>
                    <span style='color: #f97316; font-weight: 700;'>➤ MONTANT REMIS AU BANQUIER</span>
                    <span style='color: #f97316; font-weight: 800;'>" . ($session->amount_to_bank ? number_format((float)$session->amount_to_bank, 0, ',', ' ') . " FCFA" : "—") . "</span>
                </div>
                <div class='row sub'>
                    <span>➤ FONDS DE CAISSE RESTANT</span>
                    <span>" . ($session->remaining_amount ? number_format((float)$session->remaining_amount, 0, ',', ' ') . " FCFA" : "—") . "</span>
                </div>
            </div>

            <div class='row total'>
                <span style='text-transform: uppercase; letter-spacing: 1px;'>ÉCART FINAL</span>
                <span style='color: {$diffColor}; font-size: 24px;'>" . number_format((float)($session->difference ?? 0), 0, ',', ' ') . " FCFA</span>
            </div>
            
            " . ($session->closing_notes ? "<div style='margin-top:20px; font-size:12px; padding:15px; background:#fff; border-radius:8px; border-left:4px solid #94a3b8; color:#475569;'><strong>Note du caissier :</strong> {$session->closing_notes}</div>" : "") . "
        </div>

        <div class='section-header'><span>Journal des Événements (Logs)</span></div>
        <table style='margin-bottom: 30px;'>
            <thead><tr><th style='width: 60px;'>Heure</th><th style='width: 120px;'>Auteur</th><th>Action effectuée</th></tr></thead>
            <tbody>{$logsHtml}</tbody>
        </table>

        <div class='section-header'><span>Détail des Encaissements</span></div>
        <table>
            <thead><tr><th>Mode de Paiement</th><th style='text-align:center;'>Nb. Transac</th><th style='text-align:right;'>Montant Total</th></tr></thead>
            <tbody>{$paymentsHtml}</tbody>
        </table>

        <div class='section-header'><span>Ventes de cette Session</span></div>
        <table>
            <thead><tr><th>Produit / Article</th><th style='text-align:center;'>Qté</th><th style='text-align:right;'>Recette HT</th></tr></thead>
            <tbody>{$productsHtml}</tbody>
        </table>

        <div class='footer'>
            <div style='margin-bottom: 25px;'>
                <div style='font-size: 14px; color: #64748b; margin-bottom: 20px;'>Ce rapport est généré automatiquement par le système SmartFlow POS.</div>
                <a href='{$pdfUrl}' class='btn-pdf' target='_blank'>📥 Télécharger le Rapport PDF</a>
            </div>
            <div style='font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px;'>Généré le " . date('d/m/Y \à H:i') . " — Omega POS Pro</div>
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
            $subject = "RAPPORT DE CAISSE - " . strtoupper($session->restaurant->name) . " - " . ($session->opened_at?->format('d/m/Y') ?? date('d/m/Y'));

            Mail::send([], [], function ($message) use ($toEmail, $subject, $html) {
                $message->to($toEmail)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->html($html);
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
