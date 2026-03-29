<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RpgController
{
    // ─── Helpers ────────────────────────────────────────────────────────────

    private function member(Request $request)
    {
        return $request->attributes->get('member');
    }

    private function session(string $id)
    {
        return DB::table('rpg_sessions')->where('id', $id)->first();
    }

    private function notFound()
    {
        return response()->json(['error' => 'Not found'], 404);
    }

    private function forbidden()
    {
        return response()->json(['error' => 'Forbidden'], 403);
    }

    // ─── Session ────────────────────────────────────────────────────────────

    // GET /api/plugins/rpg/channel/{channelId}/session
    public function channelSession(Request $request, string $channelId)
    {
        $session = DB::table('rpg_sessions')
            ->where('channel_id', $channelId)
            ->whereIn('status', ['waiting', 'active'])
            ->first();

        if (!$session) {
            return response()->json(['session' => null]);
        }

        return response()->json(['session' => $this->sessionWithPlayers($session->id)]);
    }

    // POST /api/plugins/rpg/sessions
    public function createSession(Request $request)
    {
        $member = $this->member($request);
        $channelId = $request->input('channel_id');

        // Check if an active session already exists
        $existing = DB::table('rpg_sessions')
            ->where('channel_id', $channelId)
            ->whereIn('status', ['waiting', 'active'])
            ->first();

        if ($existing) {
            return response()->json(['error' => 'A session is already active in this channel'], 409);
        }

        $id = (string) Str::uuid();
        DB::table('rpg_sessions')->insert([
            'id'            => $id,
            'channel_id'    => $channelId,
            'gm_member_id'  => $member->central_user_id,
            'gm_username'   => $member->username,
            'status'        => 'waiting',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['session' => $this->sessionWithPlayers($id)], 201);
    }

    // GET /api/plugins/rpg/sessions/{id}
    public function getSession(Request $request, string $id)
    {
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        return response()->json(['session' => $this->sessionWithPlayers($id)]);
    }

    // POST /api/plugins/rpg/sessions/{id}/start
    public function startSession(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->gm_member_id !== $member->central_user_id) return $this->forbidden();

        DB::table('rpg_sessions')
            ->where('id', $id)
            ->update(['status' => 'active', 'updated_at' => now()]);

        return response()->json(['session' => $this->sessionWithPlayers($id)]);
    }

    // DELETE /api/plugins/rpg/sessions/{id}
    public function endSession(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->gm_member_id !== $member->central_user_id) return $this->forbidden();

        DB::table('rpg_sessions')
            ->where('id', $id)
            ->update(['status' => 'ended', 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ─── Players ─────────────────────────────────────────────────────────────

    // POST /api/plugins/rpg/sessions/{id}/join
    public function joinSession(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->status === 'ended') {
            return response()->json(['error' => 'Session has ended'], 409);
        }

        $existing = DB::table('rpg_players')
            ->where('session_id', $id)
            ->where('member_id', $member->central_user_id)
            ->first();

        if (!$existing) {
            DB::table('rpg_players')->insert([
                'id'         => (string) Str::uuid(),
                'session_id' => $id,
                'member_id'  => $member->central_user_id,
                'username'   => $member->username,
                'joined_at'  => now(),
            ]);
        }

        return response()->json(['session' => $this->sessionWithPlayers($id)]);
    }

    // DELETE /api/plugins/rpg/sessions/{id}/leave
    public function leaveSession(Request $request, string $id)
    {
        $member = $this->member($request);

        DB::table('rpg_players')
            ->where('session_id', $id)
            ->where('member_id', $member->central_user_id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    // ─── State (polling endpoint) ─────────────────────────────────────────────

    // GET /api/plugins/rpg/sessions/{id}/state
    public function getState(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();

        $players = DB::table('rpg_players')
            ->where('session_id', $id)
            ->get()
            ->map(fn($p) => [
                'member_id' => $p->member_id,
                'username'  => $p->username,
                'joined_at' => $p->joined_at,
            ]);

        // Roll queue — all pending items if GM, or only yours if player
        $queueQuery = DB::table('rpg_roll_queue')
            ->where('session_id', $id)
            ->where('status', 'pending');

        if ($session->gm_member_id !== $member->central_user_id) {
            $queueQuery->where('assigned_to_member_id', $member->central_user_id);
        }

        $queue = $queueQuery->get()->map(fn($q) => [
            'id'                      => $q->id,
            'requested_by_member_id'  => $q->requested_by_member_id,
            'assigned_to_member_id'   => $q->assigned_to_member_id,
            'dice_type'               => $q->dice_type,
            'note'                    => $q->note,
            'is_public'               => isset($q->is_public) ? (bool) $q->is_public : true,
            'created_at'              => $q->created_at,
        ]);

        // Recent rolls — public rolls visible to all; private rolls only to GM and the roller
        $rollsQuery = DB::table('rpg_rolls')
            ->where('session_id', $id)
            ->orderByDesc('created_at')
            ->limit(50);

        $isGm = ($session->gm_member_id === $member->central_user_id);
        $rolls = $rollsQuery->get()
            ->filter(fn($r) => $r->is_public || $isGm || $r->roller_member_id === $member->central_user_id)
            ->values()
            ->map(fn($r) => [
                'id'               => $r->id,
                'roller_member_id' => $r->roller_member_id,
                'roller_username'  => $r->roller_username,
                'dice_type'        => $r->dice_type,
                'result'           => $r->result,
                'is_public'        => (bool) $r->is_public,
                'note'             => $r->note,
                'queue_id'         => $r->queue_id,
                'created_at'       => $r->created_at,
            ]);

        // Messages — whispers only visible to sender, recipient, and GM
        $messages = DB::table('rpg_messages')
            ->where('session_id', $id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->filter(function($m) use ($member, $isGm) {
                if (!$m->is_whisper) return true;
                return $isGm
                    || $m->author_member_id === $member->central_user_id
                    || $m->target_member_id === $member->central_user_id;
            })
            ->values()
            ->map(fn($m) => [
                'id'               => $m->id,
                'author_member_id' => $m->author_member_id,
                'author_username'  => $m->author_username,
                'content'          => $m->content,
                'is_whisper'       => (bool) $m->is_whisper,
                'target_member_id' => $m->target_member_id,
                'target_username'  => $m->target_username,
                'created_at'       => $m->created_at,
            ]);

        // Characters
        $characters = DB::table('rpg_characters')
            ->where('session_id', $id)
            ->get()
            ->map(fn($c) => [
                'id'          => $c->id,
                'member_id'   => $c->member_id,
                'username'    => $c->username,
                'template_id' => $c->template_id,
                'data'        => $c->data ? json_decode($c->data, true) : null,
            ]);

        return response()->json([
            'session'    => (array) $session,
            'players'    => $players,
            'queue'      => $queue,
            'rolls'      => $rolls,
            'messages'   => $messages,
            'characters' => $characters,
        ]);
    }

    // ─── Rolls ───────────────────────────────────────────────────────────────

    // POST /api/plugins/rpg/sessions/{id}/rolls
    public function createRoll(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();

        $diceType = $request->input('dice_type');
        $queueId  = $request->input('queue_id');
        $note     = $request->input('note');

        // If fulfilling a GM-requested roll, inherit visibility from the queue item
        if ($queueId && !$request->has('is_public')) {
            $queueItem = DB::table('rpg_roll_queue')->where('id', $queueId)->first();
            $isPublic = $queueItem ? (bool) $queueItem->is_public : true;
        } else {
            $isPublic = $request->boolean('is_public', true);
        }

        $max = $this->diceMax($diceType);
        if (!$max) {
            return response()->json(['error' => 'Invalid dice type'], 422);
        }

        $result = rand(1, $max);

        $rollId = (string) Str::uuid();
        DB::table('rpg_rolls')->insert([
            'id'               => $rollId,
            'session_id'       => $id,
            'queue_id'         => $queueId,
            'roller_member_id' => $member->central_user_id,
            'roller_username'  => $member->username,
            'dice_type'        => $diceType,
            'result'           => $result,
            'is_public'        => $isPublic,
            'note'             => $note,
            'created_at'       => now(),
        ]);

        // Mark queue item as rolled
        if ($queueId) {
            DB::table('rpg_roll_queue')
                ->where('id', $queueId)
                ->where('assigned_to_member_id', $member->central_user_id)
                ->update(['status' => 'rolled', 'updated_at' => now()]);
        }

        return response()->json([
            'roll' => [
                'id'               => $rollId,
                'dice_type'        => $diceType,
                'result'           => $result,
                'is_public'        => $isPublic,
                'roller_member_id' => $member->central_user_id,
                'roller_username'  => $member->username,
                'note'             => $note,
                'queue_id'         => $queueId,
                'created_at'       => now(),
            ]
        ]);
    }

    // POST /api/plugins/rpg/sessions/{id}/queue
    public function requestRoll(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->gm_member_id !== $member->central_user_id) return $this->forbidden();

        $assignedTo = $request->input('assigned_to_member_id');
        $diceType   = $request->input('dice_type');
        $note       = $request->input('note');
        $isPublic   = $request->boolean('is_public', true);

        $max = $this->diceMax($diceType);
        if (!$max) {
            return response()->json(['error' => 'Invalid dice type'], 422);
        }

        $queueId = (string) Str::uuid();
        DB::table('rpg_roll_queue')->insert([
            'id'                     => $queueId,
            'session_id'             => $id,
            'requested_by_member_id' => $member->central_user_id,
            'assigned_to_member_id'  => $assignedTo,
            'dice_type'              => $diceType,
            'note'                   => $note,
            'is_public'              => $isPublic,
            'status'                 => 'pending',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        return response()->json(['queue_item' => ['id' => $queueId, 'dice_type' => $diceType, 'note' => $note, 'is_public' => $isPublic]], 201);
    }

    // DELETE /api/plugins/rpg/sessions/{id}/queue/{queueId}
    public function cancelQueueItem(Request $request, string $id, string $queueId)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->gm_member_id !== $member->central_user_id) return $this->forbidden();

        DB::table('rpg_roll_queue')
            ->where('id', $queueId)
            ->where('session_id', $id)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ─── Messages ────────────────────────────────────────────────────────────

    // POST /api/plugins/rpg/sessions/{id}/messages
    public function sendMessage(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();

        $content         = trim($request->input('content', ''));
        $isWhisper       = $request->boolean('is_whisper', false);
        $targetMemberId  = $request->input('target_member_id');
        $targetUsername  = $request->input('target_username');

        if ($content === '') {
            return response()->json(['error' => 'Content required'], 422);
        }

        // Only GM can send whispers to arbitrary players; players can reply to whispers
        $msgId = (string) Str::uuid();
        DB::table('rpg_messages')->insert([
            'id'               => $msgId,
            'session_id'       => $id,
            'author_member_id' => $member->central_user_id,
            'author_username'  => $member->username,
            'content'          => $content,
            'is_whisper'       => $isWhisper,
            'target_member_id' => $isWhisper ? $targetMemberId : null,
            'target_username'  => $isWhisper ? $targetUsername : null,
            'created_at'       => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    // ─── Characters ──────────────────────────────────────────────────────────

    // PUT /api/plugins/rpg/sessions/{id}/characters
    public function saveCharacter(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();

        $templateId = $request->input('template_id');
        $data       = $request->input('data', []);

        $existing = DB::table('rpg_characters')
            ->where('session_id', $id)
            ->where('member_id', $member->central_user_id)
            ->first();

        if ($existing) {
            DB::table('rpg_characters')
                ->where('id', $existing->id)
                ->update([
                    'template_id' => $templateId,
                    'data'        => json_encode($data),
                    'updated_at'  => now(),
                ]);
            $charId = $existing->id;
        } else {
            $charId = (string) Str::uuid();
            DB::table('rpg_characters')->insert([
                'id'          => $charId,
                'session_id'  => $id,
                'member_id'   => $member->central_user_id,
                'username'    => $member->username,
                'template_id' => $templateId,
                'data'        => json_encode($data),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return response()->json(['ok' => true, 'id' => $charId]);
    }

    // ─── Templates ───────────────────────────────────────────────────────────

    // GET /api/plugins/rpg/sessions/{id}/templates
    public function getTemplates(Request $request, string $id)
    {
        $templates = DB::table('rpg_character_templates')
            ->where('session_id', $id)
            ->get()
            ->map(fn($t) => [
                'id'     => $t->id,
                'name'   => $t->name,
                'fields' => json_decode($t->fields, true),
            ]);

        return response()->json(['templates' => $templates]);
    }

    // POST /api/plugins/rpg/sessions/{id}/templates
    public function createTemplate(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->gm_member_id !== $member->central_user_id) return $this->forbidden();

        $templateId = (string) Str::uuid();
        DB::table('rpg_character_templates')->insert([
            'id'         => $templateId,
            'session_id' => $id,
            'name'       => $request->input('name', 'Character Sheet'),
            'fields'     => json_encode($request->input('fields', [])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $templateId], 201);
    }

    // PUT /api/plugins/rpg/sessions/{id}/templates/{templateId}
    public function updateTemplate(Request $request, string $id, string $templateId)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->gm_member_id !== $member->central_user_id) return $this->forbidden();

        DB::table('rpg_character_templates')
            ->where('id', $templateId)
            ->where('session_id', $id)
            ->update([
                'name'       => $request->input('name'),
                'fields'     => json_encode($request->input('fields', [])),
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    // ─── Invites ─────────────────────────────────────────────────────────────

    // POST /api/plugins/rpg/sessions/{id}/invite
    public function createInvite(Request $request, string $id)
    {
        $member = $this->member($request);
        $session = $this->session($id);
        if (!$session) return $this->notFound();
        if ($session->gm_member_id !== $member->central_user_id) return $this->forbidden();

        $invitedMemberId = $request->input('member_id');
        $invitedUsername = $request->input('username', '');

        if (!$invitedMemberId) {
            return response()->json(['error' => 'member_id required'], 422);
        }

        // Upsert — re-invite clears a previous decline
        $existing = DB::table('rpg_invites')
            ->where('session_id', $id)
            ->where('member_id', $invitedMemberId)
            ->first();

        if ($existing) {
            DB::table('rpg_invites')
                ->where('id', $existing->id)
                ->update(['status' => 'pending', 'created_at' => now()]);
        } else {
            DB::table('rpg_invites')->insert([
                'id'         => (string) \Illuminate\Support\Str::uuid(),
                'session_id' => $id,
                'member_id'  => $invitedMemberId,
                'username'   => $invitedUsername,
                'status'     => 'pending',
                'created_at' => now(),
            ]);
        }

        return response()->json(['ok' => true], 201);
    }

    // GET /api/plugins/rpg/invites/pending
    public function pendingInvites(Request $request)
    {
        $member = $this->member($request);

        $invites = DB::table('rpg_invites')
            ->join('rpg_sessions', 'rpg_invites.session_id', '=', 'rpg_sessions.id')
            ->where('rpg_invites.member_id', $member->central_user_id)
            ->where('rpg_invites.status', 'pending')
            ->whereIn('rpg_sessions.status', ['waiting', 'active'])
            ->select(
                'rpg_invites.id',
                'rpg_invites.session_id',
                'rpg_sessions.channel_id',
                'rpg_sessions.gm_member_id',
                'rpg_sessions.gm_username',
                'rpg_sessions.status as session_status'
            )
            ->get();

        return response()->json(['invites' => $invites]);
    }

    // POST /api/plugins/rpg/invites/{inviteId}/accept
    public function acceptInvite(Request $request, string $inviteId)
    {
        $member = $this->member($request);

        $invite = DB::table('rpg_invites')
            ->where('id', $inviteId)
            ->where('member_id', $member->central_user_id)
            ->where('status', 'pending')
            ->first();

        if (!$invite) return $this->notFound();

        DB::table('rpg_invites')->where('id', $inviteId)->update(['status' => 'accepted']);

        // Auto-join the session
        $existing = DB::table('rpg_players')
            ->where('session_id', $invite->session_id)
            ->where('member_id', $member->central_user_id)
            ->first();

        if (!$existing) {
            DB::table('rpg_players')->insert([
                'id'         => (string) \Illuminate\Support\Str::uuid(),
                'session_id' => $invite->session_id,
                'member_id'  => $member->central_user_id,
                'username'   => $member->username,
                'joined_at'  => now(),
            ]);
        }

        $session = $this->session($invite->session_id);
        return response()->json(['session' => (array) $session]);
    }

    // POST /api/plugins/rpg/invites/{inviteId}/decline
    public function declineInvite(Request $request, string $inviteId)
    {
        $member = $this->member($request);

        DB::table('rpg_invites')
            ->where('id', $inviteId)
            ->where('member_id', $member->central_user_id)
            ->update(['status' => 'declined']);

        return response()->json(['ok' => true]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function diceMax(string $type): ?int
    {
        return match($type) {
            'd4'   => 4,
            'd6'   => 6,
            'd8'   => 8,
            'd10'  => 10,
            'd12'  => 12,
            'd20'  => 20,
            'd100' => 100,
            default => null,
        };
    }

    private function sessionWithPlayers(string $id): array
    {
        $session = $this->session($id);
        if (!$session) return [];

        $players = DB::table('rpg_players')
            ->where('session_id', $id)
            ->get()
            ->map(fn($p) => [
                'member_id' => $p->member_id,
                'username'  => $p->username,
                'joined_at' => $p->joined_at,
            ]);

        return array_merge((array) $session, ['players' => $players]);
    }
}
