<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use Auditable, SoftDeletes;

    const AUDIT_MODULE = 'order';

    protected $fillable = [
        'restaurant_id', 'table_id', 'user_id', 'cashier_id',
        'order_number', 'type', 'status', 'covers',
        'customer_name', 'customer_phone',
        'subtotal', 'discount_amount', 'discount_reason',
        'vat_amount', 'total', 'notes',
        'sent_to_kitchen_at', 'served_at', 'paid_at',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount'      => 'decimal:2',
        'total'           => 'decimal:2',
        'sent_to_kitchen_at' => 'datetime',
        'served_at'       => 'datetime',
        'paid_at'         => 'datetime',
    ];

    // Relations
    public function restaurant()   { return $this->belongsTo(Restaurant::class); }
    public function table()        { return $this->belongsTo(Table::class); }
    public function waiter()       { return $this->belongsTo(User::class, 'user_id'); }
    public function cashier()      { return $this->belongsTo(User::class, 'cashier_id'); }
    public function items()        { return $this->hasMany(OrderItem::class); }
    public function payments()     { return $this->hasMany(Payment::class); }
    public function logs()         { return $this->hasMany(OrderLog::class)->latest(); }
    public function delivery()     { return $this->hasOne(Delivery::class); }
    public function cancellations(){ return $this->morphMany(Cancellation::class, 'cancellable'); }
    public function customerTabs() { return $this->belongsToMany(CustomerTab::class, 'customer_tab_orders'); }

    // Scopes
    public function scopeOpen($q)   { return $q->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served']); }
    public function scopeToday($q)  { return $q->whereDate('created_at', today()); }
    public function scopePaid($q)   { return $q->where('status', 'paid'); }

    // Calculer et mettre à jour les totaux
    public function recalculate(): void
    {
        $subtotal = $this->items()
            ->whereNotIn('status', ['cancelled'])
            ->sum(DB::raw('unit_price * quantity'));

        $vatRate = $this->restaurant->settings['default_vat_rate'] ?? 18;
        $vatAmount = round(($subtotal - $this->discount_amount) * ($vatRate / 100), 2);
        
        $total     = $subtotal - $this->discount_amount + $vatAmount;

        $this->update([
            'subtotal'   => $subtotal,
            'vat_amount' => $vatAmount,
            'total'      => max(0, $total),
        ]);
    }

    // Montant déjà payé
    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    // Reste à payer
    public function amountDue(): float
    {
        return max(0, $this->total - $this->amountPaid());
    }

    // Générer numéro de commande
    public static function generateNumber(int $restaurantId): string
    {
        $today = now()->format('Ymd');
        $count = static::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', today())
            ->count() + 1;
        return "ORD-{$today}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Enregistrer une activité pour cette commande
     */
    public function logActivity(string $action, string $message, array $meta = []): OrderLog
    {
        return $this->logs()->create([
            'user_id' => \Illuminate\Support\Facades\Auth::id(),
            'action'  => $action,
            'message' => $message,
            'meta'    => $meta,
        ]);
    }
}
