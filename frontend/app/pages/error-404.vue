<script setup lang="ts">
definePageMeta({
  layout: false,
})

useHead({
  title: '404',
})

const { isAuthenticated } = useSession()
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
    <div class="w-full max-w-xl rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-xl">
      <p class="display-kicker">404</p>
      <h1 class="mt-3 text-3xl font-bold text-slate-900">Halaman Tidak Ditemukan</h1>
      <p class="mt-4 text-sm leading-7 text-slate-600">
        Route yang diminta tidak tersedia di workspace SIMANIS.
      </p>
      <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
        <NuxtLink
          :to="isAuthenticated ? '/dashboard' : '/login'"
          class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800"
        >
          Kembali ke Dashboard
        </NuxtLink>
        <NuxtLink
          to="/"
          class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
        >
          Kembali ke Beranda
        </NuxtLink>
      </div>
    </div>
  </div>
</template>
