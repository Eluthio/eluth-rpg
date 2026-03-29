<?php
// RPG plugin — add rpg_invites table (v1.0.5+)

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (!Schema::hasTable('rpg_invites')) {
    Schema::create('rpg_invites', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('session_id', 36)->index();
        $table->string('member_id')->index();
        $table->string('username');
        $table->string('status', 20)->default('pending');
        $table->timestamp('created_at')->useCurrent();
        $table->unique(['session_id', 'member_id']);
    });
}
