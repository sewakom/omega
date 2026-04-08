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

        // 1. Résumé Global
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

    private function collectData($date, $restaurantId)
    {
        $today = $date;

        // Payments (Full CA)
        $payments = Payment::where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereDate('created_at', $today)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        // Destination Breakdown
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get()
            ->pluck('revenue', 'destination');

        // Cake Revenue specifically
        $cakeRevenue = Payment::whereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->whereDate('created_at', $today)
            ->sum('amount');

        // Expenses
        $expenses = Expense::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->get();

        // Standard Order Stats
        $orderStats = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today)
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(covers) as covers')->first();

        return [
            'date'           => $today,
            'total_revenue'  => $payments->sum('total'),
            'payments'       => $payments,
            'by_destination' => $byDestination,
            'cake_revenue'   => $cakeRevenue,
            'expenses'       => $expenses,
            'order_stats'    => $orderStats,
        ];
    }

    private function renderHeader()
    {
        // Company Name
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, strtoupper($this->restaurant->name), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, $this->restaurant->address, 0, 1, 'C');
        $this->Cell(0, 5, "Telephone: " . $this->restaurant->phone, 0, 1, 'C');
        
        $this->Ln(10);
        
        // Report Title
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 12, "RAPPORT D'ANALYSE ADMINISTRATIVE - " . Carbon::parse($this->data['date'])->format('d/m/Y'), 1, 1, 'C', true);
        
        $this->Ln(10);
    }

    private function renderFinancialSummary()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0);
        $this->Cell(0, 10, "1. RESUME FINANCIER GLOBAL", 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', '', 11);
        
        $this->Cell(95, 8, "Chiffre d'Affaires de la Journee:", 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(95, 8, number_format($this->data['total_revenue'], 0, ',', ' ') . " FCFA", 0, 1, 'R');
        
        $this->SetFont('Arial', '', 11);
        $this->Cell(95, 8, "Total Commandes Restaurant:", 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->count ?? 0, 0, 1, 'R');
        
        $this->Cell(95, 8, "Total Couverts:", 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->covers ?? 0, 0, 1, 'R');

        $this->Ln(10);
    }

    private function renderPillarsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, "2. ANALYSE PAR SECTION D'ACTIVITE", 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(120, 10, "Section", 1, 0, 'L', true);
        $this->Cell(70, 10, "Montant Encaissé", 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);
        
        // 4 Pillars as requested
        $pillars = [
            'cuisine' => 'Cuisine / Restaurant',
            'bar'     => 'Bar / Boissons',
            'pizza'   => 'Pizzeria',
            'gateaux' => 'Patisserie / Gateaux'
        ];

        foreach ($pillars as $key => $label) {
            $amount = 0;
            if ($key === 'gateaux') {
                $amount = $this->data['cake_revenue'];
            } else {
                $amount = $this->data['by_destination'][$key] ?? 0;
            }

            $this->Cell(120, 10, $label, 1, 0, 'L');
            $this->Cell(70, 10, number_format($amount, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        $this->Ln(10);
    }

    private function renderPaymentsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, "3. DETAIL DES ENCAISSEMENTS PAR MODE", 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 10, "Mode de Paiement", 1, 0, 'L', true);
        $this->Cell(50, 10, "Nb. Transactions", 1, 0, 'C', true);
        $this->Cell(60, 10, "Total Encache", 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);
        foreach ($this->data['payments'] as $p) {
            $method = ucfirst(str_replace('_', ' ', $p->method));
            $this->Cell(80, 10, $method, 1, 0, 'L');
            $this->Cell(50, 10, $p->count, 1, 0, 'C');
            $this->Cell(60, 10, number_format($p->total, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        $this->Ln(10);
    }

    private function renderExpenses()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, "4. JOURNAL DES DEPENSES", 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        if ($this->data['expenses']->isEmpty()) {
            $this->SetFont('Arial', 'I', 11);
            $this->Cell(0, 10, "Aucune depense enregistree pour cette date.", 0, 1, 'L');
        } else {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(130, 10, "Motif / Beneficiaire", 1, 0, 'L', true);
            $this->Cell(60, 10, "Montant", 1, 1, 'R', true);

            $this->SetFont('Arial', '', 10);
            foreach ($this->data['expenses'] as $e) {
                $label = $e->category . " - " . ($e->description ?? $e->beneficiary);
                $this->Cell(130, 10, $label, 1, 0, 'L');
                $this->Cell(60, 10, "- " . number_format($e->amount, 0, ',', ' ') . " F", 1, 1, 'R');
            }
            
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(130, 10, "TOTAL DEPENSES", 1, 0, 'R', true);
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
