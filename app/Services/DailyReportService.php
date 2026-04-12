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
<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd'>
<html xmlns='http://www.w3.org/1999/xhtml' lang='fr'>
<head>
    <meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />
    <title>Rapport de Caisse</title>
</head>
<body style='margin: 0; padding: 20px; background-color: #f4f7f9; font-family: Arial, sans-serif; color: #333;'>
    <table width='100%' border='0' cellspacing='0' cellpadding='0'>
        <tr>
            <td align='center'>
                <table width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e1e8ed;'>
                    <!-- Header -->
                    <tr>
                        <td style='padding: 30px; background-color: #f97316; color: #ffffff;'>
                            <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                <tr>
                                    <td>
                                        <h1 style='margin: 0; font-size: 24px;'>" . strtoupper($restaurant->name) . "</h1>
                                        <p style='margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;'>Rapport Financier Journalier</p>
                                    </td>
                                    <td align='right' style='font-size: 12px;'>
                                        <span style='background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 4px;'>SESSION #{$session->id}</span>
                                        <div style='margin-top: 5px; font-weight: bold;'>{$session->opened_at->format('d/m/Y')}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Synthèse rapide -->
                    <tr>
                        <td style='padding: 20px;'>
                            <table width='100%' border='0' cellspacing='10' cellpadding='0'>
                                <tr>
                                    <td width='33%' style='background-color: #1e293b; color: #ffffff; padding: 15px; border-radius: 6px; text-align: center;'>
                                        <div style='font-size: 10px; text-transform: uppercase;'>Recette</div>
                                        <div style='font-size: 16px; font-weight: bold;'>" . number_format($totalRevenue, 0, ',', ' ') . "</div>
                                    </td>
                                    <td width='33%' style='background-color: #f1f5f9; padding: 15px; border-radius: 6px; text-align: center;'>
                                        <div style='font-size: 10px; text-transform: uppercase;'>Dépenses</div>
                                        <div style='font-size: 16px; font-weight: bold; color: #ef4444;'>-" . number_format($totalExpenses, 0, ',', ' ') . "</div>
                                    </td>
                                    <td width='33%' style='background-color: #f1f5f9; padding: 15px; border-radius: 6px; text-align: center;'>
                                        <div style='font-size: 10px; text-transform: uppercase;'>Crédits</div>
                                        <div style='font-size: 16px; font-weight: bold; color: #f97316;'>" . number_format($totalCredits, 0, ',', ' ') . "</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Détail Réconciliation -->
                    <tr>
                        <td style='padding: 0 30px 20px 30px;'>
                            <div style='border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; background-color: #fafbfc;'>
                                <h3 style='margin: 0 0 15px 0; font-size: 14px; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;'>SYNTHÈSE DE TRÉSORERIE</h3>
                                <table width='100%' border='0' cellspacing='0' cellpadding='8' style='font-size: 14px;'>
                                    <tr>
                                        <td>Ouverture de caisse</td>
                                        <td align='right'><strong>" . number_format((float)$session->opening_amount, 0, ',', ' ') . " FCFA</strong></td>
                                    </tr>
                                    <tr>
                                        <td>Ventes en espèces</td>
                                        <td align='right'>+" . number_format((float)($session->expected_amount - $session->opening_amount + $totalExpenses), 0, ',', ' ') . " FCFA</td>
                                    </tr>
                                    <tr style='color: #ef4444;'>
                                        <td>Dépenses déduites</td>
                                        <td align='right'>-" . number_format((float)$totalExpenses, 0, ',', ' ') . " FCFA</td>
                                    </tr>
                                    <tr style='background-color: #f1f5f9; font-weight: bold;'>
                                        <td style='border-top: 1px solid #e2e8f0;'>MONTANT ATTENDU</td>
                                        <td align='right' style='border-top: 1px solid #e2e8f0; color: #0f172a;'>" . number_format((float)$session->expected_amount, 0, ',', ' ') . " FCFA</td>
                                    </tr>
                                    <tr>
                                        <td colspan='2' style='padding-top: 15px; border-top: 1px dashed #cbd5e1;'></td>
                                    </tr>
                                    <tr>
                                        <td style='color: #f97316; font-weight: bold;'>VERSÉ À LA BANQUE</td>
                                        <td align='right' style='color: #f97316; font-weight: bold;'>" . ($session->amount_to_bank ? number_format((float)$session->amount_to_bank, 0, ',', ' ') : '—') . " FCFA</td>
                                    </tr>
                                    <tr>
                                        <td>FOND DE ROULEMENT RESTANT</td>
                                        <td align='right'>" . ($session->remaining_amount ? number_format((float)$session->remaining_amount, 0, ',', ' ') : '—') . " FCFA</td>
                                    </tr>
                                    <tr style='font-size: 18px; font-weight: bold;'>
                                        <td style='padding-top: 15px; border-top: 2px solid #0f172a;'>ÉCART FINAL</td>
                                        <td align='right' style='padding-top: 15px; border-top: 2px solid #0f172a; color: {$diffColor};'>" . number_format((float)($session->difference ?? 0), 0, ',', ' ') . " FCFA</td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <!-- Logs Activity -->
                    <tr>
                        <td style='padding: 0 30px 30px 30px;'>
                            <h3 style='font-size: 14px; color: #0f172a; margin-bottom: 10px;'>JOURNAL D'ACTIVITÉ (LOGS)</h3>
                            <table width='100%' border='0' cellspacing='0' cellpadding='5' style='font-size: 11px; border: 1px solid #f1f5f9;'>
                                <tr style='background-color: #f8fafc; color: #64748b;'>
                                    <th align='left' style='padding-left: 10px;'>Heure</th>
                                    <th align='left'>Utilisateur</th>
                                    <th align='left'>Action</th>
                                </tr>
                                {$logsHtml}
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style='padding: 20px; background-color: #f8fafc; text-align: center; border-top: 1px solid #e2e8f0; font-size: 10px; color: #94a3b8;'>
                            Ce rapport a été généré automatiquement par SmartFlow POS.<br/>
                            Généré le " . date('d/m/Y à H:i') . "
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
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
