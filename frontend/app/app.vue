<template>
  <div>
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage :page-key="pageKey" :transition="pageTransition" />
    </NuxtLayout>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import type { RouteLocationNormalizedLoaded } from 'vue-router'

const pageKey = (route: RouteLocationNormalizedLoaded) => route.fullPath
const route = useRoute()
const router = useRouter()
const pageTransitionName = ref('simanis-page-slide-forward')
const routeStack = ref<string[]>([route.fullPath])
const pageTransition = computed(() => ({
  name: pageTransitionName.value,
  mode: 'out-in' as const,
}))

const removeRouteGuard = router.beforeEach((to, from) => {
  if (!from.fullPath || to.fullPath === from.fullPath) {
    pageTransitionName.value = 'simanis-page-slide-forward'
    return
  }

  const stack = routeStack.value
  const fromIndex = stack.lastIndexOf(from.fullPath)
  const toIndex = stack.lastIndexOf(to.fullPath)
  pageTransitionName.value = toIndex !== -1 && fromIndex !== -1 && toIndex < fromIndex
    ? 'simanis-page-slide-back'
    : 'simanis-page-slide-forward'
})

router.afterEach((to, from) => {
  if (!from.fullPath || to.fullPath === from.fullPath) return

  const stack = routeStack.value
  const toIndex = stack.lastIndexOf(to.fullPath)
  const fromIndex = stack.lastIndexOf(from.fullPath)

  if (pageTransitionName.value === 'simanis-page-slide-back' && toIndex !== -1) {
    routeStack.value = stack.slice(0, toIndex + 1)
    return
  }

  if (fromIndex === -1) {
    routeStack.value = [from.fullPath, to.fullPath]
    return
  }

  routeStack.value = [...stack.slice(0, fromIndex + 1), to.fullPath]
})

onBeforeUnmount(() => {
  removeRouteGuard()
})
</script>

<style>
.simanis-page-slide-forward-enter-active,
.simanis-page-slide-forward-leave-active,
.simanis-page-slide-back-enter-active,
.simanis-page-slide-back-leave-active {
  transition:
    transform 260ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 190ms ease-out,
    filter 260ms ease-out;
}

.simanis-page-slide-forward-enter-from {
  opacity: 0;
  filter: blur(5px);
  transform: translate3d(30px, 0, 0) scale(0.992);
}

.simanis-page-slide-forward-leave-to {
  opacity: 0;
  filter: blur(3px);
  transform: translate3d(-18px, 0, 0) scale(0.996);
}

.simanis-page-slide-back-enter-from {
  opacity: 0;
  filter: blur(5px);
  transform: translate3d(-30px, 0, 0) scale(0.992);
}

.simanis-page-slide-back-leave-to {
  opacity: 0;
  filter: blur(3px);
  transform: translate3d(18px, 0, 0) scale(0.996);
}

@media (prefers-reduced-motion: reduce) {
  .simanis-page-slide-forward-enter-active,
  .simanis-page-slide-forward-leave-active,
  .simanis-page-slide-back-enter-active,
  .simanis-page-slide-back-leave-active {
    transition: opacity 120ms ease-out !important;
  }

  .simanis-page-slide-forward-enter-from,
  .simanis-page-slide-forward-leave-to,
  .simanis-page-slide-back-enter-from,
  .simanis-page-slide-back-leave-to {
    filter: none !important;
    transform: none !important;
  }
}
</style>
