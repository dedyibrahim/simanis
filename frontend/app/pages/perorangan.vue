<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

useHead({
  title: 'Data Client Perorangan',
})
</script>

<template>
  <ClientDataWorkspace client-type="Perorangan" />
</template>
