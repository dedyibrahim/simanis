<template>
  <main
    class="public-calendar-shell h-screen overflow-hidden"
    :class="isDark ? 'public-theme-dark text-white' : 'public-theme-light text-slate-950'"
  >
    <div class="pointer-events-none fixed inset-0 overflow-hidden">
      <div class="absolute left-[-10rem] top-[-10rem] h-96 w-96 rounded-full bg-blue-500/20 blur-3xl"></div>
      <div class="absolute bottom-[-12rem] right-[-8rem] h-[28rem] w-[28rem] rounded-full bg-cyan-400/10 blur-3xl"></div>
    </div>

    <section class="relative z-10 flex h-screen min-h-0 flex-col px-3 py-3 sm:px-4 lg:px-5">
      <header class="mb-2 flex-shrink-0 rounded-2xl border border-white/10 bg-white/10 px-4 py-3 shadow-xl backdrop-blur-xl">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex min-w-0 items-center gap-3">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-blue-500 shadow-lg shadow-blue-500/30">
              <CalendarDaysIcon class="h-6 w-6" />
            </div>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-blue-200">Jadwal Publik</p>
              <h1 class="truncate text-lg font-bold sm:text-xl">Kalender Jadwal Notaris</h1>
              <p class="hidden max-w-2xl truncate text-xs text-slate-300 md:block">
                Lihat agenda kantor tanpa perlu login. Untuk membuat atau mengubah jadwal, masuk ke SIMANIS.
              </p>
            </div>
          </div>

          <div class="flex flex-wrap items-center justify-end gap-1.5">
            <button
              type="button"
              class="public-ghost-button inline-flex items-center gap-2 rounded-xl border border-white/15 px-3 py-2 text-xs font-semibold transition hover:bg-white/10"
              @click="toggleFullscreen"
            >
              <ArrowsPointingInIcon v-if="isFullscreen" class="h-4 w-4" />
              <ArrowsPointingOutIcon v-else class="h-4 w-4" />
              {{ isFullscreen ? 'Keluar Fullscreen' : 'Fullscreen' }}
            </button>
            <div class="relative">
              <button
                ref="themeButtonEl"
                type="button"
                class="public-ghost-button inline-flex items-center gap-2 rounded-xl border border-white/15 px-3 py-2 text-xs font-semibold transition hover:bg-white/10"
                @click="toggleThemeMenu"
              >
                <span class="h-4 w-4 rounded-md ring-1 ring-white/60" :style="{ background: activeAccentTheme.swatch }"></span>
                <SwatchIcon class="h-4 w-4" />
                Tema
              </button>
            </div>
            <button
              type="button"
              class="public-ghost-button inline-flex items-center gap-2 rounded-xl border border-white/15 px-3 py-2 text-xs font-semibold transition hover:bg-white/10"
              :disabled="loading"
              @click="loadEvents"
            >
              <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': loading }" />
              Refresh
            </button>
            <button
              type="button"
              class="public-gradient-button inline-flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold text-white shadow-lg transition hover:-translate-y-0.5"
              @click="openScanDialog"
            >
              <DocumentArrowUpIcon class="h-4 w-4" />
              Scan Dokumen
            </button>
            <NuxtLink
              to="/login?redirect=/jadwal-notaris"
              class="public-login-button inline-flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold shadow-lg transition hover:-translate-y-0.5"
            >
              <ArrowRightOnRectangleIcon class="h-4 w-4" />
              Login SIMANIS
            </NuxtLink>
          </div>
        </div>
      </header>

      <div
        v-if="scanSessionChecked"
        class="mb-3 flex flex-shrink-0 flex-col gap-2 rounded-2xl border px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between"
        :class="activeScanSession ? 'border-blue-300/30 bg-blue-500/10 text-blue-50' : 'border-white/10 bg-white/5 text-slate-300'"
      >
        <div>
          <span class="font-bold">{{ activeScanSession ? 'Sesi scan aktif' : 'Tidak ada sesi scan aktif' }}</span>
          <span v-if="activeScanSession">
            untuk {{ activeScanSession.assistant?.nama_lengkap || '-' }}, berlaku sampai {{ formatDateTime(activeScanSession.expires_at) }}.
          </span>
          <span v-else>
            Buat sesi dulu sebelum scan agar dokumen masuk ke asisten yang benar.
          </span>
        </div>
        <button
          type="button"
          class="text-left text-xs font-bold uppercase tracking-wider text-blue-200 hover:text-white"
          @click="openScanDialog"
        >
          {{ activeScanSession ? 'Lihat sesi' : 'Buat sesi' }}
        </button>
      </div>

      <div class="grid min-h-0 flex-1 gap-3 xl:grid-cols-[minmax(0,1fr)_23rem]">
        <section class="flex min-h-0 flex-col overflow-hidden rounded-3xl border border-white/10 bg-white/10 shadow-2xl backdrop-blur-xl">
          <div class="flex flex-shrink-0 flex-col gap-2 border-b border-white/10 p-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-400">Periode</p>
              <h2 class="mt-1 text-xl font-bold">{{ currentPeriodLabel }}</h2>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <div class="inline-flex rounded-2xl border border-white/15 bg-slate-950/40 p-1">
                <button
                  type="button"
                  class="rounded-xl px-3 py-1.5 text-xs font-bold transition"
                  :class="calendarView === 'month' ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/20' : 'text-slate-300 hover:bg-white/10'"
                  @click="setCalendarView('month')"
                >
                  Bulan
                </button>
                <button
                  type="button"
                  class="rounded-xl px-3 py-1.5 text-xs font-bold transition"
                  :class="calendarView === 'week' ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/20' : 'text-slate-300 hover:bg-white/10'"
                  @click="setCalendarView('week')"
                >
                  Minggu
                </button>
              </div>
              <button
                type="button"
                class="rounded-2xl border border-white/15 p-2 transition hover:bg-white/10"
                :aria-label="calendarView === 'week' ? 'Minggu sebelumnya' : 'Bulan sebelumnya'"
                @click="movePeriod(-1)"
              >
                <ChevronLeftIcon class="h-5 w-5" />
              </button>
              <button
                type="button"
                class="rounded-2xl border border-white/15 px-4 py-2 text-sm font-semibold transition hover:bg-white/10"
                @click="goToday"
              >
                Hari Ini
              </button>
              <button
                type="button"
                class="rounded-2xl border border-white/15 p-2 transition hover:bg-white/10"
                :aria-label="calendarView === 'week' ? 'Minggu berikutnya' : 'Bulan berikutnya'"
                @click="movePeriod(1)"
              >
                <ChevronRightIcon class="h-5 w-5" />
              </button>
            </div>
          </div>

          <div v-if="errorMessage" class="m-4 rounded-2xl border border-red-300/30 bg-red-500/10 px-4 py-3 text-sm font-semibold text-red-100">
            {{ errorMessage }}
          </div>

          <div
            v-if="calendarView === 'month'"
            class="grid flex-shrink-0 grid-cols-7 border-b border-white/10 px-3 pt-2 text-center text-xs font-bold uppercase tracking-widest text-slate-400"
          >
            <span v-for="day in weekdays" :key="day" class="py-2">{{ day }}</span>
          </div>

          <div
            v-if="calendarView === 'month'"
            class="grid min-h-0 flex-1 grid-cols-7 gap-px overflow-hidden rounded-b-3xl bg-white/10 p-px"
            :class="'grid-rows-6'"
          >
            <button
              v-for="cell in calendarCells"
              :key="cell.dateKey"
              type="button"
              class="min-h-0 overflow-hidden bg-slate-950/80 p-1.5 text-left transition hover:bg-slate-900"
              :class="[
                cell.inCurrentMonth ? 'text-white' : 'text-slate-600',
                selectedDateKey === cell.dateKey ? 'ring-2 ring-inset ring-blue-400' : '',
              ]"
              @click="selectDate(cell.dateKey)"
            >
              <div class="mb-1 flex items-center justify-between">
                <span
                  class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold"
                  :class="cell.isToday ? 'bg-blue-500 text-white' : 'bg-white/5 text-inherit'"
                >
                  {{ cell.dateNumber }}
                </span>
                <span v-if="cell.events.length" class="text-[10px] font-semibold text-blue-200">
                  {{ cell.events.length }} agenda
                </span>
              </div>

              <div class="space-y-1">
                <div
                  v-for="event in cell.events.slice(0, 3)"
                  :key="`${cell.dateKey}-${event.id}`"
                  class="truncate rounded-lg px-1.5 py-0.5 text-[10px] font-semibold 2xl:px-2 2xl:py-1 2xl:text-[11px]"
                  :style="eventColorStyle(event)"
                  :title="event.title"
                >
                  {{ formatTime(event.start) }} {{ event.title }}
                </div>
                <p v-if="cell.events.length > 3" class="text-[10px] font-semibold text-slate-400">
                  +{{ cell.events.length - 3 }} agenda lain
                </p>
              </div>
            </button>
          </div>

          <div v-else class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-b-3xl">
            <div class="grid flex-shrink-0 grid-cols-[4.75rem_repeat(7,minmax(0,1fr))] border-b border-white/10 bg-slate-950/70 text-center text-xs font-bold uppercase tracking-widest text-slate-400">
              <div class="border-r border-white/10 px-2 py-2 text-left">Jam</div>
              <button
                v-for="cell in calendarCells"
                :key="`week-head-${cell.dateKey}`"
                type="button"
                class="border-r border-white/10 px-2 py-2 text-left transition last:border-r-0 hover:bg-white/10"
                :class="selectedDateKey === cell.dateKey ? 'bg-blue-500/20 text-blue-100' : ''"
                @click="selectDate(cell.dateKey)"
              >
                <span class="block text-[10px] text-slate-400">{{ weekdays[new Date(cell.dateKey).getDay()] }}</span>
                <span class="mt-1 inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold" :class="cell.isToday ? 'bg-blue-500 text-white' : 'bg-white/5 text-white'">
                  {{ cell.dateNumber }}
                </span>
                <span v-if="cell.events.length" class="ml-2 align-middle text-[10px] text-blue-200">
                  {{ cell.events.length }} agenda
                </span>
              </button>
            </div>

            <div class="min-h-0 flex-1 overflow-hidden bg-slate-950/80">
              <div
                class="grid h-full min-w-0 grid-cols-[4.75rem_repeat(7,minmax(0,1fr))]"
              >
                <div class="relative border-r border-white/10">
                  <div
                    v-for="hour in weekTimeSlots"
                    :key="`time-${hour}`"
                    class="absolute right-2 -translate-y-2 text-[11px] font-semibold text-slate-500"
                    :style="weekTimeSlotStyle(hour)"
                  >
                    {{ formatHourLabel(hour) }}
                  </div>
                </div>

                <button
                  v-for="cell in calendarCells"
                  :key="`week-body-${cell.dateKey}`"
                  type="button"
                  class="relative border-r border-white/10 text-left transition last:border-r-0 hover:bg-white/5"
                  :class="selectedDateKey === cell.dateKey ? 'bg-blue-500/10' : ''"
                  @click="selectDate(cell.dateKey)"
                >
                  <span
                    v-for="hour in weekTimeSlots"
                    :key="`${cell.dateKey}-line-${hour}`"
                    class="pointer-events-none absolute left-0 right-0 border-t border-white/10"
                    :style="weekTimeSlotStyle(hour)"
                  ></span>

                  <article
                    v-for="event in cell.events"
                    :key="`${cell.dateKey}-week-${event.id}`"
                    class="absolute left-1 right-1 overflow-hidden rounded-xl border border-white/10 px-2 py-1 text-left text-[11px] font-semibold shadow-lg transition hover:scale-[1.01]"
                    :style="[eventColorStyle(event), weekEventBlockStyle(event, cell.dateKey)]"
                    :title="`${formatTime(event.start)} - ${formatTime(event.end)} ${event.title}`"
                    @click.stop="selectDate(cell.dateKey)"
                  >
                    <span class="block truncate text-[10px] opacity-90">
                      {{ formatTime(event.start) }} - {{ formatTime(event.end) }}
                    </span>
                    <span class="block truncate">{{ event.title }}</span>
                    <span v-if="event.location" class="block truncate text-[10px] opacity-80">{{ event.location }}</span>
                  </article>
                </button>
              </div>
            </div>
          </div>
        </section>

        <aside class="flex min-h-0 flex-col overflow-hidden rounded-3xl border border-white/10 bg-white/10 p-4 shadow-2xl backdrop-blur-xl">
          <div class="mb-3 flex-shrink-0">
            <p class="text-xs font-bold uppercase tracking-[0.3em] text-slate-400">Agenda Tanggal</p>
            <h2 class="mt-2 text-xl font-bold">{{ selectedDateLabel }}</h2>
            <p class="mt-1 text-sm text-slate-400">{{ selectedDateEvents.length }} agenda ditemukan</p>
          </div>

          <div v-if="loading" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-5 text-sm text-slate-300">
            Memuat jadwal...
          </div>

          <div v-else-if="!selectedDateEvents.length" class="rounded-2xl border border-dashed border-white/15 bg-white/5 px-4 py-8 text-center text-sm text-slate-400">
            Belum ada agenda pada tanggal ini.
          </div>

          <div
            v-else
            ref="detailScrollEl"
            class="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1"
          >
            <article
              v-for="event in selectedDateEvents"
              :key="event.id"
              class="rounded-2xl border border-white/10 bg-slate-950/60 p-4"
            >
              <div class="mb-3 inline-flex rounded-full px-3 py-1 text-xs font-bold" :style="eventColorStyle(event)">
                {{ formatTime(event.start) }} - {{ formatTime(event.end) }}
              </div>
              <h3 class="text-lg font-bold">{{ event.title }}</h3>
              <p v-if="event.location" class="mt-2 flex items-center gap-2 text-sm text-slate-300">
                <MapPinIcon class="h-4 w-4 text-blue-300" />
                {{ event.location }}
              </p>
              <p v-if="event.description" class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-300">
                {{ event.description }}
              </p>
              <div v-if="event.users.length" class="mt-4 flex flex-wrap gap-2">
                <span
                  v-for="user in event.users"
                  :key="user.id"
                  class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-200"
                >
                  {{ user.nama_lengkap || user.name }}
                </span>
              </div>
            </article>
          </div>
        </aside>
      </div>

      <div v-if="scanDialogOpen" class="public-scan-dialog fixed inset-0 z-50 flex flex-col overflow-hidden bg-slate-950 text-white">
        <div class="pointer-events-none fixed inset-0 overflow-hidden">
          <div class="absolute left-[-12rem] top-[-10rem] h-[28rem] w-[28rem] rounded-full bg-blue-500/20 blur-3xl"></div>
          <div class="absolute bottom-[-12rem] right-[-8rem] h-[30rem] w-[30rem] rounded-full bg-cyan-400/10 blur-3xl"></div>
        </div>

        <div class="relative z-10 flex min-h-0 flex-1 flex-col">
          <div class="flex flex-shrink-0 items-start justify-between gap-4 border-b border-white/10 bg-white/5 px-5 py-4 backdrop-blur-xl">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-200">Scan Dokumen</p>
              <h2 class="mt-2 text-2xl font-bold">Pilih pemilik dokumen scan</h2>
              <p class="mt-2 text-sm text-slate-300">
                Buat sesi, scan ke folder SMB, preview hasilnya, rename, lalu upload ke SIMANIS.
              </p>
            </div>
            <button
              type="button"
              class="rounded-2xl border border-white/10 p-2 text-slate-300 transition hover:bg-white/10"
              @click="closeScanDialog"
            >
              <XMarkIcon class="h-5 w-5" />
            </button>
          </div>

          <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 pb-6">
            <div v-if="!activeScanSession" class="mx-auto max-w-3xl rounded-3xl border border-white/10 bg-white/10 p-6 shadow-2xl backdrop-blur-xl">
              <label class="block">
                <span class="text-sm font-semibold text-slate-200">Asisten aktif</span>
                <select
                  v-model="scanForm.assistant_user_id"
                  class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-blue-400"
                  :disabled="scanLoadingAssistants || scanCreating"
                >
                  <option value="">{{ scanLoadingAssistants ? 'Memuat asisten...' : 'Pilih asisten' }}</option>
                  <option v-for="assistant in scanAssistants" :key="assistant.id" :value="assistant.id">
                    {{ assistant.nama_lengkap }} ({{ assistant.id_user }})
                  </option>
                </select>
              </label>

              <label class="mt-4 block">
                <span class="text-sm font-semibold text-slate-200">Catatan singkat</span>
                <textarea
                  v-model="scanForm.note"
                  rows="4"
                  class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-blue-400"
                  placeholder="Opsional, misalnya nama client atau keterangan dokumen."
                ></textarea>
              </label>

              <button
                type="button"
                class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-500 px-4 py-3 text-sm font-bold text-white transition hover:bg-blue-400 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!scanForm.assistant_user_id || scanCreating"
                @click="createScanSession"
              >
                <ArrowPathIcon v-if="scanCreating" class="h-5 w-5 animate-spin" />
                <DocumentArrowUpIcon v-else class="h-5 w-5" />
                {{ scanCreating ? 'Membuat sesi...' : 'Mulai Sesi Scan' }}
              </button>
            </div>

            <div v-else class="grid min-h-0 flex-1 gap-4 xl:grid-cols-[27rem_minmax(0,1fr)]">
              <aside class="flex min-h-0 flex-col overflow-hidden rounded-2xl border border-blue-300/20 bg-blue-500/10 shadow-2xl backdrop-blur-xl xl:max-h-[calc(100vh-10.5rem)]">
                <div class="flex-shrink-0 border-b border-white/10 px-3 py-2.5">
                  <p class="text-[11px] font-semibold text-blue-100">Sesi aktif</p>
                  <h3 class="truncate text-base font-bold">{{ activeScanSession.assistant?.nama_lengkap }}</h3>
                  <details class="mt-1.5 rounded-xl border border-white/10 bg-slate-950/40 text-xs">
                    <summary class="cursor-pointer list-none px-3 py-1.5 font-bold text-blue-100">
                      Detail sesi
                    </summary>
                    <div class="border-t border-white/10 px-3 py-2">
                      <p class="font-bold uppercase tracking-widest text-slate-400">Token</p>
                      <p class="mt-1 break-all font-mono text-blue-100">{{ activeScanSession.token }}</p>
                      <p class="mt-1 text-slate-400">Aktif sampai {{ formatDateTime(activeScanSession.expires_at) }}</p>
                    </div>
                  </details>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-hidden p-2.5">
                  <div class="flex items-center justify-between gap-3">
                    <div>
                      <p class="text-xs font-bold text-white">File hasil scan</p>
                      <p class="truncate text-[10px] text-slate-400">{{ scannerAgentBase }}</p>
                    </div>
                    <button
                      type="button"
                      class="inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-white/15 px-2.5 text-[11px] font-bold text-slate-100 transition hover:bg-white/10 disabled:opacity-60"
                      :disabled="scannerFilesLoading"
                      @click="loadScannerFiles"
                    >
                      <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': scannerFilesLoading }" />
                      Refresh
                    </button>
                  </div>

                  <p v-if="scannerAgentError" class="mt-3 rounded-xl border border-red-300/30 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-100">
                    {{ scannerAgentError }}
                  </p>
                  <p v-else-if="scannerFilesLoading" class="mt-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300">
                    Mengecek folder scanner...
                  </p>
                  <p v-else-if="!scannerFiles.length" class="mt-3 rounded-xl border border-dashed border-white/15 bg-white/5 px-3 py-6 text-center text-xs text-slate-400">
                    Belum ada file. Scan dokumen ke folder SMB lalu klik Refresh.
                  </p>

                  <div v-else class="flex min-h-0 flex-1 flex-col gap-2">
                    <div class="flex flex-col gap-2 rounded-lg border border-white/10 bg-slate-950/80 p-2 sm:flex-row sm:items-center sm:justify-between">
                      <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-200">
                        <input
                          type="checkbox"
                          class="h-4 w-4 rounded border-white/20 bg-slate-900 text-blue-500"
                          :checked="allReadyScannerFilesSelected"
                          @change="toggleAllScannerFiles"
                        >
                        Pilih semua siap
                      </label>
                      <button
                        type="button"
                        class="inline-flex h-8 items-center justify-center gap-1.5 rounded-lg bg-emerald-500 px-2.5 text-[11px] font-bold text-white transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!selectedScannerFiles.length || Boolean(uploadingScannerFile)"
                        @click="uploadSelectedScannerFiles"
                      >
                        <ArrowPathIcon v-if="uploadingScannerFile === '__bulk__'" class="h-4 w-4 animate-spin" />
                        <DocumentArrowUpIcon v-else class="h-4 w-4" />
                        Upload Terpilih ({{ selectedScannerFiles.length }})
                      </button>
                    </div>

                    <div class="min-h-0 flex-1 space-y-1.5 overflow-y-auto pr-1">
                      <button
                        v-for="file in scannerFiles"
                        :key="file.name"
                        type="button"
                        class="w-full rounded-lg border p-2 text-left transition"
                        :class="previewScannerFileName === file.name ? 'border-blue-300 bg-blue-500/15' : 'border-white/10 bg-white/5 hover:bg-white/10'"
                        @click="selectScannerPreview(file)"
                      >
                        <div class="flex gap-3">
                          <input
                            v-model="selectedScannerFiles"
                            type="checkbox"
                            class="mt-1 h-4 w-4 flex-shrink-0 rounded border-white/20 bg-slate-900 text-blue-500"
                            :value="file.name"
                            :disabled="!file.ready || Boolean(uploadingScannerFile)"
                            @click.stop
                          >
                          <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-bold text-white" :title="file.name">{{ file.name }}</p>
                            <p class="mt-0.5 text-[10px] text-slate-400">
                              {{ formatBytes(file.size_bytes) }} - {{ formatDateTime(file.last_write_time) }}
                            </p>
                            <p v-if="!file.ready" class="mt-1 text-xs font-semibold text-amber-200">Masih diproses scanner</p>
                          </div>
                          <EyeIcon class="mt-1 h-5 w-5 flex-shrink-0 text-blue-200" />
                        </div>
                      </button>
                    </div>
                  </div>

                  <div v-if="uploadedScanDocuments.length" class="max-h-40 flex-shrink-0 overflow-y-auto rounded-xl border border-emerald-300/20 bg-emerald-500/10 p-2.5">
                    <div class="flex items-center justify-between gap-2">
                      <div>
                        <p class="text-sm font-bold text-white">Dokumen sudah di server</p>
                        <p class="mt-1 text-xs text-slate-400">Bisa langsung diposting tanpa login asisten.</p>
                      </div>
                      <button
                        type="button"
                        class="rounded-xl border border-white/15 px-3 py-2 text-xs font-bold text-slate-100 transition hover:bg-white/10"
                        @click="loadPublicScanDocuments"
                      >
                        Muat
                      </button>
                    </div>
                    <div class="mt-2 space-y-1.5 pr-1">
                      <article
                        v-for="document in uploadedScanDocuments"
                        :key="document.id"
                        class="rounded-xl border border-white/10 bg-slate-950/60 p-3"
                      >
                        <div class="flex items-start justify-between gap-3">
                          <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-white" :title="document.original_name || document.title || ''">
                              {{ document.original_name || document.title || `Dokumen #${document.id}` }}
                            </p>
                            <p class="mt-1 text-xs text-slate-400">
                              {{ formatBytes(document.size_bytes) }} - {{ formatDateTime(document.created_at) }}
                            </p>
                            <p v-if="document.status === 'posted'" class="mt-1 text-xs font-semibold text-emerald-100">
                              Diposting ke {{ moduleLabel(document.posted_module) }} #{{ document.posted_record_id }}
                            </p>
                          </div>
                          <button
                            type="button"
                            class="inline-flex flex-shrink-0 items-center gap-1 rounded-xl px-3 py-2 text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-60"
                            :class="document.status === 'posted' ? 'border border-emerald-300/30 text-emerald-100' : 'bg-emerald-500 text-white hover:bg-emerald-400'"
                            :disabled="document.status === 'posted' || publicPostingId === document.id"
                            @click="openPublicPosting(document)"
                          >
                            <ArrowPathIcon v-if="publicPostingId === document.id" class="h-4 w-4 animate-spin" />
                            <PaperAirplaneIcon v-else class="h-4 w-4" />
                            {{ document.status === 'posted' ? 'Selesai' : 'Posting' }}
                          </button>
                        </div>
                      </article>
                    </div>
                  </div>

                </div>

                <div class="sticky bottom-0 z-10 flex-shrink-0 border-t border-white/10 bg-slate-950/80 p-2.5 backdrop-blur-xl">
                  <button
                    type="button"
                    class="h-9 w-full rounded-xl border border-white/15 bg-white/5 px-3 text-xs font-bold text-slate-100 transition hover:bg-white/10"
                    @click="startNewScanSessionForm"
                  >
                    Buat sesi baru
                  </button>
                </div>
              </aside>

              <section class="flex min-h-0 flex-col overflow-hidden rounded-3xl border border-white/10 bg-white/10 shadow-2xl backdrop-blur-xl">
                <div class="flex flex-shrink-0 flex-col gap-3 border-b border-white/10 p-5 lg:flex-row lg:items-end lg:justify-between">
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-200">Preview & Rename</p>
                    <h3 class="mt-2 truncate text-2xl font-bold">
                      {{ previewScannerFile?.name || 'Pilih file scan' }}
                    </h3>
                    <p class="mt-1 text-sm text-slate-400">
                      Nama di bawah ini yang akan tersimpan di server saat upload.
                    </p>
                  </div>
                  <button
                    v-if="previewScannerFile"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-bold text-white transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!previewScannerFile.ready || uploadingScannerFile === previewScannerFile.name"
                    @click="uploadScannerFile(previewScannerFile)"
                  >
                    <ArrowPathIcon v-if="uploadingScannerFile === previewScannerFile.name" class="h-5 w-5 animate-spin" />
                    <DocumentArrowUpIcon v-else class="h-5 w-5" />
                    {{ uploadingScannerFile === previewScannerFile.name ? 'Upload...' : 'Upload File Ini' }}
                  </button>
                </div>

                <div v-if="previewScannerFile" class="grid min-h-0 flex-1 gap-4 p-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                  <div class="min-h-0 overflow-hidden rounded-2xl border border-white/10 bg-slate-950">
                    <img
                      v-if="previewScannerIsImage"
                      :src="previewScannerUrl"
                      class="h-full w-full object-contain"
                      alt="Preview scan"
                    >
                    <iframe
                      v-else
                      :src="previewScannerUrl"
                      class="h-full w-full border-0 bg-white"
                      title="Preview dokumen scan"
                    ></iframe>
                  </div>

                  <div class="space-y-4 rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                    <label class="block">
                      <span class="text-sm font-semibold text-slate-200">Rename sebelum upload</span>
                      <input
                        v-model="scannerUploadNames[previewScannerFile.name]"
                        type="text"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-blue-400"
                        placeholder="Nama file di server"
                      >
                    </label>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-xs text-slate-300">
                      <p><span class="font-bold text-slate-100">Ukuran:</span> {{ formatBytes(previewScannerFile.size_bytes) }}</p>
                      <p class="mt-2"><span class="font-bold text-slate-100">Waktu scan:</span> {{ formatDateTime(previewScannerFile.last_write_time) }}</p>
                      <p class="mt-2"><span class="font-bold text-slate-100">Status:</span> {{ previewScannerFile.ready ? 'Siap upload' : 'Masih diproses scanner' }}</p>
                    </div>
                  </div>
                </div>

                <div v-else class="flex min-h-0 flex-1 items-center justify-center p-6 text-center text-slate-400">
                  Pilih salah satu file dari daftar kiri untuk melihat preview.
                </div>
              </section>
            </div>
          </div>
        </div>

        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="translate-y-2 opacity-0"
          enter-to-class="translate-y-0 opacity-100"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="translate-y-0 opacity-100"
          leave-to-class="translate-y-2 opacity-0"
        >
          <div
            v-if="scanToastMessage"
            class="fixed right-4 top-5 z-[80] w-[min(92vw,28rem)] rounded-2xl border px-4 py-3 text-sm font-semibold shadow-2xl backdrop-blur"
            :class="scanError ? 'border-red-300/40 bg-red-500/20 text-red-50 shadow-red-950/30' : 'border-emerald-300/40 bg-emerald-500/20 text-emerald-50 shadow-emerald-950/30'"
          >
            {{ scanToastMessage }}
          </div>
        </Transition>
      </div>

      <div v-if="publicPostingDialog.open" class="public-scan-dialog fixed inset-0 z-[70] flex flex-col bg-slate-950 text-white">
        <header class="flex flex-shrink-0 items-center justify-between gap-4 border-b border-white/10 bg-slate-900 px-5 py-4">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-200">Posting Dokumen Scan</p>
            <h2 class="mt-1 truncate text-lg font-bold">{{ publicPostingDialog.title }}</h2>
            <p class="mt-1 text-sm text-slate-400">Pilih modul dan target, lalu dokumen langsung dipindahkan ke folder modul tujuan.</p>
          </div>
          <button
            type="button"
            class="rounded-2xl border border-white/15 p-2 text-white transition hover:bg-white/10"
            @click="closePublicPosting"
          >
            <XMarkIcon class="h-6 w-6" />
          </button>
        </header>

        <main class="grid min-h-0 flex-1 gap-4 bg-slate-950 p-4 xl:grid-cols-[minmax(0,1fr)_28rem]">
          <section class="min-h-0 overflow-hidden rounded-3xl border border-white/10 bg-slate-900">
            <img
              v-if="isPublicPostingPreviewImage && publicPostingDocument"
              :src="publicDocumentPreviewUrl(publicPostingDocument)"
              :alt="publicPostingDialog.title"
              class="mx-auto h-full max-h-full max-w-full object-contain"
            >
            <iframe
              v-else-if="publicPostingDocument"
              :src="publicDocumentPreviewUrl(publicPostingDocument)"
              class="h-full w-full rounded-3xl border-0 bg-white"
              title="Preview posting dokumen scan"
            ></iframe>
          </section>

          <aside class="min-h-0 overflow-y-auto rounded-3xl border border-white/10 bg-white/10 p-5 shadow-2xl backdrop-blur-xl">
            <div v-if="publicPostingDialog.error" class="mb-4 rounded-2xl border border-red-300/30 bg-red-500/10 px-4 py-3 text-sm font-semibold text-red-100">
              {{ publicPostingDialog.error }}
            </div>

            <label class="block">
              <span class="text-sm font-bold text-slate-100">Modul tujuan</span>
              <select
                v-model="publicPostingDialog.module"
                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
                @change="publicPostingDialog.recordId = ''; loadPublicPostingTargets()"
              >
                <option v-for="item in postingModules" :key="item.value" :value="item.value">
                  {{ item.label }}
                </option>
              </select>
            </label>

            <label class="mt-4 block">
              <span class="text-sm font-bold text-slate-100">Cari target</span>
              <div class="mt-2 flex gap-2">
                <input
                  v-model="publicPostingDialog.search"
                  type="search"
                  class="min-w-0 flex-1 rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
                  placeholder="Cari nomor, nama client, atau keterangan..."
                  @keyup.enter="loadPublicPostingTargets"
                >
                <button
                  type="button"
                  class="rounded-2xl bg-blue-500 px-4 py-3 text-sm font-bold text-white transition hover:bg-blue-400 disabled:opacity-60"
                  :disabled="publicPostingDialog.loadingTargets"
                  @click="loadPublicPostingTargets"
                >
                  Cari
                </button>
              </div>
            </label>

            <div class="mt-4 rounded-2xl border border-white/10 bg-slate-950/70 p-3">
              <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Pilih target</p>
              <p v-if="publicPostingDialog.loadingTargets" class="mt-3 text-sm text-slate-300">Memuat target...</p>
              <p v-else-if="!publicPostingDialog.targets.length" class="mt-3 text-sm text-slate-400">Target belum ditemukan. Coba kata kunci lain.</p>
              <div v-else class="mt-3 max-h-64 space-y-2 overflow-y-auto pr-1">
                <label
                  v-for="target in publicPostingDialog.targets"
                  :key="String(target.id)"
                  class="flex cursor-pointer gap-3 rounded-xl border p-3 transition"
                  :class="String(publicPostingDialog.recordId) === String(target.id) ? 'border-emerald-300 bg-emerald-500/15' : 'border-white/10 bg-white/5 hover:bg-white/10'"
                >
                  <input
                    v-model="publicPostingDialog.recordId"
                    type="radio"
                    class="mt-1 h-4 w-4 border-white/20 bg-slate-900 text-emerald-500"
                    :value="String(target.id)"
                  >
                  <span class="min-w-0">
                    <span class="block text-sm font-bold text-white">{{ target.title || target.id }}</span>
                    <span class="mt-1 block truncate text-xs text-slate-400">{{ target.subtitle || target.id }}</span>
                  </span>
                </label>
              </div>
            </div>

            <label class="mt-4 block">
              <span class="text-sm font-bold text-slate-100">Nama dokumen di modul</span>
              <input
                v-model="publicPostingDialog.documentName"
                type="text"
                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
                placeholder="Contoh: SK Kemenkumham"
              >
            </label>

            <label class="mt-4 block">
              <span class="text-sm font-bold text-slate-100">Nama file final</span>
              <input
                v-model="publicPostingDialog.fileName"
                type="text"
                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
                placeholder="Contoh: SK Kemenkumham.pdf"
              >
            </label>

            <button
              type="button"
              class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-bold text-white transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="publicPostingId === publicPostingDialog.id || !publicPostingDialog.recordId || !publicPostingDialog.documentName"
              @click="submitPublicPosting"
            >
              <ArrowPathIcon v-if="publicPostingId === publicPostingDialog.id" class="h-5 w-5 animate-spin" />
              <PaperAirplaneIcon v-else class="h-5 w-5" />
              {{ publicPostingId === publicPostingDialog.id ? 'Memposting...' : 'Posting ke Modul' }}
            </button>
          </aside>
        </main>
      </div>
    </section>

    <Teleport to="body">
      <Transition name="public-keyboard">
        <section
          v-if="virtualKeyboardOpen"
          class="public-virtual-keyboard fixed inset-x-0 bottom-0 z-[120] border-t border-white/15 p-2 shadow-2xl backdrop-blur-2xl sm:p-3"
          :class="isDark ? 'public-theme-dark' : 'public-theme-light'"
          aria-label="Keyboard virtual"
          @mousedown.prevent
        >
          <div class="mx-auto max-w-5xl">
            <div class="mb-2 flex items-center justify-between gap-3 px-1">
              <p class="truncate text-xs font-bold uppercase tracking-[0.2em]">Keyboard Virtual</p>
              <button type="button" class="rounded-lg border border-current/20 px-3 py-1 text-xs font-bold" @click="closeVirtualKeyboard">
                Tutup
              </button>
            </div>
            <div v-for="(row, rowIndex) in virtualKeyboardRows" :key="`keyboard-row-${rowIndex}`" class="mb-1.5 flex justify-center gap-1 sm:gap-1.5">
              <button
                v-for="key in row"
                :key="key"
                type="button"
                class="virtual-key min-w-0 flex-1 rounded-lg border px-1 py-2 text-sm font-bold shadow-sm transition active:translate-y-px sm:max-w-20 sm:py-2.5"
                @click="pressVirtualKey(key)"
              >
                {{ displayVirtualKey(key) }}
              </button>
            </div>
            <div class="flex justify-center gap-1 sm:gap-1.5">
              <button type="button" class="virtual-key rounded-lg border px-3 py-2 text-xs font-bold sm:py-2.5" :class="{ 'virtual-key-active': virtualKeyboardShift }" @click="toggleVirtualKeyboardShift">Shift</button>
              <button type="button" class="virtual-key min-w-0 flex-[5] rounded-lg border py-2 text-xs font-bold sm:max-w-lg sm:py-2.5" @click="pressVirtualKey(' ')">Spasi</button>
              <button type="button" class="virtual-key rounded-lg border px-3 py-2 text-xs font-bold sm:py-2.5" @click="pressVirtualKey('BACKSPACE')">Hapus</button>
              <button type="button" class="virtual-key rounded-lg border px-3 py-2 text-xs font-bold sm:py-2.5" @click="pressVirtualKey('ENTER')">Enter</button>
            </div>
          </div>
        </section>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="themeMenuOpen"
        class="public-theme-menu public-theme-menu-floating max-h-[calc(100vh-5rem)] w-80 overflow-y-auto rounded-3xl border border-white/15 bg-slate-950/95 p-2 text-white shadow-2xl backdrop-blur-xl"
        :class="isDark ? 'public-theme-dark' : 'public-theme-light'"
        :style="themeMenuStyle"
      >
        <div class="px-3 py-2">
          <p class="text-xs font-bold uppercase tracking-[0.25em] text-blue-200">Tema Warna</p>
          <p class="mt-1 text-xs text-slate-400">Pilih aksen seperti dashboard SIMANIS.</p>
        </div>
        <button
          type="button"
          class="public-theme-option flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left transition hover:bg-white/10"
          @click="toggleTheme"
        >
          <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl bg-white/10">
            <SunIcon v-if="isDark" class="h-5 w-5" />
            <MoonIcon v-else class="h-5 w-5" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold">{{ isDark ? 'Light Mode' : 'Dark Mode' }}</span>
            <span class="mt-1 block text-xs text-slate-400">{{ isDark ? 'Ganti tampilan ke terang.' : 'Ganti tampilan ke gelap.' }}</span>
          </span>
        </button>
        <div class="my-2 border-t border-white/10"></div>
        <button
          v-for="option in accentThemeOptions"
          :key="option.value"
          type="button"
          class="public-theme-option flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition hover:bg-white/10"
          :class="accentTheme === option.value ? 'bg-white/10' : ''"
          @click="selectPublicAccent(option.value)"
        >
          <span class="h-10 w-14 flex-shrink-0 rounded-2xl shadow-inner ring-1 ring-white/20" :style="{ background: option.swatch }"></span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold">{{ option.name }}</span>
            <span class="block truncate text-xs text-slate-400">{{ option.description }}</span>
          </span>
          <CheckIcon v-if="accentTheme === option.value" class="h-5 w-5 text-blue-200" />
        </button>
      </div>
    </Teleport>
  </main>
