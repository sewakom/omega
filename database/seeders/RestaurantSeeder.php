<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\Floor;
use App\Models\Table;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les clés étrangères pour permettre de vider les tables
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Liste des tables à vider pour repartir à zéro proprement
        $tables = [
            'restaurants', 'roles', 'users', 'floors', 'tables', 
            'categories', 'products', 'ingredients', 'recipes',
            'stock_movements', 'orders', 'order_items', 'payments', 
            'cash_sessions'
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 1. Création du Restaurant principal
        // On FORCE l'id à 1 pour être sûr que le VITE_RESTAURANT_ID=1 du frontend fonctionne
        $restaurant = Restaurant::create([
            'id'       => 1, // FORCE L'ID A 1
            'name'     => 'SmartFlow POS',
            'slug'     => 'smartflow-pos-demo',
            'address'  => 'Avenue du 24 Janvier, Lomé, Togo',
            'phone'    => '+228 90 00 00 00',
            'email'    => 'contact@smartflow-pos.tg',
            'currency' => 'XOF',
            'timezone' => 'Africa/Lome',
            'settings' => [
                'receipt_footer'     => 'Merci de votre visite ! Revenez bientôt.',
                'receipt_width'      => '80mm',
                'default_vat_rate'   => 18,
                'auto_print_receipt' => true,
                'currency_symbol'    => 'FCFA',
                'currency_position'  => 'after',
            ],
        ]);

        // 2. Création des Rôles
        $roles = [
            ['name' => 'admin',   'display_name' => 'Administrateur', 'permissions' => ['*'], 'is_system' => true],
            ['name' => 'manager', 'display_name' => 'Manager',        'permissions' => ['orders.*','payments.*','stock.*','reports.*','users.view','cancellations.*'], 'is_system' => true],
            ['name' => 'cashier', 'display_name' => 'Caissier',       'permissions' => ['orders.view','payments.create','cash_sessions.*'], 'is_system' => true],
            ['name' => 'waiter',  'display_name' => 'Serveur',        'permissions' => ['orders.create','orders.update','tables.view'], 'is_system' => true],
            ['name' => 'cook',    'display_name' => 'Cuisinier',      'permissions' => ['kitchen.*'], 'is_system' => true],
            ['name' => 'driver',  'display_name' => 'Livreur',        'permissions' => ['deliveries.update'], 'is_system' => true],
        ];

        $createdRoles = [];
        foreach ($roles as $role) {
            $createdRoles[$role['name']] = Role::create([
                'restaurant_id' => $restaurant->id, 
                'name' => $role['name'],
                'display_name' => $role['display_name'],
                'permissions' => $role['permissions'],
                'is_system' => $role['is_system']
            ]);
        }

        // 3. Création des Utilisateurs
        $users = [
            ['first_name' => 'Super',  'last_name' => 'Admin',   'email' => 'admin@smartflow.tg',   'pin' => '0000', 'role' => 'admin'],
            ['first_name' => 'Kwame',  'last_name' => 'Manager', 'email' => 'manager@smartflow.tg', 'pin' => '1111', 'role' => 'manager'],
            ['first_name' => 'Ama',    'last_name' => 'Serveur', 'email' => 'ama@smartflow.tg',     'pin' => '2222', 'role' => 'waiter'],
            ['first_name' => 'Kofi',   'last_name' => 'Caisse',  'email' => 'kofi@smartflow.tg',    'pin' => '3333', 'role' => 'cashier'],
            ['first_name' => 'Jean',   'last_name' => 'Cuisine', 'email' => 'jean@smartflow.tg',    'pin' => '4444', 'role' => 'cook'],
        ];

        foreach ($users as $u) {
            User::create([
                'restaurant_id' => $restaurant->id,
                'role_id'       => $createdRoles[$u['role']]->id,
                'first_name'    => $u['first_name'],
                'last_name'     => $u['last_name'],
                'email'         => $u['email'],
                'password'      => Hash::make('password'),
                'pin'           => Hash::make($u['pin']),
                'active'        => true,
            ]);
        }

        // 4. Salle et Tables
        $floor = Floor::create(['restaurant_id' => $restaurant->id, 'name' => 'Salle principale', 'order' => 1]);
        $tablePositions = [
            ['number' => '1', 'cap' => 4, 'x' => 50, 'y' => 60],
            ['number' => '2', 'cap' => 4, 'x' => 200, 'y' => 60],
            ['number' => '3', 'cap' => 2, 'x' => 350, 'y' => 60],
            ['number' => '4', 'cap' => 6, 'x' => 500, 'y' => 60],
            ['number' => 'T1', 'cap' => 10, 'x' => 250, 'y' => 250, 'shape' => 'round'],
        ];
        foreach ($tablePositions as $t) {
            Table::create([
                'floor_id' => $floor->id, 'number' => $t['number'], 'capacity' => $t['cap'],
                'position_x' => $t['x'], 'position_y' => $t['y'], 'width' => 100, 'height' => 80,
                'shape' => $t['shape'] ?? 'rectangle',
            ]);
        }

        // 5. Ingrédients (Stock)
        $ingredientsData = [
            ['name' => 'Pain Burger', 'unit' => 'unité', 'quantity' => 50, 'min_quantity' => 10, 'cost' => 150],
            ['name' => 'Steak de Bœuf', 'unit' => 'unité', 'quantity' => 40, 'min_quantity' => 15, 'cost' => 800],
            ['name' => 'Tomates', 'unit' => 'kg', 'quantity' => 10.5, 'min_quantity' => 2, 'cost' => 500],
            ['name' => 'Pommes de terre', 'unit' => 'kg', 'quantity' => 25, 'min_quantity' => 5, 'cost' => 400],
            ['name' => 'Huile de friture', 'unit' => 'L', 'quantity' => 20, 'min_quantity' => 5, 'cost' => 1200],
            ['name' => 'Sucre', 'unit' => 'kg', 'quantity' => 5, 'min_quantity' => 1, 'cost' => 700],
            ['name' => 'Poulet entier', 'unit' => 'unité', 'quantity' => 15, 'min_quantity' => 5, 'cost' => 3500],
            ['name' => 'Riz Long Grain', 'unit' => 'kg', 'quantity' => 100, 'min_quantity' => 20, 'cost' => 650],
        ];

        $ingredients = [];
        foreach ($ingredientsData as $ing) {
            $ingredients[$ing['name']] = Ingredient::create([
                'restaurant_id' => $restaurant->id,
                'name'          => $ing['name'],
                'unit'          => $ing['unit'],
                'quantity'      => $ing['quantity'],
                'min_quantity'  => $ing['min_quantity'],
                'cost_per_unit' => $ing['cost'],
                'category'      => 'Général',
            ]);
        }

        // 6. Catégories et Produits
        $categoriesData = [
            'Burgers' => [
                ['name' => 'Cheeseburger King', 'price' => 3500, 'ingredients' => [
                    ['name' => 'Pain Burger', 'qty' => 1],
                    ['name' => 'Steak de Bœuf', 'qty' => 1],
                    ['name' => 'Tomates', 'qty' => 0.05],
                ]],
                ['name' => 'Double Steak Burger', 'price' => 5000, 'ingredients' => [
                    ['name' => 'Pain Burger', 'qty' => 1],
                    ['name' => 'Steak de Bœuf', 'qty' => 2],
                ]],
            ],
            'Plats Locaux' => [
                ['name' => 'Poulet Braisé + Frites', 'price' => 4500, 'ingredients' => [
                    ['name' => 'Poulet entier', 'qty' => 0.25],
                    ['name' => 'Pommes de terre', 'qty' => 0.3],
                    ['name' => 'Huile de friture', 'qty' => 0.1],
                ]],
                ['name' => 'Riz Sauce Arachide', 'price' => 2500, 'ingredients' => [
                    ['name' => 'Riz Long Grain', 'qty' => 0.2],
                ]],
            ],
            'Boissons' => [
                ['name' => 'Coca Cola 33cl', 'price' => 800, 'direct_stock' => 24],
                ['name' => 'Eau Minérale 50cl', 'price' => 500, 'direct_stock' => 48],
                ['name' => 'Jus d\'Orange Frais', 'price' => 1500, 'ingredients' => [
                    ['name' => 'Sucre', 'qty' => 0.01],
                ]],
            ],
        ];

        foreach ($categoriesData as $catName => $products) {
            $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => $catName]);
            foreach ($products as $pData) {
                $product = Product::create([
                    'restaurant_id' => $restaurant->id,
                    'category_id'   => $category->id,
                    'name'          => $pData['name'],
                    'price'         => $pData['price'],
                    'track_stock'   => isset($pData['direct_stock']) || isset($pData['ingredients']),
                    'quantity'      => $pData['direct_stock'] ?? 0,
                    'min_quantity'  => isset($pData['direct_stock']) ? 6 : 0,
                ]);

                // Création de la recette
                if (isset($pData['ingredients'])) {
                    foreach ($pData['ingredients'] as $recipeItem) {
                        Recipe::create([
                            'product_id'    => $product->id,
                            'ingredient_id' => $ingredients[$recipeItem['name']]->id,
                            'quantity'      => $recipeItem['qty'],
                        ]);
                    }
                }
            }
        }

        $this->command->info("✅ SYSTÈME COMPLET INITIALISÉ FORCÉ AVEC ID 1 !");
        $this->command->info("🔑 Mdp: password | PIN Admin: 0000");
    }
}
