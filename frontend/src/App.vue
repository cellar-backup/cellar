<script setup lang="ts">
import { RouterView, useRoute } from "vue-router";
import { computed } from "vue";
import AppSidebar from "@/components/layout/AppSidebar.vue";
import ConfirmModal from "@/components/ConfirmModal.vue";

const route = useRoute();
const isPublicPage = computed(() => route.meta.public === true);
</script>

<template>
  <!-- Login: full-screen, no sidebar -->
  <RouterView v-if="isPublicPage" />

  <!-- App: sidebar + main -->
  <div v-else class="flex h-screen overflow-hidden">
    <AppSidebar />
    <main class="flex-1 overflow-y-auto">
      <RouterView />
    </main>
  </div>

  <!-- Global confirm modal -->
  <ConfirmModal />
</template>
