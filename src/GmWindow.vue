<template>
  <div class="rpg-gm-overlay">
    <div class="rpg-gm">
      <!-- Header -->
      <div class="rpg-gm__header">
        <span class="rpg-gm__title">🎲 GM Console</span>
        <div class="rpg-gm__header-actions">
          <span class="rpg-header__status" :class="'rpg-status--' + session?.status">{{ session?.status ?? 'connecting…' }}</span>
          <button v-if="session?.status === 'waiting'" class="rpg-btn rpg-btn--success" @click="startSession">Start Session</button>
          <template v-if="session?.status !== 'ended'">
            <template v-if="endConfirm">
              <span style="font-size:12px;color:#e88;">End session?</span>
              <button class="rpg-btn rpg-btn--danger rpg-btn--sm" @click="confirmEnd">Yes, end</button>
              <button class="rpg-btn rpg-btn--ghost rpg-btn--sm" @click="endConfirm = false">Cancel</button>
            </template>
            <button v-else class="rpg-btn rpg-btn--danger" @click="endConfirm = true">End</button>
          </template>
        </div>
      </div>

      <div class="rpg-gm__body">
        <!-- Left panel: players -->
        <div class="rpg-gm__players-panel">
          <div class="rpg-gm__section-title">Players ({{ players.length }})</div>

          <div v-if="players.length === 0" class="rpg-gm__empty">
            Waiting for players to join…
          </div>

          <div v-for="player in players" :key="player.member_id" class="rpg-player-card">
            <div class="rpg-player-card__header">
              <span class="rpg-player-card__name">{{ player.username }}</span>
              <button
                class="rpg-btn rpg-btn--ghost rpg-btn--sm"
                @click="toggleSheet(player.member_id)"
              >{{ expandedSheet === player.member_id ? 'Hide Sheet' : 'Sheet' }}</button>
            </div>

            <!-- Dice area for this player -->
            <div class="rpg-player-card__dice">
              <div class="rpg-player-card__dice-grid">
                <DiceRoll
                  v-for="dt in diceTypes"
                  :key="dt"
                  :dice-type="dt"
                  :result="getPlayerRollResult(player.member_id, dt)"
                />
              </div>
            </div>

            <!-- Pending queue items for this player -->
            <div v-if="playerQueue(player.member_id).length > 0" class="rpg-player-card__queue">
              <div
                v-for="item in playerQueue(player.member_id)"
                :key="item.id"
                class="rpg-queue-item"
              >
                Waiting for <strong>{{ item.dice_type }}</strong>
                <span v-if="item.note"> — {{ item.note }}</span>
                <button class="rpg-btn rpg-btn--ghost rpg-btn--xs" @click="cancelQueueItem(item.id)">✕</button>
              </div>
            </div>

            <!-- Request roll -->
            <div class="rpg-player-card__request">
              <select v-model="requestDice[player.member_id]" class="rpg-select">
                <option v-for="dt in diceTypes" :key="dt" :value="dt">{{ dt }}</option>
              </select>
              <input
                v-model="requestNote[player.member_id]"
                class="rpg-input rpg-input--sm"
                placeholder="Note (optional)"
              />
              <button class="rpg-btn rpg-btn--primary rpg-btn--sm" @click="requestRoll(player.member_id)">
                Request Roll
              </button>
            </div>

            <!-- Expanded character sheet -->
            <div v-if="expandedSheet === player.member_id" class="rpg-player-card__sheet">
              <CharacterSheet
                :template="templateForPlayer(player.member_id)"
                :model-value="characterFor(player.member_id)?.data"
                :editable="false"
              />
            </div>
          </div>

          <!-- Invite players -->
          <div class="rpg-gm__section-title" style="margin-top: 16px;">
            Invite Players
            <button class="rpg-btn rpg-btn--ghost rpg-btn--xs" style="margin-left:6px;" @click="toggleInvitePanel">
              {{ showInvitePanel ? 'Hide' : 'Show' }}
            </button>
          </div>
          <template v-if="showInvitePanel">
            <div v-if="serverMembers.length === 0" class="rpg-gm__empty">Loading members…</div>
            <div
              v-for="m in invitableMembers"
              :key="m.id"
              class="rpg-invite-row"
            >
              <span class="rpg-invite-row__name">{{ m.username }}</span>
              <span v-if="invitedIds.has(m.id)" class="rpg-invite-row__sent">Invited</span>
              <button v-else class="rpg-btn rpg-btn--primary rpg-btn--xs" @click="inviteMember(m)">Invite</button>
            </div>
            <div v-if="invitableMembers.length === 0 && serverMembers.length > 0" class="rpg-gm__empty">
              All members are already in the session.
            </div>
          </template>

          <!-- Template manager -->
          <div class="rpg-gm__section-title" style="margin-top: 16px;">Character Templates</div>
          <div v-for="tmpl in templates" :key="tmpl.id" class="rpg-template-row">
            <span>{{ tmpl.name }}</span>
            <button class="rpg-btn rpg-btn--ghost rpg-btn--sm" @click="editTemplate(tmpl)">Edit</button>
          </div>
          <button class="rpg-btn rpg-btn--ghost rpg-btn--sm" style="margin-top: 6px;" @click="showNewTemplate = true">+ New Template</button>

          <!-- Template editor inline -->
          <div v-if="showNewTemplate || editingTemplate" class="rpg-template-editor">
            <input v-model="tmplName" class="rpg-input" placeholder="Template name" />
            <div v-for="(field, i) in tmplFields" :key="i" class="rpg-template-field">
              <input v-model="field.label" class="rpg-input rpg-input--sm" placeholder="Label" />
              <select v-model="field.type" class="rpg-select rpg-select--sm">
                <option value="text">Text</option>
                <option value="number">Number</option>
                <option value="textarea">Long text</option>
              </select>
              <button class="rpg-btn rpg-btn--ghost rpg-btn--xs" @click="tmplFields.splice(i,1)">✕</button>
            </div>
            <button class="rpg-btn rpg-btn--ghost rpg-btn--sm" @click="tmplFields.push({ key: '', label: '', type: 'text' })">+ Field</button>
            <div class="rpg-template-editor__actions">
              <button class="rpg-btn rpg-btn--primary rpg-btn--sm" @click="saveTemplate">Save</button>
              <button class="rpg-btn rpg-btn--ghost rpg-btn--sm" @click="cancelTemplate">Cancel</button>
            </div>
          </div>
        </div>

        <!-- Right panel: chat + rolls -->
        <div class="rpg-gm__chat-panel">
          <div class="rpg-tabs">
            <button v-for="tab in chatTabs" :key="tab" class="rpg-tab" :class="{ active: activeTab === tab }" @click="activeTab = tab">{{ tab }}</button>
          </div>

          <!-- Chat -->
          <template v-if="activeTab === 'Chat'">
            <div class="rpg-chat__messages" ref="msgContainer">
              <div
                v-for="msg in messages"
                :key="msg.id"
                class="rpg-chat__msg"
                :class="{ whisper: msg.is_whisper }"
              >
                <span class="rpg-chat__author">{{ msg.author_username }}</span>
                <span v-if="msg.is_whisper" class="rpg-chat__whisper-tag">→ {{ msg.target_username ?? 'GM' }}</span>
                <span class="rpg-chat__content">{{ msg.content }}</span>
              </div>
              <div v-if="messages.length === 0" class="rpg-chat__empty">No messages yet.</div>
            </div>
            <div class="rpg-chat__input-row">
              <select v-model="whisperTarget" class="rpg-select rpg-select--sm" style="max-width:120px;">
                <option value="">All</option>
                <option v-for="p in players" :key="p.member_id" :value="p.member_id">→ {{ p.username }}</option>
              </select>
              <input
                v-model="chatDraft"
                class="rpg-chat__input"
                :placeholder="whisperTarget ? 'Whisper…' : 'Message…'"
                @keydown.enter.prevent="sendMessage"
              />
              <button class="rpg-btn rpg-btn--primary" @click="sendMessage">Send</button>
            </div>
          </template>

          <!-- Rolls log -->
          <template v-else-if="activeTab === 'Rolls'">
            <div class="rpg-rolls-log">
              <div v-for="roll in rolls" :key="roll.id" class="rpg-roll-entry">
                <span class="rpg-roll-entry__who">{{ roll.roller_username }}</span>
                <span class="rpg-roll-entry__die">{{ roll.dice_type }}</span>
                <span class="rpg-roll-entry__result">{{ roll.result }}</span>
                <span v-if="roll.note" class="rpg-roll-entry__note">{{ roll.note }}</span>
                <span v-if="!roll.is_public" class="rpg-private">(private)</span>
              </div>
              <div v-if="rolls.length === 0" class="rpg-chat__empty">No rolls yet.</div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import DiceRoll from './DiceRoll.vue'
