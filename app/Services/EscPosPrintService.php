<?php

namespace App\Services;

use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\Printer;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;

class EscPosPrintService
{
    protected OrderRoutingService $routing;

    public function __construct(OrderRoutingService $routing)
    {
        $this->routing = $routing;
    }

    /**
     * Envoie la commande d'impression directement à l'imprimante thermique via réseau (IP)
     */
    public function printKitchenTicket(Order $order, string $destination, $itemsToPrint = null, bool $isModification = false): array
    {
        $order->loadMissing(['items.product.category', 'table', 'waiter', 'restaurant']);
        
        $settings = $order->restaurant->settings ?? [];
        $ip = $settings["{$destination}_printer_ip"] ?? null;
        $port = $settings['printer_port'] ?? 9100;

        if (!$ip) {
            // Aucune IP configurée pour ce pôle, on ignore silencieusement
            return ['success' => true, 'message' => 'Non configurée'];
        }

        if ($itemsToPrint) {
            $allGroups = $this->routing->groupByDestination($itemsToPrint);
            $items = $allGroups[$destination] ?? collect();
        } else {
            $filteredItems = $order->items->whereNotIn('status', ['cancelled']);
            $allGroups = $this->routing->groupByDestination($filteredItems);
            $items = $allGroups[$destination] ?? collect();
        }

        if ($items->isEmpty()) {
            return ['success' => true]; // Rien à imprimer pour cette destination
        }

        try {
            $connector = new NetworkPrintConnector($ip, $port, 3); // 3 secondes de timeout
            $printer = new Printer($connector);
            
            // Entête
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            if ($isModification) {
                $printer->setTextSize(2, 2);
                $printer->text("*** MODIFICATION ***\n");
            }

            $printer->setTextSize(2, 2);
            // Convertir les caractères spéciaux
            $printer->text(strtoupper($this->removeAccents($this->routing->destinationLabel($destination))) . "\n");
            $printer->setTextSize(1, 1);
            $printer->text("Ticket #" . $order->order_number . "\n");
            $printer->text("Modifie le: " . now()->format('d/m/Y H:i') . "\n");
            $printer->text("IFU : 1001580865\n");
            $printer->text(str_repeat('-', 42) . "\n");

            // Info de la commande
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->setTextSize(2, 2);
            if ($order->table) {
                $printer->text("TABLE " . $order->table->number . "\n");
            } else {
                $typeLabel = match($order->type) { 'dine_in' => 'Sur place', 'takeaway' => 'A emporter', 'gozem' => 'Livraison', default => ucfirst($order->type) };
                $printer->text(strtoupper($typeLabel) . "\n");
            }
            $printer->setTextSize(1, 1);
            $printer->text(str_repeat('-', 42) . "\n");

            if ($order->waiter) {
                $printer->text("Serveur: " . $this->removeAccents($order->waiter->first_name) . "\n");
            }
            if ($order->customer_name && $order->type === 'gozem') {
                $printer->text("Client: " . $this->removeAccents($order->customer_name) . " (" . $order->customer_phone . ")\n");
            }
            if ($order->notes) {
                $printer->setEmphasis(true);
                $printer->text("Note Cmd: " . $this->removeAccents($order->notes) . "\n");
                $printer->setEmphasis(false);
            }
            $printer->text(str_repeat('-', 42) . "\n");

            // Articles
            $printer->setTextSize(1, 1);
            foreach ($items as $item) {
                $printer->setEmphasis(true);
                $printer->setTextSize(2, 1);
                $printer->text("X" . $item->quantity . " " . strtoupper($this->removeAccents($item->product->name)) . "\n");
                $printer->setEmphasis(false);
                $printer->setTextSize(1, 1);
                if ($item->notes) {
                    $printer->text("   ! " . $this->removeAccents($item->notes) . "\n");
                }
            }

            // Pied de page
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("*** FIN DU BON ***\n");
            $printer->feed(3);
            $printer->cut();
            $printer->close();

            return ['success' => true];
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error("Failed to print to {$destination} printer at {$ip}: " . $errorMsg);
            return ['success' => false, 'message' => $errorMsg];
        }
    }

