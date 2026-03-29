<?php
use Illuminate\Support\Facades\Schema;

// Dropped in reverse dependency order
Schema::dropIfExists('rpg_messages');
Schema::dropIfExists('rpg_rolls');
Schema::dropIfExists('rpg_roll_queue');
Schema::dropIfExists('rpg_characters');
Schema::dropIfExists('rpg_character_templates');
Schema::dropIfExists('rpg_players');
Schema::dropIfExists('rpg_sessions');