import CharacterSheet from './CharacterSheet.vue'

const props = defineProps({
  sessionId: { type: String, required: true },
  authToken: { type: String, required: true },
  apiBase:   { type: String, required: true },
})

const diceTypes = ['d4', 'd6', 'd8', 'd10', 'd12', 'd20', 'd100']
const chatTabs  = ['Chat', 'Rolls']

const session       = ref(null)
const players       = ref([])
const messages      = ref([])
const rolls         = ref([])
const queue         = ref([])
const characters    = ref([])
const templates     = ref([])
const myMemberId    = ref(null)

const chatDraft     = ref('')
const whisperTarget = ref('')
const activeTab     = ref('Chat')
const expandedSheet = ref(null)
const msgContainer  = ref(null)

// Per-player request controls
const requestDice = ref({})
const requestNote = ref({})

// End session confirmation
const endConfirm = ref(false)

// Invite panel
const showInvitePanel = ref(false)
const serverMembers   = ref([])
const invitedIds      = ref(new Set())

const invitableMembers = computed(() => {
  const playerIds = new Set(players.value.map(p => p.member_id))
  return serverMembers.value.filter(m => m.id !== myMemberId.value && !playerIds.has(m.id))
})

async function fetchServerMembers() {
  try {
    const res = await fetch(base() + '/members', {
      headers: { 'Authorization': 'Bearer ' + props.authToken, 'Accept': 'application/json' },
    })
    if (res.ok) {
      const data = await res.json()
      serverMembers.value = data.members ?? []
    }
  } catch {}
}

