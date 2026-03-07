<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import api from "@/lib/api";
import {
  Wine,
  ArrowRight,
  ArrowLeft,
  User,
  Settings,
  Check,
  Eye,
  EyeOff,
  Loader2,
  Globe,
  Clock,
  Cpu,
  Sparkles,
} from "lucide-vue-next";

const router = useRouter();
const auth = useAuthStore();

const ready = ref(false);
const currentStep = ref(0);
const submitting = ref(false);
const error = ref("");
const direction = ref<"forward" | "back">("forward");

// Step 1: Admin account
const adminForm = ref({
  name: "admin",
  email: "admin@cellar.local",
  password: "",
  password_confirmation: "",
});
const showPassword = ref(false);

// Step 2: Configuration
const configForm = ref({
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  app_url: window.location.origin,
  max_parallel_jobs: 2,
});

const commonTimezones = [
  "UTC",
  "America/New_York",
  "America/Chicago",
  "America/Denver",
  "America/Los_Angeles",
  "America/Sao_Paulo",
  "America/Argentina/Buenos_Aires",
  "Europe/London",
  "Europe/Paris",
  "Europe/Berlin",
  "Europe/Moscow",
  "Asia/Dubai",
  "Asia/Kolkata",
  "Asia/Shanghai",
  "Asia/Tokyo",
  "Asia/Seoul",
  "Australia/Sydney",
  "Pacific/Auckland",
];

// Ensure the detected timezone is in the list
const detectedTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
if (!commonTimezones.includes(detectedTz)) {
  commonTimezones.unshift(detectedTz);
}

const steps = [
  { label: "Welcome", icon: Sparkles },
  { label: "Account", icon: User },
  { label: "Configure", icon: Settings },
  { label: "Complete", icon: Check },
];