</template>

<script setup lang="ts">
import {
  ArrowPathIcon,
  ArrowRightOnRectangleIcon,
  ArrowsPointingInIcon,
  ArrowsPointingOutIcon,
  CalendarDaysIcon,
  CheckIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  DocumentArrowUpIcon,
  EyeIcon,
  MapPinIcon,
  MoonIcon,
  PaperAirplaneIcon,
  SwatchIcon,
  SunIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

useHead({
  title: 'Jadwal Notaris Publik',
})

type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type RawRecord = Record<string, unknown>

type UserOption = {
  id: string | number
  nama_lengkap?: string
  name?: string
}

type CalendarEvent = {
  id: string | number
  title: string
  location: string
  description: string
  start: Date
  end: Date
  users: UserOption[]
  creator: UserOption | null
  color: string
}

type CalendarCell = {
  dateKey: string
  dateNumber: number
  inCurrentMonth: boolean
  isToday: boolean
  events: CalendarEvent[]
}

type ScanAssistant = {
  id: string | number
  id_user: string | number
  nama_lengkap: string
  level_user?: string
}

type ScanSessionPayload = {
  token: string
  status: string
  output_type: string
  expires_at: string
  assistant?: ScanAssistant
  documents?: ScannedDocumentRow[]
}

type ScannedDocumentRow = {
  id: number
  title?: string | null
  original_name?: string
  file_name?: string
  file_path?: string
  mime_type?: string
  extension?: string
  size_bytes?: number
  note?: string | null
  status?: string
  posted_module?: string | null
  posted_record_id?: string | number | null
  posted_document_id?: string | number | null
  posted_file_path?: string | null
  posted_at?: string | null
  created_at?: string
}

type PostingTarget = {
  id: string | number
  title?: string
  subtitle?: string
}

type ScannerFile = {
  name: string
  size_bytes: number
  extension: string
  last_write_time: string
  ready: boolean
}

const { request, withBase } = useApi()
const {
  activeAccentTheme,
  accentTheme,
  accentThemeOptions,
  isDark,
  setAccentTheme,
  toggleTheme,
} = useThemeMode()

const scannerAgentBase = 'http://127.0.0.1:8787'
const weekdays = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab']
const virtualKeyboardRows = [
  ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
  ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
  ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
  ['z', 'x', 'c', 'v', 'b', 'n', 'm', '-', '.', '/'],
]
const loading = ref(false)
const errorMessage = ref('')
const events = ref<CalendarEvent[]>([])
const cursorDate = ref(new Date())
const selectedDateKey = ref('')
const calendarView = ref<'month' | 'week'>('month')
const detailScrollEl = ref<HTMLElement | null>(null)
const themeButtonEl = ref<HTMLElement | null>(null)
const scanDialogOpen = ref(false)
const themeMenuOpen = ref(false)
const virtualKeyboardOpen = ref(false)
const virtualKeyboardShift = ref(false)
const virtualKeyboardTarget = shallowRef<HTMLInputElement | HTMLTextAreaElement | null>(null)
const themeMenuPosition = reactive({
  top: 84,
  left: 0,
})
const scanAssistants = ref<ScanAssistant[]>([])
const scanLoadingAssistants = ref(false)
const scanCreating = ref(false)
const scanCheckingSession = ref(false)
const scanSessionChecked = ref(false)
const scanError = ref('')
const scanMessage = ref('')
const activeScanSession = ref<ScanSessionPayload | null>(null)
const scannerFiles = ref<ScannerFile[]>([])
const scannerFilesLoading = ref(false)
const scannerAgentError = ref('')
const uploadingScannerFile = ref('')
const selectedScannerFiles = ref<string[]>([])
const scannerUploadNames = reactive<Record<string, string>>({})
const previewScannerFileName = ref('')
const uploadedScanDocuments = ref<ScannedDocumentRow[]>([])
const publicPostingId = ref<number | null>(null)
const publicPostingDialog = reactive({
  open: false,
  id: 0,
  title: '',
  module: 'client',
  search: '',
  documentName: '',
  fileName: '',
  recordId: '',
  loadingTargets: false,
  targets: [] as PostingTarget[],
  error: '',
})
const isFullscreen = ref(false)
const scanForm = reactive({
  assistant_user_id: '',
  note: '',
})
let detailAutoScrollFrame = 0
let scanToastTimeout = 0
let detailAutoScrollHoldUntil = 0
let detailAutoScrollLastTime = 0

const postingModules = [
  { value: 'client', label: 'Dokumen Client' },
  { value: 'buku_notaris', label: 'Buku Akta Notaris' },
  { value: 'buku_ppat', label: 'Buku PPAT' },
  { value: 'buku_legalisasi', label: 'Buku Legalisasi' },
  { value: 'buku_warmerking', label: 'Buku Waarmerking' },
  { value: 'surat_notaris', label: 'Surat Notaris' },
  { value: 'surat_ppat', label: 'Surat PPAT' },
  { value: 'tanda_terima', label: 'Tanda Terima' },
]

const moduleLabel = (value: unknown) =>
  postingModules.find(item => item.value === String(value || ''))?.label || String(value || '-')

const scanToastMessage = computed(() => scanError.value || scanMessage.value)

const pad = (value: number) => String(value).padStart(2, '0')

const formatDateKey = (date: Date) =>
  `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`

const parseDateValue = (value: unknown): Date | null => {
  const raw = String(value || '').trim()
  if (!raw) return null

  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
    const parsed = new Date(`${raw}T00:00:00`)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T')
  const parsed = new Date(normalized)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

const formatDateTime = (value: unknown) => {
  const raw = String(value || '').trim()
  if (!raw) return '-'
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) return raw
  return date.toLocaleString('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const formatBytes = (value: unknown) => {
  const size = Number(value || 0)
  if (!Number.isFinite(size) || size <= 0) return '-'
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(2)} MB`
  if (size >= 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${size} B`
}

const formatTime = (date: Date) =>
  new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)

