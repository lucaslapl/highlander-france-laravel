<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table des maps ETF2L gérées via le panel admin.
 * Remplaçant la liste en dur présente à l'origine dans PageController::etf2lMaps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etf2l_maps', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 128)->comment('Nom de la map, ex: cp_process_f12');
            $table->string('label', 160)->comment('Nom affiché sur la page publique');
            $table->enum('category', ['6v6', '9v9'])->default('6v6');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('bsp_file', 191)->nullable()->comment('Nom du fichier .bsp');
            $table->string('thumbnail', 500)->nullable()->comment('Chemin de la miniature');
            $table->boolean('is_active')->default(1);
            $table->timestamps();

            // Une même map peut exister dans les deux catégories (ex: koth_product).
            $table->unique(['name', 'category']);
        });

        // Import des 15 maps de la saison en cours (source de vérité = recette + panel).
        $maps = [
            // 6v6 — Sixes
            ['cp_sunshine', '6v6', 1],
            ['cp_process_f12', '6v6', 2],
            ['cp_gullywash_f9', '6v6', 3],
            ['cp_metalworks_f7', '6v6', 4],
            ['koth_govan_rc2', '6v6', 5],
            ['cp_subbase_b3a', '6v6', 6],
            ['koth_bagel_rc12', '6v6', 7],
            ['cp_granary_pro_rc17a3', '6v6', 8],
            ['koth_product_final', '6v6', 9],
            // 9v9 — Highlander
            ['pl_swiftwater_final1', '9v9', 1],
            ['pl_vigil_rc10', '9v9', 2],
            ['cp_steel_f12', '9v9', 3],
            ['pl_upward_f12', '9v9', 4],
            ['koth_product_final', '9v9', 5],
            ['koth_proot_b5b', '9v9', 6],
        ];

        foreach ($maps as [$name, $category, $sort]) {
            DB::table('etf2l_maps')->insert([
                'name' => $name,
                'label' => $name,
                'category' => $category,
                'sort_order' => $sort,
                'bsp_file' => $name . '.bsp',
                'thumbnail' => null,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('etf2l_maps');
    }
};
