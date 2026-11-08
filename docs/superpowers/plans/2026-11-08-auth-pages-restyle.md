# Login and Register Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Restyle the Login and Register pages to the approved MSU maroon/beige design, add working policy pages, and add login/registration rate limiting without changing existing routes or auth flows.

**Architecture:** One shared `AuthShell` React component owns the two-panel layout and the left brand panel. A scoped `.auth-page` CSS block defines design tokens and all auth styles. Login and Register retain their existing `useAuth` calls; Register gains client-side field validation and maps backend 422 field errors back to inputs. Two small policy pages reuse the shell.

**Tech Stack:** React 19, React Router 7, Vite 8, plain global CSS with CSS variables, Laravel 8 routes.

**Spec:** User-approved brief in chat, 2026-11-08 (design tokens, shared layout, Login/Register behavior, accessibility, backend requirements).

## Global Constraints

- Keep all existing logic, API calls, and routes.
- No gradients, orange, or decorative pink. Accent rose `#D35067` is used only for the input focus ring.
- No Google sign-in, no Google package, no Role dropdown.
- Light theme only.
- Brand `#7F0020`; brand-dark `#5E0018`; page `#FFF8F3`; surface `#F0E4D6`; gold `#C9A227`; ink `#2A1A1D`; muted `#6F6464`; input border `#CFC7BA`; success `#1E5A37` on `#DDF0E4`; warning `#92400E` on `#FEF3C7`; error `#8E1B1B` on `#FDECEC`; valid border `#2F7D4F`; invalid border `#B3261E`.
- Font: Inter, falling back to system-ui.
- At width below 800px: hide the left panel, form full width, two-column grids one column.
- Every input has a visible linked label; errors use `aria-live="polite"`.
- No commits are made unless the user asks.

## File Structure

- Create `frontend/src/components/auth/AuthShell.jsx` — shared two-panel layout and brand panel.
- Create `frontend/src/pages/Policy.jsx` — concise Terms and Privacy content.
- Rewrite `frontend/src/pages/Login.jsx` — restyled login form.
- Rewrite `frontend/src/pages/Register.jsx` — restyled registration form and confirmation.
- Modify `frontend/src/styles/global.css` — Inter import and scoped `.auth-page` styles.
- Modify `frontend/src/api/auth.js` — field errors, friendly duplicate messages, PH mobile normalization.
- Modify `frontend/src/App.jsx` — `/terms` and `/privacy` routes.
- Modify `backend/routes/api.php` — throttle login and register.

## Review Focus

- Failed login must not reveal whether the email or password was wrong; a pending account gets its own message.
- Registration must never sign the user in or assign a role.
- Server 422 errors must appear next to the responsible field, not as a generic banner.
- `+639XXXXXXXXX` must be accepted in the UI and normalized to `09XXXXXXXXX` for the existing backend rule.
- Registration confirmation must show the submitted email and return to `/login`.

---

### Task 1: Shared auth shell and CSS tokens

**Files:**
- Create: `frontend/src/components/auth/AuthShell.jsx`
- Modify: `frontend/src/styles/global.css`

**Interfaces:**
- Produces: `AuthShell({ title, lead, items, numbered = false, maxWidth = 420, children })`.
- Produces: CSS classes `.auth-page`, `.auth-brand`, `.auth-main`, `.auth-form`, `.auth-field`, `.auth-input`, `.auth-btn`, `.auth-error`, `.auth-note`, `.auth-link`, `.auth-checkbox`, `.auth-section-title`, `.auth-grid-2`, `.auth-password-rules`, `.auth-policy`.

- [x] **Step 1: Create `AuthShell.jsx`**

Render a `.auth-page` wrapper containing an `<aside className="auth-brand">` and `<main className="auth-main">`. The aside shows the 44px gold MSU badge, brand name, heading, gold rule, lead, list items (gold checks or numbered gold circles), and the footer. The main renders `<div className="auth-form" style={{ maxWidth }}>{children}</div>`.

- [x] **Step 2: Add Inter and the scoped CSS block**

Update the existing Google Fonts import to include Inter. Add the token block exactly as specified on `.auth-page`, plus all layout/form/validation/policy styles. Remove the old `.auth-*` rules that only Login/Register used.

- [x] **Step 3: Verify scoped CSS has no gradients/orange/decorative pink**

Run a search over the new `.auth-page` block for `gradient`, `orange`, `#FFA`, `#FFB`, and pink hex values other than `#D35067`. Expected: no matches.