async function toggleInvitePanel() {
  showInvitePanel.value = !showInvitePanel.value
  if (showInvitePanel.value && serverMembers.value.length === 0) {
    await fetchServerMembers()
  }
}

async function inviteMember(member) {
  invitedIds.value = new Set([...invitedIds.value, member.id])
  await api('POST', `/plugins/rpg/sessions/${props.sessionId}/invite`, {
    member_id: member.id,
    username:  member.username,
  })
}

// Template editor
const showNewTemplate  = ref(false)
const editingTemplate  = ref(null)
const tmplName         = ref('')
const tmplFields       = ref([])

// Track last roll result per player per die for the animation
const playerRolls = ref({}) // { memberId: { diceType: result } }

let pollTimer = null
const bc = new BroadcastChannel('rpg-' + props.sessionId)

function base() { return props.apiBase.replace(/\/$/, '') }

async function api(method, path, body = null) {
  const res = await fetch(base() + path, {
    method,
    headers: {
      'Authorization': 'Bearer ' + props.authToken,
      'Content-Type':  'application/json',
      'Accept':        'application/json',
    },
    body: body ? JSON.stringify(body) : undefined,
  })
  if (!res.ok) return null
  return res.json()
}

async function pollState() {
  try {
    const data = await api('GET', `/plugins/rpg/sessions/${props.sessionId}/state`)
    if (!data) return

    // Detect new rolls and trigger animations on player cards
    const prevRollIds = new Set(rolls.value.map(r => r.id))
    const newRolls    = (data.rolls ?? []).filter(r => !prevRollIds.has(r.id))
    newRolls.forEach(r => {
      const perPlayer = { ...(playerRolls.value[r.roller_member_id] ?? {}) }
      perPlayer[r.dice_type] = r.result
      playerRolls.value = { ...playerRolls.value, [r.roller_member_id]: perPlayer }
      // Clear the result after 5s so the die resets
      setTimeout(() => {
        const pp = { ...(playerRolls.value[r.roller_member_id] ?? {}) }
        delete pp[r.dice_type]
        playerRolls.value = { ...playerRolls.value, [r.roller_member_id]: pp }
      }, 5000)
    })

    session.value    = data.session
    players.value    = data.players ?? []
    queue.value      = data.queue ?? []
    rolls.value      = data.rolls ?? []
    characters.value = data.characters ?? []

    const newMessages = data.messages ?? []
    if (newMessages.length !== messages.value.length) {
      messages.value = [...newMessages].reverse()
      nextTick(scrollMessages)
    }

    bc.postMessage({ type: 'state', session: data.session, players: data.players })
  } catch {}
}

