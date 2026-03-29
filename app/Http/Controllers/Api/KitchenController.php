<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Events\OrderItemStatusUpdated;
use App\Events\OrderReady;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    public function orders(Request $request)
    {
        $orders = Order::with([
                'items' => fn($q) => $q->whereIn('status', ['pending', 'preparing', 'done'])
                                        ->with('product:id,name')
                                        ->orderBy('course')
                                        ->orderBy('created_at'),
                'table:id,number',
                'waiter:id,first_name,last_name',
            ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereIn('status', ['sent_to_kitchen', 'partially_served'])
            ->orderBy('sent_to_kitchen_at')
            ->get()
            ->map(function ($order) {
                $order->minutes_since_sent = $order->sent_to_kitchen_at
                    ? now()->diffInMinutes($order->sent_to_kitchen_at) : 0;
                $order->priority = $order->minutes_since_sent > 15 ? 'urgent'
                    : ($order->minutes_since_sent > 10 ? 'warning' : 'normal');
                return $order;
            });

        return response()->json($orders);
    }

    public function updateItemStatus(Request $request, OrderItem $item)
    {
        $this->authorizeItem($request, $item);

        $request->validate(['status' => 'required|in:preparing,done']);

        $item->update([
            'status'      => $request->status,
            'prepared_at' => $request->status === 'done' ? now() : $item->prepared_at,
        ]);

        $order = $item->order;

        $allDone = $order->items()
            ->whereNotIn('status', ['done', 'served', 'cancelled'])
            ->doesntExist();

        if ($allDone) {
            $order->update(['status' => 'served']);
            broadcast(new OrderReady($order->load('table')))->toOthers();

            $order->logs()->create([
                'user_id' => $request->user()->id,
                'action'  => 'order_ready',
                'message' => "Commande {$order->order_number} prête à être servie",
            ]);
        } elseif ($request->status === 'done') {
            $order->update(['status' => 'partially_served']);
        }

        broadcast(new OrderItemStatusUpdated($item, $order))->toOthers();

        return response()->json([
            'item'      => $item,
            'order'     => $order->fresh(),
            'all_done'  => $allDone,
        ]);
    }

    public function validateAll(Request $request, Order $order)
    {
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        DB::transaction(function () use ($order, $request) {
            $order->items()
                ->whereIn('status', ['pending', 'preparing'])
                ->update(['status' => 'done', 'prepared_at' => now()]);

            $order->update(['status' => 'served', 'served_at' => now()]);

            $order->logs()->create([
                'user_id' => $request->user()->id,
                'action'  => 'order_ready',
                'message' => "Commande {$order->order_number} entièrement validée en cuisine",
            ]);
        });

        broadcast(new OrderReady($order->load('table')))->toOthers();

        return response()->json(['message' => 'Commande validée.', 'order' => $order->fresh()]);
    }

    private function authorizeItem(Request $request, OrderItem $item): void
    {
        abort_if($item->order->restaurant_id !== $request->user()->restaurant_id, 403);
    }
}
