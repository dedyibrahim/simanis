<script setup lang="ts">
import { Dialog, DialogPanel, DialogTitle, DialogDescription } from '@headlessui/vue'
import { ExclamationTriangleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline'
const { state, resolve } = useAppDialog()
const cancelButton = ref<HTMLButtonElement | null>(null)
</script>

<template>
  <ClientOnly>
    <Dialog v-if="state" :key="state.id" :open="true" :initial-focus="cancelButton" class="relative z-[200]" @close="resolve(false)">
      <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" aria-hidden="true" />
      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <DialogPanel class="relative w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl">
            <button type="button" aria-label="Tutup dialog" class="absolute right-4 top-4 rounded-lg p-2 text-slate-400 hover:bg-slate-100" @click="resolve(false)"><XMarkIcon class="h-5 w-5" /></button>
            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl" :class="state.danger ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600'"><ExclamationTriangleIcon v-if="state.danger" class="h-6 w-6" /><InformationCircleIcon v-else class="h-6 w-6" /></div>
            <DialogTitle class="pr-6 text-lg font-semibold">{{ state.title }}</DialogTitle>
            <DialogDescription class="mt-3 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ state.message }}</DialogDescription>
            <div class="mt-6 flex justify-end gap-3">
              <button v-if="state.kind === 'confirm'" ref="cancelButton" type="button" class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="resolve(false)">{{ state.cancelText }}</button>
              <button :ref="state.kind === 'notice' ? 'cancelButton' : undefined" type="button" class="inline-flex min-h-11 items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold text-white transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" :class="state.danger ? 'bg-red-600 hover:bg-red-700 focus-visible:outline-red-600' : 'bg-blue-600 hover:bg-blue-700 focus-visible:outline-blue-600'" @click="resolve(true)">{{ state.confirmText }}</button>
            </div>
          </DialogPanel>
        </div>
      </div>
    </Dialog>
  </ClientOnly>
</template>
