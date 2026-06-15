<script setup lang="ts">
import { ArrowRightStartOnRectangleIcon } from '@heroicons/vue/24/outline'
import { findNavigationItem } from '~/data/navigation'
import { iconMap } from '~/utils/icons'

const emit = defineEmits<{
  toggle: []
}>()

const route = useRoute()
const { clearSession, user } = useSession()
const { auth } = useLegacyBusiness()

const currentPage = computed(() => findNavigationItem(route.path))
const todayLabel = computed(() =>
  new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date()),
)

const loggingOut = ref(false)

const handleLogout = async () => {
  loggingOut.value = true

  try {
    await auth.SignOut()
  } catch {
    // Tetap hapus sesi lokal walau backend tidak merespons.
  } finally {
    clearSession()
    loggingOut.value = false
    await navigateTo('/login')
  }
}
</script>

<template>
  <header class="sticky top-0 z-30 px-4 pb-4 pt-4 sm:px-6 lg:px-8">
    <div class="surface-card mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
      <div class="flex min-w-0 items-center gap-3">
        <button
          type="button"
          class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-950 text-white shadow-lg lg:hidden"
          @click="emit('toggle')"
        >
          <component :is="iconMap.menu" class="h-5 w-5" />
        </button>

        <div class="min-w-0">
          <p class="display-kicker">SIMANIS Workspace</p>
          <div class="flex items-center gap-3">
            <h1 class="truncate text-xl font-semibold text-slate-900">
              {{ currentPage?.title || 'Workspace' }}
            </h1>
            <span class="hidden text-sm text-slate-500 sm:inline">{{ todayLabel }}</span>
          </div>
          <p class="truncate text-sm text-slate-500">
            {{ currentPage?.hint || 'Migrasi frontend baru berbasis Nuxt dan Tailwind.' }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <div class="hidden rounded-2xl bg-slate-950 px-4 py-3 text-white md:block">
          <p class="text-xs uppercase tracking-[0.24em] text-slate-400">User</p>
          <p class="text-sm font-semibold">{{ user?.name || 'SIMANIS Operator' }}</p>
        </div>

        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
          :disabled="loggingOut"
          @click="handleLogout"
        >
          <ArrowRightStartOnRectangleIcon class="h-4 w-4" />
          {{ loggingOut ? 'Keluar...' : 'Keluar' }}
        </button>
      </div>
    </div>
  </header>
</template>
