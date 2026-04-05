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

    /**
     * Reçu de dépense format PDF/HTML avec FILIGRANE
     */
    public function receipt(Expense $expense)
    {
        $expense->load(['user', 'restaurant', 'cashSession']);
        $restaurantName = $expense->restaurant->name ?? 'RESTAURANT';

        $html = "
        <!DOCTYPE html>
        <html lang='fr'>
        <head>
            <meta charset='UTF-8'>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap');
                body { font-family: 'Inter', sans-serif; background: #f4f6f9; color: #1e293b; margin: 0; padding: 40px; }
                .receipt { 
                    max-width: 800px; margin: 0 auto; background: white; padding: 60px; 
                    border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); 
                    position: relative; overflow: hidden;
                }
                /* FILIGRANE */
                .watermark {
                    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg);
                    font-size: 80px; font-weight: 900; color: rgba(0,0,0,0.05); white-space: nowrap;
                    pointer-events: none; z-index: 0; text-transform: uppercase; letter-spacing: 0.1em;
                }
                .header { border-bottom: 2px solid #f1f5f9; padding-bottom: 30px; margin-bottom: 40px; position: relative; z-index: 1; }
                .title { font-size: 24px; font-weight: 900; text-transform: uppercase; letter-spacing: -0.02em; color: #f97316; }
                .ref { font-size: 11px; text-transform: uppercase; font-weight: 700; color: #94a3b8; margin-top: 5px; }
                
                .grid { display: grid; grid-template-cols: 1fr 1fr; gap: 40px; position: relative; z-index: 1; }
                .item-label { font-size: 10px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.1em; margin-bottom: 8px; }
                .item-value { font-size: 16px; font-weight: 700; color: #1e293b; }
                
                .amount-box { 
                    margin-top: 50px; padding: 30px; background: #0f172a; border-radius: 15px; 
                    text-align: center; color: white; position: relative; z-index: 1;
                }
                .amount-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: rgba(255,255,255,0.4); margin-bottom: 10px; letter-spacing: 0.2em; }
                .amount-value { font-size: 42px; font-weight: 900; color: #f97316; letter-spacing: -0.01em; }
                
                .footer { margin-top: 60px; padding-top: 30px; border-top: 1px solid #f1f5f9; font-size: 10px; color: #94a3b8; text-align: center; position: relative; z-index: 1; }
                .signature-boxes { display: grid; grid-template-cols: 1fr 1fr; gap: 40px; margin-top: 40px; position: relative; z-index: 1; }
                .sig-box { border: 1px dashed #e2e8f0; height: 100px; border-radius: 10px; position: relative; }
                .sig-label { position: absolute; bottom: 10px; left: 0; width: 100%; text-align: center; font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; }
            </style>
        </head>
        <body>
            <div class='receipt'>
                <div class='watermark'>{$restaurantName}</div>
                
                <div class='header'>
                    <div class='title'>Justificatif de Sortie de Caisse</div>
                    <div class='ref'>Référence : EXP-" . str_pad($expense->id, 6, '0', STR_PAD_LEFT) . " • Émis le " . $expense->created_at->format('d/m/Y à H:i') . "</div>
                </div>

                <div class='grid'>
                    <div>
                        <div class='item-label'>Agent ayant perçu les fonds</div>
                        <div class='item-value'>" . ($expense->agent_name ?: 'Non spécifié') . "</div>
                    </div>
                    <div>
                        <div class='item-label'>Bénéficiaire / Fournisseur</div>
                        <div class='item-value'>" . ($expense->beneficiary ?: 'Non spécifié') . "</div>
                    </div>
                    <div style='margin-top: 20px;'>
                        <div class='item-label'>Motif de la dépense</div>
                        <div class='item-value'>" . ($expense->description ?: 'Aucun motif') . "</div>
                    </div>
                    <div style='margin-top: 20px;'>
                        <div class='item-label'>Catégorie comptable</div>
                        <div class='item-value' style='text-transform: uppercase;'>" . $expense->category . "</div>
                    </div>
                </div>

                <div class='amount-box'>
                    <div class='amount-label'>Montant Total Décaissé</div>
                    <div class='amount-value'>" . number_format($expense->amount, 0, ',', ' ') . " FCFA</div>
                </div>

                <div class='signature-boxes'>
                    <div class='sig-box'><div class='sig-label'>Signature Agent</div></div>
                    <div class='sig-box'><div class='sig-label'>Validation BOSS / Manager</div></div>
                </div>

                <div class='footer'>
                    Ce document fait office de pièce comptable pour les justificatifs de caisse de " . $restaurantName . ".<br>
                    ID Session : #" . ($expense->cash_session_id ?: 'N/A') . " • Utilisateur : " . $expense->user->first_name . "
                </div>
            </div>
            <script>window.onload = function() { window.print(); }</script>
        </body>
        </html>";

        return response($html)->header('Content-Type', 'text/html');
    }
}
