<template>
  <ClientOnly>
    <div
      class="app-shell relative flex h-screen overflow-hidden font-sans transition-colors duration-300"
      :class="isDark ? 'bg-slate-950 text-slate-100' : 'bg-gray-100 text-slate-900'"
      @pointermove="handleWindowsPointerMove"
    >
      <div v-if="mouseEffect !== 'off'" class="windows-cursor-layer pointer-events-none fixed inset-0 z-[60] overflow-hidden" :class="`mouse-effect-${mouseEffect}`" aria-hidden="true">
        <span class="windows-cursor-ring fixed left-0 top-0" />
        <span v-for="sparkle in windowsSparkles" :key="sparkle.id" class="windows-cursor-spark fixed" :style="{ left: `${sparkle.x}px`, top: `${sparkle.y}px` }" />
      </div>

      <div v-if="windowsMode" class="windows-shell relative flex h-full w-full flex-col overflow-hidden" :class="isDark ? 'windows-shell-dark' : 'windows-shell-light'">
        <div class="windows-motion-bg pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
          <div class="windows-moving-grid absolute inset-0" />
          <span v-for="shape in windowsBackgroundShapes" :key="shape.id" class="windows-floating-shape absolute" :style="shape.style" />
        </div>
        <header class="windows-header relative z-50 flex h-20 shrink-0 items-center justify-between border-b px-5 backdrop-blur-xl sm:px-8">
          <div class="flex min-w-0 items-center gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-indigo-700 shadow-lg">
              <component :is="iconMap.folder" class="h-6 w-6 text-white" />
            </div>
            <div class="min-w-0">
              <p class="truncate text-lg font-black">SIMANIS</p>
              <p class="windows-muted truncate text-[10px] font-bold uppercase">Mode Windows</p>
            </div>
          </div>

          <form class="windows-search group relative mx-3 hidden min-w-0 max-w-xl flex-1 sm:block lg:mx-8" @submit.prevent="submitWindowsSearch">
            <MagnifyingGlassIcon class="windows-search-icon pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2" />
            <input v-model="topbarSearch" type="search" placeholder="Cari dokumen..." class="h-11 w-full rounded-lg border pl-11 pr-20 text-sm font-semibold outline-none transition focus:ring-2" @keydown.enter.prevent="submitWindowsSearch" />
            <button v-if="topbarSearch" type="button" class="absolute right-10 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md" title="Hapus pencarian" @click="clearTopbarSearch">
              <XMarkIcon class="h-4 w-4" />
            </button>
            <button type="submit" class="absolute right-1.5 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md" title="Cari dokumen">
              <MagnifyingGlassIcon class="h-4 w-4" />
            </button>
          </form>

          <div class="flex items-center gap-2">
            <Menu as="div" class="relative">
              <MenuButton class="flex h-10 items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-2.5 text-white transition hover:bg-white/10" title="Pilih tema warna">
                <span class="h-6 w-6 rounded-md ring-1 ring-white/30" :style="{ background: activeAccentTheme.swatch }" />
                <SwatchIcon class="hidden h-4 w-4 sm:block" />
              </MenuButton>
              <transition enter-active-class="transition duration-100 ease-out" enter-from-class="scale-95 opacity-0" enter-to-class="scale-100 opacity-100" leave-active-class="transition duration-75 ease-in" leave-from-class="scale-100 opacity-100" leave-to-class="scale-95 opacity-0">
                <MenuItems class="fixed right-3 top-[72px] z-[100] max-h-[calc(100vh-5.5rem)] w-[min(94vw,520px)] origin-top-right overflow-y-auto rounded-lg border border-slate-200 p-3 text-slate-900 shadow-2xl ring-1 ring-black/10 focus:outline-none sm:right-8" style="background-color: #ffffff; opacity: 1;">
                  <div class="mb-3 border-b border-slate-100 pb-3">
                    <p class="text-xs font-extrabold uppercase text-slate-500">Tema SIMANIS</p>
                    <p class="mt-1 text-xs text-slate-400">Pilih warna untuk seluruh tampilan.</p>
                    <div class="mt-3 flex items-center gap-2">
                      <span class="text-[10px] font-extrabold uppercase text-slate-500">Efek Mouse</span>
                      <select v-model="mouseEffect" class="h-8 min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-2 text-xs font-bold text-slate-700 outline-none">
                        <option v-for="option in mouseEffectOptions" :key="`windows-${option.value}`" :value="option.value">{{ option.label }}</option>
                      </select>
                    </div>
                  </div>
                  <div class="grid gap-2 sm:grid-cols-2">
                    <MenuItem v-for="option in accentThemeOptions" :key="`windows-theme-${option.value}`" v-slot="{ active }">
                      <button type="button" class="flex h-14 w-full items-center gap-2 rounded-md border px-2.5 text-left transition" :class="accentTheme === option.value ? 'border-blue-500 bg-blue-50' : active ? 'border-slate-300 bg-slate-50' : 'border-slate-200 bg-white'" @click="setAccentTheme(option.value)">
                        <span class="h-9 w-9 shrink-0 rounded-md ring-1 ring-black/10" :style="{ background: option.swatch }" />
                        <span class="min-w-0 flex-1">
                          <span class="block truncate text-xs font-extrabold">{{ option.name }}</span>
                          <span class="block truncate text-[10px] text-slate-500">{{ option.description }}</span>
                        </span>
                        <span v-if="accentTheme === option.value" class="h-2.5 w-2.5 shrink-0 rounded-full bg-blue-600" />
                      </button>
                    </MenuItem>
                  </div>
                </MenuItems>
              </transition>
            </Menu>
            <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-white/15 bg-white/5 text-white transition hover:bg-white/10" title="Semua modul" @click="openWindowsLauncher">
              <Squares2X2Icon class="h-5 w-5" />
            </button>
            <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-white/15 bg-white/5 text-white transition hover:bg-white/10" :title="isDark ? 'Mode terang' : 'Mode gelap'" @click="toggleTheme">
              <SunIcon v-if="isDark" class="h-5 w-5" />
              <MoonIcon v-else class="h-5 w-5" />
            </button>
            <button type="button" class="inline-flex h-10 items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 text-xs font-bold text-white transition hover:bg-white/10" @click="windowsMode = false">
              <RectangleStackIcon class="h-4 w-4" />
              <span class="hidden sm:inline">Mode Dashboard</span>
            </button>
          </div>
        </header>

        <main class="relative z-10 min-h-0 flex-1 overflow-hidden">
          <Transition :name="windowsWorkspaceTransitionName" mode="out-in">
          <div v-if="windowsLauncherVisible" key="windows-launcher" class="h-full overflow-y-auto px-4 py-7 sm:px-8">
            <div class="mx-auto w-full max-w-6xl overflow-hidden">
              <Transition :name="windowsLauncherTransitionName" mode="out-in">
                <div :key="windowsLauncherPanelKey" class="windows-launcher-panel">
                  <div class="windows-divider mb-6 flex items-center gap-3 border-b pb-5">
                    <button v-if="selectedWindowsGroup" type="button" class="windows-control flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border transition" title="Kembali ke semua modul" @click="backToWindowsGroups">
                      <ChevronLeftIcon class="h-5 w-5" />
                    </button>
                    <div class="min-w-0">
                      <p class="windows-muted text-[10px] font-bold uppercase">{{ selectedWindowsGroup ? 'Submenu' : 'Semua aplikasi' }}</p>
                      <h1 class="mt-1 truncate text-2xl font-black sm:text-3xl">{{ selectedWindowsGroup?.name || 'Modul SIMANIS' }}</h1>
                      <p class="windows-muted mt-1 text-xs">{{ selectedWindowsGroup ? `${selectedWindowsGroup.children.length} menu tersedia` : `${windowsLauncherGroups.length} kelompok modul tersedia` }}</p>
                    </div>
                  </div>

                  <div v-if="!selectedWindowsGroup" class="grid auto-rows-[150px] grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <button v-for="(group, index) in windowsLauncherGroups" :key="`windows-group-${group.name}`" type="button" class="windows-tile windows-tile-3d group relative flex min-w-0 flex-col justify-between overflow-hidden rounded-lg border bg-gradient-to-br from-blue-600 to-indigo-700 p-4 text-left shadow-lg transition hover:-translate-y-0.5 hover:shadow-2xl sm:p-5" :class="index === 0 ? 'sm:col-span-2' : ''" :style="{ '--tile-float-delay': `${index * -0.65}s` }" @click="openWindowsGroup(group)">
                      <component :is="group.icon" class="windows-tile-ambient pointer-events-none absolute -bottom-5 right-8 h-28 w-28" aria-hidden="true" />
                      <div class="relative z-10 flex items-start justify-between">
                        <span class="windows-tile-icon-stage flex h-12 w-12 items-center justify-center rounded-xl">
                          <component :is="group.icon" class="windows-tile-icon h-7 w-7" />
                        </span>
                        <span class="windows-tile-badge rounded-md px-2 py-1 text-[10px] font-bold">{{ group.children.length }}</span>
                      </div>
                      <div class="relative z-10 min-w-0">
                        <p class="windows-tile-title break-words text-base font-extrabold leading-5">{{ group.name }}</p>
                        <p class="windows-tile-muted mt-1 text-[10px] font-semibold uppercase">Lihat submenu</p>
                      </div>
                    </button>
                  </div>

                  <div v-else class="grid auto-rows-[132px] grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <NuxtLink v-for="(item, index) in selectedWindowsGroup.children" :key="item.href" :to="item.href" class="windows-tile windows-tile-3d group relative flex min-w-0 flex-col justify-between overflow-hidden rounded-lg border bg-gradient-to-br from-blue-600 to-indigo-700 p-4 shadow-lg transition hover:-translate-y-0.5 hover:shadow-2xl sm:p-5" :style="{ '--tile-float-delay': `${index * -0.65}s` }" @click="openWindowsModule">
                      <component :is="item.icon" class="windows-tile-ambient pointer-events-none absolute -bottom-6 right-5 h-24 w-24" aria-hidden="true" />
                      <div class="relative z-10 flex items-start justify-between">
                        <span class="windows-tile-icon-stage flex h-11 w-11 items-center justify-center rounded-xl">
                          <component :is="item.icon" class="windows-tile-icon h-6 w-6" />
                        </span>
                        <ChevronRightIcon class="windows-tile-muted h-5 w-5 transition group-hover:translate-x-1" />
                      </div>
                      <div class="relative z-10 min-w-0">
                        <p class="windows-tile-title break-words text-sm font-extrabold leading-5 sm:text-base">{{ item.name }}</p>
                        <p class="windows-tile-muted mt-1 text-[10px] font-semibold uppercase">Buka halaman</p>
                      </div>
                    </NuxtLink>
                  </div>
                </div>
              </Transition>
            </div>
          </div>

          <div v-else key="windows-page" class="h-full overflow-y-auto bg-slate-50 text-slate-900">
            <div class="mx-auto w-full max-w-7xl px-4 py-7 sm:px-8">
              <div class="mb-4 flex items-center justify-between gap-3">
                <button type="button" class="windows-page-back windows-back-action inline-flex h-10 items-center gap-2 rounded-lg border px-3 text-xs font-extrabold transition hover:-translate-x-0.5 active:scale-[0.97]" @click="backToWindowsSubmenu">
                  <ChevronLeftIcon class="h-5 w-5" />
                  <span>Kembali ke {{ activeWindowsGroup?.name || 'Semua Modul' }}</span>
                </button>
                <button type="button" class="windows-page-back flex h-10 w-10 items-center justify-center rounded-lg border transition" title="Semua modul" @click="openWindowsLauncher">
                  <Squares2X2Icon class="h-5 w-5" />
                </button>
              </div>
              <slot />
            </div>
          </div>
          </Transition>
        </main>
      </div>

      <TransitionRoot as="template" :show="sidebarOpen && !windowsMode">
        <Dialog as="div" class="relative z-50 md:hidden" @close="sidebarOpen = false">
          <TransitionChild
            as="template"
            enter="transition-opacity ease-linear duration-300"
            enter-from="opacity-0"
            enter-to="opacity-100"
            leave="transition-opacity ease-linear duration-300"
            leave-from="opacity-100"
            leave-to="opacity-0"
          >
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" />
          </TransitionChild>

          <div class="fixed inset-0 z-40 flex">
            <TransitionChild
              as="template"
              enter="transition ease-in-out duration-300 transform"
              enter-from="-translate-x-full"
              enter-to="translate-x-0"
              leave="transition ease-in-out duration-300 transform"
              leave-from="translate-x-0"
              leave-to="-translate-x-full"
            >
              <DialogPanel class="relative flex w-full max-w-xs flex-1 flex-col bg-white shadow-2xl">
                <div class="sidebar flex h-full flex-col bg-gradient-to-b from-white to-slate-50">
                  <div class="flex flex-shrink-0 items-start justify-between px-6 py-6">
                    <div class="flex items-center space-x-3">
                      <div class="simanis-brand-mark rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 p-2 shadow-lg">
                        <component :is="iconMap.folder" class="h-7 w-7 text-white" />
                      </div>
                      <div>
                        <h1 class="text-xl font-black tracking-tight text-slate-800">SIMANIS</h1>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Sistem Informasi Administrasi Kantor Notaris</p>
                      </div>
                    </div>
                    <button
                      type="button"
                      class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition-all hover:bg-slate-100"
                      @click="sidebarOpen = false"
                    >
                      <XMarkIcon class="h-6 w-6" />
                    </button>
                  </div>

                  <nav class="flex-1 space-y-2 overflow-y-auto px-4 pb-8">
                    <NuxtLink
                      to="/dashboard"
                      class="flex w-full items-center rounded-xl px-4 py-3 text-sm font-semibold transition-all duration-200"
                      :class="route.path === '/dashboard' ? 'bg-slate-100 text-blue-600' : 'text-slate-600 hover:bg-slate-50'"
                      @click="sidebarOpen = false"
                    >
                      <component :is="iconMap.dashboard" class="mr-3 h-5 w-5" />
                      <span>Dashboard</span>
                    </NuxtLink>

                    <Disclosure v-for="item in mainNavigation" :key="item.name" as="div" class="space-y-1" :default-open="isGroupActive(item)" v-slot="{ open }">
                      <DisclosureButton
                        class="w-full rounded-xl px-4 py-3 text-sm font-semibold transition-all duration-200"
                        :class="[open || isGroupActive(item) ? 'bg-slate-100 text-blue-600' : 'text-slate-600 hover:bg-slate-50']"
                      >
                        <div class="flex items-center">
                          <component :is="item.icon" class="mr-3 h-5 w-5" />
                          <span class="flex-1 text-left">{{ item.name }}</span>
                          <ChevronRightIcon class="h-4 w-4 transition-transform duration-200" :class="open ? 'rotate-90' : ''" />
                        </div>
                      </DisclosureButton>
                      <transition name="dashboard-submenu-slide">
                        <DisclosurePanel class="mt-1 space-y-1">
                          <NuxtLink
                            v-for="subItem in item.children"
                            :key="subItem.href"
                            :to="subItem.href"
                            class="flex items-center rounded-lg py-2.5 pl-8 pr-4 text-xs font-medium transition-all"
                            :class="[isActive(subItem.href) ? 'bg-blue-50 font-bold text-blue-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700']"
                            @click="sidebarOpen = false"
                          >
                            {{ subItem.name }}
                          </NuxtLink>
                        </DisclosurePanel>
                      </transition>
                    </Disclosure>
                  </nav>

                  <div class="shrink-0 border-t border-white/15 bg-black/10 p-3 backdrop-blur-sm">
                    <div class="flex items-center gap-2">
                      <span class="h-8 w-8 shrink-0 rounded-lg ring-1 ring-white/30" :style="{ background: activeAccentTheme.swatch }" />
                      <select :value="accentTheme" class="theme-native-select h-8 min-w-0 flex-1 rounded-lg border border-white/20 bg-white/15 px-2 text-[11px] font-bold text-white outline-none" @change="handleAccentSelect">
                        <option v-for="option in accentThemeOptions" :key="option.value" :value="option.value" class="text-slate-900">{{ option.name }}</option>
                      </select>
                      <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/20 bg-white/15 text-white transition hover:bg-white/25" :title="isDark ? 'Mode terang' : 'Mode gelap'" @click="toggleTheme">
                        <SunIcon v-if="isDark" class="h-4 w-4" />
                        <MoonIcon v-else class="h-4 w-4" />
                      </button>
                      <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/20 bg-white/15 text-white transition hover:bg-white/25" :class="windowsMode ? '!bg-white !text-slate-900' : ''" title="Mode Windows" @click="windowsMode = !windowsMode">
                        <Squares2X2Icon class="h-4 w-4" />
                      </button>
                    </div>
                    <select v-model="mouseEffect" class="theme-native-select mt-2 h-8 w-full rounded-lg border border-white/20 bg-white/15 px-2 text-[11px] font-bold text-white outline-none">
                      <option v-for="option in mouseEffectOptions" :key="`mobile-${option.value}`" :value="option.value">Efek: {{ option.label }}</option>
                    </select>
                  </div>
                </div>
              </DialogPanel>
            </TransitionChild>
          </div>
        </Dialog>
      </TransitionRoot>

      <div
        v-if="!windowsMode"
        class="hidden overflow-hidden transition-all duration-300 ease-out md:flex md:flex-col"
        :class="desktopSidebarOpen ? 'md:w-64' : 'md:w-0'"
      >
        <div
          class="sidebar flex flex-grow flex-col overflow-hidden transition-opacity duration-200"
          :class="desktopSidebarOpen ? 'opacity-100' : 'pointer-events-none opacity-0'"
          :aria-hidden="desktopSidebarOpen ? 'false' : 'true'"
        >
          <div class="flex flex-shrink-0 items-center px-5 py-5">
            <div class="flex items-center space-x-3">
              <div class="simanis-brand-mark rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 p-2 shadow-xl shadow-blue-200">
                <component :is="iconMap.folder" class="h-7 w-7 text-white" />
              </div>
              <div>
                <h1 class="text-xl font-black tracking-tight text-slate-800">SIMANIS</h1>
                <p class="max-w-[150px] text-[9px] font-bold uppercase leading-3 tracking-[0.16em] text-slate-400">Sistem Informasi Administrasi Kantor Notaris</p>
              </div>
            </div>
          </div>

          <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-4 pb-3">
            <NuxtLink
              to="/dashboard"
              class="group flex w-full items-center rounded-lg px-3 py-2.5 text-[13px] font-bold transition-all duration-200"
              :class="route.path === '/dashboard' ? 'bg-slate-50 text-blue-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800'"
            >
              <component :is="iconMap.dashboard" class="mr-3 h-5 w-5 transition-colors" :class="route.path === '/dashboard' ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600'" />
              <span>Dashboard</span>
            </NuxtLink>

            <Disclosure v-for="item in mainNavigation" :key="item.name" as="div" class="space-y-1" :default-open="isGroupActive(item)" v-slot="{ open }">
              <DisclosureButton
                class="group w-full rounded-lg px-3 py-2.5 text-[13px] font-bold transition-all duration-200"
                :class="[open || isGroupActive(item) ? 'bg-slate-50 text-blue-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800']"
              >
                <div class="flex items-center">
                  <component :is="item.icon" class="mr-3 h-5 w-5 transition-colors" :class="open || isGroupActive(item) ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600'" />
                  <span class="flex-1 text-left">{{ item.name }}</span>
                  <ChevronRightIcon class="h-4 w-4 transition-all duration-300" :class="open || isGroupActive(item) ? 'rotate-90 text-blue-600' : 'text-slate-300'" />
                </div>
              </DisclosureButton>
              <transition name="dashboard-submenu-slide">
                <DisclosurePanel class="space-y-0.5 px-1">
                  <NuxtLink
                    v-for="subItem in item.children"
                    :key="subItem.href"
                    :to="subItem.href"
                    class="flex items-center rounded-lg py-2 pl-7 pr-3 text-[11px] font-bold transition-all"
                    :class="[isActive(subItem.href) ? 'bg-blue-50 text-blue-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800']"
                  >
                    <component :is="subItem.icon" class="mr-2 h-3.5 w-3.5 shrink-0" />
                    <span>{{ subItem.name }}</span>
                  </NuxtLink>
                </DisclosurePanel>
              </transition>
            </Disclosure>
          </nav>

          <div class="shrink-0 border-t border-white/15 bg-black/10 p-3 backdrop-blur-sm">
            <p class="mb-1.5 text-[9px] font-bold uppercase tracking-widest text-white/60">Tampilan</p>
            <div class="flex items-center gap-2">
              <span class="h-9 w-9 shrink-0 rounded-lg ring-1 ring-white/30" :style="{ background: activeAccentTheme.swatch }" />
              <select :value="accentTheme" class="theme-native-select h-9 min-w-0 flex-1 rounded-lg border border-white/20 bg-white/15 px-2 text-xs font-bold text-white outline-none" @change="handleAccentSelect">
                <option v-for="option in accentThemeOptions" :key="option.value" :value="option.value" class="text-slate-900">{{ option.name }}</option>
              </select>
              <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-white/20 bg-white/15 text-white transition hover:bg-white/25" :title="isDark ? 'Mode terang' : 'Mode gelap'" @click="toggleTheme">
                <SunIcon v-if="isDark" class="h-[18px] w-[18px]" />
                <MoonIcon v-else class="h-[18px] w-[18px]" />
              </button>
              <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-white/20 bg-white/15 text-white transition hover:bg-white/25" :class="windowsMode ? '!bg-white !text-slate-900' : ''" title="Mode Windows" @click="windowsMode = !windowsMode">
                <Squares2X2Icon class="h-[18px] w-[18px]" />
              </button>
            </div>
            <select v-model="mouseEffect" class="theme-native-select mt-2 h-9 w-full rounded-lg border border-white/20 bg-white/15 px-2 text-xs font-bold text-white outline-none">
              <option v-for="option in mouseEffectOptions" :key="`desktop-${option.value}`" :value="option.value">Efek mouse: {{ option.label }}</option>
            </select>
          </div>
        </div>
      </div>

      <div class="relative flex flex-col" :class="windowsMode ? 'w-0 flex-none overflow-visible' : 'w-0 flex-1 overflow-hidden'">
        <header v-if="!windowsMode" class="relative z-20 flex h-20 items-center border-b border-slate-200 bg-white/80 px-4 backdrop-blur-md sm:px-8">
          <button
            type="button"
            class="rounded-xl border border-slate-200 p-2 text-slate-500 hover:bg-slate-100 md:hidden"
            @click="sidebarOpen = true"
          >
            <Bars3CenterLeftIcon class="h-6 w-6" />
          </button>

          <div class="hidden items-center md:flex">
            <button
              type="button"
              class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
              :aria-label="desktopSidebarOpen ? 'Sembunyikan drawer navigasi' : 'Tampilkan drawer navigasi'"
              @click="toggleDesktopSidebar"
            >
              <ChevronDoubleLeftIcon v-if="desktopSidebarOpen" class="h-5 w-5" />
              <ChevronDoubleRightIcon v-else class="h-5 w-5" />
            </button>
            <div class="mx-4 h-8 w-px bg-slate-200" />
          </div>

          <div class="ml-4 flex flex-1 px-4 sm:px-0 md:ml-0">
            <form class="group relative w-full max-w-md" @submit.prevent="submitTopbarSearch">
              <MagnifyingGlassIcon class="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-blue-500" />
              <input
                v-model="topbarSearch"
                type="search"
                placeholder="Cari data..."
                class="h-11 w-full rounded-xl border-transparent pl-12 pr-4 text-sm font-medium transition-all focus:border-transparent focus:ring-2 focus:ring-blue-500"
                :class="isDark ? 'bg-slate-800 text-slate-100 placeholder:text-slate-400 focus:bg-slate-800' : 'bg-slate-100 focus:bg-white'"
                @keydown.enter.prevent="submitTopbarSearch"
              />
              <button
                v-if="topbarSearch"
                type="button"
                class="absolute right-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                @click="clearTopbarSearch"
              >
                <XMarkIcon class="h-4 w-4" />
              </button>
            </form>
          </div>

          <div class="flex items-center space-x-4">
            <button
              type="button"
              class="hidden rounded-xl border p-2.5 transition-all"
              :class="isDark ? 'border-slate-700 text-slate-300 hover:bg-slate-800' : 'border-slate-200 text-slate-500 hover:bg-slate-50'"
              :title="isDark ? 'Gunakan mode terang' : 'Gunakan mode gelap'"
              @click="toggleTheme"
            >
              <SunIcon v-if="isDark" class="h-5 w-5" />
              <MoonIcon v-else class="h-5 w-5" />
            </button>

            <Menu as="div" class="relative hidden">
              <MenuButton
                class="group inline-flex h-11 items-center gap-2 rounded-xl border px-2.5 text-sm font-bold transition-all"
                :class="isDark ? 'border-slate-700 bg-slate-900 text-slate-200 hover:bg-slate-800' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                title="Pilih tema warna"
              >
                <span
                  class="h-6 w-6 rounded-lg shadow-sm ring-2 ring-white transition-transform group-hover:scale-105"
                  :style="{ background: activeAccentTheme.swatch }"
                />
                <SwatchIcon class="h-5 w-5" />
              </MenuButton>

              <transition
                enter-active-class="transition duration-100 ease-out"
                enter-from-class="transform scale-95 opacity-0"
                enter-to-class="transform scale-100 opacity-100"
                leave-active-class="transition duration-75 ease-in"
                leave-from-class="transform scale-100 opacity-100"
                leave-to-class="transform scale-95 opacity-0"
              >
                <MenuItems class="absolute right-0 mt-3 max-h-[calc(100vh-7rem)] w-[min(92vw,760px)] origin-top-right overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-2xl ring-1 ring-black/5 focus:outline-none">
                  <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Tema Warna</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                      Pilih aksen dan gradasi tampilan SIMANIS.
                    </p>
                  </div>

                  <div class="grid gap-2 p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <MenuItem
                      v-for="option in accentThemeOptions"
                      :key="option.value"
                      v-slot="{ active }"
                    >
                      <button
                        type="button"
                        class="flex h-16 w-full items-center gap-2 rounded-md border px-2.5 py-2 text-left transition-all"
                        :class="[
                          accentTheme === option.value
                            ? 'border-fuchsia-400 bg-fuchsia-50 shadow-sm'
                            : active
                              ? 'border-slate-200 bg-slate-50'
                              : 'border-slate-200 bg-white',
                        ]"
                        @click="setAccentTheme(option.value)"
                      >
                        <span
                          class="h-10 w-10 flex-shrink-0 rounded-md shadow-inner ring-1 ring-black/10"
                          :style="{ background: option.swatch }"
                        />
                        <span class="min-w-0 flex-1">
                          <span class="block truncate text-xs font-extrabold text-slate-900">{{ option.name }}</span>
                          <span class="mt-0.5 block truncate text-[10px] font-medium leading-4 text-slate-500">{{ option.description }}</span>
                        </span>
                        <span
                          class="inline-flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full text-[10px] font-black"
                          :class="accentTheme === option.value ? 'bg-fuchsia-500 text-white' : 'bg-slate-200 text-white'"
                        >
                          ✓
                        </span>
                      </button>
                    </MenuItem>
                  </div>
                </MenuItems>
              </transition>
            </Menu>

            <Menu as="div" class="relative hidden sm:block">
              <MenuButton
                class="relative rounded-xl border border-slate-200 p-2.5 text-slate-500 transition-all hover:bg-slate-50"
                @click="loadAllNotifications(true)"
              >
                <BellIcon class="h-5 w-5" />
                <span
                  v-if="notificationBadgeTotal > 0"
                  class="absolute -right-1 -top-1 inline-flex min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
                >
                  {{ notificationBadgeTotal > 99 ? '99+' : notificationBadgeTotal }}
                </span>
              </MenuButton>

              <transition
                enter-active-class="transition duration-100 ease-out"
                enter-from-class="transform scale-95 opacity-0"
                enter-to-class="transform scale-100 opacity-100"
                leave-active-class="transition duration-75 ease-in"
                leave-from-class="transform scale-100 opacity-100"
                leave-to-class="transform scale-95 opacity-0"
              >
                <MenuItems class="absolute right-0 mt-3 w-96 origin-top-right overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 focus:outline-none">
                  <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Notifikasi</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                      {{ notificationBadgeTotal ? `${notificationBadgeTotal} notifikasi aktif` : 'Tidak ada notifikasi baru' }}
                    </p>
                  </div>

                  <div class="max-h-[360px] overflow-y-auto p-2">
                    <div class="space-y-1.5 border-b border-slate-100 pb-3">
                      <p class="px-1 text-[11px] font-bold uppercase tracking-wider text-slate-500">Chat</p>
                      <p
                        v-if="unreadLoading"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500"
                      >
                        Memuat notifikasi chat...
                      </p>
                      <p
                        v-else-if="unreadError"
                        class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700"
                      >
                        {{ unreadError }}
                      </p>
                      <p
                        v-else-if="!unreadConversations.length && !globalUnreadCount"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500"
                      >
                        Tidak ada chat masuk baru.
                      </p>

                      <div v-else class="space-y-1.5">
                        <button
                          v-if="globalUnreadCount > 0"
                          type="button"
                          class="w-full rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-left transition hover:bg-indigo-100"
                          @click="openGlobalChatFromNotification"
                        >
                          <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-xs font-bold text-indigo-900">Chat Umum</p>
                            <span class="rounded-full bg-indigo-600 px-2 py-0.5 text-[10px] font-bold text-white">
                              {{ globalUnreadCount }}
                            </span>
                          </div>
                          <p class="mt-0.5 truncate text-[11px] text-indigo-700">
                            {{ globalUnreadPreview }}
                          </p>
                        </button>

                        <button
                          v-for="conversation in unreadConversations"
                          :key="`notif-${conversation.sender_id}`"
                          type="button"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-left transition hover:bg-slate-50"
                          @click="openChatFromNotification(conversation.sender_id)"
                        >
                          <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-xs font-bold text-slate-800">
                              {{ conversation.sender?.nama_lengkap || 'Karyawan' }}
                            </p>
                            <span class="rounded-full bg-blue-600 px-2 py-0.5 text-[10px] font-bold text-white">
                              {{ conversation.unread_count }}
                            </span>
                          </div>
                          <p class="mt-0.5 truncate text-[11px] text-slate-500">
                            {{ unreadPreview(conversation) }}
                          </p>
                        </button>
                      </div>
                    </div>

                    <div class="mt-3 space-y-1.5">
                      <p class="px-1 text-[11px] font-bold uppercase tracking-wider text-slate-500">Request Download</p>
                      <p
                        v-if="downloadRequestLoading"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500"
                      >
                        Memuat status download...
                      </p>
                      <p
                        v-else-if="downloadRequestError"
                        class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700"
                      >
                        {{ downloadRequestError }}
                      </p>
                      <p
                        v-else-if="!recentDownloadRequests.length"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500"
                      >
                        Belum ada request download.
                      </p>
                      <div v-else class="space-y-1.5">
                        <button
                          v-for="row in recentDownloadRequests"
                          :key="`download-notif-${row.id}`"
                          type="button"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-left transition hover:bg-slate-50"
                          @click="openDownloadRequestFromNotification(row)"
                        >
                          <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-xs font-bold text-slate-800" :title="String(row.file_name || '-')">
                              {{ row.file_name || '-' }}
                            </p>
                            <span
                              class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold"
                              :class="downloadStatusBadgeClass(row.status)"
                            >
                              {{ downloadStatusLabel(row.status) }}
                            </span>
                          </div>
                          <p class="mt-0.5 truncate text-[11px] text-slate-500">
                            {{ resolveDownloadModuleLabel(row.module_path) }} • {{ String(row.created_at || row.approved_at || '-') }}
                          </p>
                        </button>
                      </div>
                    </div>
                  </div>
                </MenuItems>
              </transition>
            </Menu>

            <Menu as="div" class="relative">
              <MenuButton class="flex items-center rounded-2xl border border-transparent p-1 pr-3 transition-all hover:border-slate-200 hover:bg-slate-50">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 shadow-sm ring-2 ring-white">
                  <img
                    v-if="userPhotoUrl"
                    :src="userPhotoUrl"
                    alt="Foto profil"
                    class="h-9 w-9 rounded-xl object-cover"
                    @error="handleAvatarError"
                  />
                  <UserCircleIcon v-else class="h-7 w-7 text-slate-500" />
                </div>
                <div class="ml-3 hidden flex-col text-left sm:flex">
                  <span class="line-clamp-1 text-xs font-bold text-slate-800">{{ displayUserName }}</span>
                  <span class="text-[10px] font-medium text-slate-500">{{ displayUserLevel }}</span>
                </div>
                <ChevronDownIcon class="ml-2 hidden h-4 w-4 text-slate-400 sm:block" />
              </MenuButton>

              <transition
                enter-active-class="transition duration-100 ease-out"
                enter-from-class="transform scale-95 opacity-0"
                enter-to-class="transform scale-100 opacity-100"
                leave-active-class="transition duration-75 ease-in"
                leave-from-class="transform scale-100 opacity-100"
                leave-to-class="transform scale-95 opacity-0"
              >
                <MenuItems class="absolute right-0 mt-3 max-h-[calc(100vh-7rem)] w-80 origin-top-right overflow-y-auto rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 focus:outline-none">
                  <div class="border-b border-slate-100 bg-slate-50 px-5 py-4">
                    <div class="mb-3 flex items-center gap-3">
                      <div class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl bg-slate-100">
                        <img
                          v-if="userPhotoUrl"
                          :src="userPhotoUrl"
                          alt="Foto profil"
                          class="h-10 w-10 object-cover"
                          @error="handleAvatarError"
                        />
                        <UserCircleIcon v-else class="h-7 w-7 text-slate-500" />
                      </div>
                      <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-900">{{ displayUserName }}</p>
                        <p class="truncate text-xs text-slate-500">{{ user?.email || '-' }}</p>
                      </div>
                    </div>
                    <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Akun Saya</p>
                    <p class="mt-1 text-xs text-slate-500">{{ displayUserLevel }}</p>
                  </div>

                  <div class="p-2">
                    <div v-for="item in footerNavigation" :key="`profile-${item.name}`" class="border-b border-slate-100 py-1.5 last:border-b-0">
                      <div class="flex items-center gap-2 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        <component :is="item.icon" class="h-4 w-4" />
                        <span>{{ item.name }}</span>
                      </div>
                      <MenuItem v-for="subItem in item.children" :key="subItem.href" v-slot="{ active }">
                        <NuxtLink
                          :to="subItem.href"
                          class="flex items-center rounded-lg px-3 py-2 text-xs font-semibold transition"
                          :class="isActive(subItem.href) ? 'bg-blue-50 text-blue-700' : active ? 'bg-slate-50 text-slate-900' : 'text-slate-600'"
                        >
                          <component :is="subItem.icon" class="mr-2.5 h-4 w-4 shrink-0" />
                          <span>{{ subItem.name }}</span>
                        </NuxtLink>
                      </MenuItem>
                    </div>
                    <MenuItem v-slot="{ active }">
                      <button
                        type="button"
                        class="flex w-full items-center rounded-xl px-4 py-2.5 text-sm font-bold transition-all"
                        :class="[active ? 'bg-red-50 text-red-600' : 'text-slate-700']"
                        @click="handleLogout"
                      >
                        <ArrowRightOnRectangleIcon class="mr-3 h-5 w-5" />
                        {{ loggingOut ? 'Keluar...' : 'Keluar' }}
                      </button>
                    </MenuItem>
                  </div>
                </MenuItems>
              </transition>
            </Menu>
          </div>
        </header>

        <main v-if="!windowsMode" class="dashboard-main relative flex-1 overflow-y-auto transition-colors duration-300">
          <div class="windows-motion-bg pointer-events-none fixed inset-y-20 right-0 overflow-hidden" :class="desktopSidebarOpen ? 'left-64' : 'left-0'" aria-hidden="true">
            <div class="windows-moving-grid absolute inset-0" />
            <span v-for="shape in windowsBackgroundShapes" :key="`dashboard-shape-${shape.id}`" class="windows-floating-shape absolute" :style="shape.style" />
          </div>
          <div
            class="dashboard-page-container relative z-10 mx-auto px-4 py-8 sm:px-8"
            :class="route.path === '/ppat-rekanan' ? 'w-full max-w-none' : 'max-w-7xl'"
          >
            <slot />
          </div>
        </main>

        <div class="pointer-events-none fixed right-5 top-24 z-50 flex w-[340px] flex-col gap-2">
          <transition-group
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="translate-y-1 opacity-0"
          >
            <div
              v-for="toast in chatToasts"
              :key="toast.id"
              class="pointer-events-auto rounded-xl border border-slate-200 bg-white p-3 shadow-lg"
            >
              <div class="flex items-start justify-between gap-2">
                <button
                  type="button"
                  class="min-w-0 text-left"
                  @click="openToastTarget(toast)"
                >
                  <p class="truncate text-xs font-bold uppercase tracking-wide text-slate-500">Chat Masuk</p>
                  <p class="mt-0.5 truncate text-sm font-semibold text-slate-900">{{ toast.title }}</p>
                  <p class="mt-1 line-clamp-2 text-xs text-slate-600">{{ toast.message }}</p>
                </button>
                <button
                  type="button"
                  class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50"
                  @click="removeToast(toast.id)"
                >
                  <XMarkIcon class="h-4 w-4" />
                </button>
              </div>
            </div>
          </transition-group>
        </div>

        <div class="fixed bottom-6 right-6 z-40 flex flex-col items-end">
          <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform translate-y-8 opacity-0 scale-95"
            enter-to-class="transform translate-y-0 opacity-100 scale-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100 scale-100"
            leave-to-class="transform translate-y-8 opacity-0 scale-95"
          >
            <div
              v-if="showChatBalloon"
              class="mb-4 flex h-[500px] w-80 flex-col overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-2xl sm:w-96"
            >
              <div class="flex items-center justify-between bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-800 p-5 shadow-lg">
                <div class="flex items-center space-x-3">
                  <div class="relative">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl border border-white/30 bg-white/20 backdrop-blur-md">
                      <AcademicCapIcon class="h-6 w-6 text-white" />
                    </div>
                    <span class="absolute -bottom-1 -right-1 h-3.5 w-3.5 rounded-full border-2 border-white bg-green-400"></span>
                  </div>
                  <div>
                    <p class="text-sm font-extrabold text-white">Chat Internal</p>
                    <p class="text-[10px] font-bold uppercase tracking-tighter text-blue-100">Komunikasi Karyawan</p>
                  </div>
                </div>
                <button class="rounded-xl bg-white/10 p-1.5 text-white transition-all hover:bg-white/20" @click="showChatBalloon = false">
                  <XMarkIcon class="h-5 w-5" />
                </button>
              </div>

              <div class="border-b border-slate-100 bg-white px-4 py-3">
                <label class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Ruang Chat</label>
                <select
                  :value="selectedChatValue"
                  class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                  @change="selectChatContact"
                >
                  <option value="global">Semua Karyawan (Chat Umum)</option>
                  <option v-for="contact in chatContacts" :key="contact.id" :value="`direct:${contact.id}`">
                    {{ chatContactOptionLabel(contact) }}
                  </option>
                </select>
                <p class="mt-1.5 flex items-center justify-between gap-2 text-[10px] font-semibold text-slate-500">
                  <span class="truncate">
                    Mengobrol di:
                    <span class="font-bold text-slate-700">{{ activeChatLabel }}</span>
                  </span>
                  <span
                    class="shrink-0 rounded-full px-2 py-0.5 font-extrabold"
                    :class="presenceConnected ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400'"
                  >
                    {{ presenceConnected ? `${onlineContactsCount} online` : 'offline' }}
                  </span>
                </p>
              </div>

              <div ref="chatContainer" class="flex-1 space-y-4 overflow-y-auto bg-slate-50/50 p-5">
                <div
                  v-if="chatLoading"
                  class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-semibold text-slate-500"
                >
                  Memuat percakapan...
                </div>
                <div
                  v-else-if="chatError"
                  class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700"
                >
                  {{ chatError }}
                </div>
                <div
                  v-else-if="!chatMessages.length"
                  class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-semibold text-slate-500"
                >
                  Belum ada pesan. Mulai percakapan di ruang {{ activeChatLabel }}.
                </div>
                <div v-else>
                  <div
                    v-for="msg in chatMessages"
                    :key="msg.id"
                    class="mb-3 flex"
                    :class="isOwnChatMessage(msg) ? 'justify-end' : 'justify-start'"
                  >
                    <div
                      class="max-w-[84%] rounded-2xl p-3 text-xs font-medium shadow-sm"
                      :class="isOwnChatMessage(msg) ? 'rounded-tr-none bg-blue-600 text-white' : 'rounded-tl-none border border-slate-100 bg-white text-slate-700'"
                    >
                      <p
                        v-if="!isOwnChatMessage(msg)"
                        class="mb-1 text-[10px] font-bold uppercase tracking-wide text-slate-400"
                      >
                        {{ msg.sender?.nama_lengkap || 'Karyawan' }}
                      </p>
                      <p class="whitespace-pre-wrap break-words">{{ msg.message }}</p>
                      <div
                        v-if="msg.attachment"
                        class="mt-2 overflow-hidden rounded-xl border"
                        :class="isOwnChatMessage(msg) ? 'border-white/40 bg-white/10' : 'border-slate-200 bg-slate-50'"
                      >
                        <a
                          v-if="msg.attachment.is_image"
                          :href="msg.attachment.url"
                          target="_blank"
                          class="block"
                        >
                          <img :src="msg.attachment.url" :alt="msg.attachment.name || 'Lampiran gambar'" class="max-h-52 w-full object-cover" />
                        </a>
                        <a
                          v-else
                          :href="msg.attachment.url"
                          target="_blank"
                          class="flex items-center justify-between gap-2 px-3 py-2 text-[11px] font-semibold transition hover:opacity-90"
                          :class="isOwnChatMessage(msg) ? 'text-white' : 'text-blue-700'"
                        >
                          <span class="truncate">Lampiran: {{ msg.attachment.name || 'Dokumen' }}</span>
                          <span>Buka</span>
                        </a>
                      </div>
                      <div
                        class="mt-1 flex items-center gap-2 text-[9px] opacity-70"
                        :class="isOwnChatMessage(msg) ? 'justify-end' : 'justify-start'"
                      >
                        <span>{{ formatChatTime(msg.created_at) }}</span>
                        <span
                          v-if="isOwnChatMessage(msg) && msg.scope === 'direct'"
                          class="rounded-full px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide"
                          :class="msg.is_read ? 'bg-emerald-200/80 text-emerald-900' : 'bg-slate-200/90 text-slate-700'"
                        >
                          {{ msg.is_read ? 'Dibaca' : 'Terkirim' }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="relative border-t border-slate-100 bg-white p-4">
                <input
                  ref="chatFileInput"
                  type="file"
                  class="hidden"
                  accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.txt,.csv,.zip,.rar,.7z,.exe"
                  @change="onChatFileChange"
                />

                <div
                  v-if="selectedAttachment"
                  class="mb-2 flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2"
                >
                  <p class="truncate text-[11px] font-semibold text-slate-700" :title="selectedAttachment.name">
                    {{ selectedAttachment.name }}
                  </p>
                  <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-2 py-1 text-[10px] font-bold text-slate-600 transition hover:bg-white"
                    @click="clearSelectedAttachment"
                  >
                    Hapus
                  </button>
                </div>

                <div class="relative flex items-center space-x-2">
                  <button
                    type="button"
                    class="inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!hasChatTarget || chatSending"
                    @click="openFilePicker"
                  >
                    <PaperClipIcon class="h-5 w-5" />
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!hasChatTarget || chatSending"
                    @click="toggleEmojiPicker"
                  >
                    <FaceSmileIcon class="h-5 w-5" />
                  </button>
                  <input
                    ref="chatInputRef"
                    v-model="userInput"
                    type="text"
                    :placeholder="hasChatTarget ? `Ketik pesan ke ${activeChatLabel}...` : 'Pilih ruang chat dulu...'"
                    class="h-12 flex-1 rounded-2xl border-none bg-slate-50 px-4 text-xs font-bold transition-all focus:ring-2 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-70"
                    :disabled="!hasChatTarget || chatSending"
                    @keyup.enter="handleSendMessage"
                  />
                  <button
                    class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-md shadow-blue-200 transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!canSendChat"
                    @click="handleSendMessage"
                  >
                    <PaperAirplaneIcon class="h-5 w-5" />
                  </button>
                </div>

                <div v-if="showEmojiPicker" class="absolute bottom-16 left-16 z-20">
                  <emoji-picker
                    class="overflow-hidden rounded-2xl border border-slate-200 shadow-xl"
                    @emoji-click="handleEmojiPick"
                  />
                </div>
              </div>
            </div>
          </transition>

          <button
            class="simanis-brand-mark group relative flex h-16 w-16 items-center justify-center overflow-hidden rounded-[1.5rem] bg-gradient-to-tr from-blue-600 to-indigo-700 text-white shadow-2xl shadow-blue-300 transition-all hover:scale-110 active:scale-95"
            @click="toggleChat"
          >
            <div class="absolute inset-0 translate-y-full bg-white/20 transition-transform duration-300 group-hover:translate-y-0"></div>
            <ChatBubbleLeftEllipsisIcon v-if="!showChatBalloon" class="relative z-10 h-8 w-8" />
            <XMarkIcon v-else class="relative z-10 h-8 w-8" />
          </button>
        </div>
      </div>
    </div>
  </ClientOnly>
</template>

<script setup lang="ts">
import type { Component } from 'vue'
import type { AccentTheme } from '~/composables/useThemeMode'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { navigationSections } from '~/data/navigation'
import { iconMap } from '~/utils/icons'

import {
  Dialog,
  DialogPanel,
  Disclosure,
  DisclosureButton,
  DisclosurePanel,
  Menu,
  MenuButton,
  MenuItem,
  MenuItems,
  TransitionChild,
  TransitionRoot,
} from '@headlessui/vue'

import {
  AcademicCapIcon,
  ArrowRightOnRectangleIcon,
  Bars3CenterLeftIcon,
  BellIcon,
  ChatBubbleLeftEllipsisIcon,
  ChevronDoubleLeftIcon,
  ChevronDoubleRightIcon,
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  Cog6ToothIcon,
  FaceSmileIcon,
  MagnifyingGlassIcon,
  MoonIcon,
  PaperClipIcon,
  PaperAirplaneIcon,
  RectangleStackIcon,
  Squares2X2Icon,
  SwatchIcon,
  SunIcon,
  UserCircleIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

type NavigationItem = {
  name: string
  href: string
  icon: Component
}

type NavigationGroup = {
  name: string
  icon: Component
  children: NavigationItem[]
}

type MouseEffect = 'off' | 'sparkle' | 'trail'
type WindowsLauncherDirection = 'forward' | 'back'

type ApiEnvelope<T> = {
  status?: boolean
  message?: string
  data?: T
}

type ChatContact = {
  id: number
  id_user?: string | number
  nama_lengkap?: string
  level_user?: string
  foto?: string | null
}

type ChatRecord = {
  id: number
  scope?: 'direct' | 'global'
  sender_id: number
  receiver_id: number | null
  message: string
  is_read?: boolean
  read_at?: string | null
  created_at: string
  updated_at?: string | null
  sender?: ChatContact | null
  receiver?: ChatContact | null
  attachment?: {
    path?: string
    name?: string
    mime?: string
    size?: number
    url?: string
    is_image?: boolean
  } | null
}

type ChatMessagePayload = {
  scope?: 'direct' | 'global'
  receiver_id: number | null
  current_user_id: number
  messages: ChatRecord[]
}

type ChatUnreadConversation = {
  sender_id: number
  unread_count: number
  latest_at?: string | null
  sender?: ChatContact | null
  latest_message?: {
    message?: string | null
    attachment_name?: string | null
    has_attachment?: boolean
  } | null
}

type ChatUnreadSummary = {
  total_unread: number
  direct_unread_total?: number
  global_unread_count?: number
  global_latest_message?: ChatRecord | null
  conversations: ChatUnreadConversation[]
}

type ChatScope = 'direct' | 'global'

type ChatToast = {
  id: number
  title: string
  message: string
  target: 'global' | 'direct'
  senderId?: number
}

type PresenceUser = {
  id_user?: string | number
  nama_lengkap?: string
  level_user?: string
  connections?: number
  last_seen_at?: string
}

type PresencePayload = {
  type?: string
  users?: PresenceUser[]
  online_id_users?: Array<string | number>
}

type DownloadRequestRow = {
  id?: number
  module_path?: string
  row_id?: string
  file_name?: string
  status?: 'pending' | 'approved' | 'rejected' | string
  approved_at?: string | null
  created_at?: string | null
  note?: string | null
}

const sectionIcons: Record<string, Component> = {
  'Operasional Kantor': iconMap.briefcase,
  'Informasi Pekerjaan': iconMap.queue,
  'Buku Reportorium': iconMap.document,
  'PPAT Rekanan': iconMap.bank,
  'Buku Surat': iconMap.clipboard,
  'Tanda Terima': iconMap.report,
  'Data Klien': iconMap.users,
  'Kontrol Admin': iconMap.settings,
  Pengaturan: iconMap.settings,
}

const route = useRoute()
const { user, clearSession } = useSession()
const business = useLegacyBusiness()
const { activeAccentTheme, accentTheme, accentThemeOptions, isDark, setAccentTheme, toggleTheme } = useThemeMode()

const DESKTOP_SIDEBAR_STORAGE_KEY = 'simanis.desktop-sidebar-open'
const WINDOWS_MODE_STORAGE_KEY = 'simanis.windows-mode'
const MOUSE_EFFECT_STORAGE_KEY = 'simanis.mouse-effect'
const sidebarOpen = ref(false)
const desktopSidebarOpen = ref(true)
const windowsMode = ref(false)
const windowsLauncherVisible = ref(true)
const selectedWindowsGroup = ref<NavigationGroup | null>(null)
const windowsLauncherDirection = ref<WindowsLauncherDirection>('forward')
const windowsWorkspaceDirection = ref<'forward' | 'back'>('forward')
const mouseEffect = ref<MouseEffect>('sparkle')
const mouseEffectOptions: Array<{ value: MouseEffect; label: string }> = [
  { value: 'off', label: 'Mati' },
  { value: 'sparkle', label: 'Kilau' },
  { value: 'trail', label: 'Jejak' },
]
const windowsSparkles = ref<Array<{ id: number; x: number; y: number }>>([])
const windowsBackgroundShapes = [
  { id: 1, style: { left: '7%', top: '18%', animationDelay: '-2s', animationDuration: '15s' } },
  { id: 2, style: { left: '24%', top: '72%', animationDelay: '-7s', animationDuration: '18s' } },
  { id: 3, style: { left: '48%', top: '30%', animationDelay: '-11s', animationDuration: '21s' } },
  { id: 4, style: { left: '71%', top: '76%', animationDelay: '-4s', animationDuration: '17s' } },
  { id: 5, style: { left: '88%', top: '20%', animationDelay: '-9s', animationDuration: '20s' } },
]
let windowsSparkleId = 0
let lastWindowsSparkleAt = 0
const loggingOut = ref(false)
const showChatBalloon = ref(false)
const userInput = ref('')
const topbarSearch = ref('')
const chatContainer = ref<HTMLElement | null>(null)
const chatInputRef = ref<HTMLInputElement | null>(null)
const chatFileInput = ref<HTMLInputElement | null>(null)
const avatarErrored = ref(false)
const chatLoading = ref(false)
const chatSending = ref(false)
const chatError = ref('')
const chatContacts = ref<ChatContact[]>([])
const chatScope = ref<ChatScope>('global')
const selectedChatUserId = ref<number | null>(null)
const chatMessages = ref<ChatRecord[]>([])
const selectedAttachment = ref<File | null>(null)
const showEmojiPicker = ref(false)
const currentAuthUserId = ref<number | null>(null)
const presenceConnected = ref(false)
const onlineChatIdUsers = ref<Set<string>>(new Set())
let chatPollingTimer: ReturnType<typeof setInterval> | null = null
let downloadPollingTimer: ReturnType<typeof setInterval> | null = null
let presenceSocket: WebSocket | null = null
let presenceReconnectTimer: ReturnType<typeof setTimeout> | null = null
let presenceHeartbeatTimer: ReturnType<typeof setInterval> | null = null
const unreadLoading = ref(false)
const unreadError = ref('')
const unreadSummary = ref<ChatUnreadSummary>({
  total_unread: 0,
  conversations: [],
})
const downloadRequestLoading = ref(false)
const downloadRequestError = ref('')
const downloadRequests = ref<DownloadRequestRow[]>([])
const downloadSnapshotInitialized = ref(false)
const previousDownloadStatusMap = ref<Record<number, string>>({})
const chatToasts = ref<ChatToast[]>([])
const notificationPermission = ref<NotificationPermission>('default')
const baseDocumentTitle = ref('SIMANIS')
let toastCounter = 0

const toggleDesktopSidebar = () => {
  desktopSidebarOpen.value = !desktopSidebarOpen.value
}

const handleAccentSelect = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value as AccentTheme
  setAccentTheme(value)
}

const isAdminRole = computed(() => {
  const role = String(user.value?.level_user || '').trim().toLowerCase()
  return role === 'admin' || role === 'super admin' || role === 'superadmin'
})

const navigation = computed<NavigationGroup[]>(() =>
  navigationSections
    .map(section => ({
      name: section.title,
      icon: sectionIcons[section.title] || iconMap.fallback,
      children: section.items
        .filter(item => !item.adminOnly || isAdminRole.value)
        .map(item => ({
          name: item.title,
          href: item.path,
          icon: iconMap[item.icon] || iconMap.fallback,
        })),
    }))
    .filter(section => section.children.length > 0),
)

const displayUserName = computed(() => user.value?.name || user.value?.email || 'Operator')
const displayUserLevel = computed(() => user.value?.level_user || 'User')
const userPhotoUrl = computed(() => {
  const fileName = String(user.value?.foto || '').trim()
  if (!fileName || avatarErrored.value) {
    return ''
  }
  return business.assets.foto(fileName)
})

const selectedChatContactName = computed(() =>
  chatContacts.value.find(contact => contact.id === selectedChatUserId.value)?.nama_lengkap || 'karyawan',
)

const isGlobalChat = computed(() => chatScope.value === 'global')
const selectedChatValue = computed(() =>
  isGlobalChat.value ? 'global' : (selectedChatUserId.value ? `direct:${selectedChatUserId.value}` : ''),
)
const hasChatTarget = computed(() => isGlobalChat.value || Boolean(selectedChatUserId.value))
const activeChatLabel = computed(() => (isGlobalChat.value ? 'Semua Karyawan' : selectedChatContactName.value))
const onlineContactsCount = computed(() => chatContacts.value.filter(contact => isChatContactOnline(contact)).length)

const unreadTotal = computed(() => Number(unreadSummary.value.total_unread || 0))
const unreadConversations = computed(() =>
  Array.isArray(unreadSummary.value.conversations) ? unreadSummary.value.conversations : [],
)
const globalUnreadCount = computed(() => Number(unreadSummary.value.global_unread_count || 0))
const globalUnreadLatestMessage = computed(() => unreadSummary.value.global_latest_message || null)
const globalUnreadPreview = computed(() => {
  const latest = globalUnreadLatestMessage.value
  if (!latest) {
    return 'Ada pesan baru di chat umum.'
  }

  const text = String(latest.message || '').trim()
  if (text) {
    return text
  }

  const attachmentName = String(latest.attachment?.name || '').trim()
  if (attachmentName) {
    return `Lampiran: ${attachmentName}`
  }

  return 'Ada pesan baru di chat umum.'
})

const recentDownloadRequests = computed(() => downloadRequests.value.slice(0, 8))
const pendingDownloadCount = computed(() =>
  downloadRequests.value.filter(item => String(item.status || '').toLowerCase() === 'pending').length,
)
const notificationBadgeTotal = computed(() => unreadTotal.value + pendingDownloadCount.value)

const canSendChat = computed(() =>
  Boolean(
    hasChatTarget.value
    && (userInput.value.trim() || selectedAttachment.value)
    && !chatSending.value,
  ),
)

const normalizePathOnly = (path: string) => String(path || '').split('?')[0] || path
const isActive = (path: string) => {
  const target = String(path || '')
  if (target.includes('?')) {
    return route.fullPath === target
  }
  return route.path === normalizePathOnly(target)
}
const isGroupActive = (group: NavigationGroup) => group.children.some(child => isActive(child.href))

const submitTopbarSearch = async () => {
  const query = topbarSearch.value.trim()
  await navigateTo({
    path: '/dashboard',
    query: query ? { view: 'documents', q: query } : { view: 'documents' },
  })
}

const submitWindowsSearch = async () => {
  windowsLauncherVisible.value = false
  await submitTopbarSearch()
}

const clearTopbarSearch = async () => {
  topbarSearch.value = ''
  if (route.path === '/dashboard' && route.query.view === 'documents') {
    await navigateTo({ path: '/dashboard', query: { view: 'documents' } })
  }
}

const toData = <T>(payload: unknown, fallback: T): T => {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const rawData = (payload as ApiEnvelope<unknown>).data
    if (typeof rawData === 'string') {
      const trimmed = rawData.trim()
      if (
        (trimmed.startsWith('{') && trimmed.endsWith('}'))
        || (trimmed.startsWith('[') && trimmed.endsWith(']'))
      ) {
        try {
          return JSON.parse(trimmed) as T
        } catch {
          return fallback
        }
      }
    }

    return (rawData ?? fallback) as T
  }
  return fallback
}

