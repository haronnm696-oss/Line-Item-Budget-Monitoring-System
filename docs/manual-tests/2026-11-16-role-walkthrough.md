# Manual test: one expense and one payroll batch through every role

Walks one expense and one payroll batch from the Research Assistant to the
Project Leader (with a Return), to Finance/Admin (with a Reject), back
again, and to verified. After each step it lists what every role should
see. All expected numbers were measured by running these exact steps through
the backend services on a freshly seeded database (2026-11-16).

## 0. Setup (local)

1. `backend/.env` points at your local MySQL (`lib_monitoring_system`) and
   contains the payroll **TEST VALUES** block (not official rates).
2. From `backend/`:
   ```bash
   php artisan migrate
   php artisan db:seed          # demo users and the four demo projects
   php artisan config:clear     # so the payroll TEST VALUES are read
   php artisan serve            # http://localhost:8000
   ```
   The expected numbers below assume a **freshly seeded** database. If your
   database already has records from earlier testing, the starting numbers
   will differ: check the **change** at each step instead (+1 / −1).
3. From `frontend/`: `npm run dev` (http://localhost:5173), with
   `VITE_API_URL=http://localhost:8000` in `frontend/.env`.
4. **Pay rate for the payroll part.** There is no screen for pay rates yet
   (only `PATCH /api/projects/{id}/pay-rates/{user}`). For this test, give
   the Researcher a monthly rate on the Urban Mobility project:
   ```bash
   php artisan tinker --execute="App\Models\ProjectUser::where('project_id', App\Models\Project::where('code','MOST-RD-2026-001')->value('id'))->where('user_id', App\Models\User::where('email','researcher@gmail.com')->value('id'))->update(['monthly_rate' => 40000, 'allowance' => 0]);"
   ```
   (Test setup only: this bypasses the audit entry the API would write.)
5. Use **one browser window per role** (or private windows), so each stays
   signed in.

## Demo accounts (password for all: `password123`)

| Role | Name | Email |
|---|---|---|
| Program Leader | Dr. Rahana Bayabao | `program.leader@gmail.com` |
| Project Leader (Urban Mobility) | Dr. Aminah Macarambon | `project.leader@gmail.com` |
| Research Assistant | Norjannah Haron | `assistant@gmail.com` |
| Finance/Admin | Faisal Sarip | `finance@gmail.com` |
| Researcher | Sittie Dimaporo | `researcher@gmail.com` |

Other Project Leaders (other projects, not used here): `project.leader2@gmail.com`,
`project.leader3@gmail.com`, `project.leader4@gmail.com`.

**Project:** everything happens on **Enhancing Urban Mobility in Marawi City
(MOST-RD-2026-001)**. The Research Assistant, Finance/Admin and Researcher
are on all four demo projects, so pick this project in the **Project**
selector on each page (it may not be the first one). Dr. Macarambon leads
only this project. The selected project stays in the URL (`?project=`).

## What to look at for each role

| Role | Overview KPIs | Sidebar badge | Total spent shown on |
|---|---|---|---|
| Project Leader | **To review** | **Submissions** (= To review) | Overview "Total spent" |
| Research Assistant | **Drafts**, **Waiting for Project Leader**, **Returned to you**, **Missing receipts** | **My submissions** (= Returned to you) | (not shown) |
| Finance/Admin | **To verify**, **Verified this month** (₱ expenses; "n expenses · m payroll batches"), **Missing receipts**, **Total spent** | **Verification queue** (= To verify) | Overview "Total spent" |
| Researcher | Total budget, **Total spent**, Remaining balance, **Verified transactions** | none | Overview "Total spent" |
| Program Leader | Projects → Urban Mobility | Approvals | project page "Spent" |

**Bell** = number of **unread** notifications. The counts below assume you do
**not** open them until the step that says so. Clicking one marks it read
(the count drops by 1) and opens the exact record:
- Research Assistant: the record's page with its side panel (Expenses or Payroll).
- Project Leader: Submissions with the side panel.
- Finance/Admin: Verification queue with the side panel.

## Starting point (fresh seed)

| | Value |
|---|---|
| PL | To review **2**, Submissions badge **2**, Total spent **₱1,430,000.00** |
| RA | Drafts **0**, Waiting **2**, Returned **1** (badge **1**), Missing receipts **1** |
| FIN | To verify **0** (badge **0**), Verified this month **₱0.00** · 0 expenses · 0 payroll batches, Missing receipts **1** (compliance 85.7% · 6 of 7), Total spent **₱1,430,000.00** |
| RES | Total spent **₱1,430,000.00**, Verified transactions **7** |
| PRG | Urban Mobility spent **₱1,430,000.00** |
| Bells | all **0** |

Total spent: **₱1,430,000.00** everywhere until step E8.

---

## Part A: one expense

Line item **MOOE-05 Cloud/Server Rent** (₱200,000.00 allocated, nothing spent).

### E1. RA records the expense (Research Assistant)
Expenses and receipts → **Record expense**:
- Line item: *MOOE · Cloud/Server Rent* (the hint shows the available amount)
- Description `Cloud server rent, November`, Amount `12500`, Date `2026-11-10`
- Payee `Marawi Cloud Hosting`, Reference no. `SV-2026-11`
- Receipt: any PDF/JPG/PNG
- Click **Save, attach and submit**.

Optional check first: **Save as draft** without a receipt → RA Drafts **1**,
and the row says "Attach the receipt, then submit". Then open it from the
panel (**Edit**), attach the receipt and submit.

Also try **Save, attach and submit** with no receipt: it is refused with
"Attach the receipt (PDF, JPG or PNG)."

| After E1 | |
|---|---|
| PL | To review **3**, badge **3** |
| RA | Drafts **0**, Waiting **3**, Returned **1** (badge **1**) |
| FIN | To verify **0** |
| Bells | **PL 1** ("Expense waiting for your approval"), others 0 |

### E2. PL returns it (Project Leader)
Click the bell notification (opens Submissions with SV-2026-11 in the
panel) → **Return** → reason `Receipt is unclear. Please upload a clearer copy.`

| After E2 | |
|---|---|
| PL | To review **2**, badge **2** |
| RA | Waiting **2**, Returned **2**, badge **2** |
| Bells | PL 1 (or 0 if you opened it), **RA 1** ("Expense returned to you") |

RA Overview, My submissions: SV-2026-11 shows **Returned** and
"Dr. Aminah Macarambon: “Receipt is unclear…”".

### E3. RA corrects and resubmits (Research Assistant)
Click the bell (opens Expenses with the panel) → **Edit and resubmit**. The
return reason is at the top. **Remove** the old receipt, attach a new one,
**Resubmit**. (Removing the receipt without adding one is refused: a
receipt is required.)

| After E3 | |
|---|---|
| PL | To review **3**, badge **3** |
| RA | Waiting **3**, Returned **1**, badge **1** |
| Bells | **PL +1** (2 unread if none opened), RA 1 |

### E4. PL approves (Project Leader)
Submissions → SV-2026-11 → **Approve**.

| After E4 | |
|---|---|
| PL | To review **2**, badge **2** |
| RA | Waiting **2** (next step: "Waiting for Finance/Admin verification") |
| FIN | To verify **1**, badge **1**; the card shows "Receipt attached ✓" and "Approved by Dr. Aminah Macarambon" |
| Bells | **FIN 1** ("Expense ready for verification") |

### E5. FIN rejects (Finance/Admin)
Click the bell (opens the Verification queue panel) → **Reject** → reason
`Amount does not match the official receipt (P12,000.00).`

| After E5 | |
|---|---|
| FIN | To verify **0**, badge **0**; "Rejected by you" tab: **1** |
| RA | Waiting **2**, Returned **2**, badge **2** |
| Bells | **RA +1** (2 unread if none opened) ("Expense rejected") |
| Total spent | unchanged **₱1,430,000.00** |

### E6. RA corrects the amount and resubmits (Research Assistant)
Bell → **Edit and resubmit** → Amount `12000` (keep the receipt) → **Resubmit**.
A resubmitted expense goes back to the **Project Leader** first.

| After E6 | |
|---|---|
| PL | To review **3**, badge **3** |
| RA | Waiting **3**, Returned **1**, badge **1** |
| FIN | To verify **0** |
| Bells | **PL +1** |

### E7. PL approves again (Project Leader)
Submissions → SV-2026-11 → **Approve**.

| After E7 | |
|---|---|
| PL | To review **2**, badge **2** |
| FIN | To verify **1**, badge **1** |
| Bells | **FIN +1** |

### E8. FIN verifies (Finance/Admin) → **Total spent changes here**
Verification queue → SV-2026-11 → **Verify** (the panel says "Verifying
adds ₱12,000.00 to Total spent").

| After E8 | |
|---|---|
| FIN | To verify **0**, badge **0**; Verified this month **₱12,000.00** · 1 expense · 0 payroll batches; Recently verified lists SV-2026-11; **Total spent ₱1,442,000.00** |
| PL | **Total spent ₱1,442,000.00** |
| RES | **Total spent ₱1,442,000.00**, Verified transactions **8** |
| PRG | Urban Mobility spent **₱1,442,000.00** |
| RA | Waiting **2**; SV-2026-11 shows **Verified** · "Counted in the budget" |
| Bells | **RA +1** ("Expense verified") |

Also check: Researcher Budget, row *Cloud/Server Rent*: spent **₱12,000.00**;
RA Balancing query for the same line: Spent ₱12,000.00, Pending ₱0.00.

---

## Part B: one payroll batch (November 2026)

Uses the Researcher's ₱40,000.00 monthly rate from setup step 4 and your
TEST VALUES deduction rates. Total spent must **not** change in this part
(payroll is not part of LIB spent).

### P1. RA prepares and submits (Research Assistant)
Payroll → Month **2026-11** → PS line item **Research Assistant Honoraria** →
**Prepare 1 person**. The row should read:

| Name | Basic | Gross | Deductions | Net |
|---|---|---|---|---|
| Sittie Dimaporo | ₱40,000.00 | ₱40,000.00 | ₱4,907.50 | ₱35,092.50 |

(GSIS 2,900.00 · PhilHealth 680.00 · Pag-IBIG 600.00 · Withholding Tax 727.50.)
No "Deduction rates are not set yet" warning should show; if it does, run
`php artisan config:clear`.

**Submit batch** is disabled until a payroll file is attached. **Upload
payroll file** (any PDF), then **Submit batch**.

| After P1 | (change from the end of Part A) |
|---|---|
| PL | To review **3**, badge **3** |
| RA | Drafts 1 while preparing, then Drafts **0**, Waiting **3** |
| Bells | **PL +1** ("Payroll waiting for your approval") |

### P2. PL returns (Project Leader)
Bell → Submissions panel → **Return** → `Attach the signed payroll sheet.`

| After P2 | |
|---|---|
| PL | To review **2**, badge **2** |
| RA | Waiting **2**, Returned **2**, badge **2** |
| Bells | **RA +1** ("Payroll returned to you"; opens Payroll for November) |

### P3. RA resubmits (Research Assistant)
Payroll (November) shows **Returned** with the reason. **Upload payroll
file** (the "signed" sheet) → **Resubmit batch**.

| After P3 | |
|---|---|
| PL | To review **3**, badge **3** |
| RA | Waiting **3**, Returned **1**, badge **1** |
| Bells | **PL +1** |

### P4. PL approves (Project Leader)

| After P4 | |
|---|---|
| PL | To review **2**, badge **2** |
| FIN | To verify **1**, badge **1** (the PAY-2026-11 card has no receipt pill) |
| Bells | **FIN +1** ("Payroll ready for verification") |

### P5. FIN rejects (Finance/Admin)
Bell → queue panel (PAY-2026-11) → **Reject** → `Net pay differs from the signed sheet.`

| After P5 | |
|---|---|
| FIN | To verify **0**, badge **0** |
| RA | Waiting **2**, Returned **2**, badge **2** |
| Bells | **RA +1** ("Payroll rejected") |
| Total spent | unchanged **₱1,442,000.00** |

### P6. RA recomputes and resubmits (Research Assistant)
Payroll (November) → **Recompute** → **Resubmit batch**. It goes back to
the Project Leader.

| After P6 | |
|---|---|
| PL | To review **3**, badge **3** |
| RA | Waiting **3**, Returned **1**, badge **1** |
| Bells | **PL +1** |

### P7. PL approves again (Project Leader)

| After P7 | |
|---|---|
| PL | To review **2**, badge **2** |
| FIN | To verify **1**, badge **1** |
| Bells | **FIN +1** |

### P8. FIN verifies (Finance/Admin) → **Total spent does NOT change**
Verification queue → PAY-2026-11 → **Verify** (the panel says "Payroll is
not counted in LIB spent").

| After P8 | |
|---|---|
| FIN | To verify **0**, badge **0**; Verified this month **₱12,000.00** · 1 expense · **1 payroll batch**; Total spent **₱1,442,000.00** |
| PL / RES / PRG | Total spent **₱1,442,000.00** (unchanged) |
| FIN Payroll page | PAY-2026-11 **Verified** · "Verified (payroll is not part of LIB spent)" |
| Bells | **RA +1** ("Payroll verified") |

---

## Final state (fresh seed, no notification opened)

| | Value |
|---|---|
| PL | To review **2**, badge **2**, Total spent **₱1,442,000.00**, bell **6** |
| RA | Drafts 0, Waiting **2**, Returned **1** (badge **1**), Missing receipts 1, bell **6** |
| FIN | To verify **0**, Verified this month **₱12,000.00** · 1 expense · 1 payroll batch, Total spent **₱1,442,000.00**, bell **4** |
| RES | Total spent **₱1,442,000.00**, Verified transactions **8**, bell 0 |
| PRG | Urban Mobility spent **₱1,442,000.00**, bell 0 |

## Extra checks (optional)

- **Researcher is read-only:** no Record, Upload, Verify or Delete button
  anywhere. Reports → **PDF** / **CSV** buttons download / print the report.
- **Notify RA:** Finance/Admin Overview → Missing receipts → **Notify RA**
  on the seeded missing-receipt expense → RA bell **+1** ("Receipt needed")
  → it opens the expense panel with **Upload receipt**. After the upload,
  Missing receipts drops by 1 for PL, RA and FIN, and FIN gets a "Missing
  receipt uploaded" notification; the queue lists it under "Late receipts
  (for information)", not as something to verify.
- **Badge = KPI:** at every step, each sidebar badge equals its KPI
  (PL Submissions = To review, RA My submissions = Returned to you, FIN
  Verification queue = To verify).

## Reset

To repeat from the start you need the seeded state again. `php artisan
migrate:fresh --seed` **deletes every row** in the local database; use it
only on a local test database, never on Aiven.
