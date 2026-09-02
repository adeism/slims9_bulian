# UI Rules — Tautan

## Main UX Rule
Fewer user steps is always better. One screen one main action. One flow one clear goal. Remove any step that does not help user reach value.

## Hierarchy, Spacing, Grouping, Visual Focus
- Use Tailwind: compact, clear, calm, not overloaded.
- Important first, hide advanced until later (progressive disclosure).
- Dense with useful info but not overwhelming.
- Split content into clear blocks, each section one clear job.
- Avoid too many equal-weight actions on one screen.
- Use 8px spacing system, card shadows subtle, rounded-xl.
- Primary action: solid, high contrast. Secondary: outline. Tertiary: ghost.
- Visual focus: first value CTA largest, centered or top.

## Responsive Design
- Must work well desktop and mobile from start.
- Mobile practical, not broken: drawer nav, bottom sheet for actions, touch-friendly drag-drop with tap alternative.
- Breakpoints: mobile <768, tablet 768-1024, desktop >1024.
- Preview mobile/desktop toggle in microsite builder.

## SVG Assets
- Use SVG for backgrounds and lightweight visuals.
- Keep SVG files separate when possible (assets/bg-pattern.svg, assets/logo.svg).
- SVG can be handmade or AI generated.
- No fake decorative complexity.
- Background SVG subtle, low opacity, not distracting.

## First-Run Structure
- Keep first-run flow simple.
- One setup file until defaults complete: handles mode selection, role selection, sample data choice, default settings, guide start, first success handoff.
- Use smart/safe/recommended defaults, mark recommended clearly.
- Let user skip non-critical setup, change later in Settings.
- Auto-detect when safe: language (navigator.language), timezone (Intl), theme (prefers-color-scheme), date format (locale).
- Show short review page before setup ends.
- Show setup progress clearly (progress bar, step 1/5).
- Autosave setup progress to localStorage.

## Onboarding
- No long generic tour.
- Prefer direct-to-task onboarding: teach by doing.
- Short interactive guide only for first important task (e.g., drag first block).
- Keep first guide short (<60 sec).
- Contextual help when user reaches friction, not all at once.
- Every help bubble dismissible, reopenable later.
- Help center always available.
- One beginner hint per critical screen.

## Tutorial System
- Quick tutorial: fast start, short, simple (checklist 3-5 items)
- Detailed tutorial: deeper learning, full feature explanation
- In-app help center: always available (question mark icon)
- Contextual help on each important screen
- Reset onboarding in Settings

## Checklist
- Show short onboarding checklist 3-5 items.
- Each item leads directly to correct screen.
- First item can be pre-completed when appropriate (e.g., sample data chosen → workspace created pre-completed).
- Focus on activation, not everything.

## Navigation & Task Design
- Ask user what they want to do (intent question on empty dashboard).
- Route to shortest path.
- One-click start where possible (e.g., "Buat Shortlink" button creates draft immediately).
- Role-based templates.
- Recent actions.
- Continue-where-you-left-off (last edited resource).
- Command palette (Ctrl+K) if feature-rich (future).
- Avoid making user search menus for next step.

## Information Design
- Show important things first.
- Use cards for resources, with status badge (draft/published/paused).
- Analytics: funnel visualization, not just tables.
- Use skeleton loaders, not blank.

## Empty States
- Never blank dead screen.
- Every empty state must explain what this area is for.
- Every empty state must show next best action.
- If no real data, offer sample data, starter data, or template.

Example empty state:
```
Icon (SVG)
Title: Belum ada Shortlink
Desc: Shortlink membantu bagikan link panjang jadi pendek & terlacak
CTA: + Buat Shortlink pertama (primary)
Secondary: Lihat template / Gunakan data contoh
```

## First Goal
- User enters → one real task → one clear result fast → after that configure more.
- First screen after setup should not be empty: show first workspace, project, or item automatically.

## Setup Rules
- Ask if user wants sample data: if yes generate, if no start empty, if useful offer starter templates by role.
- Create first workspace/project/item automatically when possible.
- Do not let user land on dead empty screen.

## Buttons & Actions
- One primary per screen.
- Dangerous actions (delete, revoke sessions) need confirmation.
- Copy actions show toast "Disalin!"
- Download actions show progress.

## Accessibility
- Keyboard navigable.
- ARIA for drag-drop.
- Color contrast AA.
- Focus visible.

## Compact, Clear, Calm
- Avoid clutter.
- Use whitespace.
- Use system font stack, readable.
- Animations subtle, 200ms.

## SVG Background Example
- Use separate file: assets/bg-dots.svg with pattern, low opacity.
- Handmade simple shapes, not stock heavy.

## Theme
- Auto-detect light/dark via prefers-color-scheme, allow toggle.
- Default: light, calm, white + indigo primary + slate text.
- Recommended: mark with "Direkomendasikan" badge.