const formatHourLabel = (hour: number) => `${pad(hour)}.00`

const normalizeUsers = (value: unknown): UserOption[] => {
  if (!Array.isArray(value)) return []

  return value
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const row = item as RawRecord
      const id = (row.id as string | number) ?? (row.id_user as string | number)
      if (id === null || id === undefined || String(id) === '') return null

      return {
        id,
        nama_lengkap: String(row.nama_lengkap || row.name || ''),
        name: String(row.name || row.nama_lengkap || ''),
      }
    })
    .filter(Boolean) as UserOption[]
}

const normalizeEventRow = (row: RawRecord, index: number): CalendarEvent | null => {
  const title = String(row.title || row.name || row.judul || 'Jadwal').trim()
  const startDate = parseDateValue(row.start_datetime || row.start || row.tanggal_mulai || row.tanggal)
  const endDate = parseDateValue(row.end_datetime || row.end || row.tanggal_selesai) || startDate

  if (!startDate || !endDate) return null

  const rawId = (row.id as string | number) ?? (row.id_jadwal as string | number)
  const creatorRaw = row.creator
  let creator: UserOption | null = null

  if (creatorRaw && typeof creatorRaw === 'object') {
    const c = creatorRaw as RawRecord
    const creatorId = (c.id as string | number) ?? (c.id_user as string | number)
    if (creatorId !== null && creatorId !== undefined) {
      creator = {
        id: creatorId,
        nama_lengkap: String(c.nama_lengkap || c.name || ''),
        name: String(c.name || c.nama_lengkap || ''),
      }
    }
  }

  return {
    id: rawId ?? `public-${index}-${formatDateKey(startDate)}-${title}`,
    title,
    location: String(row.location || row.jenis || '').trim(),
    description: String(row.description || row.keterangan || '').trim(),
    start: startDate,
    end: endDate >= startDate ? endDate : startDate,
    users: normalizeUsers(row.users),
    creator,
    color: String(row.color || '').trim(),
  }
}

