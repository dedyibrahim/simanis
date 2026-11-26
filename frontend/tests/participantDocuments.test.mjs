import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'
import { test } from 'node:test'
const require = createRequire(import.meta.url)
const ts = require('typescript')
const vue = require('vue')
async function component(file, props, business = {}) {
  const source = await readFile(new URL('../app/components/' + file, import.meta.url), 'utf8')
  const script = source.match(/<script setup[^>]*>([\s\S]*?)<\/script>/)[1]
  const code = ts.transpileModule(script, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
  const scope = vue.effectScope()
  const emitted = []
  const names = file.startsWith('Participant') ? 'clients,onBusy' : 'files,documents,upload,error,saving'
  class Data { entries = []; append(...entry) { this.entries.push(entry) } }
  const state = scope.run(() => runInNewContext(code + ';({' + names + '})', {
    ...vue, defineProps: () => props, defineEmits: () => (...args) => emitted.push(args),
    useLegacyBusiness: () => business, FormData: Data,
  }))
  return { state, emitted, stop: () => scope.stop() }
}
test('each unique penghadap has a card and concurrent uploads keep dialog locked', async () => {
  const h = await component('ParticipantDocumentsUpload.vue', { participants: [
    { id_client: 'C1', nama_client: 'A', id_mewakili: 'C2', mewakili: 'B' },
    { id_client: 'C1', nama_client: 'A' },
  ] })
  assert.equal(h.state.clients.value.length, 2)
  h.state.onBusy('C1', true); h.state.onBusy('C2', true)
  h.state.onBusy('C1', false)
  assert.equal(h.emitted.at(-1)[1], true)
  h.state.onBusy('C2', false)
  assert.equal(h.emitted.at(-1)[1], false)
  h.stop()
})
test('uploads from separate cards stay attached to their own client', async () => {
  const calls = []
  const business = { client: {
    getDataDokumenClient: async () => ({ data: [] }),
    UploadDokumenClient: async data => { calls.push(data.entries); return { status: true } },
  } }
  const a = await component('ClientDocumentsUploadCard.vue', { client: { id: 'C1', name: 'A' } }, business)
  const b = await component('ClientDocumentsUploadCard.vue', { client: { id: 'C2', name: 'B' } }, business)
  a.state.files.value = [{ name: 'a.pdf' }]
  b.state.files.value = [{ name: 'b.pdf' }]
  await Promise.all([a.state.upload(), b.state.upload()])
  assert.equal(calls[0][0][1], 'C1')
  assert.equal(calls[1][0][1], 'C2')
  assert.equal(calls[0][1][2], 'a.pdf')
  assert.equal(calls[1][1][2], 'b.pdf')
  assert.equal(a.state.files.value.length, 0)
  assert.equal(b.state.files.value.length, 0)
  a.stop(); b.stop()
})
test('failed upload keeps selected files available for retry and releases dialog lock', async () => {
  const h = await component('ClientDocumentsUploadCard.vue', { client: { id: 'C1', name: 'A' } }, { client: {
    getDataDokumenClient: async () => ({ data: [] }),
    UploadDokumenClient: async () => { throw new Error('Upload gagal') },
  } })
  h.state.files.value = [{ name: 'retry.pdf' }]
  await h.state.upload()
  assert.equal(h.state.files.value[0].name, 'retry.pdf')
  assert.equal(h.state.saving.value, false)
  assert.equal(h.emitted.at(-1)[1], false)
  assert.match(h.state.error.value, /Upload gagal/)
  h.stop()
})
