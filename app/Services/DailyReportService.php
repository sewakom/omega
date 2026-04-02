<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Order;
use App\Models\Expense;
use Illuminate\Support\Facades\Mail;

/**
 * DailyReportService
 * Génère le rapport journalier HTML (format A4) et l'envoie par email au boss.
 */
class DailyReportService
{
    /**
     * Génère le HTML du rapport A4 de clôture de session
     */
    public function generateSessionReportHtml(CashSession $session): string
    {
        $session->loadMissing(['user', 'restaurant']);
        $restaurant = $session->restaurant;

        // Totaux par méthode
        $payments = \App\Models\Payment::where('cash_session_id', $session->id)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get();

        // Commandes de la session
        $orders = Order::whereHas('payments', fn($q) => $q->where('cash_session_id', $session->id))
            ->with('table')
            ->get();

        $ordersCount  = $orders->count();
        $totalRevenue = $orders->sum('total');

        // Dépenses de la session
        $expenses = Expense::where('cash_session_id', $session->id)->get();
        $totalExpenses = $expenses->sum('amount');

        // Commandes Gâteaux (Acomptes encaissés durant cette session)
        $cakePayments = \App\Models\CakeOrder::where('cash_session_id', $session->id)->get();
        $totalCakes = $cakePayments->sum(function($c) use ($session) {
            // Si la commande a été créée ET payée dans la même session, on prend 'advance_paid' (qui est le total)
            // C'est un peu approximatif sans table de paiement séparée, mais ça donne une idée.
            return $c->advance_paid;
        });

        // Par type de commande
        $byType = $orders->groupBy('type')->map(fn($g) => [
            'count' => $g->count(),
            'total' => $g->sum('total'),
        ]);

        $paymentsHtml = '';
        foreach ($payments as $p) {
            $method = strtoupper($p->method);
            $total  = number_format($p->total, 0, ',', ' ');
            $paymentsHtml .= "<tr><td>{$method}</td><td>{$p->count}</td><td style='text-align:right'><strong>{$total} FCFA</strong></td></tr>";
        }

        $expensesHtml = '';
        foreach ($expenses as $e) {
            $amt = number_format($e->amount, 0, ',', ' ');
            $expensesHtml .= "<tr><td>{$e->description}</td><td>{$e->category}</td><td style='text-align:right'>{$amt} FCFA</td></tr>";
        }

        $typeHtml = '';
        $typeLabels = ['dine_in' => 'Sur place', 'takeaway' => 'À emporter', 'gozem' => 'Gozem'];
        foreach ($byType as $type => $data) {
            $label = $typeLabels[$type] ?? $type;
            $total = number_format($data['total'], 0, ',', ' ');
            $typeHtml .= "<tr><td>{$label}</td><td>{$data['count']}</td><td style='text-align:right'>{$total} FCFA</td></tr>";
        }

        $totalRevenueF  = number_format($totalRevenue + $totalCakes, 0, ',', ' ');
        $totalExpensesF = number_format($totalExpenses, 0, ',', ' ');
        $netF           = number_format(($totalRevenue + $totalCakes) - $totalExpenses, 0, ',', ' ');
        $openAmount     = number_format($session->opening_amount, 0, ',', ' ');
        $closeAmount    = number_format($session->closing_amount ?? 0, 0, ',', ' ');
        $expectedF      = number_format($session->expected_amount ?? 0, 0, ',', ' ');
        $diffF          = number_format($session->difference ?? 0, 0, ',', ' ');
        $diffColor      = ($session->difference ?? 0) >= 0 ? 'green' : 'red';

        $openedAt  = $session->opened_at?->format('d/m/Y H:i') ?? '-';
        $closedAt  = $session->closed_at?->format('d/m/Y H:i') ?? 'En cours';
        $caissier  = $session->user->first_name . ' ' . $session->user->last_name;
        $date      = $session->opened_at?->format('d/m/Y') ?? now()->format('d/m/Y');

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; color: #222; margin: 15mm; }
  h1 { font-size: 24px; color: #1a1a2e; margin-bottom: 4px; }
  h2 { font-size: 14px; color: #16213e; margin: 16px 0 8px; border-bottom: 2px solid #1a1a2e; padding-bottom: 4px; }
  .header { display: flex; justify-content: space-between; margin-bottom: 20px; }
  .badge { display: inline-block; background: #1a1a2e; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th { background: #1a1a2e; color: white; padding: 8px; text-align: left; font-size: 11px; }
  td { padding: 6px 8px; border-bottom: 1px solid #eee; }
  .summary-box { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 20px; }
  .box { background: #f8f9fa; border-left: 4px solid #1a1a2e; padding: 12px; border-radius: 4px; }
  .box .label { font-size: 10px; color: #666; text-transform: uppercase; }
  .box .value { font-size: 18px; font-weight: bold; color: #1a1a2e; }
  .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
  @media print { @page { size: A4; margin: 15mm; } }
</style>
</head>
<body>
  <div class='header'>
    <div>
      <h1>{$restaurant->name}</h1>
      <div>Rapport de caisse — {$date}</div>
      <div>Caissier: {$caissier}</div>
      <div>Ouverte: {$openedAt} | Fermée: {$closedAt}</div>
    </div>
    <div style='text-align:right'>
      <span class='badge'>RAPPORT OFFICIEL</span>
    </div>
  </div>

  <div class='summary-box'>
    <div class='box'>
      <div class='label'>Total Ventes</div>
      <div class='value'>{$totalRevenueF} FCFA</div>
    </div>
    <div class='box'>
      <div class='label'>Total Dépenses</div>
      <div class='value'>{$totalExpensesF} FCFA</div>
    </div>
    <div class='box'>
      <div class='label'>Net Encaissé</div>
      <div class='value'>{$netF} FCFA</div>
    </div>
  </div>

  <h2>Paiements par méthode</h2>
  <table>
    <thead><tr><th>Mode</th><th>Nb transactions</th><th>Total</th></tr></thead>
    <tbody>{$paymentsHtml}</tbody>
  </table>

  <h2>Commandes par type</h2>
  <table>
    <thead><tr><th>Type</th><th>Nb commandes</th><th>Total</th></tr></thead>
    <tbody>{$typeHtml}</tbody>
  </table>

  <h2>Dépenses de la session</h2>
  " . ($expensesHtml ? "
  <table>
    <thead><tr><th>Description</th><th>Catégorie</th><th>Montant</th></tr></thead>
    <tbody>{$expensesHtml}</tbody>
  </table>" : "<p style='color:#999'>Aucune dépense enregistrée.</p>") . "

  <h2>Clôture de caisse</h2>
  <table>
    <tr><td>Fond d'ouverture</td><td style='text-align:right'>{$openAmount} FCFA</td></tr>
    <tr><td>Montant attendu</td><td style='text-align:right'>{$expectedF} FCFA</td></tr>
    <tr><td>Montant compté</td><td style='text-align:right'>{$closeAmount} FCFA</td></tr>
    <tr><td><strong>Écart</strong></td><td style='text-align:right;color:{$diffColor}'><strong>{$diffF} FCFA</strong></td></tr>
  </table>

  <div class='footer'>
    Rapport généré automatiquement le " . now()->format('d/m/Y à H:i') . " — {$restaurant->name}
  </div>
</body>
</html>";
    }

    /**
     * Envoie le rapport par email au boss
     */
    public function sendReportByEmail(CashSession $session, string $toEmail): bool
    {
        try {
            $html     = $this->generateSessionReportHtml($session);
            $subject  = "Rapport de caisse — {$session->restaurant->name} — " . ($session->opened_at?->format('d/m/Y') ?? now()->format('d/m/Y'));

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
            \Log::error('Failed to send cash session report: ' . $e->getMessage());
            return false;
        }
    }
}
