<?php

namespace App\Services;

use FPDF;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\CakeOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdministrativeReportService extends FPDF
{
    private $data;
    private $restaurant;

    public function generateDailyReport($date, $restaurant)
    {
        $this->restaurant = $restaurant;
        $this->data = $this->collectData($date, $restaurant->id);

        $this->AddPage();
        $this->SetAutoPageBreak(true, 20);

        // Header
        $this->renderHeader();

        // 1. Resume Global
        $this->renderFinancialSummary();

        // 2. Pillars Breakdown (Cuisine, Pizza, Bar, Gateaux)
        $this->renderPillarsBreakdown();

        // 3. Payments Breakdown
        $this->renderPaymentsBreakdown();

        // 4. Expenses
        $this->renderExpenses();

        // Footer Signatures
        $this->renderSignatures();

        return $this->Output('S');
    }

    /** Helper pour gerer les accents en FPDF */
    private function s($str)
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT', $str ?? '');
    }

    private function collectData($date, $restaurantId)
    {
        $today = $date;

        // Paiements (Argent REEL encaisse aujourd'hui) - Inclut Commandes + Gateaux
        $payments = Payment::where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereDate('created_at', $today)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        // Ventilation par Destination (Uniquement Restaurant Standard)
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get()
            ->pluck('revenue', 'destination');

        // Revenu Gateaux specifique (Argent encaisse aujourd'hui pour les gateaux)
        $cakeRevenue = Payment::whereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->whereDate('created_at', $today)
            ->sum('amount');

        // Depenses
        $expenses = Expense::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->get();

        // Stats Commandes Restaurant Standard
        $orderStats = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today)
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(covers) as covers')->first();

        return [
            'date'           => $today,
            'total_collected' => (float)$payments->sum('total'), // Tout l'argent encaisse
            'restaurant_ca'  => (float)($orderStats->revenue ?? 0), // Valeur des commandes payees
            'payments'       => $payments,
            'by_destination' => $byDestination,
            'cake_revenue'   => (float)$cakeRevenue,
            'expenses'       => $expenses,
            'order_stats'    => $orderStats,
        ];
    }

    private function renderHeader()
    {
        // Direction
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, $this->s(strtoupper($this->restaurant->name)), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, $this->s($this->restaurant->address), 0, 1, 'C');
        $this->Cell(0, 5, "Telephone: " . $this->s($this->restaurant->phone), 0, 1, 'C');
        
        $this->Ln(10);
        
        // Titre
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 12, $this->s("RAPPORT D'ANALYSE ADMINISTRATIVE - " . Carbon::parse($this->data['date'])->format('d/m/Y')), 1, 1, 'C', true);
        
        $this->Ln(10);
    }

    private function renderFinancialSummary()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0);
        $this->Cell(0, 10, $this->s("1. RÉSUMÉ FINANCIER GLOBAL"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', '', 11);
        
        // On affiche bien la distinction pour que ca concorde avec l'interface
        $this->Cell(95, 8, $this->s("Total Encaissé (Argent Collecté):"), 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(95, 8, number_format($this->data['total_collected'], 0, ',', ' ') . " FCFA", 0, 1, 'R');
        
        $this->SetFont('Arial', '', 11);
        $this->Cell(95, 8, $this->s("- Dont Ventes Restaurant:"), 0, 0);
        $this->Cell(95, 8, number_format($this->data['restaurant_ca'], 0, ',', ' ') . " FCFA", 0, 1, 'R');

        $this->Cell(95, 8, $this->s("- Dont Ventes Gâteaux:"), 0, 0);
        $this->Cell(95, 8, number_format($this->data['cake_revenue'], 0, ',', ' ') . " FCFA", 0, 1, 'R');
        
        $this->Ln(3);
        $this->Line($this->GetX() + 100, $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(3);

        $this->Cell(95, 8, $this->s("Nombre de Commandes (Restaurant):"), 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->count ?? 0, 0, 1, 'R');
        
        $this->Cell(95, 8, $this->s("Total Couverts:"), 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->covers ?? 0, 0, 1, 'R');

        $this->Ln(10);
    }

    private function renderPillarsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("2. ANALYSE DES VENTES PAR SECTION"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(120, 10, "Section", 1, 0, 'L', true);
        $this->Cell(70, 10, $this->s("Valeur des Ventes"), 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);
        
        $pillars = [
            'cuisine' => 'Cuisine / Restaurant',
            'bar'     => 'Bar / Boissons',
            'pizza'   => 'Pizzeria',
            'gateaux' => 'Pâtisserie / Gâteaux'
        ];

        foreach ($pillars as $key => $label) {
            $amount = 0;
            if ($key === 'gateaux') {
                $amount = $this->data['cake_revenue'];
            } else {
                $amount = $this->data['by_destination'][$key] ?? 0;
            }

            $this->Cell(120, 10, $this->s($label), 1, 0, 'L');
            $this->Cell(70, 10, number_format($amount, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        $this->Ln(10);
    }

    private function renderPaymentsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("3. DÉTAIL DES ENCAISSEMENTS PAR MODE"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 10, "Mode de Paiement", 1, 0, 'L', true);
        $this->Cell(50, 10, "Nb. Transactions", 1, 0, 'C', true);
        $this->Cell(60, 10, $this->s("Total Encaissé"), 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);
        foreach ($this->data['payments'] as $p) {
            $methodMapping = [
                'cash' => 'Espèces', 
                'card' => 'Carte Bancaire', 
                'wave' => 'Wave', 
                'orange_money' => 'Orange Money', 
                'momo' => 'MTN MoMo',
                'mixx' => 'Mixx Luck',
                'moov' => 'Moov Money'
            ];
            $method = $methodMapping[$p->method] ?? ucfirst(str_replace('_', ' ', $p->method));
            
            $this->Cell(80, 10, $this->s($method), 1, 0, 'L');
            $this->Cell(50, 10, $p->count, 1, 0, 'C');
            $this->Cell(60, 10, number_format($p->total, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        $this->Ln(10);
    }

    private function renderExpenses()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("4. JOURNAL DES DÉPENSES"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        if ($this->data['expenses']->isEmpty()) {
            $this->SetFont('Arial', 'I', 11);
            $this->Cell(0, 10, $this->s("Aucune dépense enregistrée pour cette date."), 0, 1, 'L');
        } else {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(130, 10, $this->s("Motif / Bénéficiaire"), 1, 0, 'L', true);
            $this->Cell(60, 10, "Montant", 1, 1, 'R', true);

            $this->SetFont('Arial', '', 10);
            foreach ($this->data['expenses'] as $e) {
                $label = $e->category . " - " . ($e->description ?? $e->beneficiary);
                $this->Cell(130, 10, $this->s($label), 1, 0, 'L');
                $this->Cell(60, 10, "- " . number_format($e->amount, 0, ',', ' ') . " F", 1, 1, 'R');
            }
            
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(130, 10, $this->s("TOTAL DÉPENSES"), 1, 0, 'R', true);
            $this->Cell(60, 10, "- " . number_format($this->data['expenses']->sum('amount'), 0, ',', ' ') . " F", 1, 1, 'R', true);
        }

        $this->Ln(20);
    }

    private function renderSignatures()
    {
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(63, 10, "La Caisse", 0, 0, 'C');
        $this->Cell(63, 10, "Comptabilite", 0, 0, 'C');
        $this->Cell(63, 10, "Direction", 0, 1, 'C');
        
        $this->Ln(15);
        $this->Cell(63, 10, "..........................", 0, 0, 'C');
        $this->Cell(63, 10, "..........................", 0, 0, 'C');
        $this->Cell(63, 10, "..........................", 0, 1, 'C');
    }
}