const toNumber = (value: unknown): number | null => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

const normalizePresenceId = (value: unknown) => String(value ?? '').trim()

const isChatContactOnline = (contact: ChatContact) => {
  const legacyId = normalizePresenceId(contact.id_user)
  return Boolean(legacyId && onlineChatIdUsers.value.has(legacyId))
}

const chatContactOptionLabel = (contact: ChatContact) => {
  const name = String(contact.nama_lengkap || 'Karyawan').trim()
  const role = String(contact.level_user || '-').trim()
  return `${isChatContactOnline(contact) ? 'Online - ' : ''}${name} (${role})`
}

const unreadPreview = (conversation: ChatUnreadConversation) => {
  const previewText = String(conversation.latest_message?.message || '').trim()
  if (previewText) return previewText

  const attachmentName = String(conversation.latest_message?.attachment_name || '').trim()
  if (attachmentName) return `Lampiran: ${attachmentName}`

  return 'Pesan baru'
}

const downloadModuleLabelMap: Record<string, string> = {
  '/buku_akta': 'Buku Akta Notaris',
  '/buku_legalisasi': 'Buku Legalisasi',
  '/buku_waarmerking': 'Buku Waarmerking',
  '/buku_ppat': 'Buku PPAT',
  '/buku_surat_notaris': 'Surat Notaris',
  '/buku_surat_ppat': 'Surat PPAT',
  '/tanda_terima': 'Tanda Terima Keluar',
  '/tanda_terima_masuk': 'Tanda Terima Masuk',
  '/pencarian-dokumen': 'Pencarian Dokumen',
}

