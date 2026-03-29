<template>
  <div class="rpg-sheet">
    <div v-if="!template" class="rpg-sheet__empty">
      No character template has been set by the GM yet.
    </div>
    <template v-else>
      <div class="rpg-sheet__name">{{ template.name }}</div>
      <div class="rpg-sheet__fields">
        <div v-for="field in template.fields" :key="field.key" class="rpg-sheet__field">
          <label class="rpg-sheet__label">{{ field.label }}</label>
          <template v-if="editable">
            <textarea
              v-if="field.type === 'textarea'"
              v-model="localData[field.key]"
              class="rpg-sheet__textarea"
              rows="3"
            />
            <input
              v-else
              v-model="localData[field.key]"
              :type="field.type === 'number' ? 'number' : 'text'"
              class="rpg-sheet__input"
            />
          </template>
          <template v-else>
            <div class="rpg-sheet__value">{{ modelValue?.[field.key] ?? '—' }}</div>
          </template>
        </div>
      </div>
      <div v-if="editable" class="rpg-sheet__actions">
        <button class="rpg-btn rpg-btn--primary" @click="save">Save Sheet</button>
        <button class="rpg-btn rpg-btn--ghost" @click="exportSheet">Export JSON</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  template:   { type: Object, default: null },
  modelValue: { type: Object, default: null },
  editable:   { type: Boolean, default: false },
})
const emit = defineEmits(['save'])

const localData = ref({})

watch(() => props.modelValue, (val) => {
  localData.value = val ? { ...val } : {}
}, { immediate: true, deep: true })

function save() {
  emit('save', { ...localData.value })
}

function exportSheet() {
  const blob = new Blob(
    [JSON.stringify({ template: props.template?.name, data: localData.value }, null, 2)],
    { type: 'application/json' }
  )
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `character-${Date.now()}.json`
  a.click()
}
</script>

<style scoped>
.rpg-sheet { display: flex; flex-direction: column; gap: 10px; }

.rpg-sheet__empty { color: #888; font-size: 13px; text-align: center; padding: 20px 0; }

.rpg-sheet__name {
  font-size: 14px;
  font-weight: 600;
  color: #c0b8ff;
  border-bottom: 1px solid #3d3a55;
  padding-bottom: 6px;
}

.rpg-sheet__fields { display: flex; flex-direction: column; gap: 8px; }

.rpg-sheet__field { display: flex; flex-direction: column; gap: 3px; }

.rpg-sheet__label { font-size: 11px; font-weight: 600; color: #9991dd; text-transform: uppercase; letter-spacing: 0.04em; }

.rpg-sheet__value { font-size: 13px; color: #e0deff; min-height: 18px; }

.rpg-sheet__input,
.rpg-sheet__textarea {
  background: #1c1e2d;
  border: 1px solid #3d3a55;
  border-radius: 5px;
  color: #e0deff;
  font-size: 13px;
  padding: 5px 8px;
  outline: none;
  resize: vertical;
  width: 100%;
  box-sizing: border-box;
}
.rpg-sheet__input:focus,
.rpg-sheet__textarea:focus { border-color: #7c6af7; }

.rpg-sheet__actions { display: flex; gap: 8px; margin-top: 4px; }

/* shared button styles (duplicated here for standalone use) */
.rpg-btn {
  padding: 6px 14px;
  border-radius: 6px;
  border: none;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: opacity 0.15s;
}
.rpg-btn:hover { opacity: 0.85; }
.rpg-btn--primary  { background: #7c6af7; color: #fff; }
.rpg-btn--ghost    { background: transparent; color: #9991dd; border: 1px solid #3d3a55; }
</style>
