<script setup lang="ts">
type ClientOption = {
  id_client: string | number
  nama_client?: string
  nama_pencarian?: string
}

type PenghadapRow = {
  id_client: string
  nama_client: string
  status_kedudukan: string
  id_mewakili: string
  mewakili: string
}

const props = defineProps<{
  visible: boolean
  inputClass: string
  secondaryButtonClass: string
  loadingClient: boolean
  penghadapDatalistId: string
  penghadapInput: string
  mewakiliInput: string
  statusKedudukan: string
  clientOptions: ClientOption[]
  rows: PenghadapRow[]
  clientOptionLabel: (item: ClientOption) => string
}>()

const emit = defineEmits<{
  'update:penghadapInput': [value: string]
  'update:mewakiliInput': [value: string]
  'update:statusKedudukan': [value: string]
  add: []
  remove: [index: number]
}>()

const onPenghadapInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  emit('update:penghadapInput', target.value)
}

const onMewakiliInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  emit('update:mewakiliInput', target.value)
}

const onStatusInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  emit('update:statusKedudukan', target.value)
}
</script>

<template>
  <div v-if="visible" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
    <p class="text-sm font-semibold text-slate-800">Data Penghadap</p>
    <p class="mt-1 text-xs text-slate-500">Autocomplete langsung aktif saat mengetik nama client.</p>

    <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
          <thead class="bg-slate-100/80">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">#</th>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama Penghadap</th>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Status Kedudukan</th>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Mewakili</th>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr class="bg-white">
              <td class="px-3 py-2 text-sm text-slate-500">+</td>
              <td class="px-3 py-2">
                <input
                  :value="penghadapInput"
                  type="text"
                  :class="inputClass"
                  :list="penghadapDatalistId"
                  placeholder="Cari Nama Penghadap"
                  @input="onPenghadapInput"
                />
              </td>
              <td class="px-3 py-2">
                <input
                  :value="statusKedudukan"
                  type="text"
                  :class="inputClass"
                  placeholder="Status kedudukan"
                  @input="onStatusInput"
                />
              </td>
              <td class="px-3 py-2">
                <input
                  :value="mewakiliInput"
                  type="text"
                  :class="inputClass"
                  :list="penghadapDatalistId"
                  placeholder="Cari Nama Mewakili"
                  @input="onMewakiliInput"
                />
              </td>
              <td class="px-3 py-2">
                <button type="button" :class="secondaryButtonClass" @click="$emit('add')">
                  Tambah
                </button>
              </td>
            </tr>

            <tr v-for="(row, index) in rows" :key="`${row.id_client}-${index}`" class="bg-white">
              <td class="px-3 py-2 text-sm text-slate-600">{{ index + 1 }}</td>
              <td class="px-3 py-2 text-sm text-slate-700">{{ row.nama_client }}</td>
              <td class="px-3 py-2 text-sm text-slate-700">{{ row.status_kedudukan }}</td>
              <td class="px-3 py-2 text-sm text-slate-700">{{ row.mewakili }}</td>
              <td class="px-3 py-2">
                <button
                  type="button"
                  class="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700"
                  @click="$emit('remove', index)"
                >
                  Hapus
                </button>
              </td>
            </tr>

            <tr v-if="!rows.length" class="bg-white">
              <td colspan="5" class="px-3 py-3 text-sm text-slate-500">
                Belum ada data penghadap.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="loadingClient" class="mt-2 text-xs text-slate-500">Mencari client...</p>
    <datalist :id="penghadapDatalistId">
      <option v-for="item in clientOptions" :key="String(item.id_client)" :value="clientOptionLabel(item)" />
    </datalist>
  </div>
</template>
