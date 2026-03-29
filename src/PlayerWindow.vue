<template>
  <div class="rpg-player-overlay">
    <div class="rpg-player">
      <!-- Header -->
      <div class="rpg-header">
        <span class="rpg-header__title">🎲 Campaign</span>
        <span class="rpg-header__status" :class="'rpg-status--' + session?.status">{{ session?.status ?? 'connecting…' }}</span>
      </div>

      <!-- Dice area -->
      <div class="rpg-player__dice-area">
        <!-- Active roll request -->
        <div v-if="pendingRequest" class="rpg-player__request">
          <div class="rpg-player__request-label">
            GM requests: <strong>{{ pendingRequest.dice_type }}</strong>
            <span v-if="pendingRequest.note" class="rpg-player__request-note"> — {{ pendingRequest.note }}</span>
          </div>
          <DiceRoll
            :dice-type="pendingRequest.dice_type"
            :result="pendingRollResult"
          />
          <div v-if="pendingRollResult === null" style="display:flex;flex-direction:column;align-items:center;gap:8px;">
            <span class="rpg-vis-label">{{ pendingRequest.is_public ? 'Public roll' : 'Private roll' }}</span>
            <button class="rpg-btn rpg-btn--primary rpg-btn--big" @click="rollRequested">Roll {{ pendingRequest.dice_type }}</button>
          </div>
          <div v-else class="rpg-player__roll-result">
            <span>You rolled</span> <strong>{{ pendingRollResult }}</strong>
          </div>
        </div>

        <!-- Free dice -->
        <div v-else class="rpg-player__free-dice">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
            <div class="rpg-player__free-label">Free roll</div>
            <div class="rpg-visibility-toggle">
              <button :class="['rpg-vis-btn', freeRollPublic ? 'rpg-vis-btn--on' : '']" @click="freeRollPublic = true">Public</button>
              <button :class="['rpg-vis-btn', !freeRollPublic ? 'rpg-vis-btn--on' : '']" @click="freeRollPublic = false">Private</button>
            </div>
          </div>
          <div class="rpg-player__dice-row">
            <DiceRoll
              v-for="dt in diceTypes"
              :key="dt"
              :dice-type="dt"
              :result="freeRollResults[dt] ?? null"
              :trigger-free-roll="true"
              @roll="doFreeRoll"
            />
          </div>
        </div>

        <!-- Last roll log -->
        <div v-if="latestRoll" class="rpg-player__last-roll">
          Last roll: <strong>{{ latestRoll.roller_username }}</strong> rolled
          <strong>{{ latestRoll.dice_type }}</strong> → <strong class="rpg-result">{{ latestRoll.result }}</strong>
          <span v-if="!latestRoll.is_public" class="rpg-private">(private)</span>
        </div>
      </div>

      <!-- Tabs -->
      <div class="rpg-tabs">
        <button v-for="tab in tabs" :key="tab" class="rpg-tab" :class="{ active: activeTab === tab }" @click="activeTab = tab">{{ tab }}</button>
      </div>

      <!-- Chat tab -->
      <div v-if="activeTab === 'Chat'" class="rpg-player__chat">
        <div class="rpg-chat__messages" ref="msgContainer">
          <div
            v-for="msg in messages"
            :key="msg.id"
            class="rpg-chat__msg"
            :class="{ whisper: msg.is_whisper, mine: msg.author_member_id === myMemberId }"
          >
            <span class="rpg-chat__author">{{ msg.author_username }}</span>
            <span v-if="msg.is_whisper" class="rpg-chat__whisper-tag">→ {{ msg.target_username ?? 'GM' }}</span>
            <span class="rpg-chat__content">{{ msg.content }}</span>
          </div>
          <div v-if="messages.length === 0" class="rpg-chat__empty">No messages yet.</div>
        </div>
        <div class="rpg-chat__input-row">
          <input
            v-model="chatDraft"
            class="rpg-chat__input"
            placeholder="Message…"
            @keydown.enter.prevent="sendMessage(false)"
          />
          <button class="rpg-btn rpg-btn--primary" @click="sendMessage(false)">Send</button>
          <button class="rpg-btn rpg-btn--ghost" title="Whisper to GM" @click="sendMessage(true)">Whisper</button>
        </div>
      </div>

      <!-- Sheet tab -->
      <div v-else-if="activeTab === 'Sheet'" class="rpg-player__sheet">
        <CharacterSheet
          :template="myTemplate"
          :model-value="myCharacter?.data"
          :editable="true"
          @save="saveCharacter"
        />
      </div>

      <!-- Rolls log tab -->
      <div v-else-if="activeTab === 'Rolls'" class="rpg-player__rolls">
        <div v-for="roll in rolls" :key="roll.id" class="rpg-roll-entry">
          <span class="rpg-roll-entry__who">{{ roll.roller_username }}</span>
          <span class="rpg-roll-entry__die">{{ roll.dice_type }}</span>
          <span class="rpg-roll-entry__result">{{ roll.result }}</span>
          <span v-if="roll.note" class="rpg-roll-entry__note">{{ roll.note }}</span>
          <span v-if="!roll.is_public" class="rpg-private">(private)</span>
        </div>
        <div v-if="rolls.length === 0" class="rpg-chat__empty">No rolls yet.</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import DiceRoll from './DiceRoll.vue'
