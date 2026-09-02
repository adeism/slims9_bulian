# Feature Flags — Tautan

## Flag System
Flags controlled in `core/config.js` `featureFlags` object. Simple JSON, no external service for MVP. Production can use Laravel config or env.

```js
featureFlags: {
  demoMode: {
    enabled: true,
    owner: 'core team',
    purpose: 'Allow no-password role/sample user picking for demo & first value fast',
    created: '2026-09-02',
    expires: '2026-12-31',
    cleanup: 'Set enabled false, remove demo/ folder imports in onboarding/setup.js and core/auth.js, remove demo UI badge',
    type: 'temporary' // temporary | permanent kill switch
  },
  onboardingExperiment: {
    enabled: true,
    variant: 'direct-to-task', // vs 'tour'
    owner: 'ux team',
    purpose: 'Test direct-to-task onboarding vs generic tour',
    created: '2026-09-02',
    expires: '2026-10-15',
    cleanup: 'Pick winning variant, remove other code path',
    type: 'temporary'
  },
  riskyFeatures: {
    customDomain: { enabled: false, killSwitch: true, owner: 'infra', purpose: 'Custom domain SSL risky', created: '2026-09-02', expires: '2026-11-01', cleanup: 'Enable after SSL automation stable' },
    fileUpload: { enabled: true, killSwitch: true, owner: 'security', purpose: 'File upload needs virus scan', created: '2026-09-02', expires: null, cleanup: 'Keep kill switch permanent' },
    aiGenerator: { enabled: false, killSwitch: true, owner: 'ai team', purpose: 'AI campaign generator', created: '2026-09-02', expires: '2027-01-01', cleanup: 'Enable after Ollama local tested' }
  },
  killSwitches: {
    shortlinkCreation: true, // permanent kill switch, true = feature ON, false = OFF (kill)
    micrositePublish: true,
    formPublish: true,
    qrGeneration: true,
    analyticsCollection: true
  },
  rollout: {
    campaignWizard: { enabled: true, percentage: 100, owner: 'product', purpose: 'Campaign wizard rollout', created: '2026-09-02', expires: '2026-09-30', cleanup: 'Remove flag after 100% stable' },
    analyticsFunnel: { enabled: true, percentage: 100 }
  }
}
```

## Rules
- Every temporary flag must have: owner, purpose, creation date, expiration date, cleanup plan.
- Review flags on schedule (weekly).
- Remove temporary flags after rollout complete.
- Remove code references before deleting flag from flag system.
- Keep permanent kill switches separate from temporary release flags.
- Demo Mode must be easy to disable, hide, or remove later (flag + isolated folder).

## Demo Mode Removal Steps
1. Set `demoMode.enabled = false` in core/config.js
2. Remove `demo/` folder
3. Remove demo user picker UI in onboarding/setup.js
4. Remove demo sample data generation call
5. Ensure auth.js requires password in production mode
6. Remove "Demo" badge from header
7. Test production login flow

## Kill Switch Usage
- If risky feature causes issue, set killSwitch to false immediately (no deploy needed if config fetched from server, else quick config deploy).
- Kill switches are permanent, not removed after rollout.
- Example: `if (!featureFlags.killSwitches.qrGeneration) { show disabled message }`

## Rollout Strategy
- Internal testing first (percentage 10, team only)
- Small group rollout second (percentage 30, beta users)
- Larger rollout next (percentage 70)
- Full release last (100%)
- No big bang for risky features
- Always keep rollback simple: flip flag.

## Current Flags Status (MVP)
- demoMode: enabled true (for first value fast)
- onboardingExperiment: direct-to-task enabled
- customDomain: disabled (v1.1)
- fileUpload: enabled with kill switch true (MVP limited to images)
- aiGenerator: disabled
- All kill switches: true (features ON)
- campaignWizard: 100% enabled
- analyticsFunnel: 100% enabled

## Audit
- All flag changes logged to audit_logs with actor, action, flag name, old/new value.
- Reserved for admin.

## Future
- Move flags to backend /api/flags for dynamic control without redeploy.
- Use env variables for production mode toggle.
