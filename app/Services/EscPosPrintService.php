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
