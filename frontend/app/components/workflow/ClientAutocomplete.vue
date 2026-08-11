<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { CheckIcon, ChevronDownIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'

type ClientOption = {
  id: string | number
  label: string
  type?: string
  identity?: string
}

const props = defineProps<{
  modelValue: string | number
  initialLabel?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const business = useLegacyBusiness()
const root = ref<HTMLElement | null>(null)
const query = ref('')
const options = ref<ClientOption[]>([])
const open = ref(false)
const loading = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null
let requestId = 0

const selected = computed(() => options.value.find(item => String(item.id) === String(props.modelValue)))
const displayValue = computed(() => selected.value?.label || props.initialLabel || '')
const unwrap = (response: any) => response?.data?.data ?? response?.data ?? response
const typeLabel = (value?: string) => {
  const normalized = String(value || '').trim().toLowerCase()
  return normalized.includes('badan') || normalized.includes('hukum') ? 'Badan Hukum' : 'Perorangan'
}

const load = async (keyword = '') => {
  const currentRequest = ++requestId
  loading.value = true
  try {
    const payload = unwrap(await business.workItems.options({ search: keyword }))
    if (currentRequest === requestId) options.value = payload?.clients || []
  } catch {
    if (currentRequest === requestId) options.value = []
  } finally {
    if (currentRequest === requestId) loading.value = false
  }
}

const focus = () => {
  query.value = displayValue.value
  open.value = true
  void load('')
}

const input = () => {
  open.value = true
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => void load(query.value.trim()), 250)
}

const select = (item: ClientOption) => {
  emit('update:modelValue', String(item.id))
  query.value = item.label
  open.value = false
}

const clear = () => {
  emit('update:modelValue', '')
  query.value = ''
  open.value = false
}

const closeLater = () => setTimeout(() => {
  open.value = false
  query.value = displayValue.value
}, 150)

watch(() => props.modelValue, (value) => {
  if (!value) query.value = ''
})
watch(() => props.initialLabel, (value) => {
  if (props.modelValue && value && !query.value) query.value = value
}, { immediate: true })
onBeforeUnmount(() => { if (timer) clearTimeout(timer) })
</script>

<template>
  <div ref="root" class="relative">
    <div class="relative mt-1">
      <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input
        v-model="query"
        type="text"
        autocomplete="off"
        placeholder="Cari nama, ID client, atau NIK..."
        class="h-10 w-full rounded-lg border pl-9 pr-16 text-sm"
        @focus="focus"
        @input="input"
        @blur="closeLater"
      />
      <button v-if="modelValue" type="button" title="Hapus pilihan" class="absolute right-8 top-1/2 -translate-y-1/2 text-slate-400 hover:text-rose-500" @mousedown.prevent @click="clear">
        <XMarkIcon class="h-4 w-4" />
      </button>
      <ChevronDownIcon class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
    </div>

    <div v-if="open" class="absolute z-[240] mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white p-1 text-slate-900 shadow-xl">
      <p v-if="loading" class="px-3 py-3 text-xs text-slate-500">Mencari client...</p>
      <button type="button" class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left hover:bg-slate-50" @mousedown.prevent @click="clear">
        <span class="grid h-7 w-7 place-items-center rounded-md bg-slate-100 text-xs font-bold">-</span>
        <span class="text-sm font-semibold">Tanpa klien</span>
      </button>
      <button
        v-for="item in options"
        :key="item.id"
        type="button"
        class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left hover:bg-blue-50"
        @mousedown.prevent
        @click="select(item)"
      >
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm font-bold">{{ item.label }}</span>
          <span class="block truncate text-[11px] text-slate-500">{{ item.id }}<template v-if="item.identity"> · {{ item.identity }}</template></span>
        </span>
        <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-[9px] font-black uppercase text-slate-500">{{ typeLabel(item.type) }}</span>
        <CheckIcon v-if="String(modelValue) === String(item.id)" class="h-4 w-4 shrink-0 text-blue-600" />
      </button>
      <p v-if="!loading && !options.length" class="px-3 py-3 text-xs text-slate-500">Client tidak ditemukan.</p>
    </div>
  </div>
</template>
