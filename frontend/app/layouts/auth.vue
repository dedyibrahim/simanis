<template>
  <div class="relative min-h-screen overflow-hidden px-4 py-8 sm:px-6 lg:px-8">
    <div class="pointer-events-none absolute left-[-4rem] top-[-4rem] h-56 w-56 rounded-full bg-teal-300/30 blur-3xl"></div>
    <div class="pointer-events-none absolute bottom-[-4rem] right-[-4rem] h-56 w-56 rounded-full bg-orange-300/30 blur-3xl"></div>
    <div class="pointer-events-none absolute inset-0 grid-fade opacity-50"></div>

    <div class="relative mx-auto flex min-h-[calc(100vh-4rem)] max-w-7xl items-center">
      <slot />
    </div>
  </div>
</template>
