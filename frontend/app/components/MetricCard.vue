<script setup lang="ts">
import { iconMap } from '~/utils/icons'
import type { AppIcon } from '~/utils/icons'

const props = withDefaults(
  defineProps<{
    label: string
    value: string | number
    detail?: string
    icon?: AppIcon
    accent?: 'teal' | 'navy' | 'amber' | 'coral'
  }>(),
  {
    detail: '',
    icon: 'fallback',
    accent: 'teal',
  },
)

const accentMap = {
  teal: 'from-teal-500/15 via-teal-500/5 to-white text-teal-900',
  navy: 'from-sky-700/15 via-sky-700/5 to-white text-sky-900',
  amber: 'from-amber-500/20 via-amber-500/5 to-white text-amber-900',
  coral: 'from-orange-400/20 via-orange-400/5 to-white text-orange-900',
}

const iconComponent = computed(() => iconMap[props.icon] || iconMap.fallback)
</script>

<template>
  <div class="surface-card h-full overflow-hidden bg-gradient-to-br p-5" :class="accentMap[props.accent]">
    <div class="flex items-start justify-between gap-4">
      <div>
        <p class="text-sm font-medium text-slate-500">{{ props.label }}</p>
        <p class="mt-3 text-3xl font-semibold tracking-tight">{{ props.value }}</p>
      </div>
      <div class="rounded-2xl bg-white/80 p-3 shadow-sm">
        <component :is="iconComponent" class="h-5 w-5" />
      </div>
    </div>
    <p v-if="props.detail" class="mt-4 text-sm text-slate-600">{{ props.detail }}</p>
  </div>
</template>