const toList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<T[]>).data)) {
    return (payload as ApiEnvelope<T[]>).data || []
  }
  return []
}

const toDataList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const data = (payload as ApiEnvelope<unknown>).data
    if (Array.isArray(data)) return data as T[]
    if (data && typeof data === 'object') return [data as T]
  }
  if (payload && typeof payload === 'object') return [payload as T]
  return []
}

const eventColorStyle = (event: CalendarEvent) => {
  const custom = String(event.color || '').trim()
  if (/^#([0-9A-Fa-f]{3}){1,2}$/.test(custom)) {
    return { backgroundColor: custom, color: '#ffffff' }
  }

  const location = event.location.toLowerCase()
  if (location.includes('kantor')) {
    return { backgroundColor: '#0f172a', color: '#ffffff' }
  }
  if (location.includes('luar')) {
    return { backgroundColor: '#2563eb', color: '#ffffff' }
  }
  return { backgroundColor: '#64748b', color: '#ffffff' }
}

const currentPeriodLabel = computed(() => {
  if (calendarView.value === 'week') {
    const { start, end } = visibleRange.value
    const format = new Intl.DateTimeFormat('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    })
    return `${format.format(start)} - ${format.format(end)}`
  }

  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(cursorDate.value)
})

const selectedDateLabel = computed(() => {
  const parsed = parseDateValue(selectedDateKey.value)
  if (!parsed) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(parsed)
})

const visibleRange = computed(() => {
  if (calendarView.value === 'week') {
    const base = new Date(cursorDate.value)
    const start = new Date(base)
    start.setDate(base.getDate() - base.getDay())
    start.setHours(0, 0, 0, 0)
    const end = new Date(start)
    end.setDate(start.getDate() + 6)
    end.setHours(0, 0, 0, 0)
    return { start, end }
  }

  const year = cursorDate.value.getFullYear()
  const month = cursorDate.value.getMonth()
  const first = new Date(year, month, 1)
  const start = new Date(year, month, 1 - first.getDay())
  const end = new Date(start)
  end.setDate(start.getDate() + 41)
  return { start, end }
})

const eventsByDate = computed(() => {
  const map = new Map<string, CalendarEvent[]>()

  events.value.forEach((event) => {
    const startAt = new Date(event.start)
    let endAt = new Date(event.end)

    if (
      endAt.getTime() > startAt.getTime()
      && endAt.getHours() === 0
      && endAt.getMinutes() === 0
      && endAt.getSeconds() === 0
      && endAt.getMilliseconds() === 0
    ) {
      endAt = new Date(endAt.getTime() - 1)
    }

    const start = new Date(startAt)
    const end = new Date(endAt)
    start.setHours(0, 0, 0, 0)
    end.setHours(0, 0, 0, 0)

    if (end < start) end.setTime(start.getTime())

    const cursor = new Date(start)
    while (cursor <= end) {
      const key = formatDateKey(cursor)
      const list = map.get(key) || []
      list.push(event)
      map.set(key, list)
      cursor.setDate(cursor.getDate() + 1)
    }
  })

  map.forEach((list, key) => {
    map.set(
      key,
      [...list].sort((a, b) =>
        a.start.getTime() - b.start.getTime() || a.title.localeCompare(b.title, 'id-ID'),
      ),
    )
  })

  return map
})

const calendarCells = computed(() => {
  const year = cursorDate.value.getFullYear()
  const month = cursorDate.value.getMonth()
  const start = new Date(visibleRange.value.start)
  const todayKey = formatDateKey(new Date())
  const length = calendarView.value === 'week' ? 7 : 42

  return Array.from({ length }, (_, index) => {
    const date = new Date(start)
    date.setDate(start.getDate() + index)
    const dateKey = formatDateKey(date)

    return {
      dateKey,
      dateNumber: date.getDate(),
      inCurrentMonth: date.getMonth() === month,
      isToday: dateKey === todayKey,
      events: eventsByDate.value.get(dateKey) || [],
    } satisfies CalendarCell
  })
})

const selectedDateEvents = computed(() =>
  (eventsByDate.value.get(selectedDateKey.value) || []).slice().sort((a, b) =>
    a.start.getTime() - b.start.getTime() || a.title.localeCompare(b.title, 'id-ID'),
  ),
)

const eventSpanForDate = (event: CalendarEvent, dateKey: string) => {
  const dayStart = parseDateValue(dateKey) || new Date()
  dayStart.setHours(0, 0, 0, 0)

  const dayEnd = new Date(dayStart)
  dayEnd.setDate(dayEnd.getDate() + 1)

  const effectiveStart = new Date(Math.max(event.start.getTime(), dayStart.getTime()))
  const effectiveEnd = new Date(Math.min(event.end.getTime(), dayEnd.getTime()))

  const startMinutes = effectiveStart.getHours() * 60 + effectiveStart.getMinutes()
  let endMinutes = effectiveEnd.getHours() * 60 + effectiveEnd.getMinutes()

  if (effectiveEnd.getTime() >= dayEnd.getTime()) {
    endMinutes = 24 * 60
  }

  if (endMinutes <= startMinutes) {
    endMinutes = Math.min(24 * 60, startMinutes + 60)
  }

  return { startMinutes, endMinutes }
}

const visibleWeekEventSpans = computed(() =>
  calendarCells.value.flatMap((cell) =>
    cell.events.map((event) => eventSpanForDate(event, cell.dateKey)),
  ),
)

const weekStartHour = computed(() => {
  if (!visibleWeekEventSpans.value.length) return 8

  const earliest = Math.min(...visibleWeekEventSpans.value.map(span => span.startMinutes))
  return Math.max(0, Math.floor(earliest / 60) - 1)
})

const weekEndHour = computed(() => {
  if (!visibleWeekEventSpans.value.length) return 18

  const latest = Math.max(...visibleWeekEventSpans.value.map(span => span.endMinutes))
  return Math.min(24, Math.max(weekStartHour.value + 1, Math.ceil(latest / 60) + 1))
})

const weekTimeSlots = computed(() =>
  Array.from({ length: weekEndHour.value - weekStartHour.value + 1 }, (_, index) => weekStartHour.value + index),
)

const weekTotalMinutes = computed(() =>
  Math.max(60, (weekEndHour.value - weekStartHour.value) * 60),
)

const weekTimeSlotStyle = (hour: number) => {
  const minutes = Math.max(0, (hour - weekStartHour.value) * 60)
  return {
    top: `${(minutes / weekTotalMinutes.value) * 100}%`,
  }
}

const weekEventBlockStyle = (event: CalendarEvent, dateKey: string) => {
  const span = eventSpanForDate(event, dateKey)
  const startOffsetMinutes = Math.max(0, span.startMinutes - weekStartHour.value * 60)
  const durationMinutes = Math.max(30, span.endMinutes - span.startMinutes)
  const top = (startOffsetMinutes / weekTotalMinutes.value) * 100
  const height = (durationMinutes / weekTotalMinutes.value) * 100

  return {
    top: `${top}%`,
    height: `calc(${Math.min(height, 100 - top)}% - 4px)`,
    minHeight: '28px',
  }
}

const readyScannerFileNames = computed(() =>
  scannerFiles.value.filter(file => file.ready).map(file => file.name),
)

const allReadyScannerFilesSelected = computed(() =>
  readyScannerFileNames.value.length > 0
  && readyScannerFileNames.value.every(name => selectedScannerFiles.value.includes(name)),
)

const previewScannerFile = computed(() =>
  scannerFiles.value.find(file => file.name === previewScannerFileName.value) || null,
)

const previewScannerUrl = computed(() => {
  const file = previewScannerFile.value
  if (!file) return ''
  return `${scannerAgentBase}/preview?file_name=${encodeURIComponent(file.name)}&v=${encodeURIComponent(file.last_write_time)}`
})

const previewScannerIsImage = computed(() => {
  const extension = String(previewScannerFile.value?.extension || '').toLowerCase()
  return extension === 'jpg' || extension === 'jpeg'
})

const uploadNameFor = (file: ScannerFile) => scannerUploadNames[file.name] || file.name

const upsertUploadedScanDocument = (document: ScannedDocumentRow) => {
  if (!document?.id) return
  const index = uploadedScanDocuments.value.findIndex(row => row.id === document.id)
  if (index >= 0) {
    uploadedScanDocuments.value.splice(index, 1, document)
  } else {
    uploadedScanDocuments.value = [document, ...uploadedScanDocuments.value]
  }
}

const publicDocumentPreviewUrl = (document: ScannedDocumentRow) => {
  const token = activeScanSession.value?.token || ''
  if (!document.id || !token) return ''
  return `${withBase(`/public/scan/documents/${document.id}/download`)}?token=${encodeURIComponent(token)}`
}

const publicPostingDocument = computed(() =>
  uploadedScanDocuments.value.find(row => row.id === publicPostingDialog.id) || null,
)

const isPublicPostingPreviewImage = computed(() => {
  const mimeType = String(publicPostingDocument.value?.mime_type || '').toLowerCase()
  const extension = String(publicPostingDocument.value?.extension || '').toLowerCase()
  return mimeType.startsWith('image/') || extension === 'jpg' || extension === 'jpeg'
})

const closePublicPosting = () => {
  publicPostingDialog.open = false
  publicPostingDialog.id = 0
  publicPostingDialog.title = ''
  publicPostingDialog.module = 'client'
  publicPostingDialog.search = ''
  publicPostingDialog.documentName = ''
  publicPostingDialog.fileName = ''
  publicPostingDialog.recordId = ''
  publicPostingDialog.targets = []
  publicPostingDialog.error = ''
}

const loadPublicScanDocuments = async () => {
  const token = activeScanSession.value?.token
  if (!token) {
    uploadedScanDocuments.value = []
    return
  }

  try {
    const response = await request<ApiEnvelope<ScannedDocumentRow[]>>(`/public/scan/sessions/${encodeURIComponent(token)}/documents`, {
      method: 'GET',
      auth: false,
    })
    uploadedScanDocuments.value = toList<ScannedDocumentRow>(response)
  } catch {
    uploadedScanDocuments.value = []
  }
}

const loadPublicPostingTargets = async () => {
  publicPostingDialog.loadingTargets = true
  publicPostingDialog.error = ''

  try {
    const response = await request<ApiEnvelope<PostingTarget[]>>('/public/scan/posting-targets', {
      method: 'GET',
      auth: false,
      query: {
        module: publicPostingDialog.module,
        search: publicPostingDialog.search,
      },
    })
    publicPostingDialog.targets = toList<PostingTarget>(response)
  } catch (error) {
    publicPostingDialog.targets = []
    publicPostingDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal mencari target posting.'
  } finally {
    publicPostingDialog.loadingTargets = false
  }
}

const openPublicPosting = async (document: ScannedDocumentRow) => {
  if (!document.id || document.status === 'posted') return

  closePublicPosting()
  publicPostingDialog.id = document.id
  publicPostingDialog.title = document.original_name || document.title || `Dokumen scan #${document.id}`
  publicPostingDialog.documentName = document.title || document.original_name || `Dokumen scan #${document.id}`
  publicPostingDialog.fileName = document.original_name || document.file_name || `dokumen-scan-${document.id}.${document.extension || 'pdf'}`
  publicPostingDialog.open = true
  await loadPublicPostingTargets()
}

const submitPublicPosting = async () => {
  const token = activeScanSession.value?.token
  if (!token || !publicPostingDialog.id || publicPostingId.value) return
  if (!publicPostingDialog.recordId) {
    publicPostingDialog.error = 'Pilih target tujuan terlebih dahulu.'
    return
  }

  publicPostingId.value = publicPostingDialog.id
  publicPostingDialog.error = ''
  scanMessage.value = ''

  try {
    const response = await request<ApiEnvelope<ScannedDocumentRow>>(`/public/scan/documents/${publicPostingDialog.id}/post`, {
      method: 'POST',
      auth: false,
      body: {
        token,
        module: publicPostingDialog.module,
        record_id: publicPostingDialog.recordId,
        document_name: publicPostingDialog.documentName,
        file_name: publicPostingDialog.fileName,
      },
    })
    if (response.data) {
      upsertUploadedScanDocument(response.data)
    }
    scanMessage.value = response.message || 'Dokumen scan berhasil diposting.'
    closePublicPosting()
    await loadPublicScanDocuments()
  } catch (error) {
    publicPostingDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal posting dokumen scan.'
  } finally {
    publicPostingId.value = null
  }
}

const selectScannerPreview = (file: ScannerFile) => {
  previewScannerFileName.value = file.name
  if (!scannerUploadNames[file.name]) {
    scannerUploadNames[file.name] = file.name
  }
}

const selectDate = (dateKey: string) => {
  selectedDateKey.value = dateKey
  resetDetailAutoScroll()
}

const updateThemeMenuPosition = () => {
  if (!import.meta.client || !themeButtonEl.value) return

  const rect = themeButtonEl.value.getBoundingClientRect()
  const width = 320
  const gap = 8
  themeMenuPosition.top = Math.min(window.innerHeight - 80, rect.bottom + gap)
  themeMenuPosition.left = Math.max(12, Math.min(window.innerWidth - width - 12, rect.right - width))
}

const themeMenuStyle = computed(() => ({
  top: `${themeMenuPosition.top}px`,
  left: `${themeMenuPosition.left}px`,
}))

const toggleThemeMenu = async () => {
  themeMenuOpen.value = !themeMenuOpen.value
  if (themeMenuOpen.value) {
    await nextTick()
    updateThemeMenuPosition()
  }
}

const selectPublicAccent = (value: (typeof accentThemeOptions)[number]['value']) => {
  setAccentTheme(value)
  themeMenuOpen.value = false
}

const setCalendarView = (view: 'month' | 'week') => {
  calendarView.value = view
}

const movePeriod = (amount: number) => {
  const next = new Date(cursorDate.value)
  if (calendarView.value === 'week') {
    next.setDate(next.getDate() + amount * 7)
  } else {
    next.setMonth(next.getMonth() + amount)
  }
  cursorDate.value = next
}

const goToday = () => {
  const today = new Date()
  cursorDate.value = today
  selectedDateKey.value = formatDateKey(today)
  resetDetailAutoScroll()
}

const syncFullscreenState = () => {
  if (!import.meta.client) return
  isFullscreen.value = Boolean(document.fullscreenElement)
}

const toggleFullscreen = async () => {
  if (!import.meta.client) return

  if (document.fullscreenElement) {
    await document.exitFullscreen?.()
  } else {
    await document.documentElement.requestFullscreen?.()
  }
  syncFullscreenState()
}

const loadScanAssistants = async () => {
  scanLoadingAssistants.value = true
  scanError.value = ''

  try {
    const response = await request<ApiEnvelope<ScanAssistant[]>>('/public/scan/assistants', {
      method: 'GET',
      auth: false,
    })
    scanAssistants.value = toList<ScanAssistant>(response)
  } catch (error) {
    scanAssistants.value = []
    scanError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat daftar asisten aktif.'
  } finally {
    scanLoadingAssistants.value = false
  }
}

const loadLatestScanSession = async (silent = true) => {
  scanCheckingSession.value = true
  if (!silent) {
    scanError.value = ''
    scanMessage.value = ''
  }

  try {
    const response = await request<ApiEnvelope<ScanSessionPayload>>('/public/scan/sessions/latest-waiting', {
      method: 'GET',
      auth: false,
    })
    activeScanSession.value = response.data || null
    uploadedScanDocuments.value = activeScanSession.value?.documents || []
    if (activeScanSession.value && scanDialogOpen.value) {
      void loadScannerFiles()
      void loadPublicScanDocuments()
    }
    if (!silent && activeScanSession.value) {
      scanMessage.value = response.message || 'Sesi scan aktif ditemukan.'
    }
  } catch (error) {
    activeScanSession.value = null
    uploadedScanDocuments.value = []
    const statusCode = (error as { statusCode?: number; status?: number })?.statusCode || (error as { status?: number })?.status
    if (!silent && statusCode !== 404) {
      scanError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengecek sesi scan aktif.'
    } else if (!silent) {
      scanMessage.value = 'Tidak ada sesi scan aktif. Silakan pilih asisten untuk membuat sesi baru.'
    }
  } finally {
    scanCheckingSession.value = false
    scanSessionChecked.value = true
  }
}

const openScanDialog = async () => {
  scanDialogOpen.value = true
  scanError.value = ''
  scanMessage.value = ''
  await loadLatestScanSession(false)
  if (!scanAssistants.value.length) {
    void loadScanAssistants()
  }
}

const closeScanDialog = () => {
  scanDialogOpen.value = false
  closePublicPosting()
}

const startNewScanSessionForm = () => {
  activeScanSession.value = null
  scannerFiles.value = []
  uploadedScanDocuments.value = []
  closePublicPosting()
  selectedScannerFiles.value = []
  previewScannerFileName.value = ''
  Object.keys(scannerUploadNames).forEach(key => delete scannerUploadNames[key])
  scannerAgentError.value = ''
  scanMessage.value = ''
  scanError.value = ''
  if (!scanAssistants.value.length) {
    void loadScanAssistants()
  }
}

const createScanSession = async () => {
  if (!scanForm.assistant_user_id) return
  scanCreating.value = true
  scanError.value = ''
  scanMessage.value = ''

  try {
    const response = await request<ApiEnvelope<ScanSessionPayload>>('/public/scan/sessions', {
      method: 'POST',
      auth: false,
      body: {
        assistant_user_id: scanForm.assistant_user_id,
        output_type: 'pdf',
        note: scanForm.note,
      },
    })
    activeScanSession.value = response.data || null
    uploadedScanDocuments.value = activeScanSession.value?.documents || []
    scanMessage.value = response.message || 'Sesi scan berhasil dibuat.'
    scanSessionChecked.value = true
    void loadScannerFiles()
  } catch (error) {
    scanError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal membuat sesi scan.'
  } finally {
    scanCreating.value = false
  }
}

const loadScannerFiles = async () => {
  scannerFilesLoading.value = true
  scannerAgentError.value = ''

  try {
    const response = await $fetch<ApiEnvelope<ScannerFile[]>>(`${scannerAgentBase}/files`, {
      method: 'GET',
    })
    scannerFiles.value = toDataList<ScannerFile>(response)
    scannerFiles.value.forEach((file) => {
      if (!scannerUploadNames[file.name]) {
        scannerUploadNames[file.name] = file.name
      }
    })
    Object.keys(scannerUploadNames).forEach((name) => {
      if (!scannerFiles.value.some(file => file.name === name)) {
        delete scannerUploadNames[name]
      }
    })
    selectedScannerFiles.value = selectedScannerFiles.value.filter(name =>
      scannerFiles.value.some(file => file.name === name && file.ready),
    )
    if (!previewScannerFileName.value || !scannerFiles.value.some(file => file.name === previewScannerFileName.value)) {
      previewScannerFileName.value = scannerFiles.value.find(file => file.ready)?.name || scannerFiles.value[0]?.name || ''
    }
  } catch {
    scannerFiles.value = []
    selectedScannerFiles.value = []
    previewScannerFileName.value = ''
    scannerAgentError.value = 'Agent scanner lokal belum aktif. Jalankan simanis-scanner-agent.ps1 di PC scanner lalu klik Refresh File.'
  } finally {
    scannerFilesLoading.value = false
  }
}

const toggleAllScannerFiles = () => {
  if (allReadyScannerFilesSelected.value) {
    selectedScannerFiles.value = []
    return
  }
  selectedScannerFiles.value = [...readyScannerFileNames.value]
}

const uploadScannerFile = async (file: ScannerFile) => {
  if (!activeScanSession.value?.token || uploadingScannerFile.value) return
  uploadingScannerFile.value = file.name
  scannerAgentError.value = ''
  scanMessage.value = ''

  try {
    const response = await $fetch<ApiEnvelope<ScannedDocumentRow>>(`${scannerAgentBase}/upload`, {
      method: 'POST',
      body: {
        token: activeScanSession.value.token,
        file_name: file.name,
        target_name: uploadNameFor(file),
      },
    })
    if (response.data) {
      upsertUploadedScanDocument(response.data)
      void openPublicPosting(response.data)
    }
    scanMessage.value = response.message || 'File scan berhasil di-upload.'
    selectedScannerFiles.value = selectedScannerFiles.value.filter(name => name !== file.name)
    await loadScannerFiles()
  } catch (error) {
    scannerAgentError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal upload file dari agent scanner.'
  } finally {
    uploadingScannerFile.value = ''
  }
}

const uploadSelectedScannerFiles = async () => {
  if (!activeScanSession.value?.token || uploadingScannerFile.value || !selectedScannerFiles.value.length) return

  const targets = scannerFiles.value.filter(file => file.ready && selectedScannerFiles.value.includes(file.name))
  if (!targets.length) return

  uploadingScannerFile.value = '__bulk__'
  scannerAgentError.value = ''
  scanMessage.value = ''

  let successCount = 0
  const failed: string[] = []
  const uploadedDocuments: ScannedDocumentRow[] = []

  try {
    for (const file of targets) {
      try {
        const response = await $fetch<ApiEnvelope<ScannedDocumentRow>>(`${scannerAgentBase}/upload`, {
          method: 'POST',
          body: {
            token: activeScanSession.value.token,
            file_name: file.name,
            target_name: uploadNameFor(file),
          },
        })
        if (response.status === false) {
          failed.push(file.name)
        } else {
          successCount += 1
          if (response.data) {
            uploadedDocuments.push(response.data)
            upsertUploadedScanDocument(response.data)
          }
          selectedScannerFiles.value = selectedScannerFiles.value.filter(name => name !== file.name)
        }
      } catch {
        failed.push(file.name)
      }
    }

    if (failed.length) {
      scannerAgentError.value = `${successCount} file berhasil, ${failed.length} file gagal: ${failed.join(', ')}`
    } else {
      scanMessage.value = `${successCount} file scan berhasil di-upload.`
    }

    await loadScannerFiles()
    await loadPublicScanDocuments()
    const firstUnposted = uploadedDocuments.find(document => document.status !== 'posted')
    if (firstUnposted) {
      void openPublicPosting(firstUnposted)
    }
  } finally {
    uploadingScannerFile.value = ''
  }
}


const loadEvents = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await request<ApiEnvelope<RawRecord[]>>('/public/events', {
      method: 'GET',
      auth: false,
      query: {
        start_date: formatDateKey(visibleRange.value.start),
        end_date: formatDateKey(visibleRange.value.end),
      },
    })
    events.value = toList<RawRecord>(response)
      .map((row, index) => normalizeEventRow(row, index))
      .filter(Boolean) as CalendarEvent[]
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat jadwal publik.'
    events.value = []
  } finally {
    loading.value = false
  }
}

