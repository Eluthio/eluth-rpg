<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

// Sessions — one active session per channel at a time
if (!Schema::hasTable('rpg_sessions')) {
    Schema::create('rpg_sessions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('channel_id')->index();
        $table->string('gm_member_id');           // central_user_id of the GM
        $table->string('gm_username');
        $table->string('status')->default('waiting'); // waiting | active | ended
        $table->timestamps();
    });
}

// Players — members who have joined the session
if (!Schema::hasTable('rpg_players')) {
    Schema::create('rpg_players', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('session_id')->index();
        $table->string('member_id');              // central_user_id
        $table->string('username');
        $table->timestamp('joined_at')->useCurrent();
        $table->unique(['session_id', 'member_id']);
    });
}

// Character templates — GM defines the fields, stored per session
if (!Schema::hasTable('rpg_character_templates')) {
    Schema::create('rpg_character_templates', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('session_id')->index();
        $table->string('name');
        $table->json('fields');  // [{ key, label, type: text|number|textarea }]
        $table->timestamps();
    });
}

// Characters — player-filled sheets, one per player per session
if (!Schema::hasTable('rpg_characters')) {
    Schema::create('rpg_characters', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('session_id')->index();
        $table->string('member_id');
        $table->string('username');
        $table->uuid('template_id')->nullable();
        $table->json('data')->nullable();         // { field_key: value }
        $table->timestamps();
        $table->unique(['session_id', 'member_id']);
    });
}

// Roll queue — GM requests a specific roll from a player
if (!Schema::hasTable('rpg_roll_queue')) {
    Schema::create('rpg_roll_queue', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('session_id')->index();
        $table->string('requested_by_member_id');
        $table->string('assigned_to_member_id');  // central_user_id of who must roll
        $table->string('dice_type');              // d4 d6 d8 d10 d12 d20 d100
        $table->string('note')->nullable();
        $table->string('status')->default('pending'); // pending | rolled | cancelled
        $table->timestamps();
    });
}

// Rolls — completed dice rolls log
if (!Schema::hasTable('rpg_rolls')) {
    Schema::create('rpg_rolls', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('session_id')->index();
        $table->uuid('queue_id')->nullable();     // which queue item triggered this, if any
        $table->string('roller_member_id');
        $table->string('roller_username');
        $table->string('dice_type');
        $table->integer('result');
        $table->boolean('is_public')->default(true);
        $table->string('note')->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
}

// Campaign messages — in-session chat, separate from main channel chat
if (!Schema::hasTable('rpg_messages')) {
    Schema::create('rpg_messages', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('session_id')->index();
        $table->string('author_member_id');
        $table->string('author_username');
        $table->text('content');
        $table->boolean('is_whisper')->default(false);
        $table->string('target_member_id')->nullable(); // null = all, or specific member
        $table->string('target_username')->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
}
