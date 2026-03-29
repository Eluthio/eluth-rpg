<template>
  <div class="rpg-launcher">
    <!-- Topbar button — only visible to GMs -->
    <button
      v-if="isGm"
      class="rpg-launch-btn"
      :class="{ 'rpg-launch-btn--active': hasActiveSession }"
      :title="btnTitle"
      @click="launch"
    >
      🎲
      <span v-if="hasActiveSession" class="rpg-launch-btn__dot" />
    </button>

    <!-- Invite toast — visible to players who have been invited -->
    <Teleport to="body">
      <div v-if="pendingInvite" class="rpg-invite-toast">
        <div class="rpg-invite-toast__icon">🎲</div>
        <div class="rpg-invite-toast__body">
          <div class="rpg-invite-toast__title">RPG Campaign Invite</div>
          <div class="rpg-invite-toast__msg">{{ pendingInvite.gm_username }} has invited you to join their campaign</div>
        </div>
        <div class="rpg-invite-toast__actions">
          <button class="rpg-invite-btn rpg-invite-btn--accept" @click="acceptInvite">Join</button>
          <button class="rpg-invite-btn rpg-invite-btn--decline" @click="declineInvite">Decline</button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'

const props = defineProps({
  settings:      { type: Object, default: () => ({}) },
  apiBase:       { type: String, default: '' },
  authToken:     { type: String, default: '' },
  channelId:     { type: String, default: '' },
  currentMember: { type: Object, default: null },
})

const activeSession = ref(null)
const pendingInvite = ref(null)
let pollTimer = null

const isGm = computed(() =>
  props.currentMember?.isSuperAdmin ||
  props.currentMember?.isAdmin ||
  (props.currentMember?.can?.('rpg.gm') ?? false)
)

const hasActiveSession = computed(() => !!activeSession.value)

const btnTitle = computed(() => {
  if (!hasActiveSession.value) return 'Start RPG Campaign'
  return 'Open GM Console'
})

function base() { return props.apiBase.replace(/\/$/, '') }

async function api(method, path, body = null) {
  try {
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
  } catch {
    return null
  }
}

async function checkActiveSession() {
  if (!props.channelId) return
  const data = await api('GET', `/plugins/rpg/channel/${props.channelId}/session`)
  activeSession.value = data?.session ?? null
}

async function checkPendingInvites() {
  if (isGm.value) return  // GMs don't receive invites
  const data = await api('GET', '/plugins/rpg/invites/pending')
  pendingInvite.value = data?.invites?.[0] ?? null
}

async function launch() {
  let session = activeSession.value

  if (!session) {
    const data = await api('POST', '/plugins/rpg/sessions', {
      channel_id: props.channelId,
    })
    if (!data?.session) return
    session = data.session
    activeSession.value = session
  }

  openPopup('gm', session.id)
}

function openPopup(role, sessionId) {
  const origin = (window._eluthCommunityUrl ?? window.location.origin).replace(/\/$/, '')
  const param  = role === 'gm' ? 'rpg_gm' : 'rpg_player'
  const dims   = role === 'gm' ? 'width=1100,height=700,resizable=yes' : 'width=420,height=680,resizable=yes'
  window.open(`${origin}/?${param}=${sessionId}`, `rpg-${role}`, dims)
}

async function acceptInvite() {
  if (!pendingInvite.value) return
  const invite = pendingInvite.value
  pendingInvite.value = null

  const data = await api('POST', `/plugins/rpg/invites/${invite.id}/accept`)
  if (data?.session) {
    openPopup('player', invite.session_id)
  }
}

async function declineInvite() {
  if (!pendingInvite.value) return
  const inviteId = pendingInvite.value.id
  pendingInvite.value = null
  await api('POST', `/plugins/rpg/invites/${inviteId}/decline`)
}