### Task 2: Login page

**Files:**
- Rewrite: `frontend/src/pages/Login.jsx`

**Interfaces:**
- Consumes: `AuthShell` from Task 1 and `useAuth().signIn(email, password)`.
- Produces: no new exports.

- [x] **Step 1: Replace the duplicated left panel with `AuthShell`**

Use title `Welcome back`, the specified lead, and the three check items.

- [x] **Step 2: Implement the form**

Fields: email (`placeholder="name@msumain.edu.ph"`, `autoComplete="email"`), password with label row + `Forgot password?`, Show/Hide button with `aria-pressed`, Caps Lock hint `Caps Lock is on.`, keep-signed-in checkbox, primary button `Sign in` / `Signing in…`, centered register link, warning note.

- [x] **Step 3: Implement error behavior**

One `role="alert"` box. Pending message `Your account is awaiting approval.`; all other auth failures `Invalid email or password.` Keep the existing client lockout message as the only exception.

### Task 3: Register page and auth API errors

**Files:**
- Rewrite: `frontend/src/pages/Register.jsx`
- Modify: `frontend/src/api/auth.js`

**Interfaces:**
- Consumes: `AuthShell`, `useAuth().signUp(payload)`, `useNavigate`.
- Produces: `register()` throws `Error` objects with `fieldErrors` when the backend returns 422.
- Produces: frontend validation helpers `validateEmail`, `validateContact`, `validateEmployeeId`, `isPersonalPassword`.

- [x] **Step 1: Add field-error support to `api/auth.js`**

On 422, build `fieldErrors` from `err.response.data.errors`, map duplicate email/employee ID messages to friendly text, attach `fieldErrors` to the thrown error, and normalize `+639XXXXXXXXX` / `639XXXXXXXXX` to `09XXXXXXXXX` before POSTing.

- [x] **Step 2: Build the Register form**

Use the three specified sections and exact copy. Personal details grid: first name, last name, email, contact. Employment: employee ID + position grid, then college full width, then department full width (disabled until college chosen). Security: password + confirm grid, one Show/Hide toggle controlling both.

- [x] **Step 3: Add validation and password feedback**

Validate on blur and submit. Required fields; MSU email; PH mobile; `MSU-\d{5}`; password rules; matching passwords; agreement checked. Show valid/invalid borders and 12px errors with `aria-live="polite"`. Show the four-segment bar and rule list only after the user types a password.

- [x] **Step 4: Add the success state**

Render inside the same `AuthShell`: 56px success circle, `Registration submitted`, text with the submitted email, and a primary `Back to sign in` button that navigates to `/login`. Do not sign the user in.

### Task 4: Policy pages

**Files:**
- Create: `frontend/src/pages/Policy.jsx`
- Modify: `frontend/src/App.jsx`
- Modify: `frontend/src/styles/global.css`

**Interfaces:**
- Produces: `<Policy type="terms" />` and `<Policy type="privacy" />`.
- Produces: routes `/terms` and `/privacy`.

- [x] **Step 1: Add concise policy content and page**

Use the shared shell with page-specific title/lead/items. Include Terms sections (account use, authorized use, accuracy, availability, contact) and Privacy sections (data collected, purpose, access, retention, rights, security).

- [x] **Step 2: Register the routes**

Add both routes outside `ProtectedRoute`.

- [x] **Step 3: Add policy content styles**

Add `.auth-policy` heading/paragraph/list/link styles using the auth tokens. No gradients.

### Task 5: Backend rate limiting

**Files:**
- Modify: `backend/routes/api.php`

- [x] **Step 1: Add throttles**

`POST /api/login` gets `throttle:10,1`; `POST /api/register` gets `throttle:5,1`, keeping the existing `web` middleware and controller calls.

- [x] **Step 2: Run targeted backend tests**

Run `php artisan test --filter='RegistrationTest|LoginTest'` if PHP is available. Expected: all pass.

### Task 6: Verification

- [x] **Step 1: Build the frontend**

Run `npm run build` in `frontend`. Expected: Vite build succeeds.

- [x] **Step 2: Lint the frontend**

Run `npm run lint` in `frontend`. Expected: no new errors.

- [x] **Step 3: Check spec copy and forbidden styles**

Search the new auth code for `gradient`, `Continue with Google`, `Role`, `orange`, and pink values other than `#D35067`. Expected: no unintended matches.

- [x] **Step 4: Report**

List changed files, build result, exercise result, and any Google env vars found. If none are found, say so explicitly.