const resetDetailAutoScroll = () => {
  detailAutoScrollHoldUntil = performance.now() + 900
  detailAutoScrollLastTime = 0

  nextTick(() => {
    if (detailScrollEl.value) {
      detailScrollEl.value.scrollTop = 0
    }
  })
}

const tickDetailAutoScroll = (timestamp: number) => {
  const el = detailScrollEl.value

  if (el && el.scrollHeight > el.clientHeight + 4) {
    const maxScroll = el.scrollHeight - el.clientHeight

    if (!detailAutoScrollLastTime) {
      detailAutoScrollLastTime = timestamp
    }

    if (!detailAutoScrollHoldUntil) {
      detailAutoScrollHoldUntil = timestamp + 900
    }

    if (timestamp >= detailAutoScrollHoldUntil) {
      const deltaSeconds = Math.min((timestamp - detailAutoScrollLastTime) / 1000, 0.06)
      el.scrollTop += 52 * deltaSeconds

      if (el.scrollTop >= maxScroll - 1) {
        el.scrollTop = maxScroll
        detailAutoScrollHoldUntil = timestamp + 1400

        window.setTimeout(() => {
          if (detailScrollEl.value === el) {
            el.scrollTo({ top: 0, behavior: 'smooth' })
            detailAutoScrollLastTime = 0
            detailAutoScrollHoldUntil = performance.now() + 1700
          }
        }, 450)
      }
    }
  } else if (el && el.scrollHeight <= el.clientHeight + 4) {
    el.scrollTop = 0
    detailAutoScrollHoldUntil = timestamp + 900
  }

  detailAutoScrollLastTime = timestamp
  detailAutoScrollFrame = requestAnimationFrame(tickDetailAutoScroll)
}

