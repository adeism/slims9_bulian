# Coding Rules — Tautan

## Modularity
- App must be modular.
- Keep each file under 1000 lines when possible, smaller better when practical.
- Separate logic, config, UI, demo data, onboarding, docs.
- Separate temporary code from permanent code.
- Structure app so demo-related code is easy to remove later.

## Suggested Structure (implemented in tautan/)
```
core/
  config.js
  storage.js
  router.js
  auth.js
  resolver.js
  analytics.js
  security.js
modules/
  shortlinks/
  microsites/
  forms/
  qr/
  campaigns/
  analytics/
  workspace/
  media/
  templates/
onboarding/
  setup.js (one file until stable)
  checklist.js
  guide.js
tutorials/
  quick-tutorial.js
  detailed-tutorial.js
  help-center.js
demo/
  sample-data.js
  demo-users.js
shared/
  components/
  utils/
docs/
  flows/
harness/
assets/
```

## File Rules
- core/ = permanent, no demo imports
- demo/ = isolated, only imported when flag demoMode.enabled
- onboarding/ = first-run, autosave progress, progress indicator
- shared/ = reusable, no business logic
- modules/ = self-contained, each has Manager + UI, uses core/ and shared/

## Code Style
- Vanilla JS ES modules, no heavy bundler for MVP.
- Use JSDoc for functions.
- Naming: camelCase for JS, kebab-case for files, PascalCase for classes.
- Functions small, single responsibility.
- No global pollution, use modules.
- Use localStorage abstraction via core/storage.js, not direct localStorage calls elsewhere.

## Security
- Validate slug server-side (simulated) + client.
- Validate URL anti-SSRF: block localhost, 127.0.0.1, 0.0.0.0, ::1, 10.x, 172.16-31.x, 192.168.x, metadata endpoints (169.254.169.254, metadata.google.internal).
- Password hashing: demo uses simple hash, prod must use Argon2id/bcrypt.
- Session server-side: session_version pattern, revoke all via version increment.
- Cache-Control: private, no-store for secure pages (simulated via meta).
- No private data in HTML before session valid.
- Audit log for sensitive actions (slug override, revoke sessions, delete).

## Performance
- Analytics logging non-blocking (queue simulated).
- Image optimization: compress, alt text.
- Lazy load heavy modules.
- Debounce autosave.

## Demo Mode Isolation
- All demo data generation in demo/sample-data.js
- Demo users in demo/demo-users.js
- core/config.js flag controls inclusion
- Easy to disable/hide/remove: set demoMode.enabled = false, remove demo/ folder, remove demo imports (only in onboarding/setup.js and core/auth.js)
- Risky features have kill switch: featureFlags.killSwitches.{feature}

## Feature Flags
- Use feature flags for demo mode, onboarding experiments, risky features, rollout control.
- Every temporary flag must have owner, purpose, creation date, expiration date, cleanup plan.
- Review flags on schedule, remove after rollout.
- Remove code references before deleting flag.
- Keep permanent kill switches separate from temporary release flags.

## Rollout Strategy
- Internal testing first
- Small group rollout second
- Larger rollout next
- Full release last
- No big bang for risky features
- Always keep rollback simple

## Default Settings
- Choose sensible defaults, reduce friction, reversible, visible, mark recommended.
- Defaults in core/config.js defaultSettings.
- Auto-detect: language, timezone, theme, date format.
- Let user skip non-critical, change later in Settings.

## Documentation
- Update flow files whenever app changes, docs must always match app.
- For every role & feature document: goal, entry point, shortest path, success state, error state, help state, next action.
- Harness docs explain what app does, modes, roles, first value goals, default settings, feature flags, file structure, important rules, what changed and why.

## Testing
- Definition of Done checklist tested manually + automated where possible.
- Security testing: slug policy, reserved slug, safe browsing, session timeout, draft not public, private data not leak.
- Mobile testing: Android, iOS safari.
- Accessibility: keyboard, screen reader basic.

## Commit & Handoff
- Changelog.md updated per change.
- agent-handoff.md explains current state, next steps, known issues.
- No stale flags become permanent debt.
