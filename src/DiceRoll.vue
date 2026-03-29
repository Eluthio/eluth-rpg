<template>
  <div class="rpg-die" :class="['rpg-die--' + diceType, { 'rpg-die--rolling': isRolling, 'rpg-die--done': isDone }]" @click="triggerFreeRoll && !isRolling && !isDone && emit('roll', diceType)">
    <svg viewBox="0 0 100 100" class="rpg-die__svg" aria-hidden="true">
      <!-- d4: triangle -->
      <polygon v-if="diceType === 'd4'"   points="50,8 95,88 5,88" class="rpg-die__face" />
      <!-- d6: square -->
      <rect    v-else-if="diceType === 'd6'"  x="10" y="10" width="80" height="80" rx="8" class="rpg-die__face" />
      <!-- d8: diamond -->
      <polygon v-else-if="diceType === 'd8'"  points="50,5 95,50 50,95 5,50" class="rpg-die__face" />
      <!-- d10: elongated diamond -->
      <polygon v-else-if="diceType === 'd10'" points="50,5 90,40 50,95 10,40" class="rpg-die__face" />
      <!-- d12: pentagon -->
      <polygon v-else-if="diceType === 'd12'" points="50,5 96,36 78,90 22,90 4,36" class="rpg-die__face" />
      <!-- d20: triangle with inner detail -->
      <polygon v-else-if="diceType === 'd20'" points="50,5 97,78 3,78" class="rpg-die__face" />
      <polygon v-if="diceType === 'd20'" points="50,22 79,68 21,68" class="rpg-die__inner" />
      <!-- d100: circle -->
      <circle  v-else-if="diceType === 'd100'" cx="50" cy="50" r="44" class="rpg-die__face" />
      <!-- Number display -->
      <text x="50" :y="textY" text-anchor="middle" dominant-baseline="middle" class="rpg-die__number">
        {{ displayValue }}
      </text>
    </svg>
    <div class="rpg-die__label">{{ diceType }}</div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
  diceType:      { type: String, required: true },
  result:        { type: Number, default: null },
  triggerFreeRoll: { type: Boolean, default: false },
})
const emit = defineEmits(['roll'])

const displayValue = ref('?')
const isRolling    = ref(false)
const isDone       = ref(false)

let timer = null

const diceMax = { d4: 4, d6: 6, d8: 8, d10: 10, d12: 12, d20: 20, d100: 100 }

const textY = computed(() => {
  if (props.diceType === 'd4') return '68'
  if (props.diceType === 'd100') return '52'
  return '55'
})

function clearTimer() {
  if (timer) { clearTimeout(timer); timer = null }
}

function runAnimation(finalResult) {
  isRolling.value = true
  isDone.value    = false
  displayValue.value = '?'
  const max = diceMax[props.diceType] ?? 20

  // Cycle through random numbers, decelerating to a stop
  const steps   = [40, 40, 60, 70, 100, 140, 190, 260, 350, 470, 600, 750]
  let stepIndex = 0

  function tick() {
    if (stepIndex < steps.length) {
      displayValue.value = String(Math.floor(Math.random() * max) + 1)
      timer = setTimeout(tick, steps[stepIndex++])
    } else {
      displayValue.value = String(finalResult)
      isRolling.value    = false
      isDone.value       = true
    }
  }
  tick()
}

watch(() => props.result, (val) => {
  clearTimer()
  if (val !== null && val !== undefined) {
    runAnimation(val)
  } else {
    displayValue.value = '?'
    isRolling.value    = false
    isDone.value       = false
  }
}, { immediate: true })

onBeforeUnmount(clearTimer)
</script>

<style scoped>
.rpg-die {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  cursor: default;
  user-select: none;
  transition: transform 0.1s;
}
.rpg-die[style*="cursor"],
.rpg-die--rolling { cursor: default; }

.rpg-die__svg {
  width: 56px;
  height: 56px;
  filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4));
}

.rpg-die__face {
  fill: #2a2d3e;
  stroke: #7c6af7;
  stroke-width: 3;
  transition: fill 0.2s;
}
.rpg-die--done .rpg-die__face {
  fill: #3d3566;
}

.rpg-die__inner {
  fill: none;
  stroke: #7c6af750;
  stroke-width: 1.5;
}

.rpg-die__number {
  fill: #e8e6ff;
  font-size: 28px;
  font-weight: 700;
  font-family: monospace;
}

.rpg-die--rolling .rpg-die__number {
  fill: #aaa;
  animation: rpg-flicker 0.08s steps(1) infinite;
}
@keyframes rpg-flicker {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.5; }
}

.rpg-die--rolling .rpg-die__svg {
  animation: rpg-wobble 0.15s ease-in-out infinite alternate;
}
@keyframes rpg-wobble {
  from { transform: rotate(-8deg) scale(1.05); }
  to   { transform: rotate(8deg)  scale(0.97); }
}

.rpg-die__label {
  font-size: 10px;
  font-weight: 600;
  color: #9991dd;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
</style>
