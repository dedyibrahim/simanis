export const fillBlankWithOcr = (current: unknown, recognized: unknown): string => {
  const existing = String(current ?? '')
  return existing.trim() ? existing : String(recognized ?? '').trim()
}
