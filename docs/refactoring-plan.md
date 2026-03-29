# Refactoring Plan: Large File Decomposition

## Overview

Four files exceed maintainable size thresholds. This document maps each to a concrete decomposition strategy with dependency order.

## 1. `frontend/src/views/SourcesView.vue` (2569 LOC)

**Problem:** Single file handles: source listing, wizard (create), edit modal, retention modal, timeline/archive panel, job log viewer, inline actions (backup/prune/verify/restore), connection testing, and live elapsed-time tracking.

**Decomposition:**

| Extract to | Contents | LOC (est.) |
|-----------|----------|-----------|
| `composables/useElapsedTime.ts` | `now` ref, `elapsed()`, `timeAgo()`, tick timer lifecycle | ~30 |
| `composables/useSourceTypes.ts` | `DB_TYPES`, `FS_TYPES`, `ALL_TYPES`, `sourceIcon()`, type helpers | ~40 |
| `composables/useFormatters.ts` | `fmtSize()`, `fmtTime()`, `fmtRetention()`, `RETENTION_PRESETS` | ~80 |
| `components/sources/SourceWizard.vue` | Wizard modal (type select → details → done) | ~200 |
| `components/sources/SourceEditModal.vue` | Edit form, connection test, save logic | ~200 |
| `components/sources/RetentionModal.vue` | Retention policy editor with presets | ~150 |
| `components/sources/SourceTimeline.vue` | Archive timeline, restore/export/delete/pin actions | ~300 |
| `components/sources/SourceActions.vue` | Inline backup/prune/verify buttons + status | ~100 |
| `components/sources/JobLogPanel.vue` | Log viewer with polling + auto-scroll (or reuse existing `JobLogModal`) | ~100 |
| `views/SourcesView.vue` | Orchestrator: list, route to sub-components | ~400 |

**Testing:** Add Vitest tests for extracted composables. Component tests for wizard happy path.

## 2. `backend/app/Services/KubernetesDiscovery.php` (796 LOC)

**Problem:** Single class handles: kubectl invocation, namespace listing, pod/service/PVC/secret discovery, credential extraction, image-to-type detection.

**Decomposition:**

| Extract to | Contents |
|-----------|----------|
| `Services/Kubernetes/KubectlClient.php` | `kubectl()` wrapper, kubeconfig lifecycle, connection test |
| `Services/Kubernetes/PodDiscovery.php` | `discoverFromPods()`, image/name detection |
| `Services/Kubernetes/ServiceDiscovery.php` | `discoverFromServices()` |
| `Services/Kubernetes/VolumeDiscovery.php` | `discoverPVCs()` |
| `Services/Kubernetes/SecretDiscovery.php` | `discoverSecrets()`, `extractEnvCredentials()` |
| `Services/KubernetesDiscovery.php` | Facade that orchestrates the above, keeps `discover()` public API |

**Testing:** Unit test each discovery class with mocked kubectl output.

## 3. `backend/app/Services/DatabaseDumper.php` (602 LOC)

**Problem:** Static methods for every DB type × every transport (direct + kubectl). Size querying mixed with dump logic.

**Decomposition:**

| Extract to | Contents |
|-----------|----------|
| `Services/Dumpers/PostgresDumper.php` | `dumpPostgresql()`, `dumpPostgresqlKubectl()`, TimescaleDB detection |
| `Services/Dumpers/MysqlDumper.php` | `dumpMysql()`, `dumpMysqlKubectl()` |
| `Services/Dumpers/DumpSizeEstimator.php` | All `query*Size()` + `directorySize()` methods |
| `Services/Dumpers/KubectlExec.php` | `kubectlExecPrefix()`, `findKubectlPod()` |
| `Services/DatabaseDumper.php` | Dispatcher: `dump()` and `dumpViaKubectl()` delegate to type-specific dumpers |

**Testing:** Unit tests for each dumper with process mocking.

## 4. `backend/app/Http/Controllers/Api/V1/KubernetesController.php` (464 LOC)

**Problem:** Controller has business logic inline (discovery orchestration, source creation, plan creation). Should be thin.

**Decomposition:**

| Extract to | Contents |
|-----------|----------|
| `Services/Kubernetes/ClusterOnboardingService.php` | `importSources()` business logic, plan creation |
| `Http/Controllers/Api/V1/KubernetesController.php` | Thin controller: validate, delegate, respond |

**Testing:** Feature tests for the API endpoints.

## Execution Order

1. **Composables + formatters** (zero-risk, pure functions) → immediate
2. **DatabaseDumper split** (backend, clear seams) → next
3. **KubernetesDiscovery split** (backend, clear seams) → next
4. **KubernetesController thin-out** (depends on #3) → after
5. **SourcesView decomposition** (frontend, largest effort) → final

Each step is a separate PR. No step changes external behavior.