import CharacterSheet from './CharacterSheet.vue'

const props = defineProps({
  sessionId: { type: String, required: true },
  authToken: { type: String, required: true },
  apiBase:   { type: String, required: true },
})

const diceTypes = ['d4', 'd6', 'd8', 'd10', 'd12', 'd20', 'd100']
const tabs      = ['Chat', 'Sheet', 'Rolls']

const session          = ref(null)
const players          = ref([])
const messages         = ref([])
const rolls            = ref([])
const queue            = ref([])
const characters       = ref([])
const templates        = ref([])
const myMemberId       = ref(null)
const chatDraft        = ref('')
const activeTab        = ref('Chat')
const msgContainer     = ref(null)
const freeRollResults  = ref({})
const pendingRollResult = ref(null)
const freeRollPublic   = ref(true)

let pollTimer = null
const bc = new BroadcastChannel('rpg-' + props.sessionId)

const pendingRequest = computed(() => queue.value.find(q => q.assigned_to_member_id === myMemberId.value) ?? null)
const latestRoll     = computed(() => rolls.value[0] ?? null)

const myCharacter = computed(() => characters.value.find(c => c.member_id === myMemberId.value) ?? null)
const myTemplate  = computed(() => {
  const tid = myCharacter.value?.template_id ?? templates.value[0]?.id
  return templates.value.find(t => t.id === tid) ?? null
})

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

async function postRollToChannel(roll) {
  const channelId = session.value?.channel_id
  if (!channelId) return
  const content = roll.is_public
    ? `🎲 **${roll.roller_username}** rolled ${roll.dice_type} → **${roll.result}**`
    : `🎲 *${roll.roller_username} rolled ${roll.dice_type} privately*`
  await fetch(base() + '/channels/' + channelId + '/messages', {
    method: 'POST',
    headers: {
      'Authorization': 'Bearer ' + props.authToken,
      'Content-Type':  'application/json',
      'Accept':        'application/json',
    },
    body: JSON.stringify({ content }),
  }).catch(() => {})
}

async function pollState() {
  try {
    const data = await api('GET', `/plugins/rpg/sessions/${props.sessionId}/state`)
    if (!data) return
    session.value    = data.session
    players.value    = data.players ?? []
    queue.value      = data.queue ?? []
    rolls.value      = data.rolls ?? []
    characters.value = data.characters ?? []

    // Only replace messages if we have new ones (preserve scroll)
    const newMessages = data.messages ?? []
    if (newMessages.length !== messages.value.length) {
      messages.value = [...newMessages].reverse()
      nextTick(scrollMessages)
    }

    // Clear pending roll result if queue item was fulfilled
    if (pendingRollResult.value !== null && !pendingRequest.value) {
      setTimeout(() => { pendingRollResult.value = null }, 3000)
    }

    bc.postMessage({ type: 'state', session: data.session, players: data.players })
  } catch {}
}

async function fetchTemplates() {
  const data = await api('GET', `/plugins/rpg/sessions/${props.sessionId}/templates`)
  if (data) templates.value = data.templates ?? []
}

async function joinSession() {
  await api('POST', `/plugins/rpg/sessions/${props.sessionId}/join`)
}

async function doFreeRoll(diceType) {
  freeRollResults.value = { ...freeRollResults.value, [diceType]: null }
  const data = await api('POST', `/plugins/rpg/sessions/${props.sessionId}/rolls`, {
    dice_type: diceType,
    is_public: freeRollPublic.value,
  })
  if (data?.roll) {
    freeRollResults.value = { ...freeRollResults.value, [diceType]: data.roll.result }
    await postRollToChannel(data.roll)
    await pollState()
  }
}

async function rollRequested() {
  const req = pendingRequest.value
  if (!req) return
  pendingRollResult.value = null
  const data = await api('POST', `/plugins/rpg/sessions/${props.sessionId}/rolls`, {
    dice_type: req.dice_type,
    queue_id:  req.id,
  })
  if (data?.roll) {
    pendingRollResult.value = data.roll.result
    await postRollToChannel(data.roll)
    await pollState()
  }
}

async function sendMessage(isWhisper) {
  const content = chatDraft.value.trim()
  if (!content) return
  chatDraft.value = ''
  await api('POST', `/plugins/rpg/sessions/${props.sessionId}/messages`, {
    content,
    is_whisper:       isWhisper,
    target_member_id: isWhisper ? session.value?.gm_member_id : null,
    target_username:  isWhisper ? session.value?.gm_username : null,
  })
  await pollState()
}

async function saveCharacter(data) {
  const tid = myCharacter.value?.template_id ?? templates.value[0]?.id
  await api('PUT', `/plugins/rpg/sessions/${props.sessionId}/characters`, {
    template_id: tid,
    data,
  })
  await pollState()
}

