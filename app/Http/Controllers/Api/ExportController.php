<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function __construct(
        protected DataExportService $exportService
    ) {}

    public function export(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        
        try {
            $xlsPath = $this->exportService->exportToExcel(
                $request->from,
                $request->to,
                $restaurantId
            );

            if (!file_exists($xlsPath)) {
                return response()->json(['message' => 'Erreur lors de la génération du fichier.'], 500);
            }

            return response()->download($xlsPath)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            Log::error('Export Error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'params' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Erreur lors de l\'exportation de vos données.',
                'debug' => $e->getMessage()
            ], 500);
        }
    }

    public function purge(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
            'confirm_text' => 'required|string|in:EFFACER',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $from = \Carbon\Carbon::parse($request->from)->startOfDay();
        $to = \Carbon\Carbon::parse($request->to)->endOfDay();

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            // Delete Orders and related data (Manual force delete because of SoftDeletes)
            $orders = \App\Models\Order::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->get();
            
            foreach ($orders as $order) {
                $order->items()->forceDelete();
                $order->payments()->forceDelete();
                $order->logs()->delete();
                $order->cancellations()->forceDelete();
                $order->forceDelete();
            }

            // Cake Orders
            \App\Models\CakeOrder::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->forceDelete();
            
            // Cash Sessions
            \App\Models\CashSession::where('restaurant_id', $restaurantId)
                ->whereBetween('opened_at', [$from, $to])
                ->forceDelete();

            // Expenses
            \App\Models\Expense::where('restaurant_id', $restaurantId)
                ->whereBetween('date', [$from, $to])
                ->delete();

            // Activity Logs for this period
            \Illuminate\Support\Facades\DB::table('activity_log')
                ->where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->delete();

            \Illuminate\Support\Facades\DB::commit();

            return response()->json(['message' => 'Les données de la période sélectionnée ont été définitivement effacées.']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Purge Error: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de la purge : ' . $e->getMessage()], 500);
        }
    }
}
