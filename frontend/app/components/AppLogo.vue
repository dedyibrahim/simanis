<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    compact?: boolean
  }>(),
  {
    compact: false,
  },
)
</script>

<template>
  <div class="flex items-center gap-3">
    <div class="relative flex h-12 w-12 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-teal-500 via-cyan-600 to-slate-900 text-white shadow-lg">
      <span class="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.28),transparent_55%)]"></span>
      <span class="relative text-sm font-black tracking-[0.28em]">SI</span>
    </div>
    <div v-if="!props.compact" class="min-w-0">
      <p class="text-xs font-semibold uppercase tracking-[0.32em] text-teal-200/90">Nuxt Workspace</p>
      <p class="truncate text-lg font-semibold text-white">SIMANIS</p>
    </div>
  </div>
</template>