function scrollMessages() {
  if (msgContainer.value) {
    msgContainer.value.scrollTop = msgContainer.value.scrollHeight
  }
}

function getMemberIdFromToken() {
  try {
    return JSON.parse(atob(props.authToken.split('.')[1])).sub ?? null
  } catch { return null }
}

bc.onmessage = (e) => {
  // Keep handler for state broadcasts from GM window
  if (e.data?.type === 'state') {
    // state updates handled via pollState
  }
}

// Reset free roll result after 5s
watch(freeRollResults, (val) => {
  Object.entries(val).forEach(([dt, result]) => {
    if (result !== null) {
      setTimeout(() => {
        freeRollResults.value = { ...freeRollResults.value, [dt]: null }
      }, 5000)
    }
  })
}, { deep: true })

onMounted(async () => {
  myMemberId.value = getMemberIdFromToken()
  await joinSession()
  await fetchTemplates()
  await pollState()
  pollTimer = setInterval(pollState, 2500)
})

onBeforeUnmount(() => {
  clearInterval(pollTimer)
  bc.close()
})
</script>

<style scoped>
.rpg-player-overlay {
  position: fixed;
  inset: 0;
  background: #11121a;
  z-index: 9999;
  display: flex;
  align-items: stretch;
  justify-content: center;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  color: #e0deff;
}

.rpg-player {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 420px;
  height: 100%;
  background: #15162099;
}

.rpg-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: #1c1e2d;
  border-bottom: 1px solid #2d2f45;
}
.rpg-header__title { font-weight: 700; font-size: 15px; }
.rpg-header__status { font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.rpg-status--waiting  { background: #423000; color: #ffc65d; }
.rpg-status--active   { background: #003322; color: #4fe8a3; }
.rpg-status--ended    { background: #2d1a1a; color: #e88; }

.rpg-player__dice-area {
  padding: 16px;
  background: #1a1c2a;
  border-bottom: 1px solid #2d2f45;
  min-height: 140px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.rpg-player__request { display: flex; flex-direction: column; align-items: center; gap: 10px; }
.rpg-player__request-label { font-size: 13px; color: #ccc; text-align: center; }
.rpg-player__request-note { color: #aaa; font-weight: normal; }
.rpg-player__roll-result { font-size: 16px; color: #4fe8a3; }

.rpg-player__free-dice { display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%; }
.rpg-player__free-label { font-size: 11px; color: #9991dd; text-transform: uppercase; letter-spacing: 0.06em; }
.rpg-player__dice-row { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }

.rpg-player__last-roll {
  font-size: 12px;
  color: #888;
  padding-top: 4px;
}
.rpg-result { color: #7c6af7; font-size: 15px; }
.rpg-private { color: #888; font-size: 11px; }

.rpg-tabs {
  display: flex;
  background: #1c1e2d;
  border-bottom: 1px solid #2d2f45;
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

.rpg-player__chat,
.rpg-player__sheet,
.rpg-player__rolls {
  flex: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  padding: 12px;
  gap: 8px;
}

.rpg-chat__messages {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding-right: 4px;
}
.rpg-chat__msg { font-size: 13px; line-height: 1.4; }
.rpg-chat__msg.whisper { color: #c0a0ff; font-style: italic; }
.rpg-chat__msg.mine .rpg-chat__author { color: #7c6af7; }
.rpg-chat__author { font-weight: 600; margin-right: 4px; color: #a0c8ff; }
.rpg-chat__whisper-tag { font-size: 11px; color: #9988cc; margin-right: 4px; }
.rpg-chat__content { color: #d0cef5; }
.rpg-chat__empty { color: #666; font-size: 13px; text-align: center; padding: 20px 0; }

.rpg-chat__input-row {
  display: flex;
  gap: 6px;
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

.rpg-player__rolls { overflow-y: auto; }
.rpg-roll-entry {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  padding: 4px 0;
  border-bottom: 1px solid #2d2f4520;
}
.rpg-roll-entry__who { font-weight: 600; color: #a0c8ff; min-width: 80px; }
.rpg-roll-entry__die { color: #9991dd; }
.rpg-roll-entry__result { font-weight: 700; color: #7c6af7; font-size: 16px; min-width: 30px; }
.rpg-roll-entry__note { color: #888; font-size: 12px; }

.rpg-vis-label {
  font-size: 11px;
  color: #9991dd;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.rpg-visibility-toggle {
  display: flex;
  border: 1px solid #3d3a55;
  border-radius: 5px;
  overflow: hidden;
}
.rpg-vis-btn {
  padding: 2px 10px;
  border: none;
  background: transparent;
  color: #9991dd;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
}
.rpg-vis-btn--on { background: #7c6af7; color: #fff; }

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
.rpg-btn--primary  { background: #7c6af7; color: #fff; }
.rpg-btn--ghost    { background: transparent; color: #9991dd; border: 1px solid #3d3a55; }
.rpg-btn--big      { padding: 10px 24px; font-size: 15px; }
</style>
