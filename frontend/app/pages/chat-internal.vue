<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

useHead({
  title: 'Chat Internal',
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <p class="display-kicker">Komunikasi Karyawan</p>
      <h2 class="mt-2 text-3xl font-semibold text-slate-900">Chat Internal</h2>
      <p class="mt-2 max-w-3xl text-sm text-slate-600">
        Gunakan tombol <span class="font-semibold">Chat</span> di top bar atau tombol chat mengambang di kanan bawah untuk membuka percakapan.
      </p>
    </SurfaceCard>

    <SurfaceCard class="p-6">
      <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
        Notifikasi chat baru juga tampil pada icon <span class="font-semibold">bell</span> di top bar.
      </div>
    </SurfaceCard>
  </div>
</template>