async function fetchTemplates() {
  const data = await api('GET', `/plugins/rpg/sessions/${props.sessionId}/templates`)
  if (data) templates.value = data.templates ?? []
}

async function startSession() {
  await api('POST', `/plugins/rpg/sessions/${props.sessionId}/start`)
  await pollState()
}

async function confirmEnd() {
  endConfirm.value = false
  await api('DELETE', `/plugins/rpg/sessions/${props.sessionId}`)
  await pollState()
}

async function requestRoll(memberId) {
  const diceType = requestDice.value[memberId] ?? 'd20'
  const note     = requestNote.value[memberId] ?? ''
  await api('POST', `/plugins/rpg/sessions/${props.sessionId}/queue`, {
    assigned_to_member_id: memberId,
    dice_type: diceType,
    note: note || undefined,
  })
  requestNote.value = { ...requestNote.value, [memberId]: '' }
  await pollState()
}

async function cancelQueueItem(queueId) {
  await api('DELETE', `/plugins/rpg/sessions/${props.sessionId}/queue/${queueId}`)
  await pollState()
}

async function sendMessage() {
  const content = chatDraft.value.trim()
  if (!content) return
  chatDraft.value = ''
  const target = whisperTarget.value
  const targetPlayer = target ? players.value.find(p => p.member_id === target) : null
  await api('POST', `/plugins/rpg/sessions/${props.sessionId}/messages`, {
    content,
    is_whisper:       !!target,
    target_member_id: target || null,
    target_username:  targetPlayer?.username ?? null,
  })
  await pollState()
}

function playerQueue(memberId) {
  return queue.value.filter(q => q.assigned_to_member_id === memberId)
}

function getPlayerRollResult(memberId, diceType) {
  return playerRolls.value[memberId]?.[diceType] ?? null
}

function characterFor(memberId) {
  return characters.value.find(c => c.member_id === memberId) ?? null
}

function templateForPlayer(memberId) {
  const char = characterFor(memberId)
  const tid  = char?.template_id ?? templates.value[0]?.id
  return templates.value.find(t => t.id === tid) ?? null
}

function toggleSheet(memberId) {
  expandedSheet.value = expandedSheet.value === memberId ? null : memberId
}

function editTemplate(tmpl) {
  editingTemplate.value = tmpl
  tmplName.value   = tmpl.name
  tmplFields.value = tmpl.fields.map(f => ({ ...f }))
}

function cancelTemplate() {
  showNewTemplate.value  = false
  editingTemplate.value  = null
  tmplName.value         = ''
  tmplFields.value       = []
}