// rpg:invite custom event — fired by right-click context menu
async function onInvite(e) {
  if (!activeSession.value) {
    // No active session yet — this shouldn't happen if the GM has to start first,
    // but guard gracefully
    return
  }
  const { memberId, author } = e.detail ?? {}
  if (!memberId) return
  await api('POST', `/plugins/rpg/sessions/${activeSession.value.id}/invite`, {
    member_id: memberId,
    username:  author ?? '',
  })
}

// MutationObserver: badge players who are in the active session
let observer = null

function updateBadges() {
  if (!activeSession.value) {
    document.querySelectorAll('.rpg-name-badge').forEach(el => el.remove())
    return
  }
  const playerIds = new Set((activeSession.value.players ?? []).map(p => p.member_id))
  document.querySelectorAll('[data-member-id]').forEach(el => {
    const memberId = el.getAttribute('data-member-id')
    if (!playerIds.has(memberId)) {
      el.querySelector('.rpg-name-badge')?.remove()
      return
    }
    if (!el.querySelector('.rpg-name-badge')) {
      const badge = document.createElement('span')
      badge.className = 'rpg-name-badge'
      badge.textContent = '🎲'
      badge.title = 'In RPG Campaign'
      badge.style.cssText = 'font-size:11px;margin-left:4px;opacity:0.7;cursor:default;'
      el.appendChild(badge)
    }
  })
}

function startObserver() {
  if (observer) observer.disconnect()
  const target = document.querySelector('[data-message-list]') ?? document.body
  observer = new MutationObserver(updateBadges)
  observer.observe(target, { childList: true, subtree: true })
  updateBadges()
}

async function poll() {
  await checkActiveSession()
  if (!isGm.value) await checkPendingInvites()
}

watch(() => props.channelId, async () => {
  activeSession.value = null
  await checkActiveSession()
})

watch(activeSession, () => updateBadges())

onMounted(async () => {
  await poll()
  pollTimer = setInterval(poll, 10000)
  startObserver()
  document.addEventListener('rpg:invite', onInvite)
})

onBeforeUnmount(() => {
  clearInterval(pollTimer)
  observer?.disconnect()
  document.querySelectorAll('.rpg-name-badge').forEach(el => el.remove())
  document.removeEventListener('rpg:invite', onInvite)
})
</script>

<style scoped>
.rpg-launcher { display: inline-flex; align-items: center; }

.rpg-launch-btn {
  position: relative;
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 18px;
  padding: 4px 6px;
  border-radius: 6px;
  line-height: 1;
  transition: background 0.15s, opacity 0.15s;
  opacity: 0.7;
}
.rpg-launch-btn:hover { background: rgba(124,106,247,0.15); opacity: 1; }
.rpg-launch-btn--active { opacity: 1; }

.rpg-launch-btn__dot {
  position: absolute;
  top: 2px;
  right: 2px;
  width: 7px;
  height: 7px;
  background: #4fe8a3;
  border-radius: 50%;
}

/* Invite toast */
.rpg-invite-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 9998;
  background: #1c1e2d;
  border: 1px solid #7c6af7;
  border-radius: 12px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.5);
  min-width: 280px;
  max-width: 360px;
  animation: rpg-toast-in 0.2s ease;
}

@keyframes rpg-toast-in {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}

.rpg-invite-toast__icon { font-size: 28px; flex-shrink: 0; }
.rpg-invite-toast__body { flex: 1; min-width: 0; }
.rpg-invite-toast__title { font-weight: 700; font-size: 13px; color: #c0b8ff; margin-bottom: 2px; }
.rpg-invite-toast__msg { font-size: 12px; color: #9991dd; line-height: 1.3; }

.rpg-invite-toast__actions { display: flex; flex-direction: column; gap: 6px; flex-shrink: 0; }

.rpg-invite-btn {
  padding: 5px 14px;
  border-radius: 6px;
  border: none;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: opacity 0.15s;
}
.rpg-invite-btn:hover { opacity: 0.85; }
.rpg-invite-btn--accept  { background: #7c6af7; color: #fff; }
.rpg-invite-btn--decline { background: transparent; color: #9991dd; border: 1px solid #3d3a55; }
</style>
