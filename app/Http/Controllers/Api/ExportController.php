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
}
