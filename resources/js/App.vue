<script setup lang="ts">
import { RouterView, useRoute } from "vue-router";
import { computed } from "vue";
import AppSidebar from "@/components/layout/AppSidebar.vue";
import ConfirmModal from "@/components/ConfirmModal.vue";
import ToastStack from "@/components/ToastStack.vue";

const route = useRoute();
const isPublicPage = computed(() => route.meta.public === true);
</script>

<template>
  <!-- Login/Setup: full-screen, no sidebar -->
  <RouterView v-if="isPublicPage" />

  <!-- App: sidebar + main -->
  <div v-else class="app-shell">
    <AppSidebar />
    <main class="app-main">
      <RouterView />
    </main>
  </div>

  <!-- Global confirm modal -->
  <ConfirmModal />
  <!-- Global toast notifications -->
  <ToastStack />
</template>

<style scoped>
.app-shell {
  display: flex;
  height: 100vh;
  position: relative;
}

.app-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
  z-index: 1;
}
</style>