    /**
     * Imprime le ticket de caisse client complet (TTC + Dont TVA) via IP
     */
    public function printCustomerReceipt(Order $order): array
    {
        $order->loadMissing(['items.product', 'payments', 'table', 'waiter', 'restaurant', 'cashier']);
        
        $settings = $order->restaurant->settings ?? [];
        $ip = $settings["receipt_printer_ip"] ?? null;
        $port = $settings['printer_port'] ?? 9100;

        if (!$ip) {
            return ['success' => false, 'message' => 'Imprimante Reçu non configurée'];
        }

        try {
            $connector = new NetworkPrintConnector($ip, $port, 3);
            $printer = new Printer($connector);
            
            // Header
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setTextSize(1, 1);
            $printer->text($this->removeAccents(strtoupper($order->restaurant->name)) . "\n");
            $subtitle = data_get($settings, 'receipt_subtitle');
            if ($subtitle) {
                $printer->text($this->removeAccents($subtitle) . "\n");
            }
            $printer->text($this->removeAccents($order->restaurant->address) . "\n");
            if ($order->restaurant->phone) $printer->text("Tel: " . $order->restaurant->phone . "\n");
            $vNum = $order->restaurant->vat_number;
            if ($vNum) $printer->text("IFU: " . $vNum . "\n");
            
            $printer->text(str_repeat('-', 42) . "\n");
            
            $printer->setEmphasis(true);
            $printer->text("TICKET DE CAISSE #" . $order->order_number . "\n");
            $printer->setEmphasis(false);
            $printer->text("Date: " . $order->created_at->format('d/m/Y H:i') . "\n");
            $printer->text(str_repeat('-', 42) . "\n");

            // Items
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            foreach ($order->items->whereNotIn('status', ['cancelled']) as $item) {
                $name = strtoupper($this->removeAccents($item->product->name));
                $line1 = "X" . $item->quantity . " " . $name;
                $line2 = number_format($item->subtotal, 0, '.', ' ') . " F";
                
                $printer->text($line1 . "\n");
                $printer->setJustification(Printer::JUSTIFY_RIGHT);
                $printer->text($line2 . "\n");
                $printer->setJustification(Printer::JUSTIFY_LEFT);
            }
            
            $printer->text(str_repeat('-', 42) . "\n");

            // Totals
            $printer->setEmphasis(true);
            $printer->setTextSize(2, 2); // Grand
            $printer->setJustification(Printer::JUSTIFY_RIGHT);
            $printer->text("TOTAL TTC: " . number_format((float)$order->total, 0, '.', ' ') . " F\n");
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
            
            // Info VAT
            $vatRate = $settings['default_vat_rate'] ?? 18;
            $printer->text("Dont TVA (" . $vatRate . "%): " . number_format((float)$order->vat_amount, 0, '.', ' ') . " F\n");
            
            if ($order->discount_amount > 0) {
                $printer->text("Remise: -" . number_format((float)$order->discount_amount, 0, '.', ' ') . " F\n");
            }
            
            $printer->text(str_repeat('-', 42) . "\n");

            // Payments
            foreach ($order->payments as $p) {
                $method = match($p->method) { 
                    'cash' => 'ESPECES', 
                    'wave' => 'WAVE', 
                    'momo' => 'MOMO', 
                    'orange_money' => 'OM', 
                    default => strtoupper($p->method) 
                };
                $printer->text($method . ": " . number_format($p->amount, 0, '.', ' ') . " F\n");
            }
            
            $change = max(0, $order->amountPaid() - $order->total);
            if ($change > 0) {
                $printer->text("MONNAIE RENDUE: " . number_format($change, 0, '.', ' ') . " F\n");
            }

            // Footer
            $printer->feed(1);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text($this->removeAccents($settings['receipt_footer'] ?? 'Merci de votre visite !') . "\n");
            $printer->text("Powered by Omega POS\n");
            
            $printer->feed(3);
            $printer->cut();
            $printer->close();

            return ['success' => true];
        } catch (Exception $e) {
            Log::error("Failed to print receipt to IP {$ip}: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Supprime les accents pour éviter les caractères mal encodés sur l'imprimante thermique
     */
    protected function removeAccents($string)
    {
        if (!$string) return '';
        $unwanted_array = ['Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
                            'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
                            'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
                            'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                            'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' ];
        return strtr( $string, $unwanted_array );
    }
}
