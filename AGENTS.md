# 3r implementation

Use Traditional Chinese for user-facing UI. This is a Christian prison ministry association. Products are display-only. Never copy YS secrets or data. All seeded content must be visibly fictional/demo.

Architecture: backend Laravel PHP 8.3+ (container runtime), frontend Nuxt 4 Vue TypeScript, PostgreSQL 17, Redis 7, Compose. API prefix /api/v1. Same-origin session auth, CSRF. Data is synthetic UAT until HTTPS and real integrations are configured.

Delegation explicitly requested: Astra coordinates; Sol backend; Terra frontend; Luna tests/docs/CI. Independent Git worktrees, commit work for cherry-pick. Do not modify another agent's owned directory. Read docs/contract.md before implementing. Report interface changes to coordinator immediately.

Security: permissions enforced server-side and unioned across active roles. Every private file download checks permissions. Last active system admin cannot be removed. Test negative permissions, scheduling transitions, stale versions and concurrency. Do not return OTP in public API. Do not send real notifications or charge payments in mock mode.