async function saveTemplate() {
  // Ensure field keys are derived from label
  const fields = tmplFields.value.map(f => ({
    key:   f.key || f.label.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, ''),
    label: f.label,
    type:  f.type,
  }))

  if (editingTemplate.value) {
    await api('PUT', `/plugins/rpg/sessions/${props.sessionId}/templates/${editingTemplate.value.id}`, {
      name: tmplName.value,
      fields,
    })
  } else {
    await api('POST', `/plugins/rpg/sessions/${props.sessionId}/templates`, {
      name: tmplName.value,
      fields,
    })
  }

  cancelTemplate()
  await fetchTemplates()
}

function scrollMessages() {
  if (msgContainer.value) {
    msgContainer.value.scrollTop = msgContainer.value.scrollHeight
  }
}

// Respond to identity requests from player windows
bc.onmessage = (e) => {
  if (e.data?.type === 'who-am-i' && myMemberId.value) {
    bc.postMessage({ type: 'identity', memberId: myMemberId.value })
  }
}

onMounted(async () => {
  // Init request dice defaults
  diceTypes.forEach(dt => {
    requestDice.value[dt] = 'd20'
  })

  await pollState()
  await fetchTemplates()

  // Try to read member from API
  const meRes = await fetch(base() + '/me', {
    headers: { 'Authorization': 'Bearer ' + props.authToken, 'Accept': 'application/json' },
  }).catch(() => null)
  if (meRes?.ok) {
    const me = await meRes.json().catch(() => null)
    if (me) myMemberId.value = me.member?.central_user_id ?? me.id
  }

  pollTimer = setInterval(pollState, 2500)
})

onBeforeUnmount(() => {
  clearInterval(pollTimer)
  bc.close()
})
</script>

<style scoped>
.rpg-gm-overlay {
  position: fixed;
  inset: 0;
  background: #11121a;
  z-index: 9999;
  display: flex;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  color: #e0deff;
}

.rpg-gm {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;
}

.rpg-gm__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  background: #1c1e2d;
  border-bottom: 1px solid #2d2f45;
  gap: 12px;
}
.rpg-gm__title { font-weight: 700; font-size: 16px; }
.rpg-gm__header-actions { display: flex; align-items: center; gap: 8px; }

