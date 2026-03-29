<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Payment;
use Illuminate\Http\Request;

class CashSessionController extends Controller
{
    public function current(Request $request)
    {
        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->with('user:id,first_name,last_name')->latest()->first();

        if (!$session) return response()->json(null);

        $cashIn  = Payment::where('cash_session_id', $session->id)->where('method', 'cash')->sum('amount');
        $expected = $session->opening_amount + $cashIn;

        return response()->json([
            'session'          => $session,
            'cash_in'          => $cashIn,
            'expected_amount'  => $expected,
            'orders_count'     => Payment::where('cash_session_id', $session->id)->distinct('order_id')->count(),
        ]);
    }

    public function open(Request $request)
    {
        $existing = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->exists();
        abort_if($existing, 422, 'Une session de caisse est déjà ouverte.');

        $request->validate(['opening_amount' => 'required|numeric|min:0']);

        $session = CashSession::create([
            'restaurant_id'  => $request->user()->restaurant_id,
            'user_id'        => $request->user()->id,
            'opening_amount' => $request->opening_amount,
            'opened_at'      => now(),
        ]);

        $session->logActivity('cash_session_opened', "Session caisse ouverte avec {$request->opening_amount} FCFA");

        return response()->json($session, 201);
    }

    public function close(Request $request, CashSession $session)
    {
        abort_if($session->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($session->closed_at, 422, 'Session déjà fermée.');
        abort_unless($request->user()->isManager(), 403, 'Manager requis pour fermer la caisse.');

        $request->validate([
            'closing_amount' => 'required|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $cashIn   = Payment::where('cash_session_id', $session->id)->where('method', 'cash')->sum('amount');
        $expected = $session->opening_amount + $cashIn;
        $diff     = $request->closing_amount - $expected;

        $session->update([
            'closing_amount'  => $request->closing_amount,
            'expected_amount' => $expected,
            'difference'      => $diff,
            'closing_notes'   => $request->notes,
            'closed_at'       => now(),
        ]);

        $session->logActivity('cash_session_closed',
            "Session caisse fermée. Attendu: {$expected} | Compté: {$request->closing_amount} | Écart: {$diff}"
        );

        return response()->json($session);
    }

    public function index(Request $request)
    {
        $sessions = CashSession::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->date, fn($q) => $q->whereDate('opened_at', $request->date))
            ->latest('opened_at')
            ->paginate(20);

        return response()->json($sessions);
    }
}
