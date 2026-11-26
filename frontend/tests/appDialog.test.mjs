import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createAppDialog } from '../app/utils/appDialog.ts'

test('cancel does not authorize destructive action, accept authorizes once', async () => {
  const dialog = createAppDialog()
  const cancelled = dialog.confirmAction('Hapus pekerjaan ini?')
  assert.equal(dialog.state.value.danger, true)
  assert.equal(dialog.state.value.confirmText, 'Ya, hapus')
  dialog.resolve(false)
  assert.equal(await cancelled, false)
  const accepted = dialog.confirmAction('Hapus pekerjaan ini?')
  dialog.resolve(true)
  assert.equal(await accepted, true)
  assert.equal(dialog.state.value, null)
})
test('duplicate clicks cannot authorize the same action twice', async () => {
  const dialog = createAppDialog()
  const first = dialog.confirmAction('Hapus dokumen ini?')
  const duplicate = dialog.confirmAction('Hapus dokumen ini?')
  assert.equal(await duplicate, false)
  dialog.resolve(true)
  assert.equal(await first, true)
  assert.equal(dialog.state.value, null)
})
test('dialogs queue without replacing pending decisions and navigation cancels them', async () => {
  const dialog = createAppDialog()
  const first = dialog.confirmAction('Konfirmasi TTD?')
  const second = dialog.confirmAction('Selesaikan pesanan?')
  assert.equal(dialog.state.value.message, 'Konfirmasi TTD?')
  dialog.resolve(true)
  assert.equal(await first, true)
  assert.equal(dialog.state.value.message, 'Selesaikan pesanan?')
  const third = dialog.confirmAction('Hapus pekerjaan?')
  dialog.cancelAll()
  assert.equal(await second, false)
  assert.equal(await third, false)
  assert.equal(dialog.state.value, null)
})
test('notice uses application dialog and can be dismissed', async () => {
  const dialog = createAppDialog()
  const notice = dialog.showNotice('Jadwal bentrok', { title: 'Pilih waktu lain' })
  assert.equal(dialog.state.value.kind, 'notice')
  assert.equal(dialog.state.value.title, 'Pilih waktu lain')
  dialog.resolve(false)
  await notice
  assert.equal(dialog.state.value, null)
})
