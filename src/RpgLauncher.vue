<template>
  <div class="rpg-launcher">
    <button
      class="rpg-launch-btn"
      :class="{ 'rpg-launch-btn--active': hasActiveSession }"
      :title="btnTitle"
      @click="launch"
    >
      🎲
      <span v-if="hasActiveSession" class="rpg-launch-btn__dot" />
    </button>
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
let pollTimer = null

const isGm = computed(() =>
  props.currentMember?.isSuperAdmin ||
  props.currentMember?.isAdmin ||
  (props.currentMember?.can?.('rpg.gm') ?? false)
)

const hasActiveSession = computed(() => !!activeSession.value)

const btnTitle = computed(() => {
  if (!hasActiveSession.value) {
    return isGm.value ? 'Start RPG Campaign' : 'Join RPG Campaign'
  }
  return isGm.value ? 'Open GM Console' : 'Open Campaign'
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

async function launch() {
  let session = activeSession.value

  if (!session) {
    if (isGm.value) {
      // Create a new session
      const data = await api('POST', '/plugins/rpg/sessions', {
        channel_id: props.channelId,
      })
      if (!data?.session) return
      session = data.session
      activeSession.value = session
    } else {
      // No session to join
      return
    }
  }

  const origin = (window._eluthCommunityUrl ?? window.location.origin).replace(/\/$/, '')
  if (isGm.value) {
    window.open(`${origin}/?rpg_gm=${session.id}`, 'rpg-gm', 'width=1100,height=700,resizable=yes')
  } else {
    window.open(`${origin}/?rpg_player=${session.id}`, 'rpg-player', 'width=420,height=680,resizable=yes')
  }
}

// MutationObserver: add a badge next to player names in the main chat
let observer = null

function updateBadges() {
  if (!activeSession.value) {
    // Remove all existing badges
    document.querySelectorAll('.rpg-name-badge').forEach(el => el.remove())
    return
  }
  const playerIds = new Set((activeSession.value.players ?? []).map(p => p.member_id))

  // Find member username elements rendered by the host (data-member-id attribute)
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

function onInvite(e) {
  if (!activeSession.value) {
    alert('Start a campaign first, then invite players.')
    return
  }
  const username = e.detail?.author ?? 'that player'
  alert(`Ask ${username} to click the 🎲 button to join the campaign.`)
}

watch(() => props.channelId, async () => {
  activeSession.value = null
  await checkActiveSession()
})

watch(activeSession, () => updateBadges())

onMounted(async () => {
  await checkActiveSession()
  pollTimer = setInterval(checkActiveSession, 10000)
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
</style>
