<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    tone?: 'default' | 'dark' | 'soft'
  }>(),
  {
    tone: 'default',
  },
)

const cardClass = computed(() => {
  if (props.tone === 'dark') {
    return 'surface-card--dark'
  }

  if (props.tone === 'soft') {
    return 'surface-card hero-band'
  }

  return 'surface-card'
})
</script>

<template>
  <section :class="cardClass">
    <slot />
  </section>
</template>