const downloadStatusBadgeClass = (status: unknown) => {
  const value = String(status || '').toLowerCase()
  if (value === 'approved') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (value === 'rejected') return 'border-red-200 bg-red-50 text-red-700'
  return 'border-amber-200 bg-amber-50 text-amber-700'
}

const downloadStatusLabel = (status: unknown) => {
  const value = String(status || '').toLowerCase()
  if (value === 'approved') return 'Approved'
  if (value === 'rejected') return 'Rejected'
  return 'Pending'
}

const resolveDownloadModuleLabel = (modulePath: unknown) =>
  downloadModuleLabelMap[String(modulePath || '')] || String(modulePath || '-')

const stripUnreadPrefix = (title: string) => title.replace(/^\(\d+\+?\)\s*/, '').trim()

const applyUnreadDocumentTitle = () => {
  if (!import.meta.client) return
  const cleanBase = stripUnreadPrefix(baseDocumentTitle.value || window.document.title || 'SIMANIS') || 'SIMANIS'
  const count = notificationBadgeTotal.value
  if (count > 0) {
    window.document.title = `(${count > 99 ? '99+' : count}) ${cleanBase}`
    return
  }
  window.document.title = cleanBase
}

const syncBaseDocumentTitle = () => {
  if (!import.meta.client) return
  baseDocumentTitle.value = stripUnreadPrefix(window.document.title || 'SIMANIS') || 'SIMANIS'
  applyUnreadDocumentTitle()
}

