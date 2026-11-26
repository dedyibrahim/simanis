<script setup lang="ts">
type Person = { id: string; name: string }
const props = defineProps<{ participants: Record<string, unknown>[] }>()
const emit = defineEmits<{ busy: [value: boolean] }>()
const clients = computed(() => {
  const result = new Map<string, Person>()
  for (const person of props.participants) {
    if (person.id_client) result.set(String(person.id_client), { id: String(person.id_client), name: String(person.nama_client || person.id_client) })
    if (person.id_mewakili) result.set(String(person.id_mewakili), { id: String(person.id_mewakili), name: String(person.mewakili || person.nama_mewakili || person.id_mewakili) + ' (pihak yang diwakili)' })
  }
  return [...result.values()]
})
const uploading = reactive(new Set<string>())
const onBusy = (id: string, busy: boolean) => {
  if (busy) uploading.add(id)
  else uploading.delete(id)
  emit('busy', uploading.size > 0)
}
</script>
<template>
  <section class="space-y-5">
    <div>
      <h3 class="text-lg font-bold text-slate-900">Dokumen penghadap</h3>
      <p class="mt-1 text-sm text-slate-600">Upload langsung pada nama pemilik dokumen. Setiap penghadap memiliki tempat upload sendiri, tanpa perlu memilih dari dropdown.</p>
    </div>
    <p v-if="!clients.length" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Belum ada penghadap pada pekerjaan ini. Tambahkan penghadap melalui Edit terlebih dahulu.</p>
    <div v-else class="grid items-start gap-5 xl:grid-cols-2">
      <ClientDocumentsUploadCard v-for="client in clients" :key="client.id" :client="client" @busy="onBusy(client.id, $event)" />
    </div>
  </section>
</template>
