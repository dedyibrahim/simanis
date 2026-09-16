<script setup lang="ts">
import { navigationSections } from '~/data/navigation'
import { iconMap } from '~/utils/icons'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const { user } = useSession()

const isAdminRole = computed(() => {
  const role = String(user.value?.level_user || '').trim().toLowerCase()
  return role === 'admin' || role === 'super admin' || role === 'superadmin'
})

const visibleSections = computed(() =>
  navigationSections
    .map(section => ({
      ...section,
      items: section.items.filter(item => (!item.adminOnly || isAdminRole.value)
        && (!item.superAdminOnly || ['super admin', 'superadmin'].includes(String(user.value?.level_user || '').trim().toLowerCase()))),
    }))
    .filter(section => section.items.length > 0),
)

const isActive = (path: string) => route.path === path
</script>

<template>
  <Transition
    enter-active-class="transition-opacity duration-200"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity duration-200"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="props.open"
      class="fixed inset-0 z-40 bg-slate-950/45 backdrop-blur-sm lg:hidden"
      @click="emit('close')"
    />
  </Transition>

  <aside
    class="fixed inset-y-0 left-0 z-50 w-[290px] p-3 transition-transform duration-300 ease-out lg:translate-x-0"
    :class="props.open ? 'translate-x-0' : '-translate-x-full'"
  >
    <div class="surface-card--dark relative flex h-full flex-col overflow-hidden">
      <div class="absolute right-[-42px] top-[-42px] h-32 w-32 rounded-full bg-teal-400/20 blur-3xl"></div>
      <div class="absolute bottom-[-56px] left-[-18px] h-32 w-32 rounded-full bg-orange-400/10 blur-3xl"></div>

      <div class="border-b border-white/10 px-6 py-6">
        <AppLogo />
        <p class="mt-4 max-w-xs text-sm leading-6 text-slate-300">
          Shell migrasi SIMANIS untuk memindahkan modul lama ke Nuxt dan Tailwind secara bertahap.
        </p>
      </div>

      <nav class="flex-1 space-y-6 overflow-y-auto px-4 py-5">
        <section v-for="section in visibleSections" :key="section.title">
          <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.28em] text-slate-500">
            {{ section.title }}
          </p>
          <div class="mt-3 space-y-1">
            <NuxtLink
              v-for="item in section.items"
              :key="item.path"
              :to="item.path"
              class="group flex items-center gap-3 rounded-2xl px-3 py-3 transition"
              :class="
                isActive(item.path)
                  ? 'bg-white text-slate-900 shadow-lg shadow-black/20'
                  : 'text-slate-300 hover:bg-white/8 hover:text-white'
              "
              @click="emit('close')"
            >
              <div
                class="flex h-10 w-10 items-center justify-center rounded-2xl transition"
                :class="isActive(item.path) ? 'bg-slate-950 text-white' : 'bg-white/8 text-slate-200'"
              >
                <component :is="iconMap[item.icon] || iconMap.fallback" class="h-5 w-5" />
              </div>
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold">{{ item.title }}</p>
                <p
                  class="truncate text-xs"
                  :class="isActive(item.path) ? 'text-slate-500' : 'text-slate-400 group-hover:text-slate-300'"
                >
                  {{ item.hint }}
                </p>
              </div>
            </NuxtLink>
          </div>
        </section>
      </nav>

      <div class="border-t border-white/10 px-5 py-4">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
          <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">API target</p>
          <p class="mt-2 text-sm font-medium text-slate-100">{{ runtimeConfig.public.apiBase }}</p>
          <p class="mt-3 text-xs text-slate-400">
            Aktif sebagai {{ user?.name || 'guest' }}.
          </p>
        </div>
      </div>
    </div>
  </aside>
</template>