const removeToast = (id: number) => {
  chatToasts.value = chatToasts.value.filter(item => item.id !== id)
}

const pushChatToast = (toast: Omit<ChatToast, 'id'>) => {
  const id = ++toastCounter
  chatToasts.value = [{ id, ...toast }, ...chatToasts.value].slice(0, 4)
  setTimeout(() => removeToast(id), 6500)
}

const openToastTarget = (toast: ChatToast) => {
  removeToast(toast.id)
  if (toast.target === 'global') {
    openGlobalChatFromNotification()
    return
  }

  if (toast.senderId) {
    openChatFromNotification(toast.senderId)
  }
}

const ensureNotificationPermission = async () => {
  if (!import.meta.client || typeof window.Notification === 'undefined') return
  notificationPermission.value = window.Notification.permission
  if (notificationPermission.value !== 'default') return

  try {
    notificationPermission.value = await window.Notification.requestPermission()
  } catch {
    notificationPermission.value = window.Notification.permission
  }
}

const pushBrowserNotification = (title: string, message: string, onClick: () => void) => {
  if (!import.meta.client || typeof window.Notification === 'undefined') return
  if (notificationPermission.value !== 'granted') return

  try {
    const notification = new window.Notification(title, {
      body: message,
      tag: `chat-${Date.now()}-${Math.random()}`,
    })
    notification.onclick = () => {
      window.focus()
      onClick()
      notification.close()
    }
  } catch {
    // Abaikan bila browser menolak notifikasi sistem.
  }
}

