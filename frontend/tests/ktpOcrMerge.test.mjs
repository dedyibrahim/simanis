import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fillBlankWithOcr } from '../app/utils/ktpOcrMerge.ts'

test('stored client data takes precedence over conflicting OCR text', () => {
  assert.equal(fillBlankWithOcr('Nama Terkoreksi', 'NAMA SALAH'), 'Nama Terkoreksi')
  assert.equal(fillBlankWithOcr('1998-01-23', '1998-01-28'), '1998-01-23')
})

test('OCR fills fields that are blank or null in the database', () => {
  assert.equal(fillBlankWithOcr('', 'BOGOR'), 'BOGOR')
  assert.equal(fillBlankWithOcr(null, 'WNI'), 'WNI')
  assert.equal(fillBlankWithOcr('   ', 'ISLAM'), 'ISLAM')
})

test('missing OCR data does not erase existing or invent values', () => {
  assert.equal(fillBlankWithOcr('Data Lama', null), 'Data Lama')
  assert.equal(fillBlankWithOcr('', undefined), '')
})

test('late database match replaces earlier OCR only where stored data exists', () => {
  const scanned = { nama: 'NAMA OCR SALAH', alamat: 'JL. OCR', tanggal: '1998-01-28' }
  const stored = { nama: 'Nama Terkoreksi', alamat: '', tanggal: '1998-01-23' }
  const merged = Object.fromEntries(
    Object.keys(stored).map(key => [key, fillBlankWithOcr(stored[key], scanned[key])]),
  )

  assert.deepEqual(merged, {
    nama: 'Nama Terkoreksi',
    alamat: 'JL. OCR',
    tanggal: '1998-01-23',
  })
})