.rpg-header__status { font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.rpg-status--waiting  { background: #423000; color: #ffc65d; }
.rpg-status--active   { background: #003322; color: #4fe8a3; }
.rpg-status--ended    { background: #2d1a1a; color: #e88; }

.rpg-gm__body {
  display: flex;
  flex: 1;
  overflow: hidden;
}

/* Left: players */
.rpg-gm__players-panel {
  width: 360px;
  min-width: 280px;
  flex-shrink: 0;
  overflow-y: auto;
  padding: 12px;
  border-right: 1px solid #2d2f45;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.rpg-gm__section-title {
  font-size: 11px;
  font-weight: 600;
  color: #9991dd;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 4px 0;
}
.rpg-gm__empty { font-size: 13px; color: #666; text-align: center; padding: 20px 0; }

.rpg-player-card {
  background: #1c1e2d;
  border: 1px solid #2d2f45;
  border-radius: 8px;
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.rpg-player-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.rpg-player-card__name { font-weight: 600; font-size: 14px; }

.rpg-player-card__dice-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.rpg-queue-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #ffc65d;
  background: #2a2200;
  border-radius: 4px;
  padding: 3px 8px;
}

.rpg-player-card__request {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.rpg-player-card__sheet {
  border-top: 1px solid #2d2f45;
  padding-top: 8px;
}

/* Templates */
.rpg-invite-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  padding: 4px 0;
  border-bottom: 1px solid #2d2f4530;
}
.rpg-invite-row__name { color: #e0deff; }
.rpg-invite-row__sent { font-size: 11px; color: #4fe8a3; }

.rpg-template-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  padding: 4px 0;
  border-bottom: 1px solid #2d2f4530;
}

.rpg-template-editor {
  background: #1c1e2d;
  border: 1px solid #3d3a55;
  border-radius: 8px;
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 4px;
}
.rpg-template-field { display: flex; gap: 6px; align-items: center; }
.rpg-template-editor__actions { display: flex; gap: 6px; }

/* Right: chat/rolls */
.rpg-gm__chat-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0;
}

.rpg-tabs {
  display: flex;
  background: #1c1e2d;
  border-bottom: 1px solid #2d2f45;
  flex-shrink: 0;
}
.rpg-tab {
  flex: 1;
  padding: 8px 0;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: #888;
  font-size: 13px;
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
}
.rpg-tab.active { color: #c0b8ff; border-bottom-color: #7c6af7; }

.rpg-chat__messages {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.rpg-chat__msg { font-size: 13px; line-height: 1.4; }
.rpg-chat__msg.whisper { color: #c0a0ff; font-style: italic; }
.rpg-chat__author { font-weight: 600; margin-right: 4px; color: #a0c8ff; }
.rpg-chat__whisper-tag { font-size: 11px; color: #9988cc; margin-right: 4px; }
.rpg-chat__content { color: #d0cef5; }
.rpg-chat__empty { color: #666; font-size: 13px; text-align: center; padding: 20px 0; }

.rpg-chat__input-row {
  display: flex;
  gap: 6px;
  padding: 8px 12px;
  border-top: 1px solid #2d2f45;
  flex-shrink: 0;
}
.rpg-chat__input {
  flex: 1;
  background: #1c1e2d;
  border: 1px solid #3d3a55;
  border-radius: 6px;
  color: #e0deff;
  font-size: 13px;
  padding: 6px 10px;
  outline: none;
}
.rpg-chat__input:focus { border-color: #7c6af7; }

.rpg-rolls-log {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.rpg-roll-entry {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  padding: 4px 0;
  border-bottom: 1px solid #2d2f4520;
}
.rpg-roll-entry__who { font-weight: 600; color: #a0c8ff; min-width: 90px; }
.rpg-roll-entry__die { color: #9991dd; min-width: 34px; }
.rpg-roll-entry__result { font-weight: 700; color: #7c6af7; font-size: 16px; min-width: 32px; }
.rpg-roll-entry__note { color: #888; font-size: 12px; }
.rpg-private { color: #888; font-size: 11px; }

/* Form controls */
.rpg-input {
  background: #11121a;
  border: 1px solid #3d3a55;
  border-radius: 5px;
  color: #e0deff;
  font-size: 13px;
  padding: 5px 8px;
  outline: none;
  width: 100%;
  box-sizing: border-box;
}
.rpg-input:focus { border-color: #7c6af7; }
.rpg-input--sm { padding: 4px 6px; font-size: 12px; }

.rpg-select {
  background: #11121a;
  border: 1px solid #3d3a55;
  border-radius: 5px;
  color: #e0deff;
  font-size: 12px;
  padding: 4px 6px;
  outline: none;
  cursor: pointer;
}
.rpg-select:focus { border-color: #7c6af7; }
.rpg-select--sm { padding: 3px 5px; }

/* Buttons */
.rpg-btn {
  padding: 6px 14px;
  border-radius: 6px;
  border: none;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: opacity 0.15s;
  white-space: nowrap;
}
.rpg-btn:hover { opacity: 0.85; }
.rpg-btn--primary { background: #7c6af7; color: #fff; }
.rpg-btn--success { background: #2a7a4a; color: #fff; }
.rpg-btn--danger  { background: #7a2a2a; color: #fff; }
.rpg-btn--ghost   { background: transparent; color: #9991dd; border: 1px solid #3d3a55; }
.rpg-btn--sm  { padding: 4px 10px; font-size: 12px; }
.rpg-btn--xs  { padding: 2px 6px; font-size: 11px; }
</style>
