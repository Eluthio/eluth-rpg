<?php
// RPG plugin — add is_public to rpg_roll_queue so GM controls roll visibility

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (Schema::hasTable('rpg_roll_queue') && !Schema::hasColumn('rpg_roll_queue', 'is_public')) {
    Schema::table('rpg_roll_queue', function (Blueprint $table) {
        $table->boolean('is_public')->default(true)->after('note');
    });
}