watch(
  () => `${calendarView.value}-${formatDateKey(visibleRange.value.start)}-${formatDateKey(visibleRange.value.end)}`,
  () => {
    void loadEvents()
  },
)

watch(
  () => selectedDateEvents.value.map((event) => event.id).join(','),
  () => {
    resetDetailAutoScroll()
  },
)

watch(scanToastMessage, (message) => {
  if (!import.meta.client) return

  if (scanToastTimeout) {
    window.clearTimeout(scanToastTimeout)
    scanToastTimeout = 0
  }

  if (!message) return

  scanToastTimeout = window.setTimeout(() => {
    scanError.value = ''
    scanMessage.value = ''
    scanToastTimeout = 0
  }, 3500)
})

const isVirtualKeyboardField = (target: EventTarget | null): target is HTMLInputElement | HTMLTextAreaElement => {
  if (target instanceof HTMLTextAreaElement) return !target.disabled && !target.readOnly
  if (!(target instanceof HTMLInputElement) || target.disabled || target.readOnly) return false
  return ['text', 'search', 'email', 'tel', 'url', 'number'].includes(target.type)
}

const handleVirtualKeyboardFocus = (event: FocusEvent) => {
  if (!isVirtualKeyboardField(event.target)) return
  virtualKeyboardTarget.value = event.target
  virtualKeyboardOpen.value = true
}