const buildDownloadStatusMap = (rows: DownloadRequestRow[]) => {
  const map: Record<number, string> = {}
  rows.forEach((row) => {
    const id = Number(row.id)
    if (!Number.isFinite(id)) return
    map[id] = String(row.status || '').toLowerCase()
  })
  return map
}

const openDownloadRequestFromNotification = async (row: DownloadRequestRow) => {
  const modulePath = String(row.module_path || '').trim()
  if (!modulePath) {
    await navigateTo('/pencarian-dokumen')
    return
  }
  await navigateTo(modulePath)
}

const handleIncomingDownloadNotifications = (rows: DownloadRequestRow[]) => {
  const nextMap = buildDownloadStatusMap(rows)
  if (!downloadSnapshotInitialized.value) {
    previousDownloadStatusMap.value = nextMap
    downloadSnapshotInitialized.value = true
    return
  }

  const previous = previousDownloadStatusMap.value
  rows.forEach((row) => {
    const id = Number(row.id)
    if (!Number.isFinite(id)) return

    const currentStatus = String(row.status || '').toLowerCase()
    const previousStatus = String(previous[id] || '').toLowerCase()
    if (!previousStatus || previousStatus === currentStatus) return

    if (currentStatus === 'approved' || currentStatus === 'rejected') {
      const fileName = String(row.file_name || 'dokumen')
      const message = currentStatus === 'approved'
        ? `Request download "${fileName}" sudah disetujui admin.`
        : `Request download "${fileName}" ditolak admin.`
      pushBrowserNotification('SIMANIS - Persetujuan Download', message, () => {
        void openDownloadRequestFromNotification(row)
      })
    }
  })

  previousDownloadStatusMap.value = nextMap
}

