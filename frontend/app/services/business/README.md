# Legacy Business Migration (newfrontend -> frontend)

Service `useLegacyBusinessService()` sudah memetakan proses bisnis lama dari `newfrontend` ke `frontend` tanpa ubah endpoint, payload utama, atau struktur tabel backend.

Domain yang tersedia:
- `auth`
- `dashboard`
- `master`
- `client`
- `bantek`
- `order`
- `bukuNotaris`
- `bukuLegalisasi`
- `bukuWarmerking`
- `bukuPpat`
- `bukuRekanan`
- `surat`
- `dokumen`
- `pencarian`
- `jadwal`
- `events`
- `peminjamanMinuta`
- `tandaTerima`
- `reports`
- `assets`

Contoh pemakaian:

```ts
const business = useLegacyBusiness()

await business.auth.login({ email, password })
await business.order.getBukuPesanan({ date, status_order: 'Proses' })

const url = business.reports.CetakInvoice(idOrder)
window.open(url, '_blank')
```
