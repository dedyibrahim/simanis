import assert from 'node:assert/strict'
import { test } from 'node:test'
import { joinKtpBirthDetails, splitKtpBirthDetails } from '../app/utils/ktpBirthDetails.ts'

test('separates OCR birth details and restores the legacy API format', () => {
  assert.deepEqual(splitKtpBirthDetails('BOGOR, 23-01-1998'), { place: 'BOGOR', date: '1998-01-23' })
  assert.equal(joinKtpBirthDetails('BOGOR', '1998-01-23'), 'BOGOR, 23-01-1998')
})

test('reads existing slash and ISO birth dates', () => {
  assert.deepEqual(splitKtpBirthDetails('Jakarta 01/01/1990'), { place: 'Jakarta', date: '1990-01-01' })
  assert.deepEqual(splitKtpBirthDetails('Bandung, 2001-12-09'), { place: 'Bandung', date: '2001-12-09' })
})

test('does not invent a date from incomplete or invalid OCR text', () => {
  assert.deepEqual(splitKtpBirthDetails('BOGOR, 31-02-1998'), { place: 'BOGOR, 31-02-1998', date: '' })
  assert.deepEqual(splitKtpBirthDetails('BOGOR'), { place: 'BOGOR', date: '' })
})