const formatChatTime = (value: unknown) => {
  const parsed = new Date(String(value || ''))
  if (Number.isNaN(parsed.getTime())) return '--:--'
  return parsed.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

const scrollToBottom = () => {
  nextTick(() => {
    if (chatContainer.value) {
      chatContainer.value.scrollTop = chatContainer.value.scrollHeight
    }
  })
}

const isOwnChatMessage = (message: ChatRecord) => {
  const senderLegacyId = String(message.sender?.id_user ?? '').trim()
  const currentLegacyId = String(user.value?.id_user ?? '').trim()

  if (senderLegacyId && currentLegacyId) {
    return senderLegacyId === currentLegacyId
  }

  if (currentAuthUserId.value !== null) {
    return Number(message.sender_id) === currentAuthUserId.value
  }

  return false
}

const loadChatContacts = async () => {
  try {
    const response = await business.chat.getContacts() as ApiEnvelope<ChatContact[]>
    const contacts = toData(response, [])
    chatContacts.value = Array.isArray(contacts) ? contacts : []
    chatError.value = ''

    if (!isGlobalChat.value && !selectedChatUserId.value && chatContacts.value.length) {
      selectedChatUserId.value = chatContacts.value[0]?.id || null
    }
  } catch (error) {
    chatError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat daftar karyawan.'
  }
}

const loadDownloadRequests = async (silent = false) => {
  if (!silent) {
    downloadRequestLoading.value = true
  }

  try {
    const response = await business.documentAccess.listMyRequests({
      status: 'all',
      limit: 120,
    }) as ApiEnvelope<DownloadRequestRow[]>
    const payload = toData<DownloadRequestRow[]>(response, [])
    const rows = Array.isArray(payload) ? payload : []
    handleIncomingDownloadNotifications(rows)
    downloadRequests.value = rows
    downloadRequestError.value = ''
  } catch (error) {
    downloadRequestError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat notifikasi download.'
  } finally {
    if (!silent) {
      downloadRequestLoading.value = false
    }
  }
}

const loadAllNotifications = async (silent = false) => {
  await loadDownloadRequests(silent)
}

const loadChatMessages = async (silent = false) => {
  if (!hasChatTarget.value) {
    chatMessages.value = []
    return
  }

  if (!silent) {
    chatLoading.value = true
  }

  try {
    const query: Record<string, number | string> = {
      scope: chatScope.value,
      limit: 200,
    }
    if (!isGlobalChat.value && selectedChatUserId.value) {
      query.receiver_id = selectedChatUserId.value
    }

    const response = await business.chat.getMessages(query) as ApiEnvelope<ChatMessagePayload>

    const payload = toData<ChatMessagePayload>(response, {
      scope: chatScope.value,
      receiver_id: selectedChatUserId.value,
      current_user_id: 0,
      messages: [],
    })

    currentAuthUserId.value = toNumber(payload.current_user_id)
    chatMessages.value = Array.isArray(payload.messages) ? payload.messages : []
    chatError.value = ''
    scrollToBottom()
  } catch (error) {
    chatError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat percakapan.'
  } finally {
    if (!silent) {
      chatLoading.value = false
    }
  }
}

const stopChatPolling = () => {
  if (chatPollingTimer) {
    clearInterval(chatPollingTimer)
    chatPollingTimer = null
  }
}

const startChatPolling = () => {
  stopChatPolling()
  chatPollingTimer = setInterval(() => {
    if (!showChatBalloon.value || !hasChatTarget.value) {
      return
    }
    void loadChatMessages(true)
  }, 5000)
}

const stopDownloadPolling = () => {
  if (downloadPollingTimer) {
    clearInterval(downloadPollingTimer)
    downloadPollingTimer = null
  }
}

const startDownloadPolling = () => {
  stopDownloadPolling()
  downloadPollingTimer = setInterval(() => {
    void loadDownloadRequests(true)
  }, 9000)
}

const presenceWsUrl = () => {
  if (!import.meta.client) return ''
  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
  return `${protocol}//${window.location.hostname || '127.0.0.1'}:8789`
}

const sendPresenceHello = () => {
  if (!presenceSocket || presenceSocket.readyState !== WebSocket.OPEN || !user.value) return

  presenceSocket.send(JSON.stringify({
    type: 'hello',
    user: {
      id_user: user.value.id_user,
      nama_lengkap: user.value.name || user.value.email,
      level_user: user.value.level_user,
    },
  }))
}

const stopPresenceSocket = () => {
  if (presenceReconnectTimer) {
    clearTimeout(presenceReconnectTimer)
    presenceReconnectTimer = null
  }
  if (presenceHeartbeatTimer) {
    clearInterval(presenceHeartbeatTimer)
    presenceHeartbeatTimer = null
  }
  if (presenceSocket) {
    presenceSocket.onopen = null
    presenceSocket.onmessage = null
    presenceSocket.onclose = null
    presenceSocket.onerror = null
    try {
      presenceSocket.close()
    } catch {
      // Abaikan koneksi yang sudah tertutup.
    }
    presenceSocket = null
  }
  presenceConnected.value = false
  onlineChatIdUsers.value = new Set()
}

const schedulePresenceReconnect = () => {
  if (!import.meta.client || !user.value || presenceReconnectTimer) return
  presenceReconnectTimer = setTimeout(() => {
    presenceReconnectTimer = null
    connectPresenceSocket()
  }, 3000)
}

const handlePresenceMessage = (event: MessageEvent) => {
  let payload: PresencePayload
  try {
    payload = JSON.parse(String(event.data || '{}')) as PresencePayload
  } catch {
    return
  }

  if (payload.type !== 'presence') return

  const ids = Array.isArray(payload.online_id_users)
    ? payload.online_id_users
    : (payload.users || []).map(item => item.id_user)

  onlineChatIdUsers.value = new Set(ids.map(normalizePresenceId).filter(Boolean))
}

function connectPresenceSocket() {
  if (!import.meta.client || !user.value || presenceSocket) return

  try {
    presenceSocket = new WebSocket(presenceWsUrl())
  } catch {
    schedulePresenceReconnect()
    return
  }

  presenceSocket.onopen = () => {
    presenceConnected.value = true
    sendPresenceHello()
    if (presenceHeartbeatTimer) {
      clearInterval(presenceHeartbeatTimer)
    }
    presenceHeartbeatTimer = setInterval(() => {
      if (presenceSocket?.readyState === WebSocket.OPEN) {
        presenceSocket.send(JSON.stringify({ type: 'heartbeat' }))
      }
    }, 25000)
  }

  presenceSocket.onmessage = handlePresenceMessage
  presenceSocket.onerror = () => {
    presenceConnected.value = false
  }
  presenceSocket.onclose = () => {
    presenceSocket = null
    presenceConnected.value = false
    onlineChatIdUsers.value = new Set()
    if (presenceHeartbeatTimer) {
      clearInterval(presenceHeartbeatTimer)
      presenceHeartbeatTimer = null
    }
    schedulePresenceReconnect()
  }
}

const toggleChat = () => {
  showChatBalloon.value = !showChatBalloon.value

  if (showChatBalloon.value) {
    showEmojiPicker.value = false
    void loadChatContacts()
    if (hasChatTarget.value) {
      void loadChatMessages()
    }
    startChatPolling()
  } else {
    stopChatPolling()
  }
}

const openChatFromNotification = (senderId: number) => {
  showChatBalloon.value = true
  chatScope.value = 'direct'
  selectedChatUserId.value = Number(senderId)
  showEmojiPicker.value = false
  void loadChatContacts()
  void loadChatMessages()
  startChatPolling()
}

const openGlobalChatFromNotification = () => {
  showChatBalloon.value = true
  chatScope.value = 'global'
  selectedChatUserId.value = null
  showEmojiPicker.value = false
  void loadChatContacts()
  void loadChatMessages()
  startChatPolling()
}

const openFilePicker = () => {
  if (!hasChatTarget.value || chatSending.value) {
    return
  }
  showEmojiPicker.value = false
  chatFileInput.value?.click()
}

const clearSelectedAttachment = () => {
  selectedAttachment.value = null
  if (chatFileInput.value) {
    chatFileInput.value.value = ''
  }
}

const onChatFileChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0] || null
  selectedAttachment.value = file
}

const toggleEmojiPicker = () => {
  if (!hasChatTarget.value || chatSending.value) {
    return
  }
  showEmojiPicker.value = !showEmojiPicker.value
}

const handleEmojiPick = (event: Event) => {
  const detail = (event as CustomEvent<{ unicode?: string }>).detail
  const emoji = String(detail?.unicode || '')
  if (!emoji) {
    return
  }

  userInput.value = `${userInput.value}${emoji}`
  showEmojiPicker.value = false
  nextTick(() => {
    chatInputRef.value?.focus()
  })
}

const selectChatContact = (event: Event) => {
  showEmojiPicker.value = false
  const selected = String((event.target as HTMLSelectElement).value || '')
  if (!selected || selected === 'global') {
    chatScope.value = 'global'
    selectedChatUserId.value = null
    return
  }

  if (selected.startsWith('direct:')) {
    const id = Number(selected.slice(7))
    chatScope.value = 'direct'
    selectedChatUserId.value = Number.isFinite(id) ? id : null
    return
  }

  const numeric = Number(selected)
  if (Number.isFinite(numeric)) {
    chatScope.value = 'direct'
    selectedChatUserId.value = numeric
  }
}

