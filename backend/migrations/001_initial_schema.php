<?php
// RPG plugin — initial schema (all tables except rpg_invites which is added in 002)
// Uses hasTable guards so this is safe to run on existing installations.

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (!Schema::hasTable('rpg_sessions')) {
    Schema::create('rpg_sessions', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('channel_id', 36)->index();
        $table->string('gm_member_id');
        $table->string('gm_username');
        $table->string('status', 50)->default('waiting');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

if (!Schema::hasTable('rpg_players')) {
    Schema::create('rpg_players', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('session_id', 36)->index();
        $table->string('member_id');
        $table->string('username');
        $table->timestamp('joined_at')->useCurrent();
        $table->unique(['session_id', 'member_id']);
    });
}

if (!Schema::hasTable('rpg_character_templates')) {
    Schema::create('rpg_character_templates', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('session_id', 36)->index();
        $table->string('name');
        $table->json('fields');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

if (!Schema::hasTable('rpg_characters')) {
    Schema::create('rpg_characters', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('session_id', 36)->index();
        $table->string('member_id');
        $table->string('username');
        $table->char('template_id', 36)->nullable();
        $table->json('data')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->unique(['session_id', 'member_id']);
    });
}

if (!Schema::hasTable('rpg_roll_queue')) {
    Schema::create('rpg_roll_queue', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('session_id', 36)->index();
        $table->string('requested_by_member_id');
        $table->string('assigned_to_member_id');
        $table->string('dice_type', 20);
        $table->string('note', 255)->nullable();
        $table->string('status', 20)->default('pending');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

if (!Schema::hasTable('rpg_rolls')) {
    Schema::create('rpg_rolls', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('session_id', 36)->index();
        $table->char('queue_id', 36)->nullable();
        $table->string('roller_member_id');
        $table->string('roller_username');
        $table->string('dice_type', 20);
        $table->integer('result');
        $table->boolean('is_public')->default(true);
        $table->string('note', 255)->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
}

if (!Schema::hasTable('rpg_messages')) {
    Schema::create('rpg_messages', function (Blueprint $table) {
        $table->char('id', 36)->primary();
        $table->char('session_id', 36)->index();
        $table->string('author_member_id');
        $table->string('author_username');
        $table->text('content');
        $table->boolean('is_whisper')->default(false);
        $table->string('target_member_id')->nullable();
        $table->string('target_username')->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
}
