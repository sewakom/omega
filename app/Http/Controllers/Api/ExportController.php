<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataExportService;
use Illuminate\Http\Request;
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
            $zipPath = $this->exportService->exportToZip(
                $request->from,
                $request->to,
                $restaurantId
            );

            if (!file_exists($zipPath)) {
                return response()->json(['message' => 'Erreur lors de la génération du fichier.'], 500);
            }

            return response()->download($zipPath)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de l\'exportation: ' . $e->getMessage()
            ], 500);
        }
    }
}