const closeVirtualKeyboard = () => {
  virtualKeyboardOpen.value = false
  virtualKeyboardShift.value = false
  virtualKeyboardTarget.value?.blur()
  virtualKeyboardTarget.value = null
}

const toggleVirtualKeyboardShift = () => {
  virtualKeyboardShift.value = !virtualKeyboardShift.value
}

const displayVirtualKey = (key: string) => virtualKeyboardShift.value ? key.toUpperCase() : key

const updateVirtualKeyboardValue = (value: string, cursor: number) => {
  const target = virtualKeyboardTarget.value
  if (!target) return
  target.value = value
  target.dispatchEvent(new Event('input', { bubbles: true }))
  target.focus({ preventScroll: true })
  try {
    target.setSelectionRange(cursor, cursor)
  } catch {
    // Number inputs do not expose text selection in every browser.
  }
}

const pressVirtualKey = (key: string) => {
  const target = virtualKeyboardTarget.value
  if (!target) return

  const start = target.selectionStart ?? target.value.length
  const end = target.selectionEnd ?? start
  if (key === 'ENTER' && !(target instanceof HTMLTextAreaElement)) {
    target.dispatchEvent(new Event('change', { bubbles: true }))
    closeVirtualKeyboard()
    return
  }

  if (key === 'BACKSPACE') {
    if (start !== end) {
      updateVirtualKeyboardValue(target.value.slice(0, start) + target.value.slice(end), start)
    } else if (start > 0) {
      updateVirtualKeyboardValue(target.value.slice(0, start - 1) + target.value.slice(end), start - 1)
    }
    return
  }

  const insertedKey = key === 'ENTER' ? '\n' : displayVirtualKey(key)
  updateVirtualKeyboardValue(target.value.slice(0, start) + insertedKey + target.value.slice(end), start + insertedKey.length)
  if (virtualKeyboardShift.value && /^[a-z]$/i.test(key)) virtualKeyboardShift.value = false
}

