<script setup lang="ts">
import type { ModuleStage } from '~/data/modules'

const props = defineProps<{
  stage: ModuleStage
}>()

const labelMap: Record<ModuleStage, string> = {
  ready: 'Aktif',
  migrating: 'Sedang Dipetakan',
  planned: 'Blueprint',
}

const classMap: Record<ModuleStage, string> = {
  ready: 'bg-emerald-100 text-emerald-800 ring-emerald-200',
  migrating: 'bg-amber-100 text-amber-800 ring-amber-200',
  planned: 'bg-slate-200 text-slate-700 ring-slate-300',
}
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset"
    :class="classMap[props.stage]"
  >
    {{ labelMap[props.stage] }}
  </span>
</template>
