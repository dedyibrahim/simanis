<script setup lang="ts">
type AktaOption = {
  id_akta: string | number
  nama_akta?: string
}

type AsistenOption = {
  id_user: string | number
  nama_lengkap?: string
}

type ClientOption = {
  id_client: string | number
  nama_client?: string
  nama_pencarian?: string
}

type NotarisForm = {
  jenis_akta: string
  tgl_akta: string
  judul_pekerjaan: string
  nama_asisten: string
  sudah_tanda_tangan: boolean
}

type LegalisasiForm = {
  judul_surat: string
  keterangan_surat: string
}

type WaarmerkingForm = {
  judul_surat: string
  keterangan_surat: string
}

type PpatForm = {
  jenis_akta: string
  sudah_tanda_tangan: boolean
  no_hak_milik: string
  harga_transaksi: string
  luas_tanah: string
  luas_bangunan: string
  nop: string
  harga_njop: string
  tgl_bphtb: string
  harga_bphtb: string
  tgl_pph: string
  harga_pph: string
  keterangan: string
}

type SuratForm = {
  id_client: string
  pengirim: string
  keterangan: string
}

type TandaTerimaForm = {
  tgl_terima: string
  nama_pengirim: string
  nama_penerima: string
  up_penerima: string
  lokasi: string
  keterangan_tanda_terima: string
}

const props = defineProps<{
  path: string
  inputClass: string
  textAreaClass: string
  secondaryButtonClass: string
  loadingAkta: boolean
  loadingAsisten: boolean
  loadingSuratClient: boolean
  aktaOptions: AktaOption[]
  asistenOptions: AsistenOption[]
  suratClientOptions: ClientOption[]
  requireAsisten: boolean
  isEdit: boolean
  aphtMode: boolean
  tandaTerimaStatus: string
  notarisMinDate: string
  notarisForm: NotarisForm
  legalisasiForm: LegalisasiForm
  waarmerkingForm: WaarmerkingForm
  ppatForm: PpatForm
  suratForm: SuratForm
  tandaTerimaForm: TandaTerimaForm
  suratClientQuery: string
  clientDisplayName: (item: ClientOption | undefined) => string
}>()

const emit = defineEmits<{
  'update:suratClientQuery': [value: string]
  'search-surat-client': []
}>()

const isNotaris = computed(() => props.path === '/buku_akta')
const isLegalisasi = computed(() => props.path === '/buku_legalisasi')
const isWaarmerking = computed(() => props.path === '/buku_waarmerking')
const isPpatLike = computed(() => props.path === '/buku_ppat')
const isSurat = computed(() => props.path === '/buku_surat_notaris' || props.path === '/buku_surat_ppat')
const isTandaTerima = computed(() => props.path === '/tanda_terima' || props.path === '/tanda_terima_masuk')
const suratClientDatalistId = computed(() => `surat-client-options-${props.path.replace(/[^a-zA-Z0-9]/g, '-')}`)

const onSuratClientInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  emit('update:suratClientQuery', target.value)
  emit('search-surat-client')
}
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2">
    <template v-if="isNotaris">
      <select v-model="notarisForm.jenis_akta" :class="inputClass">
        <option value="">{{ loadingAkta ? 'Memuat jenis akta...' : 'Pilih jenis akta' }}</option>
        <option v-for="item in aktaOptions" :key="String(item.id_akta)" :value="String(item.id_akta)">{{ item.nama_akta || item.id_akta }}</option>
      </select>
      <input v-model="notarisForm.tgl_akta" type="date" :class="inputClass" :min="isEdit ? '' : notarisMinDate" />
      <input v-model="notarisForm.judul_pekerjaan" type="text" :class="inputClass" class="sm:col-span-2" placeholder="Judul pekerjaan" />
      <label v-if="!isEdit" class="sm:col-span-2 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        <input v-model="notarisForm.sudah_tanda_tangan" type="checkbox" class="h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-400" />
        Dokumen sudah ditandatangani
      </label>
      <select v-if="requireAsisten" v-model="notarisForm.nama_asisten" :class="inputClass">
        <option value="">{{ loadingAsisten ? 'Memuat asisten...' : 'Pilih asisten' }}</option>
        <option v-for="item in asistenOptions" :key="String(item.id_user)" :value="String(item.id_user)">{{ item.nama_lengkap || item.id_user }}</option>
      </select>
    </template>

    <template v-if="isLegalisasi">
      <input v-model="legalisasiForm.judul_surat" type="text" :class="inputClass" class="sm:col-span-2" placeholder="Judul legalisasi" />
      <textarea v-model="legalisasiForm.keterangan_surat" rows="3" :class="textAreaClass" class="sm:col-span-2" placeholder="Keterangan surat (opsional)" />
    </template>

    <template v-if="isWaarmerking">
      <input v-model="waarmerkingForm.judul_surat" type="text" :class="inputClass" class="sm:col-span-2" placeholder="Judul waarmerking" />
      <textarea v-model="waarmerkingForm.keterangan_surat" rows="3" :class="textAreaClass" class="sm:col-span-2" placeholder="Keterangan surat (opsional)" />
    </template>

    <template v-if="isPpatLike">
      <select v-model="ppatForm.jenis_akta" :class="inputClass" class="sm:col-span-2">
        <option value="">{{ loadingAkta ? 'Memuat jenis akta...' : 'Pilih jenis akta' }}</option>
        <option v-for="item in aktaOptions" :key="String(item.id_akta)" :value="String(item.id_akta)">{{ item.nama_akta || item.id_akta }}</option>
      </select>
      <label v-if="!isEdit" class="sm:col-span-2 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        <input v-model="ppatForm.sudah_tanda_tangan" type="checkbox" class="h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-400" />
        Dokumen sudah ditandatangani
      </label>
      <input v-model="ppatForm.no_hak_milik" type="text" :class="inputClass" placeholder="No hak milik" />
      <input v-model="ppatForm.harga_transaksi" type="number" :class="inputClass" placeholder="Harga transaksi" />
      <input v-model="ppatForm.luas_tanah" type="number" :class="inputClass" placeholder="Luas tanah" />
      <input v-model="ppatForm.luas_bangunan" type="number" :class="inputClass" placeholder="Luas bangunan" />
      <template v-if="!aphtMode">
        <input v-model="ppatForm.nop" type="text" :class="inputClass" placeholder="NOP" />
        <input v-model="ppatForm.harga_njop" type="number" :class="inputClass" placeholder="Harga NJOP" />
        <input v-model="ppatForm.tgl_bphtb" type="date" :class="inputClass" />
        <input v-model="ppatForm.harga_bphtb" type="number" :class="inputClass" placeholder="Harga BPHTB" />
        <input v-model="ppatForm.tgl_pph" type="date" :class="inputClass" />
        <input v-model="ppatForm.harga_pph" type="number" :class="inputClass" placeholder="Harga PPH" />
      </template>
      <textarea v-model="ppatForm.keterangan" rows="3" :class="textAreaClass" class="sm:col-span-2" placeholder="Keterangan (opsional)" />
    </template>

    <template v-if="isSurat">
      <div class="sm:col-span-2 space-y-2">
        <input
          :value="suratClientQuery"
          type="text"
          :class="inputClass"
          :list="suratClientDatalistId"
          placeholder="Cari client tujuan (autocomplete)"
          @input="onSuratClientInput"
        />
        <datalist :id="suratClientDatalistId">
          <option v-for="item in suratClientOptions" :key="String(item.id_client)" :value="clientDisplayName(item)" />
        </datalist>
        <p class="text-xs text-slate-500">
          {{ loadingSuratClient ? 'Mencari client...' : 'Mulai ketik minimal 2 huruf, lalu pilih dari saran autocomplete.' }}
        </p>
      </div>
      <select v-model="suratForm.pengirim" :class="inputClass" class="sm:col-span-2">
        <option value="">{{ loadingAsisten ? 'Memuat pengirim...' : 'Pilih pengirim' }}</option>
        <option v-for="item in asistenOptions" :key="String(item.id_user)" :value="String(item.id_user)">{{ item.nama_lengkap || item.id_user }}</option>
      </select>
      <textarea v-model="suratForm.keterangan" rows="3" :class="textAreaClass" class="sm:col-span-2" placeholder="Keterangan surat (opsional)" />
    </template>

    <template v-if="isTandaTerima">
      <input v-model="tandaTerimaForm.tgl_terima" type="date" :class="inputClass" />
      <input :value="tandaTerimaStatus" type="text" class="h-11 rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm text-slate-700" disabled />
      <input v-model="tandaTerimaForm.nama_pengirim" type="text" :class="inputClass" placeholder="Nama pengirim" />
      <input v-model="tandaTerimaForm.nama_penerima" type="text" :class="inputClass" placeholder="Nama penerima" />
      <input v-model="tandaTerimaForm.up_penerima" type="text" :class="inputClass" placeholder="UP penerima" />
      <input v-model="tandaTerimaForm.lokasi" type="text" :class="inputClass" placeholder="Lokasi" />
      <textarea v-model="tandaTerimaForm.keterangan_tanda_terima" rows="3" :class="textAreaClass" class="sm:col-span-2" placeholder="Keterangan (opsional)" />
    </template>
  </div>
</template>