const handleSendMessage = async () => {
  const message = userInput.value.trim()
  if ((!message && !selectedAttachment.value) || !hasChatTarget.value || chatSending.value) {
    return
  }

  chatSending.value = true
  try {
    const formData = new FormData()
    formData.append('scope', chatScope.value)
    if (!isGlobalChat.value && selectedChatUserId.value) {
      formData.append('receiver_id', String(selectedChatUserId.value))
    }
    if (message) {
      formData.append('message', message)
    }
    if (selectedAttachment.value) {
      formData.append('file', selectedAttachment.value)
    }

    const response = await business.chat.sendMessage(formData) as ApiEnvelope<ChatRecord>

    const sentMessage = toData<ChatRecord | null>(response, null)
    if (sentMessage) {
      chatMessages.value.push(sentMessage)
    } else {
      await loadChatMessages(true)
    }

    userInput.value = ''
    clearSelectedAttachment()
    showEmojiPicker.value = false
    chatError.value = ''
    scrollToBottom()
  } catch (error) {
    chatError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengirim pesan.'
  } finally {
    chatSending.value = false
    if (showChatBalloon.value && hasChatTarget.value) {
      nextTick(() => {
        chatInputRef.value?.focus()
      })
    }
  }
}

const handleLogout = async () => {
  if (loggingOut.value) {
    return
  }

  loggingOut.value = true
  try {
    await business.auth.SignOut()
  } catch {
    // Tetap lanjut hapus session lokal.
  } finally {
    clearSession()
    loggingOut.value = false
    await navigateTo('/login')
  }
}

const handleAvatarError = () => {
  avatarErrored.value = true
}

const onDownloadRequestCreated = (event: Event) => {
  const detail = ((event as CustomEvent<Record<string, unknown>>).detail || {}) as Record<string, unknown>
  const status = String(detail.status || '').toLowerCase()
  const message = String(detail.message || '').trim()

  if (status === 'approved') {
    pushBrowserNotification(
      'SIMANIS - Download Disetujui',
      message || 'Request download disetujui.',
      () => {
        void navigateTo('/pencarian-dokumen')
      },
    )
  }

  if (status === 'pending') {
    pushBrowserNotification(
      'SIMANIS - Request Download',
      message || 'Permintaan download dikirim dan menunggu persetujuan admin.',
      () => {
        void navigateTo('/pencarian-dokumen')
      },
    )
  }

  void loadDownloadRequests(true)
}

watch(
  desktopSidebarOpen,
  (value) => {
    if (!import.meta.client) return
    window.localStorage.setItem(DESKTOP_SIDEBAR_STORAGE_KEY, value ? '1' : '0')
  },
)

watch(
  () => route.fullPath,
  () => {
    sidebarOpen.value = false
    if (import.meta.client) {
      nextTick(() => syncBaseDocumentTitle())
    }
  },
)

watch(
  () => [route.path, route.query.view, route.query.q] as const,
  ([path, view, query]) => {
    if (path !== '/dashboard' || view !== 'documents') return
    topbarSearch.value = typeof query === 'string' ? query : ''
  },
  { immediate: true },
)

watch(
  () => user.value?.foto,
  () => {
    avatarErrored.value = false
  },
)

watch(
  windowsMode,
  (value) => {
    if (!import.meta.client) return
    window.localStorage.setItem(WINDOWS_MODE_STORAGE_KEY, value ? '1' : '0')
    if (value) openWindowsLauncher()
  },
)

watch(mouseEffect, (value) => {
  if (!import.meta.client) return
  window.localStorage.setItem(MOUSE_EFFECT_STORAGE_KEY, value)
  if (value === 'off') windowsSparkles.value = []
})

const windowsLauncherGroups = computed(() => navigation.value)
const windowsLauncherPanelKey = computed(() => selectedWindowsGroup.value?.name || 'windows-root')
const windowsLauncherTransitionName = computed(() =>
  windowsLauncherDirection.value === 'back'
    ? 'windows-launcher-slide-back'
    : 'windows-launcher-slide-forward',
)
const windowsWorkspaceTransitionName = computed(() =>
  windowsWorkspaceDirection.value === 'back'
    ? 'windows-workspace-back'
    : 'windows-workspace-forward',
)
const activeWindowsGroup = computed(() =>
  windowsLauncherGroups.value.find(group => group.children.some(item => isActive(item.href))) || null,
)

