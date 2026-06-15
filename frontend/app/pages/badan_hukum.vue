<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

useHead({
  title: 'Data Client Badan Hukum',
})
</script>

<template>
  <ClientDataWorkspace client-type="Badan Hukum" />
</template>
