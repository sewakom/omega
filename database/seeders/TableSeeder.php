<?php

namespace Database\Seeders;

use App\Models\Floor;
use App\Models\Table;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // On récupère le restaurant ID 1 par défaut
        $restaurant = Restaurant::find(1);
        
        if (!$restaurant) {
            $this->command->error("Restaurant ID 1 non trouvé. Veuillez d'abord lancer le RestaurantSeeder.");
            return;
        }

        // On vide uniquement les tables et étages liés
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Table::where('floor_id', '>', 0)->delete(); 
        Floor::where('restaurant_id', $restaurant->id)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Création de l'étage principal
        $floor = Floor::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Salle principale',
            'order'         => 1
        ]);

        // Génération des 100 tables en grille 10x10
        $this->command->info("Génération de 100 tables...");
        
        for ($i = 1; $i <= 100; $i++) {
            Table::create([
                'floor_id'   => $floor->id,
                'number'     => (string) $i,
                'capacity'   => ($i % 6 === 0) ? 8 : (($i % 4 === 0) ? 6 : 4),
                'position_x' => (($i - 1) % 10) * 140 + 50, // Espacement X
                'position_y' => floor(($i - 1) / 10) * 120 + 50, // Espacement Y
                'width'      => 100,
                'height'     => 80,
                'shape'      => ($i % 10 === 0) ? 'round' : 'rectangle',
                'active'     => true,
            ]);
        }

        $this->command->info("✅ 100 tables créées avec succès !");
    }
}
