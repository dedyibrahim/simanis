<script setup lang="ts">
type ItemDiterima = {
  isi_diterima: string
}

defineProps<{
  visible: boolean
  inputClass: string
  secondaryButtonClass: string
  isiInput: string
  items: ItemDiterima[]
}>()

const emit = defineEmits<{
  'update:isiInput': [value: string]
  add: []
  remove: [index: number]
}>()

const onInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  emit('update:isiInput', target.value)
}
</script>

<template>
  <div v-if="visible" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
    <p class="text-sm font-semibold text-slate-800">Isi Dokumen Diterima</p>
    <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto]">
      <input :value="isiInput" type="text" :class="inputClass" placeholder="Tambah isi dokumen" @input="onInput" @keyup.enter="$emit('add')" />
      <button type="button" :class="secondaryButtonClass" @click="$emit('add')">Tambah</button>
    </div>
    <div class="mt-3 space-y-2">
      <p v-if="!items.length" class="text-sm text-slate-500">Belum ada item dokumen.</p>
      <div
        v-for="(item, index) in items"
        :key="`${item.isi_diterima}-${index}`"
        class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
      >
        <span class="truncate">{{ item.isi_diterima }}</span>
        <button
          type="button"
          class="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700"
          @click="$emit('remove', index)"
        >
          Hapus
        </button>
      </div>
    </div>
  </div>
</template>
