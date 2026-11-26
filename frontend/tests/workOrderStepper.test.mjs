import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'
import { test } from 'node:test'

const require = createRequire(import.meta.url)
const ts = require('typescript')
const vue = require('vue')
const source = await readFile(new URL('../app/pages/buat-kerjaan.vue', import.meta.url), 'utf8')
const script = source.match(/<script setup[^>]*>([\s\S]*?)<\/script>/)[1]
const iconNames = script.match(/import \{([^}]+)\} from '@heroicons[^']+'/)[1].split(',').map(name => name.trim())
const code = ts.transpileModule(script.replace(/^import .+$/gm, '').replaceAll('import.meta.client', 'false'), { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function editor() {
  const calls = []
  const dialogDecision = vue.ref(false)
  const business = { order: { getDaftarClient: async () => ({ data: [{ id_client: 'C1', nama_client: 'DUMMY', nama_pencarian: 'DUMMY - 1234' }] }) }, workOrders: {
    actTypes: async () => ({ data: [] }), list: async () => ({ data: [] }),
    deleteJob: async id => { calls.push({ deleted: id }); return {} },
    create: async payload => { calls.push(JSON.parse(JSON.stringify(payload))); return { message: 'Saved' } },
    addJob: async (id, payload) => { calls.push({ id, ...JSON.parse(JSON.stringify(payload)) }); return {} },
    updateJob: async (id, payload) => { calls.push({ id, ...JSON.parse(JSON.stringify(payload)) }); return {} },
  } }
  const scope = vue.effectScope()
  const state = scope.run(() => runInNewContext(code + ';({create,add,edit,chooseType,nextStep,moveToStep,queue,save,editorStep,error,orderForm,jobForm,penghadap,queued,editorOpen,clientOptions,clientId,clientQuery,mewakiliQuery,mewakiliOptions,mewakiliId,kedudukan,addPerson,lookup,cancelLookups,load,month,orders,loading,openSchedule,scheduleForm,deleteJob,canDeleteJob})', {
    ...vue, ...Object.fromEntries(iconNames.map(name => [name, {}])),
    definePageMeta() {}, useHead() {}, useLegacyBusiness: () => business,
    useAppDialog: () => ({ confirmAction: async () => dialogDecision.value }),
    useSession: () => ({ user: vue.ref({ id_user: 'USR1', level_user: 'Admin' }) }),
    onMounted() {}, onBeforeUnmount() {}, nextTick: async () => {},
    setTimeout, clearTimeout,
  }))
  return { state, calls, business, dialogDecision, stop: () => scope.stop() }
}
function fill(state, title = 'Surat pertama') {
  state.chooseType('legalisasi')
  state.orderForm.nama_pesanan = 'Pesanan gabungan'
  state.orderForm.id_user = 'USR1'
  state.jobForm.title = title
  state.jobForm.id_user = 'USR1'
  state.penghadap.value.push({ id_client: 'C1', nama_client: 'Client contoh', status_kedudukan: 'Diri sendiri', id_mewakili: '', mewakili: '' })
}
test('stepper blocks incomplete steps and keeps entered data when going back', () => {
  const { state: s, stop } = editor()
  s.create(); s.nextStep()
  assert.equal(s.editorStep.value, 1)
  assert.match(s.error.value, /Pilih salah satu/)
  s.chooseType('legalisasi'); s.nextStep(); s.nextStep()
  assert.equal(s.editorStep.value, 2)
  fill(s); s.nextStep()
  assert.equal(s.editorStep.value, 3)
  s.moveToStep(2)
  assert.equal(s.jobForm.title, 'Surat pertama')
  assert.equal(s.penghadap.value.length, 1)
  stop()
})
test('single job saves directly from review without a separate queue action', async () => {
  const { state: s, calls, stop } = editor()
  s.create(); fill(s); s.moveToStep(4)
  await s.save()
  assert.equal(calls.length, 1)
  assert.equal(calls[0].jobs.length, 1)
  assert.equal(calls[0].jobs[0].title, 'Surat pertama')
  assert.equal(calls[0].jobs[0].penghadap[0].id_client, 'C1')
  assert.equal(s.editorOpen.value, false)
  stop()
})
test('adding another job preserves the first job and saves both once', async () => {
  const { state: s, calls, stop } = editor()
  s.create(); fill(s); s.moveToStep(4); s.queue()
  assert.equal(s.editorStep.value, 1)
  assert.equal(s.queued.value.length, 1)
  assert.equal(s.jobForm.title, '')
  fill(s, 'Surat kedua'); s.chooseType('waarmerking'); s.moveToStep(4)
  await s.save()
  assert.deepEqual(calls[0].jobs.map(job => job.title), ['Surat pertama', 'Surat kedua'])
  assert.deepEqual(calls[0].jobs.map(job => job.type), ['legalisasi', 'waarmerking'])
  stop()
})
test('review cannot submit incomplete participants or bypass required act type', async () => {
  const { state: s, calls, stop } = editor()
  s.create(); fill(s); s.penghadap.value = []; s.moveToStep(4)
  await s.save()
  assert.equal(calls.length, 0)
  assert.equal(s.editorStep.value, 3)
  fill(s); s.chooseType('notaris'); s.moveToStep(4)
  await s.save()
  assert.equal(calls.length, 0)
  assert.equal(s.editorStep.value, 2)
  stop()
})
test('participant cannot be added twice with the same role and represented party', () => {
  const { state: s, stop } = editor()
  s.create()
  s.clientOptions.value = [{ id_client: 'C1', nama_client: 'Client contoh' }]
  s.clientId.value = 'C1'; s.kedudukan.value = 'Diri sendiri'; s.addPerson()
  s.clientOptions.value = [{ id_client: 'C1', nama_client: 'Client contoh' }]
  s.clientId.value = 'C1'; s.kedudukan.value = 'Diri sendiri'; s.addPerson()
  assert.equal(s.penghadap.value.length, 1)
  assert.match(s.error.value, /sudah ditambahkan/)
  stop()
})

test('autocomplete searches after two characters and selection directly resolves the client', async () => {
  const { state: s, stop } = editor()
  s.create()
  s.clientQuery.value = 'DU'; s.lookup('DU', 'client')
  await new Promise(resolve => setTimeout(resolve, 350))
  assert.equal(s.clientOptions.value.length, 1)
  s.clientQuery.value = 'DUMMY - 1234'; s.lookup(s.clientQuery.value, 'client')
  assert.equal(s.clientId.value, 'C1')
  s.kedudukan.value = 'Diri sendiri'; s.addPerson()
  assert.equal(s.penghadap.value.length, 1)
  assert.equal(s.penghadap.value[0].nama_client, 'DUMMY')
  s.cancelLookups(); stop()
})
test('changing selected autocomplete text cannot add the previously selected client', () => {
  const { state: s, stop } = editor()
  s.create()
  s.clientOptions.value = [{ id_client: 'C1', nama_client: 'DUMMY' }]
  s.clientQuery.value = 'DUMMY'; s.lookup('DUMMY', 'client')
  assert.equal(s.clientId.value, 'C1')
  s.clientQuery.value = 'OTHER'; s.lookup('OTHER', 'client')
  assert.equal(s.clientId.value, '')
  s.kedudukan.value = 'Diri sendiri'; s.addPerson()
  assert.equal(s.penghadap.value.length, 0)
  assert.match(s.error.value, /autocomplete/)
  s.cancelLookups(); stop()
})
test('represented party must also be selected from autocomplete when entered', () => {
  const { state: s, stop } = editor()
  s.create()
  s.clientOptions.value = [{ id_client: 'C1', nama_client: 'DUMMY' }]
  s.clientQuery.value = 'DUMMY'; s.kedudukan.value = 'Direktur'
  s.mewakiliQuery.value = 'Unregistered company'; s.addPerson()
  assert.equal(s.penghadap.value.length, 0)
  assert.match(s.error.value, /pihak yang diwakili/)
  s.mewakiliOptions.value = [{ id_client: 'C2', nama_client: 'PT CONTOH' }]
  s.mewakiliQuery.value = 'PT CONTOH'; s.lookup(s.mewakiliQuery.value, 'mewakili'); s.addPerson()
  assert.equal(s.penghadap.value[0].id_mewakili, 'C2')
  s.cancelLookups(); stop()
})

test('changing month ignores old responses that arrive after the new month', async () => {
  const { state: s, business, stop } = editor()
  const pending = []
  business.workOrders.list = () => new Promise(resolve => pending.push(resolve))
  s.month.value = '2026-01'; const older = s.load()
  s.month.value = '2026-02'; const newer = s.load()
  pending[1]({ data: [{ id_order: 'FEB', jobs: [] }] }); await newer
  pending[0]({ data: [{ id_order: 'JAN', jobs: [] }] }); await older
  assert.equal(s.orders.value[0].id_order, 'FEB')
  assert.equal(s.loading.value, false)
  stop()
})
test('edit schedule preserves its stored start, end and location', () => {
  const { state: s, stop } = editor()
  s.openSchedule({ id: 1, scheduled_start: '2026-12-01 09:30:00', scheduled_end: '2026-12-01 10:30:00', scheduled_location: 'Ruang A' })
  assert.equal(s.scheduleForm.start_datetime, '2026-12-01T09:30')
  assert.equal(s.scheduleForm.end_datetime, '2026-12-01T10:30')
  assert.equal(s.scheduleForm.location, 'Ruang A')
  stop()
})
test('PPAT negative values cannot pass the detail step', () => {
  const { state: s, calls, stop } = editor()
  s.create(); fill(s); s.chooseType('ppat'); s.jobForm.details.jenis_akta = 'A2'
  s.jobForm.details.harga_transaksi = '-100'
  s.moveToStep(2); s.nextStep()
  assert.equal(s.editorStep.value, 2)
  assert.match(s.error.value, /Harga transaksi/)
  assert.equal(calls.length, 0)
  stop()
})

test('job deletion respects cancel and never offers deletion for numbered jobs', async () => {
  const { state: s, calls, dialogDecision, stop } = editor()
  const job = { id: 1, title: 'Draf', status: 'signed', book_id: null, book_number: null }
  const order = { id_order: 'ORD1', id_user: 'USR1', status_order: 'Proses', jobs: [job] }
  assert.equal(s.canDeleteJob(order, job), true)
  await s.deleteJob(order, job)
  assert.equal(calls.length, 0)
  dialogDecision.value = true
  await s.deleteJob(order, job)
  assert.equal(calls[0].deleted, 1)
  job.status = 'numbered'; job.book_id = 'BKN1'; job.book_number = '1'
  assert.equal(s.canDeleteJob(order, job), false)
  await s.deleteJob(order, job)
  assert.equal(calls.length, 1)
  stop()
})