onMounted(() => {
  selectedDateKey.value = formatDateKey(new Date())
  syncFullscreenState()
  document.addEventListener('fullscreenchange', syncFullscreenState)
  document.addEventListener('focusin', handleVirtualKeyboardFocus)
  void loadEvents()
  void loadLatestScanSession(true)
  detailAutoScrollFrame = requestAnimationFrame(tickDetailAutoScroll)
})

onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', syncFullscreenState)
  document.removeEventListener('focusin', handleVirtualKeyboardFocus)
  if (scanToastTimeout) {
    window.clearTimeout(scanToastTimeout)
  }
  if (detailAutoScrollFrame) {
    cancelAnimationFrame(detailAutoScrollFrame)
  }
})
</script>

<style>
.public-virtual-keyboard {
  background: color-mix(in srgb, #020617 94%, var(--simanis-accent-grad-from) 6%);
  color: #f8fafc;
}

.public-virtual-keyboard.public-theme-light {
  background: color-mix(in srgb, #ffffff 94%, var(--simanis-accent-50) 6%);
  color: #0f172a;
  border-color: color-mix(in srgb, var(--simanis-accent-300) 55%, #cbd5e1);
}

.public-virtual-keyboard .virtual-key {
  border-color: rgb(255 255 255 / 0.18);
  background: rgb(255 255 255 / 0.08);
  color: inherit;
}

.public-virtual-keyboard .virtual-key:hover,
.public-virtual-keyboard .virtual-key-active {
  border-color: var(--simanis-accent-400);
  background: rgb(var(--simanis-accent-rgb) / 0.28);
}

.public-virtual-keyboard.public-theme-light .virtual-key {
  border-color: color-mix(in srgb, var(--simanis-accent-300) 48%, #cbd5e1);
  background: #ffffff;
  box-shadow: 0 4px 10px rgb(15 23 42 / 0.08);
}

.public-keyboard-enter-active,
.public-keyboard-leave-active {
  transition: transform 180ms ease, opacity 180ms ease;
}

.public-keyboard-enter-from,
.public-keyboard-leave-to {
  transform: translateY(100%);
  opacity: 0;
}

.public-calendar-shell {
  background:
    radial-gradient(circle at top left, rgb(var(--simanis-accent-rgb) / 0.2), transparent 34rem),
    linear-gradient(135deg, #020617 0%, #0f172a 48%, color-mix(in srgb, #020617 82%, var(--simanis-accent-grad-from) 18%) 100%);
}

.public-calendar-shell.public-theme-light {
  background:
    radial-gradient(circle at top left, rgb(var(--simanis-accent-rgb) / 0.16), transparent 32rem),
    radial-gradient(circle at bottom right, color-mix(in srgb, var(--simanis-accent-grad-to) 18%, transparent), transparent 30rem),
    linear-gradient(135deg, #f8fafc 0%, var(--simanis-accent-50) 50%, #ffffff 100%);
}

.public-calendar-shell .public-gradient-button,
.public-calendar-shell .bg-blue-500 {
  background-image: linear-gradient(135deg, var(--simanis-accent-grad-from), var(--simanis-accent-grad-to)) !important;
  box-shadow: 0 18px 32px -20px var(--simanis-accent-shadow);
}

.public-calendar-shell .public-login-button {
  background: #ffffff;
  color: #020617;
}

.public-theme-menu-floating {
  position: fixed !important;
  z-index: 9999 !important;
}

.public-calendar-shell .public-ghost-button {
  color: #f8fafc;
}

.public-calendar-shell.public-theme-light .public-ghost-button {
  border-color: color-mix(in srgb, var(--simanis-accent-200) 70%, #cbd5e1);
  background: rgb(255 255 255 / 0.72);
  color: #0f172a;
}

.public-calendar-shell.public-theme-light .public-login-button {
  background: #0f172a;
  color: #ffffff;
  box-shadow: 0 18px 32px -22px rgb(15 23 42 / 0.35);
}

.public-calendar-shell.public-theme-light .public-theme-menu,
.public-theme-menu.public-theme-light {
  border-color: color-mix(in srgb, var(--simanis-accent-200) 70%, #e2e8f0);
  background: rgb(255 255 255 / 0.96);
  color: #0f172a;
}

.public-calendar-shell.public-theme-light .public-theme-option:hover,
.public-calendar-shell.public-theme-light .public-theme-option.bg-white\/10,
.public-theme-menu.public-theme-light .public-theme-option:hover,
.public-theme-menu.public-theme-light .public-theme-option.bg-white\/10 {
  background: var(--simanis-accent-50) !important;
}

.public-theme-menu.public-theme-light .text-blue-200 {
  color: var(--simanis-accent-600) !important;
}

.public-theme-menu.public-theme-light .text-slate-400 {
  color: #64748b !important;
}

.public-calendar-shell.public-theme-light .bg-white\/10,
.public-calendar-shell.public-theme-light .bg-white\/5 {
  background-color: rgb(255 255 255 / 0.78) !important;
}

.public-calendar-shell.public-theme-light .bg-slate-950,
.public-calendar-shell.public-theme-light .bg-slate-950\/95,
.public-calendar-shell.public-theme-light .bg-slate-950\/80,
.public-calendar-shell.public-theme-light .bg-slate-950\/70,
.public-calendar-shell.public-theme-light .bg-slate-950\/60,
.public-calendar-shell.public-theme-light .bg-slate-950\/50,
.public-calendar-shell.public-theme-light .bg-slate-950\/40,
.public-calendar-shell.public-theme-light .bg-slate-900 {
  background-color: rgb(255 255 255 / 0.88) !important;
}

.public-calendar-shell.public-theme-light .bg-slate-900\/50,
.public-calendar-shell.public-theme-light .bg-slate-900\/80 {
  background-color: rgb(15 23 42 / 0.2) !important;
}

.public-calendar-shell.public-theme-light .border-white\/10,
.public-calendar-shell.public-theme-light .border-white\/15,
.public-calendar-shell.public-theme-light .border-blue-300\/20 {
  border-color: color-mix(in srgb, var(--simanis-accent-200) 62%, #dbe3ef) !important;
}

.public-calendar-shell.public-theme-light .text-slate-200,
.public-calendar-shell.public-theme-light .text-blue-100 {
  color: #0f172a !important;
}

.public-calendar-shell.public-theme-light .public-gradient-button,
.public-calendar-shell.public-theme-light .public-gradient-button *,
.public-calendar-shell.public-theme-light .bg-blue-500,
.public-calendar-shell.public-theme-light .bg-blue-500 *,
.public-calendar-shell.public-theme-light .bg-emerald-500,
.public-calendar-shell.public-theme-light .bg-emerald-500 *,
.public-calendar-shell.public-theme-light .bg-red-600,
.public-calendar-shell.public-theme-light .bg-red-600 * {
  color: #ffffff !important;
}

.public-calendar-shell.public-theme-light .text-slate-300,
.public-calendar-shell.public-theme-light .text-slate-400,
.public-calendar-shell.public-theme-light .text-slate-500 {
  color: #64748b !important;
}

.public-calendar-shell.public-theme-light .text-blue-200,
.public-calendar-shell.public-theme-light .text-blue-300 {
  color: var(--simanis-accent-600) !important;
}

.public-calendar-shell.public-theme-light input,
.public-calendar-shell.public-theme-light select,
.public-calendar-shell.public-theme-light textarea {
  border-color: color-mix(in srgb, var(--simanis-accent-200) 70%, #cbd5e1) !important;
  background-color: #ffffff !important;
  color: #0f172a !important;
}

.public-calendar-shell.public-theme-light option {
  color: #0f172a;
}

.public-calendar-shell.public-theme-light .ring-blue-400 {
  --tw-ring-color: var(--simanis-accent-500) !important;
}

.public-calendar-shell.public-theme-light .shadow-2xl {
  box-shadow: 0 24px 60px -34px rgb(15 23 42 / 0.34);
}

.public-calendar-shell.public-theme-light .public-scan-dialog {
  background:
    radial-gradient(circle at top left, rgb(var(--simanis-accent-rgb) / 0.16), transparent 30rem),
    linear-gradient(135deg, #f8fafc 0%, #eff6ff 54%, #ffffff 100%) !important;
  color: #0f172a !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .bg-white\/5,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-white\/10 {
  background-color: rgb(255 255 255 / 0.72) !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .bg-slate-950,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-slate-950\/80,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-slate-950\/60,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-slate-950\/50,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-slate-900 {
  background-color: rgb(255 255 255 / 0.9) !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .text-white,
.public-calendar-shell.public-theme-light .public-scan-dialog .text-slate-100,
.public-calendar-shell.public-theme-light .public-scan-dialog .text-slate-200,
.public-calendar-shell.public-theme-light .public-scan-dialog .text-blue-100 {
  color: #0f172a !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .text-slate-300,
.public-calendar-shell.public-theme-light .public-scan-dialog .text-slate-400,
.public-calendar-shell.public-theme-light .public-scan-dialog .text-slate-500 {
  color: #64748b !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .text-blue-200,
.public-calendar-shell.public-theme-light .public-scan-dialog .text-blue-300 {
  color: var(--simanis-accent-600) !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .text-emerald-100 {
  color: #047857 !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .text-red-100 {
  color: #b91c1c !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .text-amber-200 {
  color: #b45309 !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog .bg-blue-500,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-blue-500 *,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-emerald-500,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-emerald-500 *,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-red-600,
.public-calendar-shell.public-theme-light .public-scan-dialog .bg-red-600 * {
  color: #ffffff !important;
}

.public-calendar-shell.public-theme-light .public-scan-dialog iframe {
  background: #ffffff !important;
}
</style>
