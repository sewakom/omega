<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Category;

/**
 * OrderRoutingService
 * Détermine la destination d'impression de chaque item de commande
 * en fonction de la catégorie du produit.
 *
 * Destinations: kitchen | bar | pizza
 */
class OrderRoutingService
{
    /**
     * Regroupe les items par destination
     * @param \Illuminate\Support\Collection $items  Collection de OrderItem avec product.category chargés
     * @return array ['kitchen' => [...], 'bar' => [...], 'pizza' => [...]]
     */
    public function groupByDestination($items): array
    {
        $groups = [
            'kitchen' => collect(),
            'bar'     => collect(),
            'pizza'   => collect(),
        ];

        foreach ($items as $item) {
            $destination = $this->getItemDestination($item);
            $groups[$destination]->push($item);
        }

        return array_filter($groups, fn($g) => $g->isNotEmpty());
    }

    /**
     * Retourne la destination d'un item de commande
     */
    public function getItemDestination(OrderItem $item): string
    {
        // Charger la catégorie si pas encore chargée
        $category = $item->product?->category ?? null;

        if (!$category) {
            return 'kitchen'; // défaut
        }

        return in_array($category->destination, ['kitchen', 'bar', 'pizza'])
            ? $category->destination
            : 'kitchen';
    }

    /**
     * Retourne le label lisible d'une destination
     */
    public function destinationLabel(string $destination): string
    {
        return match($destination) {
            'kitchen' => 'CUISINE',
            'bar'     => 'BAR',
            'pizza'   => 'PIZZA',
            default   => 'CUISINE',
        };
    }

    /**
     * Retourne l'emoji d'une destination
     */
    public function destinationIcon(string $destination): string
    {
        return match($destination) {
            'kitchen' => '🍳',
            'bar'     => '🍺',
            'pizza'   => '🍕',
            default   => '🍳',
        };
    }
}
