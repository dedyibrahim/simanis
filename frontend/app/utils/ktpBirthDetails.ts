export const splitKtpBirthDetails = (value: unknown) => {
  const raw = String(value ?? '').trim()
  const match = raw.match(/^(.*?)[,\s]*(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{4})$/)
  if (!match) return { place: raw, date: '' }

  const [first = 0, second = 0, third = 0] = (match[2] || '').split(/[-/.]/).map(Number)
  const [year, month, day] = first > 31 ? [first, second, third] : [third, second, first]
  const parsed = new Date(Date.UTC(year, month - 1, day))
  const valid = year > 0 && parsed.getUTCFullYear() === year && parsed.getUTCMonth() === month - 1 && parsed.getUTCDate() === day
  return {
    place: valid ? (match[1] || '').replace(/[,\s]+$/, '').trim() : raw,
    date: valid ? `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}` : '',
  }
}

export const joinKtpBirthDetails = (place: string, date: string) => {
  const [year = '', month = '', day = ''] = date.split('-')
  return `${place.trim()}, ${day}-${month}-${year}`
}
