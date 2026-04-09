<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function show(Request $request) { return response()->json($request->user()->restaurant); }

    public function update(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);
        $restaurant = $request->user()->restaurant;
        $data = $request->only(['name', 'address', 'phone', 'email', 'vat_number', 'currency', 'timezone', 'logo']);
        
        if ($request->has('receipt_subtitle')) {
            $settings = $restaurant->settings ?? [];
            $settings['receipt_subtitle'] = $request->input('receipt_subtitle');
            $data['settings'] = $settings;
        }

        $restaurant->update($data);
        return response()->json($restaurant);
    }

    public function updateConfig(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);
        $request->validate(['settings' => 'required|array']);
        $restaurant = $request->user()->restaurant;
        $merged = array_merge($restaurant->settings ?? [], $request->settings);
        $restaurant->update(['settings' => $merged]);
        return response()->json(['settings' => $restaurant->fresh()->settings]);
    }

    public function getConfig(Request $request)
    {
        $defaults = ['receipt_logo' => true, 'receipt_footer' => 'Merci de votre visite !', 'receipt_width' => '80mm', 'printer_ip' => null, 'kitchen_printer_ip' => null, 'bar_printer_ip' => null, 'pizza_printer_ip' => null, 'printer_port' => 9100, 'default_vat_rate' => 18, 'auto_print_receipt' => true, 'kitchen_alert_sound' => true, 'order_timeout_alert' => 15, 'currency_symbol' => 'FCFA', 'currency_position' => 'after'];
        $config = array_merge($defaults, $request->user()->restaurant->settings ?? []);
        return response()->json($config);
    }
}
