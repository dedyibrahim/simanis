<template>
  <div class="relative flex min-h-screen overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-slate-50 via-blue-50 to-indigo-100">
      <div class="absolute left-20 top-20 h-32 w-32 animate-pulse rounded-full bg-blue-200/30 blur-2xl"></div>
      <div class="delay-1000 absolute bottom-32 right-32 h-48 w-48 animate-pulse rounded-full bg-indigo-200/20 blur-3xl"></div>
      <div class="delay-2000 absolute left-1/3 top-1/2 h-24 w-24 animate-pulse rounded-full bg-blue-200/25 blur-xl"></div>
    </div>

    <div class="relative z-10 flex flex-1 items-center justify-center px-4 sm:px-6 lg:px-20 xl:px-24">
      <div class="mx-auto w-full max-w-md">
        <div class="relative overflow-hidden rounded-3xl border border-white/20 bg-white/70 p-8 shadow-2xl backdrop-blur-xl">
          <div class="absolute right-0 top-0 h-32 w-32 rounded-full bg-gradient-to-br from-blue-400/10 to-indigo-400/10 blur-2xl"></div>
          <div class="absolute bottom-0 left-0 h-24 w-24 rounded-full bg-gradient-to-tr from-blue-400/10 to-indigo-400/10 blur-xl"></div>

          <div class="relative z-10 mb-8 text-center">
            <div class="relative mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-700 shadow-xl">
              <div class="absolute inset-0 rounded-3xl bg-gradient-to-br from-blue-400 to-indigo-600 opacity-50 blur-lg animate-pulse"></div>
              <ClipboardDocumentCheckIcon class="relative z-10 h-12 w-12 text-white" />
            </div>
            <h1 class="mb-2 bg-gradient-to-r from-slate-900 via-blue-900 to-indigo-900 bg-clip-text text-4xl font-bold text-transparent">
              SIMANIS
            </h1>
            <p class="font-medium text-slate-600">Sistem Informasi Administrasi Kantor Notaris</p>
          </div>

          <NuxtLink
            to="/jadwal-publik"
            class="relative z-10 mb-6 flex items-center justify-center gap-2 rounded-2xl border border-blue-200/70 bg-blue-50/80 px-4 py-3 text-sm font-bold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100"
          >
            <CalendarDaysIcon class="h-5 w-5" />
            Lihat Jadwal Notaris Publik
          </NuxtLink>

          <form class="space-y-6" @submit.prevent="handleLogin">
            <div v-if="!otpStep" class="space-y-2">
              <label for="email" class="block text-sm font-semibold text-slate-700"> Email </label>
              <div class="group relative">
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  autocomplete="username"
                  required
                  class="w-full rounded-2xl border border-slate-200/60 bg-white/60 px-4 py-4 pl-12 text-slate-900 placeholder-slate-400 transition-all duration-300 focus:border-blue-500/50 focus:outline-none focus:ring-2 focus:ring-blue-500/50 group-hover:border-slate-300/60 group-hover:bg-white/80"
                  placeholder="Masukkan email anda"
                />
                <div class="absolute left-4 top-1/2 -translate-y-1/2 transform">
                  <svg class="h-5 w-5 text-slate-400 transition-colors group-focus-within:text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                  </svg>
                </div>
              </div>
            </div>

            <div v-if="!otpStep" class="space-y-2">
              <label for="password" class="block text-sm font-semibold text-slate-700"> Password </label>
              <div class="group relative">
                <input
                  id="password"
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  autocomplete="current-password"
                  required
                  class="w-full rounded-2xl border border-slate-200/60 bg-white/60 px-4 py-4 pl-12 pr-12 text-slate-900 placeholder-slate-400 transition-all duration-300 focus:border-blue-500/50 focus:outline-none focus:ring-2 focus:ring-blue-500/50 group-hover:border-slate-300/60 group-hover:bg-white/80"
                  placeholder="Masukkan password"
                />
                <div class="absolute left-4 top-1/2 -translate-y-1/2 transform">
                  <svg class="h-5 w-5 text-slate-400 transition-colors group-focus-within:text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                  </svg>
                </div>
                <button
                  type="button"
                  class="absolute right-4 top-1/2 -translate-y-1/2 transform text-slate-400 transition-colors hover:text-slate-600"
                  @click="showPassword = !showPassword"
                >
                  <svg v-if="!showPassword" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                  </svg>
                  <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"></path>
                  </svg>
                </button>
              </div>
            </div>

            <div v-if="!otpStep" class="flex items-center justify-between text-sm">
              <label class="flex items-center">
                <input
                  v-model="form.remember"
                  type="checkbox"
                  class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                />
                <span class="ml-2 font-medium text-slate-700">Remember me</span>
              </label>
              <span class="font-semibold text-blue-600">SIMANIS</span>
            </div>

            <div v-if="otpStep" class="space-y-4">
              <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                Kode OTP telah dikirim ke WhatsApp <strong>{{ otpState.maskedPhone }}</strong>. Kode berlaku selama 5 menit.
              </div>
              <label for="otp" class="block text-sm font-semibold text-slate-700">Kode OTP</label>
              <input
                id="otp"
                v-model="otpState.code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]{6}"
                required
                autofocus
                class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-4 text-center text-2xl font-bold tracking-[0.4em] text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                placeholder="000000"
                @input="otpState.code = otpState.code.replace(/\D/g, '').slice(0, 6)"
              />
              <div class="flex items-center justify-between gap-3 text-sm">
                <button type="button" class="font-semibold text-slate-600 hover:text-slate-900" @click="cancelOtp">Kembali ke login</button>
                <button type="button" class="font-semibold text-blue-600 hover:text-blue-800 disabled:opacity-50" :disabled="pending || resendCooldown > 0" @click="resendOtp">
                  {{ resendCooldown > 0 ? `Kirim ulang (${resendCooldown}s)` : 'Kirim ulang OTP' }}
                </button>
              </div>
            </div>

            <div v-if="errorMessage" class="rounded-2xl border border-red-200/60 bg-red-50/80 p-4 backdrop-blur-sm">
              <div class="flex items-center space-x-3">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                </div>
                <p class="font-medium text-red-700">{{ errorMessage }}</p>
              </div>
            </div>

            <button type="submit" :disabled="pending" class="group relative w-full overflow-hidden rounded-2xl">
              <div class="absolute inset-0 rounded-2xl bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 opacity-90 transition-opacity duration-300 group-hover:opacity-100"></div>
              <div class="absolute inset-0 scale-110 rounded-2xl bg-gradient-to-r from-blue-500 via-blue-600 to-indigo-600 opacity-50 blur-lg transition-opacity duration-300 group-hover:opacity-70"></div>

              <div class="relative rounded-2xl bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 px-8 py-4 text-base font-bold text-white shadow-2xl transition-all duration-300 group-hover:scale-[1.02] disabled:cursor-not-allowed disabled:opacity-60 disabled:transform-none">
                <div v-if="!pending" class="flex items-center justify-center space-x-3">
                  <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m0 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                  </svg>
                  <span>{{ otpStep ? 'Verifikasi OTP' : 'Sign In' }}</span>
                </div>
                <div v-else class="flex items-center justify-center space-x-3">
                  <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                  </svg>
                  <span>{{ otpStep ? 'Memverifikasi...' : 'Signing in...' }}</span>
                </div>
              </div>
            </button>
          </form>
        </div>
      </div>
    </div>

    <div class="relative hidden w-0 flex-1 overflow-hidden lg:block">
      <div class="absolute inset-0 bg-[url('/images/login-simanis-bg.jpeg')] bg-cover bg-center"></div>
      <div class="absolute inset-0 bg-gradient-to-br from-slate-950/80 via-slate-900/65 to-amber-900/50"></div>
      <div class="absolute inset-0">
        <div class="absolute right-1/4 top-1/4 h-40 w-40 animate-pulse rounded-full bg-white/10 blur-xl"></div>
        <div class="delay-1000 absolute bottom-1/3 left-1/3 h-32 w-32 animate-pulse rounded-full bg-amber-300/20 blur-2xl"></div>
        <div class="delay-2000 absolute left-1/4 top-1/2 h-24 w-24 animate-pulse rounded-full bg-sky-200/20 blur-xl"></div>
      </div>

      <div class="absolute inset-0 z-10 flex items-center justify-center p-12">
        <div class="max-w-lg text-center">
          <div class="mb-10">
            <div class="relative mx-auto flex h-24 w-24 items-center justify-center rounded-3xl border border-white/20 bg-white/10 shadow-2xl backdrop-blur-xl">
              <div class="absolute inset-0 rounded-3xl bg-gradient-to-br from-blue-400/20 to-indigo-400/20 blur-xl animate-pulse"></div>
              <ClipboardDocumentCheckIcon class="relative z-10 h-12 w-12 text-white" />
            </div>
          </div>

          <h1 class="mb-4 text-5xl font-bold tracking-tight text-white">SIMANIS</h1>
          <h2 class="mb-8 text-2xl font-light text-blue-100">Sistem Informasi Administrasi Kantor Notaris</h2>
          <p class="mb-12 text-lg leading-relaxed text-white/80">
            Sistem evaluasi untuk pengelolaan dokumen dan proses bisnis notaris secara modern.
          </p>

          <div class="space-y-6">
            <div class="group flex items-center text-white/70">
              <div class="mr-4 flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/10 backdrop-blur-sm transition-all group-hover:bg-white/20">
                <svg class="h-5 w-5 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
              </div>
              <div class="text-left">
                <p class="font-semibold text-white">Modern Dashboard</p>
                <p class="text-sm text-white/60">Interface responsif untuk tim operasional</p>
              </div>
            </div>

            <div class="group flex items-center text-white/70">
              <div class="mr-4 flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/10 backdrop-blur-sm transition-all group-hover:bg-white/20">
                <svg class="h-5 w-5 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9m0 10a2 2 0 002 2h2a2 2 0 002-2V9m-6 10a2 2 0 01-2-2v-4a2 2 0 012-2h2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v6"></path>
                </svg>
              </div>
              <div class="text-left">
                <p class="font-semibold text-white">Advanced Analytics</p>
                <p class="text-sm text-white/60">Ringkasan data pekerjaan real-time</p>
              </div>
            </div>

            <div class="group flex items-center text-white/70">
              <div class="mr-4 flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/10 backdrop-blur-sm transition-all group-hover:bg-white/20">
                <svg class="h-5 w-5 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4"></path>
                </svg>
              </div>
              <div class="text-left">
                <p class="font-semibold text-white">Comprehensive Tools</p>
                <p class="text-sm text-white/60">Semua modul inti dalam satu workspace</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { CalendarDaysIcon, ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline'

definePageMeta({
  layout: false,
  middleware: 'guest',
})

useHead({
  title: 'Login',
})

type LoginResponse = {
  status: boolean
  message: string
  data: import('~/composables/useSession').SessionUser & {
    otp_required?: boolean
    challenge_id?: string
    masked_phone?: string
  }
}

const business = useLegacyBusiness()
const { setSession } = useSession()
const route = useRoute()

const pending = ref(false)
const errorMessage = ref('')
const showPassword = ref(false)
const otpStep = ref(false)
const resendCooldown = ref(0)
let resendTimer: ReturnType<typeof setInterval> | undefined

const form = reactive({
  email: '',
  password: '',
  remember: false,
})
const otpState = reactive({ challengeId: '', maskedPhone: '', code: '' })

const startResendCooldown = () => {
  resendCooldown.value = 60
  if (resendTimer) clearInterval(resendTimer)
  resendTimer = setInterval(() => {
    resendCooldown.value -= 1
    if (resendCooldown.value <= 0 && resendTimer) clearInterval(resendTimer)
  }, 1000)
}

const finishLogin = async (session: import('~/composables/useSession').SessionUser) => {
  setSession(session)
  if (import.meta.client) window.localStorage.setItem('simanis.windows-mode', '1')
  await nextTick()
  await navigateTo(redirectTarget.value, { replace: true })
}

const redirectTarget = computed(() => {
  const redirect = route.query.redirect
  if (typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//')) {
    return redirect
  }

  return '/dashboard'
})

const handleLogin = async () => {
  if (pending.value) {
    return
  }

  pending.value = true
  errorMessage.value = ''

  try {
    if (otpStep.value) {
      if (otpState.code.length !== 6) {
        errorMessage.value = 'Masukkan 6 digit kode OTP.'
        return
      }
      const response = await business.auth.VerifyLoginOtp({ challenge_id: otpState.challengeId, otp: otpState.code }) as LoginResponse
      await finishLogin(response.data)
      return
    }

    const response = await business.auth.login({
      email: form.email,
      password: form.password,
    }) as LoginResponse

    if (response.data.otp_required && response.data.challenge_id) {
      otpState.challengeId = response.data.challenge_id
      otpState.maskedPhone = response.data.masked_phone || ''
      otpState.code = ''
      otpStep.value = true
      startResendCooldown()
      return
    }
    await finishLogin(response.data)
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Login gagal. Periksa email dan password.'
  } finally {
    pending.value = false
  }
}

const cancelOtp = () => {
  otpStep.value = false
  otpState.challengeId = ''
  otpState.code = ''
  errorMessage.value = ''
}

const resendOtp = async () => {
  if (pending.value || resendCooldown.value > 0) return
  pending.value = true
  errorMessage.value = ''
  try {
    const response = await business.auth.ResendLoginOtp({ challenge_id: otpState.challengeId }) as LoginResponse
    otpState.challengeId = response.data.challenge_id || otpState.challengeId
    otpState.maskedPhone = response.data.masked_phone || otpState.maskedPhone
    otpState.code = ''
    startResendCooldown()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengirim ulang OTP.'
  } finally {
    pending.value = false
  }
}

onBeforeUnmount(() => {
  if (resendTimer) clearInterval(resendTimer)
})
</script>