const handleWindowsPointerMove = (event: PointerEvent) => {
  if (!import.meta.client || mouseEffect.value === 'off' || event.pointerType === 'touch' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  document.documentElement.style.setProperty('--windows-pointer-x', `${event.clientX}px`)
  document.documentElement.style.setProperty('--windows-pointer-y', `${event.clientY}px`)

  const now = performance.now()
  const throttleMs = mouseEffect.value === 'trail' ? 28 : 55
  if (now - lastWindowsSparkleAt < throttleMs) return
  lastWindowsSparkleAt = now
  const sparkle = { id: ++windowsSparkleId, x: event.clientX, y: event.clientY }
  windowsSparkles.value.push(sparkle)
  window.setTimeout(() => {
    windowsSparkles.value = windowsSparkles.value.filter(item => item.id !== sparkle.id)
  }, mouseEffect.value === 'trail' ? 950 : 650)
}

const openWindowsLauncher = () => {
  windowsWorkspaceDirection.value = 'back'
  windowsLauncherDirection.value = 'back'
  selectedWindowsGroup.value = null
  windowsLauncherVisible.value = true
}

const openWindowsGroup = (group: NavigationGroup) => {
  windowsLauncherDirection.value = 'forward'
  selectedWindowsGroup.value = group
}

const backToWindowsGroups = () => {
  windowsLauncherDirection.value = 'back'
  selectedWindowsGroup.value = null
}

const openWindowsModule = () => {
  windowsWorkspaceDirection.value = 'forward'
  windowsLauncherVisible.value = false
}

const backToWindowsSubmenu = () => {
  windowsWorkspaceDirection.value = 'back'
  windowsLauncherDirection.value = 'back'
  selectedWindowsGroup.value = activeWindowsGroup.value
  windowsLauncherVisible.value = true
}

const footerNavigationNames = new Set(['Kontrol Admin', 'Pengaturan'])
const mainNavigation = computed(() =>
  navigation.value
    .filter(item => !footerNavigationNames.has(item.name))
    .map(item => ({
      ...item,
      children: item.children.filter(child => child.href !== '/dashboard'),
    }))
    .filter(item => item.children.length > 0),
)
const footerNavigation = computed(() =>
  navigation.value.filter(item => footerNavigationNames.has(item.name)),
)

watch(
  () => user.value?.id_user,
  (idUser) => {
    stopPresenceSocket()
    if (idUser) {
      connectPresenceSocket()
    }
  },
)

watch(selectedChatUserId, () => {
  if (showChatBalloon.value && hasChatTarget.value) {
    void loadChatMessages()
  }
})

watch(chatScope, () => {
  showEmojiPicker.value = false
  if (showChatBalloon.value && hasChatTarget.value) {
    void loadChatMessages()
  }
})

watch(showChatBalloon, (isOpen) => {
  if (!isOpen) {
    showEmojiPicker.value = false
    stopChatPolling()
    return
  }
  startChatPolling()
})

watch(notificationBadgeTotal, () => {
  applyUnreadDocumentTitle()
})

onMounted(() => {
  void import('emoji-picker-element')
  if (import.meta.client) {
    const savedSidebarState = window.localStorage.getItem(DESKTOP_SIDEBAR_STORAGE_KEY)
    if (savedSidebarState === '0') {
      desktopSidebarOpen.value = false
    } else if (savedSidebarState === '1') {
      desktopSidebarOpen.value = true
    }
    const savedWindowsMode = window.localStorage.getItem(WINDOWS_MODE_STORAGE_KEY)
    windowsMode.value = savedWindowsMode === null || savedWindowsMode === '1'
    const savedMouseEffect = window.localStorage.getItem(MOUSE_EFFECT_STORAGE_KEY)
    if (savedMouseEffect === 'off' || savedMouseEffect === 'sparkle' || savedMouseEffect === 'trail') {
      mouseEffect.value = savedMouseEffect
    }
    syncBaseDocumentTitle()
    void ensureNotificationPermission()
    window.addEventListener('simanis:download-request-created', onDownloadRequestCreated as EventListener)
    connectPresenceSocket()
  }
  void loadAllNotifications()
  startDownloadPolling()
})

onBeforeUnmount(() => {
  if (import.meta.client) {
    window.document.title = stripUnreadPrefix(baseDocumentTitle.value || window.document.title || 'SIMANIS')
    window.removeEventListener('simanis:download-request-created', onDownloadRequestCreated as EventListener)
  }
  stopChatPolling()
  stopDownloadPolling()
  stopPresenceSocket()
})
</script>

<style>
.windows-shell-light {
  color: #0f172a;
  background:
    radial-gradient(circle at 12% 8%, rgb(var(--simanis-accent-rgb) / 0.16), transparent 30rem),
    linear-gradient(135deg, #f8fafc, var(--simanis-accent-50));
}

.windows-shell-dark {
  color: #f8fafc;
  background: linear-gradient(135deg, #020617, color-mix(in srgb, #020617 84%, var(--simanis-accent-grad-from) 16%));
}

.windows-shell-light .windows-header {
  color: #0f172a;
  border-color: color-mix(in srgb, var(--simanis-accent-200) 70%, #cbd5e1);
  background: color-mix(in srgb, #ffffff 88%, var(--simanis-accent-50) 12%) !important;
}

.windows-shell-dark .windows-header {
  color: #ffffff;
  border-color: rgb(255 255 255 / 0.1);
  background: rgb(2 6 23 / 0.95) !important;
}

.windows-shell-light .windows-search input {
  color: #0f172a;
  border-color: color-mix(in srgb, var(--simanis-accent-200) 72%, #cbd5e1);
  background: rgb(255 255 255 / 0.82);
  --tw-ring-color: rgb(var(--simanis-accent-rgb) / 0.24);
}

.windows-shell-light .windows-search input::placeholder,
.windows-shell-light .windows-search-icon {
  color: #64748b;
}

.windows-shell-dark .windows-search input {
  color: #f8fafc;
  border-color: rgb(255 255 255 / 0.14);
  background: rgb(255 255 255 / 0.06);
  --tw-ring-color: rgb(var(--simanis-accent-rgb) / 0.32);
}

.windows-shell-dark .windows-search input::placeholder,
.windows-shell-dark .windows-search-icon {
  color: #94a3b8;
}

.windows-shell-light .windows-muted,
.windows-shell-light .windows-tile-muted {
  color: #475569 !important;
}

.windows-shell-dark .windows-muted,
.windows-shell-dark .windows-tile-muted {
  color: rgb(255 255 255 / 0.58) !important;
}

.windows-shell-light .windows-divider {
  border-color: color-mix(in srgb, var(--simanis-accent-200) 72%, #cbd5e1);
}

.windows-shell-dark .windows-divider {
  border-color: rgb(255 255 255 / 0.1);
}

.windows-shell-light .windows-header button,
.windows-shell-light .windows-control {
  color: #0f172a !important;
  border-color: color-mix(in srgb, var(--simanis-accent-200) 70%, #cbd5e1) !important;
  background: rgb(255 255 255 / 0.76) !important;
}

.windows-shell-dark .windows-header button,
.windows-shell-dark .windows-control {
  color: #ffffff !important;
  border-color: rgb(255 255 255 / 0.15) !important;
  background: rgb(255 255 255 / 0.05) !important;
}

.windows-shell-light .windows-tile {
  color: #0f172a !important;
  border-color: color-mix(in srgb, var(--simanis-accent-500) 38%, #ffffff) !important;
  background: linear-gradient(
    145deg,
    color-mix(in srgb, var(--simanis-accent-grad-from) 28%, #ffffff 72%),
    color-mix(in srgb, var(--simanis-accent-grad-to) 20%, #ffffff 80%)
  ) !important;
  box-shadow:
    0 18px 34px -24px var(--simanis-accent-shadow),
    inset 0 1px 0 rgb(255 255 255 / 0.72) !important;
}

.windows-shell-light .windows-tile:hover {
  border-color: color-mix(in srgb, var(--simanis-accent-500) 58%, #ffffff) !important;
  background: linear-gradient(
    145deg,
    color-mix(in srgb, var(--simanis-accent-grad-from) 36%, #ffffff 64%),
    color-mix(in srgb, var(--simanis-accent-grad-to) 27%, #ffffff 73%)
  ) !important;
}

.windows-shell-light .windows-tile-title,
.windows-shell-light .windows-tile-icon {
  color: #0f172a !important;
}

.windows-shell-light .windows-tile-badge {
  color: var(--simanis-accent-700) !important;
  border: 1px solid color-mix(in srgb, var(--simanis-accent-500) 28%, #ffffff);
  background: color-mix(in srgb, var(--simanis-accent-100) 82%, #ffffff) !important;
}

.windows-shell-light .windows-tile-icon-stage {
  color: var(--simanis-accent-700);
  border: 1px solid color-mix(in srgb, var(--simanis-accent-200) 78%, #ffffff);
  background: linear-gradient(
    145deg,
    color-mix(in srgb, var(--simanis-accent-grad-from) 22%, #ffffff 78%),
    color-mix(in srgb, var(--simanis-accent-100) 76%, #ffffff)
  );
  box-shadow:
    0 13px 18px -12px var(--simanis-accent-shadow),
    inset 1px 1px 0 rgb(255 255 255 / 0.9);
}

.windows-shell-light .windows-tile-ambient {
  color: rgb(var(--simanis-accent-rgb) / 0.16);
}

.windows-shell-dark .windows-tile {
  color: #ffffff !important;
  border-color: rgb(255 255 255 / 0.15) !important;
  background: linear-gradient(145deg, var(--simanis-accent-grad-from), var(--simanis-accent-grad-to)) !important;
}

.windows-shell-dark .windows-tile-title,
.windows-shell-dark .windows-tile-icon {
  color: #ffffff !important;
}

.windows-shell-dark .windows-tile-badge {
  color: #ffffff !important;
  background: rgb(255 255 255 / 0.16) !important;
}

.windows-shell-dark .windows-tile-icon-stage {
  color: #ffffff;
  border: 1px solid rgb(255 255 255 / 0.24);
  background: linear-gradient(145deg, rgb(255 255 255 / 0.2), rgb(255 255 255 / 0.05));
  box-shadow:
    0 14px 22px -12px rgb(2 6 23 / 0.8),
    inset 1px 1px 0 rgb(255 255 255 / 0.2);
  backdrop-filter: blur(7px);
}

.windows-shell-dark .windows-tile-ambient {
  color: rgb(255 255 255 / 0.08);
}

.windows-tile-3d {
  perspective: 700px;
  transform-style: preserve-3d;
}

.windows-tile-icon-stage {
  position: relative;
  animation: windows-icon-float 4.8s ease-in-out infinite;
  animation-delay: var(--tile-float-delay, 0s);
  transform-style: preserve-3d;
  transition: transform 280ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 280ms ease;
}

.windows-tile-icon-stage::after {
  position: absolute;
  inset: 5px;
  border-radius: 8px;
  background: linear-gradient(135deg, rgb(255 255 255 / 0.24), transparent 52%);
  content: '';
  pointer-events: none;
}

.windows-tile-icon-stage .windows-tile-icon {
  position: relative;
  z-index: 1;
  filter: drop-shadow(2px 4px 3px rgb(2 6 23 / 0.22));
  transform: translateZ(16px);
}

.windows-tile-ambient {
  animation: windows-ambient-icon 9s ease-in-out infinite;
  animation-delay: var(--tile-float-delay, 0s);
  filter: blur(0.2px);
  transform-origin: center;
  transition: color 260ms ease, transform 400ms ease;
}

.windows-tile-3d:hover .windows-tile-icon-stage {
  transform: rotateX(10deg) rotateY(-14deg) translate3d(2px, -4px, 20px) scale(1.06);
}

.windows-tile-3d:hover .windows-tile-ambient {
  transform: rotate(-8deg) scale(1.12);
}

.windows-shell-light .windows-page-back {
  color: #0f172a;
  border-color: color-mix(in srgb, var(--simanis-accent-200) 74%, #cbd5e1);
  background: color-mix(in srgb, #ffffff 88%, var(--simanis-accent-50) 12%);
  box-shadow: 0 10px 24px -20px var(--simanis-accent-shadow);
}

.windows-shell-dark .windows-page-back {
  color: #f8fafc;
  border-color: rgb(255 255 255 / 0.14);
  background: color-mix(in srgb, #0f172a 86%, var(--simanis-accent-grad-from) 14%);
}

.windows-shell main {
  background: transparent !important;
}

.dashboard-main {
  background:
    radial-gradient(circle at 12% 8%, rgb(var(--simanis-accent-rgb) / 0.16), transparent 30rem),
    linear-gradient(135deg, #f8fafc, var(--simanis-accent-50)) !important;
}

:root.theme-dark .dashboard-main {
  background: linear-gradient(135deg, #020617, color-mix(in srgb, #020617 84%, var(--simanis-accent-grad-from) 16%)) !important;
}

/* Root halaman tidak boleh membentuk bingkai persegi di atas background layout. */
.dashboard-page-container > :first-child {
  background-color: transparent !important;
  background-image: none !important;
  outline: 0 !important;
}

.dashboard-submenu-slide-enter-active,
.dashboard-submenu-slide-leave-active {
  overflow: hidden;
  transform-origin: top;
  transition:
    max-height 220ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 170ms ease-out,
    transform 220ms cubic-bezier(0.22, 1, 0.36, 1);
}

.dashboard-submenu-slide-enter-to,
.dashboard-submenu-slide-leave-from {
  max-height: 560px;
  opacity: 1;
  transform: translateY(0);
}

.dashboard-submenu-slide-enter-from,
.dashboard-submenu-slide-leave-to {
  max-height: 0;
  opacity: 0;
  transform: translateY(-8px);
}

.windows-launcher-panel {
  will-change: transform, opacity;
}

.windows-workspace-forward-enter-active,
.windows-workspace-forward-leave-active,
.windows-workspace-back-enter-active,
.windows-workspace-back-leave-active {
  transition:
    transform 300ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 210ms ease,
    filter 300ms ease;
  will-change: transform, opacity, filter;
}

.windows-workspace-forward-enter-from {
  opacity: 0;
  filter: blur(7px);
  transform: translateX(72px) scale(0.99);
}

.windows-workspace-forward-leave-to {
  opacity: 0;
  filter: blur(5px);
  transform: translateX(-48px) scale(0.99);
}

.windows-workspace-back-enter-from {
  opacity: 0;
  filter: blur(7px);
  transform: translateX(-72px) scale(0.99);
}

.windows-workspace-back-leave-to {
  opacity: 0;
  filter: blur(5px);
  transform: translateX(56px) scale(0.99);
}

.windows-back-action .h-5 {
  transition: transform 220ms ease;
}

.windows-back-action:hover .h-5 {
  transform: translateX(-3px);
}

@media (prefers-reduced-motion: reduce) {
  .windows-workspace-forward-enter-active,
  .windows-workspace-forward-leave-active,
  .windows-workspace-back-enter-active,
  .windows-workspace-back-leave-active {
    transition-duration: 1ms;
  }
}

.windows-launcher-slide-forward-enter-active,
.windows-launcher-slide-forward-leave-active,
.windows-launcher-slide-back-enter-active,
.windows-launcher-slide-back-leave-active {
  transition:
    transform 260ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 180ms ease-out,
    filter 260ms ease-out;
}

.windows-launcher-slide-forward-enter-from {
  opacity: 0;
  filter: blur(8px);
  transform: translateX(72px) scale(0.985);
}

.windows-launcher-slide-forward-leave-to {
  opacity: 0;
  filter: blur(5px);
  transform: translateX(-44px) scale(0.99);
}

.windows-launcher-slide-back-enter-from {
  opacity: 0;
  filter: blur(8px);
  transform: translateX(-72px) scale(0.985);
}

.windows-launcher-slide-back-leave-to {
  opacity: 0;
  filter: blur(5px);
  transform: translateX(44px) scale(0.99);
}

.app-shell main:not(.windows-shell main) {
  background-size: 145% 145% !important;
  animation: simanis-dashboard-background 20s ease-in-out infinite alternate;
}

.windows-motion-bg {
  z-index: 0;
  opacity: 0.62;
}

.windows-moving-grid {
  background-image:
    linear-gradient(rgb(var(--simanis-accent-rgb) / 0.1) 1px, transparent 1px),
    linear-gradient(90deg, rgb(var(--simanis-accent-rgb) / 0.1) 1px, transparent 1px);
  background-size: 48px 48px;
  animation: windows-grid-drift 24s linear infinite;
  mask-image: linear-gradient(to bottom, transparent, #000 18%, #000 82%, transparent);
}

.windows-floating-shape {
  width: 34px;
  height: 34px;
  border: 1px solid rgb(var(--simanis-accent-rgb) / 0.32);
  box-shadow: inset 0 0 0 5px rgb(var(--simanis-accent-rgb) / 0.05);
  animation: windows-shape-drift 18s ease-in-out infinite alternate;
}

.windows-cursor-ring {
  z-index: 60;
  width: 24px;
  height: 24px;
  border: 1px solid var(--simanis-accent-500);
  transform: translate(calc(var(--windows-pointer-x, -40px) - 12px), calc(var(--windows-pointer-y, -40px) - 12px)) rotate(45deg);
  box-shadow: 0 0 14px rgb(var(--simanis-accent-rgb) / 0.55);
  transition: transform 55ms linear;
}

.windows-cursor-spark {
  z-index: 59;
  width: 7px;
  height: 7px;
  background: var(--simanis-accent-500);
  transform: translate(-50%, -50%) rotate(45deg);
  box-shadow: 0 0 10px rgb(var(--simanis-accent-rgb) / 0.7);
  animation: windows-spark-fade 650ms ease-out forwards;
}

.mouse-effect-trail .windows-cursor-ring {
  width: 18px;
  height: 18px;
  border-width: 2px;
  transform: translate(calc(var(--windows-pointer-x, -40px) - 9px), calc(var(--windows-pointer-y, -40px) - 9px)) rotate(45deg);
}

.mouse-effect-trail .windows-cursor-spark {
  width: 4px;
  height: 14px;
  animation-duration: 900ms;
}

@keyframes simanis-dashboard-background {
  0% { background-position: 0% 0%; }
  50% { background-position: 55% 35%; }
  100% { background-position: 100% 75%; }
}

@keyframes windows-grid-drift {
  from { background-position: 0 0, 0 0; }
  to { background-position: 48px 48px, 48px 48px; }
}

@keyframes windows-icon-float {
  0%, 100% {
    transform: rotateX(4deg) rotateY(-5deg) translate3d(0, 0, 8px);
  }
  50% {
    transform: rotateX(-3deg) rotateY(7deg) translate3d(0, -6px, 14px);
  }
}

@keyframes windows-ambient-icon {
  0%, 100% {
    transform: rotate(-4deg) translate3d(0, 0, 0) scale(1);
  }
  50% {
    transform: rotate(5deg) translate3d(5px, -8px, 0) scale(1.06);
  }
}

@keyframes windows-shape-drift {
  0% { transform: translate3d(0, 0, 0) rotate(0deg); opacity: 0.22; }
  50% { transform: translate3d(22px, -34px, 0) rotate(45deg); opacity: 0.55; }
  100% { transform: translate3d(-14px, 20px, 0) rotate(90deg); opacity: 0.28; }
}

@keyframes windows-spark-fade {
  0% { opacity: 0.9; transform: translate(-50%, -50%) rotate(45deg) scale(1); }
  100% { opacity: 0; transform: translate(-50%, -26px) rotate(135deg) scale(0.2); }
}

@media (prefers-reduced-motion: reduce) {
  .windows-moving-grid,
  .windows-floating-shape,
  .windows-tile-icon-stage,
  .windows-tile-ambient,
  .windows-cursor-ring,
  .windows-cursor-spark {
    animation: none !important;
    display: none !important;
  }

  .windows-launcher-slide-forward-enter-active,
  .windows-launcher-slide-forward-leave-active,
  .windows-launcher-slide-back-enter-active,
  .windows-launcher-slide-back-leave-active,
  .dashboard-submenu-slide-enter-active,
  .dashboard-submenu-slide-leave-active {
    transition: opacity 120ms ease-out !important;
  }

  .windows-launcher-slide-forward-enter-from,
  .windows-launcher-slide-forward-leave-to,
  .windows-launcher-slide-back-enter-from,
  .windows-launcher-slide-back-leave-to,
  .dashboard-submenu-slide-enter-from,
  .dashboard-submenu-slide-leave-to {
    filter: none !important;
    transform: none !important;
  }
}

:root:not(.theme-dark) .theme-native-select {
  color: #ffffff !important;
  color-scheme: light;
}

:root:not(.theme-dark) .theme-native-select option {
  color: #0f172a !important;
  background-color: #ffffff !important;
}

:root.theme-dark .theme-native-select {
  color: #f8fafc !important;
  background-color: rgb(255 255 255 / 0.08) !important;
  color-scheme: dark;
}

:root.theme-dark .theme-native-select option {
  color: #f8fafc !important;
  background-color: #0f172a !important;
}
</style>