const passwordStrength = computed(() => {
  const pw = adminForm.value.password;
  if (!pw) return { score: 0, label: "", color: "" };
  let score = 0;
  if (pw.length >= 8) score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
  if (/\d/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  if (score <= 1) return { score: 1, label: "Weak", color: "bg-danger" };
  if (score <= 2) return { score: 2, label: "Fair", color: "bg-warning" };
  if (score <= 3) return { score: 3, label: "Good", color: "bg-info" };
  return { score: 4, label: "Strong", color: "bg-success" };
});

const passwordsMatch = computed(() => {
  return (
    !adminForm.value.password_confirmation ||
    adminForm.value.password === adminForm.value.password_confirmation
  );
});

const canProceed = computed(() => {
  if (currentStep.value === 1) {
    return (
      adminForm.value.name.trim() &&
      adminForm.value.email.trim() &&
      adminForm.value.password.length >= 8 &&
      adminForm.value.password === adminForm.value.password_confirmation
    );
  }
  if (currentStep.value === 2) {
    return configForm.value.timezone && configForm.value.max_parallel_jobs >= 1;
  }
  return true;
});

function nextStep() {
  direction.value = "forward";
  currentStep.value++;
}

function prevStep() {
  direction.value = "back";
  currentStep.value--;
}

async function submitSetup() {
  submitting.value = true;
  error.value = "";
  try {
    const { data } = await api.post("/setup", {
      name: adminForm.value.name,
      email: adminForm.value.email,
      password: adminForm.value.password,
      password_confirmation: adminForm.value.password_confirmation,
      timezone: configForm.value.timezone,
      app_url: configForm.value.app_url,
      max_parallel_jobs: configForm.value.max_parallel_jobs,
    });

    localStorage.setItem("cellar_access_token", data.token);
    localStorage.setItem("cellar_user", data.user?.name ?? adminForm.value.name);

    direction.value = "forward";
    currentStep.value = 3;
  } catch (err: unknown) {
    const msg =
      err && typeof err === "object" && "response" in err
        ? (err as { response?: { data?: { message?: string } } }).response
            ?.data?.message
        : undefined;
    error.value = msg ?? "Setup failed. Please check your inputs and try again.";
  } finally {
    submitting.value = false;
  }
}

function goToDashboard() {
  auth.checkAuth().then(() => {
    router.push("/");
  });
}

onMounted(async () => {
  try {
    const { data } = await api.get("/system/health");
    if (!data.needs_setup) {
      router.replace("/login");
      return;
    }
  } catch {
    router.replace("/login");
    return;
  }
  ready.value = true;
});
</script>

<template>
  <div
    v-if="ready"
    class="flex min-h-screen flex-col items-center justify-center bg-background px-4"
  >
    <div class="w-full max-w-lg">
      <!-- Step indicator -->
      <div class="mb-8 flex items-center justify-center gap-2">
        <div
          v-for="(_step, i) in steps"
          :key="i"
          class="flex items-center gap-2"
        >
          <div
            class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium transition-all duration-300"
            :class="
              i < currentStep
                ? 'bg-primary text-white'
                : i === currentStep
                  ? 'bg-primary text-white ring-2 ring-primary/30 ring-offset-2 ring-offset-background'
                  : 'bg-surface-raised text-text-muted'
            "
          >
            <Check v-if="i < currentStep" class="h-3.5 w-3.5" />
            <span v-else>{{ i + 1 }}</span>
          </div>
          <div
            v-if="i < steps.length - 1"
            class="h-px w-8 transition-colors duration-300"
            :class="i < currentStep ? 'bg-primary' : 'bg-border'"
          />
        </div>
      </div>

      <!-- Step content -->
      <div class="rounded-xl border border-border bg-surface overflow-hidden">
        <Transition
          :name="direction === 'forward' ? 'slide-left' : 'slide-right'"
          mode="out-in"
        >
          <!-- STEP 0: Welcome -->
          <div v-if="currentStep === 0" key="welcome" class="p-8 text-center">
            <div
              class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-primary"
            >
              <Wine class="h-8 w-8 text-white" />
            </div>
            <h1 class="mt-5 text-2xl font-bold text-text-primary">
              Welcome to Cellar
            </h1>
            <p class="mt-2 text-sm text-text-muted leading-relaxed">
              Your backups, preserved. Let's get your instance configured<br />
              in just a couple of steps.
            </p>
            <button
              class="mt-6 inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
              @click="nextStep"
            >
              Get Started <ArrowRight class="h-4 w-4" />
            </button>
          </div>

          <!-- STEP 1: Admin Account -->
          <div v-else-if="currentStep === 1" key="account" class="p-6">
            <div class="flex items-center gap-2.5 mb-1">
              <div
                class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10"
              >
                <User class="h-4 w-4 text-primary" />
              </div>
              <h2 class="text-lg font-semibold text-text-primary">
                Admin Account
              </h2>
            </div>
            <p class="mt-1 text-sm text-text-muted">
              Set up your administrator credentials.
            </p>

            <div class="mt-5 space-y-4">
              <div>
                <label
                  class="mb-1.5 block text-sm font-medium text-text-primary"
                >
                  Display Name
                </label>
                <input
                  v-model="adminForm.name"
                  type="text"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  placeholder="Admin"
                />
              </div>

              <div>
                <label
                  class="mb-1.5 block text-sm font-medium text-text-primary"
                >
                  Email Address
                </label>
                <input
                  v-model="adminForm.email"
                  type="email"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  placeholder="admin@example.com"
                />
              </div>

              <div>
                <label
                  class="mb-1.5 block text-sm font-medium text-text-primary"
                >
                  Password
                </label>
                <div class="relative">
                  <input
                    v-model="adminForm.password"
                    :type="showPassword ? 'text' : 'password'"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 pr-10 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    placeholder="Min. 8 characters"
                  />
                  <button
                    type="button"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 text-text-muted hover:text-text-primary transition-colors"
                    @click="showPassword = !showPassword"
                  >
                    <EyeOff v-if="showPassword" class="h-4 w-4" />
                    <Eye v-else class="h-4 w-4" />
                  </button>
                </div>
                <div v-if="adminForm.password" class="mt-2">
                  <div class="flex gap-1">
                    <div
                      v-for="i in 4"
                      :key="i"
                      class="h-1 flex-1 rounded-full transition-colors duration-300"
                      :class="
                        i <= passwordStrength.score
                          ? passwordStrength.color
                          : 'bg-surface-raised'
                      "
                    />
                  </div>
                  <p class="mt-1 text-xs text-text-muted">
                    {{ passwordStrength.label }}
                  </p>
                </div>
              </div>

              <div>
                <label
                  class="mb-1.5 block text-sm font-medium text-text-primary"
                >
                  Confirm Password
                </label>
                <input
                  v-model="adminForm.password_confirmation"
                  type="password"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  :class="
                    !passwordsMatch
                      ? 'border-danger focus:border-danger focus:ring-danger'
                      : ''
                  "
                  placeholder="Repeat your password"
                />
                <p v-if="!passwordsMatch" class="mt-1 text-xs text-danger">
                  Passwords do not match.
                </p>
              </div>
            </div>

            <div class="mt-6 flex items-center justify-between">
              <button
                class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text-primary transition-colors"
                @click="prevStep"
              >
                <ArrowLeft class="h-4 w-4" /> Back
              </button>
              <button
                :disabled="!canProceed"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                @click="nextStep"
              >
                Continue <ArrowRight class="h-4 w-4" />
              </button>
            </div>
          </div>

          <!-- STEP 2: Configuration -->
          <div v-else-if="currentStep === 2" key="config" class="p-6">
            <div class="flex items-center gap-2.5 mb-1">
              <div
                class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10"
              >
                <Settings class="h-4 w-4 text-primary" />
              </div>
              <h2 class="text-lg font-semibold text-text-primary">
                Configuration
              </h2>
            </div>
            <p class="mt-1 text-sm text-text-muted">
              Fine-tune your Cellar instance. You can change these later in
              Settings.
            </p>

            <div class="mt-5 space-y-4">
              <div>
                <label
                  class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-text-primary"
                >
                  <Clock class="h-4 w-4 text-text-muted" /> Timezone
                </label>
                <select
                  v-model="configForm.timezone"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                  <option
                    v-for="tz in commonTimezones"
                    :key="tz"
                    :value="tz"
                  >
                    {{ tz.replace(/_/g, " ") }}
                  </option>
                </select>
                <p class="mt-1 text-xs text-text-muted">
                  Detected: {{ detectedTz.replace(/_/g, " ") }}
                </p>
              </div>

              <div>
                <label
                  class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-text-primary"
                >
                  <Globe class="h-4 w-4 text-text-muted" /> Instance URL
                </label>
                <input
                  v-model="configForm.app_url"
                  type="url"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  placeholder="http://localhost:8420"
                />
                <p class="mt-1 text-xs text-text-muted">
                  The public URL used to access this Cellar instance.
                </p>
              </div>

              <div>
                <label
                  class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-text-primary"
                >
                  <Cpu class="h-4 w-4 text-text-muted" /> Max Parallel Jobs
                </label>
                <input
                  v-model.number="configForm.max_parallel_jobs"
                  type="number"
                  min="1"
                  max="20"
                  class="w-24 rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary text-center focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
                <p class="mt-1 text-xs text-text-muted">
                  How many backup/restore jobs can run concurrently (1&ndash;20).
                </p>
              </div>
            </div>

            <div
              v-if="error"
              class="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
            >
              {{ error }}
            </div>

            <div class="mt-6 flex items-center justify-between">
              <button
                class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text-primary transition-colors"
                @click="prevStep"
              >
                <ArrowLeft class="h-4 w-4" /> Back
              </button>
              <button
                :disabled="!canProceed || submitting"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                @click="submitSetup"
              >
                <Loader2 v-if="submitting" class="h-4 w-4 animate-spin" />
                {{ submitting ? "Completing setup…" : "Complete Setup" }}
              </button>
            </div>
          </div>

          <!-- STEP 3: Complete -->
          <div
            v-else-if="currentStep === 3"
            key="complete"
            class="p-8 text-center"
          >
            <div
              class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-success/10 animate-in"
            >
              <Check class="h-8 w-8 text-success" />
            </div>
            <h2 class="mt-5 text-2xl font-bold text-text-primary">
              You're all set!
            </h2>
            <p class="mt-2 text-sm text-text-muted leading-relaxed">
              Cellar is configured and ready to protect your data.<br />
              Start by adding your first database source.
            </p>
            <button
              class="mt-6 inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
              @click="goToDashboard"
            >
              Go to Dashboard <ArrowRight class="h-4 w-4" />
            </button>
          </div>
        </Transition>
      </div>

      <p class="mt-6 text-center text-xs text-text-muted">
        Cellar &mdash; Your backups, preserved.
      </p>
    </div>
  </div>
</template>

<style scoped>
.slide-left-enter-active,
.slide-left-leave-active {
  transition: all 0.25s ease;
}
.slide-left-enter-from {
  opacity: 0;
  transform: translateX(30px);
}
.slide-left-leave-to {
  opacity: 0;
  transform: translateX(-30px);
}

.slide-right-enter-active,
.slide-right-leave-active {
  transition: all 0.25s ease;
}
.slide-right-enter-from {
  opacity: 0;
  transform: translateX(-30px);
}
.slide-right-leave-to {
  opacity: 0;
  transform: translateX(30px);
}

.animate-in {
  animation: scale-in 0.4s ease-out;
}

@keyframes scale-in {
  0% {
    opacity: 0;
    transform: scale(0.8);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
