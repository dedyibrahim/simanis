<script setup lang="ts">
const { isAuthenticated } = useSession()

await navigateTo(isAuthenticated.value ? '/dashboard' : '/login', {
  replace: true,
})
</script>

<template>
  <div />
</template>
