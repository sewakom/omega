<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CakeOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CakeOrderController extends Controller
{
    /** Liste des commandes gâteaux */
    public function index(Request $request)
    {
        $orders = CakeOrder::where('restaurant_id', $request->user()->restaurant_id)
            ->with('cashier:id,first_name,last_name')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date, fn($q) => $q->where('delivery_date', $request->date))
            ->when($request->is_paid !== null, fn($q) => $q->where('is_paid', (bool)$request->is_paid))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('customer_name', 'like', "%{$request->search}%")
                  ->orWhere('customer_phone', 'like', "%{$request->search}%")
                  ->orWhere('order_number', 'like', "%{$request->search}%");
            }))
            ->orderBy('delivery_date')
            ->orderBy('delivery_time')
            ->paginate(25);

        return response()->json($orders);
    }

    /** Créer une commande gâteau */
    public function store(Request $request)
    {
        $request->validate([
            'customer_name'   => 'required|string|max:150',
            'customer_phone'  => 'required|string|max:20',
            'delivery_date'   => 'required|date|after_or_equal:today',
            'delivery_time'   => 'nullable|date_format:H:i',
            'items'           => 'required|array|min:1',
            'items.*.name'    => 'required|string',
            'items.*.qty'     => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes'   => 'nullable|string',
            'advance_paid'    => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        $total = collect($request->items)->sum(fn($i) => $i['qty'] * $i['unit_price']);
        $advance = $request->advance_paid ?? 0;

        $order = CakeOrder::create([
            'restaurant_id'   => $request->user()->restaurant_id,
            'user_id'         => $request->user()->id,
            'cash_session_id' => $this->getActiveSession($request->user()->restaurant_id)?->id,
            'order_number'    => CakeOrder::generateNumber($request->user()->restaurant_id),
            'customer_name'   => $request->customer_name,
            'customer_phone'  => $request->customer_phone,
            'items'           => $request->items,
            'total'           => $total,
            'advance_paid'    => $advance,
            'remaining_amount'=> max(0, $total - $advance),
            'delivery_date'   => $request->delivery_date,
            'delivery_time'   => $request->delivery_time,
            'notes'           => $request->notes,
        ]);

        $order->logActivity('cake_order_created', "Commande gâteau #{$order->order_number} pour {$order->customer_name}");

        return response()->json($order, 201);
    }

    /** Détail d'une commande gâteau */
    public function show(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);
        return response()->json($cakeOrder->load('cashier:id,first_name,last_name'));
    }

    /** Mettre à jour le statut d'une commande gâteau */
    public function updateStatus(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'status' => 'required|in:confirmed,preparing,ready,collected,cancelled',
        ]);

        $cakeOrder->update(['status' => $request->status]);
        $cakeOrder->logActivity('cake_status_updated', "Commande #{$cakeOrder->order_number} → {$request->status}");

        return response()->json(['message' => 'Statut mis à jour.', 'order' => $cakeOrder]);
    }

    /** Encaisser une commande gâteau */
    public function collect(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($cakeOrder->is_paid, 422, 'Commande déjà encaissée.');

        $request->validate([
            'payment_method'    => 'required|in:cash,card,wave,orange_money,bank,other',
            'payment_reference' => 'nullable|string|max:100',
            'amount_paid'       => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($cakeOrder, $request) {
            $totalPaid = $cakeOrder->advance_paid + $request->amount_paid;

            $cakeOrder->update([
                'is_paid'           => true,
                'paid_at'           => now(),
                'payment_method'    => $request->payment_method,
                'payment_reference' => $request->payment_reference,
                'advance_paid'      => $totalPaid,
                'remaining_amount'  => 0,
                'status'            => 'collected',
                'cash_session_id'   => $this->getActiveSession($cakeOrder->restaurant_id)?->id,
            ]);
        });

        $cakeOrder->logActivity('cake_order_paid', "Commande #{$cakeOrder->order_number} encaissée ({$request->payment_method})");

        return response()->json([
            'message' => 'Commande encaissée avec succès.',
            'order'   => $cakeOrder->fresh(),
        ]);
    }

    /** Ticket de confirmation gâteau — format 58mm */
    public function ticket(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);

        $items = collect($cakeOrder->items);
        $itemsHtml = '';
        foreach ($items as $item) {
            $note = isset($item['notes']) && $item['notes']
                ? "<div style='font-size:10px;font-style:italic;padding-left:22px'>{$item['notes']}</div>"
                : '';
            $price = number_format($item['unit_price'], 0, ',', ' ');
            $total = number_format($item['qty'] * $item['unit_price'], 0, ',', ' ');
            $itemsHtml .= "
            <div style='display:flex;justify-content:space-between;padding:3px 0'>
                <span><strong>x{$item['qty']}</strong> {$item['name']}</span>
                <span>{$total} FCFA</span>
            </div>{$note}";
        }

        $total     = number_format($cakeOrder->total, 0, ',', ' ');
        $advance   = number_format($cakeOrder->advance_paid, 0, ',', ' ');
        $remaining = number_format($cakeOrder->remaining_amount, 0, ',', ' ');
        $dDate     = \Carbon\Carbon::parse($cakeOrder->delivery_date)->format('d/m/Y');
        $dTime     = $cakeOrder->delivery_time ? " à {$cakeOrder->delivery_time}" : '';

        $html = "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  body { font-family:'Courier New',monospace; font-size:12px; width:58mm; padding:4px; }
  .header { text-align:center; border-bottom:2px dashed #000; padding-bottom:6px; margin-bottom:6px; }
  .divider { border-top:1px dashed #000; margin:4px 0; }
  .footer { text-align:center; margin-top:8px; font-size:10px; }
  @media print { @page { margin:0; } }
</style>
</head>
<body>
  <div class='header'>
    <div style='font-size:14px;font-weight:bold'>🎂 COMMANDE GÂTEAU</div>
    <div>#{$cakeOrder->order_number}</div>
  </div>
  <div><strong>Client:</strong> {$cakeOrder->customer_name}</div>
  <div><strong>Tél:</strong> {$cakeOrder->customer_phone}</div>
  <div><strong>Livraison:</strong> {$dDate}{$dTime}</div>
  <div class='divider'></div>
  {$itemsHtml}
  <div class='divider'></div>
  <div style='display:flex;justify-content:space-between'><span>TOTAL</span><span>{$total} FCFA</span></div>
  <div style='display:flex;justify-content:space-between'><span>Acompte</span><span>{$advance} FCFA</span></div>
  <div style='display:flex;justify-content:space-between;font-weight:bold'><span>RESTE</span><span>{$remaining} FCFA</span></div>
  <div class='footer'>Merci pour votre commande !<br>Certifié par Omega POS</div>
</body>
</html>";

        return response($html)->header('Content-Type', 'text/html');
    }
}
