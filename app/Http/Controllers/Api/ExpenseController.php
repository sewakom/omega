<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\CashSession;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /** Liste des dépenses (session courante ou toutes) */
    public function index(Request $request)
    {
        $expenses = Expense::where('restaurant_id', $request->user()->restaurant_id)
            ->with('user:id,first_name,last_name', 'cashSession:id,opened_at')
            ->when($request->cash_session_id, fn($q) => $q->where('cash_session_id', $request->cash_session_id))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->latest()
            ->paginate(30);

        $total = $expenses->sum('amount');

        return response()->json([
            'data'  => $expenses,
            'total' => $total,
        ]);
    }

    /** Créer une dépense */
    public function store(Request $request)
    {
        $request->validate([
            'category'       => 'required|string',
            'description'    => 'nullable|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'receipt_ref'    => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'beneficiary'    => 'nullable|string|max:255',
            'agent_name'     => 'nullable|string|max:255',
        ]);

        // Rattacher à la session de caisse courante
        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')
            ->latest()
            ->first();

        // Mappage de sécurité pour éviter l'erreur ENUM 'Data truncated' sur le serveur distant
        $categoryMapping = [
            'purchases' => 'other',
            'staff'     => 'salary',
            'utilities' => 'other',
        ];

        $safeCategory = $categoryMapping[$request->category] ?? (in_array($request->category, ['food_supply','equipment','fuel','salary','maintenance','cleaning','other']) ? $request->category : 'other');

        $expense = Expense::create([
            'restaurant_id'   => $request->user()->restaurant_id,
            'cash_session_id' => $session?->id,
            'user_id'         => $request->user()->id,
            'category'        => $safeCategory,
            'description'     => $request->description ?: "Dépense {$request->category}",
            'amount'          => $request->amount,
            'payment_method'  => $request->payment_method ?: 'cash',
            'receipt_ref'     => $request->receipt_ref,
            'notes'           => $request->notes,
            'beneficiary'     => $request->beneficiary,
            'agent_name'      => $request->agent_name,
        ]);

        // Mettre à jour le total dépenses de la session
        if ($session) {
            $session->update([
                'total_expenses' => $session->expenses()->sum('amount'),
            ]);
        }

        $expense->logActivity('expense_created', "Dépense: {$expense->description} — {$expense->amount} FCFA ({$expense->category})");

        return response()->json($expense->load('user:id,first_name,last_name'), 201);
    }

    /** Supprimer une dépense (Admin uniquement) */
    public function destroy(Request $request, Expense $expense)
    {
        abort_if($expense->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');

        $request->validate(['reason' => 'required|string|min:5']);

        $expense->logActivity('expense_deleted', "Dépense supprimée: {$expense->description} — Raison: {$request->reason}");
        $expense->delete();

        // Recalculer le total de la session si rattachée
        if ($expense->cash_session_id) {
            $session = CashSession::find($expense->cash_session_id);
            $session?->update([
                'total_expenses' => $session->expenses()->sum('amount'),
            ]);
        }

        return response()->json(['message' => 'Dépense supprimée.']);
    }

    /** Résumé des dépenses par catégorie */
    public function summary(Request $request)
    {
        $summary = Expense::where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->cash_session_id, fn($q) => $q->where('cash_session_id', $request->cash_session_id))
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->get();

        return response()->json($summary);
    }
}
