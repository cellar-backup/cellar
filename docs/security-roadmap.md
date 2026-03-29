# Security Roadmap

Tracked security improvements for internet-exposed deployments. Cellar's defaults are reasonable for homelab use; these harden it for public-facing installs.

## 1. WebSocket Channel Auth (P2)

**Current state:** Echo uses public channels (`echo.channel("jobs")`). Any client that can reach the WebSocket endpoint receives all job events.

**Risk:** In internet-exposed installs, unauthenticated users can observe backup job metadata (plan names, timing, success/failure).

**Proposed fix:**

1. Add a `CELLAR_WS_AUTH=private` environment variable (default: `public` for backward compat).
2. When enabled, switch frontend to `echo.private("jobs.{userId}")`.
3. Add a Reverb broadcast auth route that validates the Sanctum token:
   ```php
   // channels.php
   Broadcast::channel('jobs.{userId}', function ($user, $userId) {
       return $user->id === $userId;
   });
   ```
4. Frontend Echo config adds the auth endpoint:
   ```typescript
   authEndpoint: "/broadcasting/auth",
   auth: { headers: { Authorization: `Bearer ${token}` } }
   ```
5. Backend job broadcasts switch channel based on config:
   ```php
   $channel = config('cellar.ws_auth') === 'private'
       ? new PrivateChannel("jobs.{$job->user_id}")
       : new Channel('jobs');
   ```

**Migration:** Non-breaking. Public channels remain the default.

## 2. Token Storage Hardening (P2)

**Current state:** Bearer token stored in `localStorage`. Any XSS vulnerability leaks a long-lived API token.

**Risk:** XSS → full API access with no expiry.

**Proposed fix (short-term):**

1. Add token expiry to Sanctum config (e.g., 24h).
2. Implement refresh flow: `/api/v1/auth/refresh` returns a new token if the current one is valid but within a refresh window.
3. Frontend intercepts 401, attempts refresh, retries original request.

**Proposed fix (long-term):**

1. Switch to `httpOnly` cookie-based sessions for the SPA.
2. Sanctum already supports cookie auth for first-party SPAs — enable it:
   ```php
   // config/sanctum.php
   'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost')),
   ```
3. Frontend switches from `Authorization: Bearer` to cookie-based requests with `withCredentials: true`.
4. Add CSRF protection (`X-XSRF-TOKEN` header from the cookie).

**Migration:** Breaking change for API consumers using tokens directly. Keep token auth as an option for CLI/automation use, add cookie auth for the SPA.

## Implementation Priority

| Item | Effort | Risk Reduction | Suggested Release |
|------|--------|---------------|-------------------|
| WS private channels (opt-in) | Medium | Medium | 0.12.0 |
| Token expiry + refresh | Low | Medium | 0.11.0 |
| Cookie-based SPA auth | High | High | 0.13.0 |
