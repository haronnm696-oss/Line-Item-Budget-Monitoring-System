# Manual test: the full process on one new project (2026-11-22)

One new project, from the Memorandum of Agreement to the audit trail, in
the order the work really happens: the Program Leader sets the project up,
the funds are released, the team spends against Form B (a multi-item
purchase request, an expense that pays some of its items, one quarter of
honoraria), Finance/Admin verifies, the Project Leader liquidates, and the
reports and audit trail show it all.

This walkthrough replaces `2026-11-16-role-walkthrough.md`, which describes
deductions and pay-rate settings that no longer exist.

**The one rule to watch:** allocated, spent, pending and available must be
**the same on every page that shows them** (Program Leader project page,
Project Leader overview and Line-item budget, Research Assistant Balancing
query, Finance/Admin overview and budget, Researcher dashboard and Budget,
Form B, the reports, the payroll register). Spent = verified expenses +
verified payroll. Pending = expenses and payroll waiting for approval or
verification, plus approved purchase requests with open items.
Available = allocated − spent − pending. The automated version of this check
is `backend/tests/Feature/SameNumbersEverywhereTest.php`.

## 0. Setup (local)

1. From `backend/`: `php artisan migrate`, `php artisan db:seed`,
   `php artisan serve`. Set `MAIL_MAILER=log` in `backend/.env` (emails land in
   `storage/logs/laravel.log`). No `PAYROLL_*` setting is needed or read.
2. From `frontend/`: `npm run dev` (http://localhost:5173).
3. Have three small files ready (PDF, JPG or PNG, under 5 MB): a "MOA", a
   "quotation" and a "receipt". Any file works.
4. Use one private window per role so each stays signed in.

### Accounts (password for all: `password123`)

| Role | Name | Email |
|---|---|---|
| Program Leader | Dr. Rahana Bayabao | `program.leader@gmail.com` |
| Project Leader | Engr. Jamal Macaraya | `project.leader3@gmail.com` |
| Research Assistant | Norjannah Haron | `assistant@gmail.com` |
| Finance/Admin | Faisal Sarip | `finance@gmail.com` |
| Researcher | Sittie Dimaporo | `researcher@gmail.com` |

Engr. Macaraya leads only the completed demo project, so he can lead the new
one (a Project Leader leads one active project at a time). The other roles
are on several projects: on each page, pick **WT-2026-001** with the project
switch next to the project name. The choice stays in the URL (`?project=`).

### Numbers used in this walkthrough

| Line item | Category | Allocated |
|---|---|---|
| Researcher Honoraria | PS | ₱216,000.00 |
| Supplies and Materials Expenses | MOOE | ₱500,000.00 |
| Traveling Expenses | MOOE | ₱284,000.00 |
| **Total (= approved budget)** | | **₱1,000,000.00** |

---

## Part 1. Program Leader: set up the project

Sign in as the Program Leader.

### 1.1 New project with the MOA details
Projects → **New project**:
- Project code `WT-2026-001`, Project title `Walkthrough Project`
- Funder **MOST-BARMM**, Project Leader **Engr. Jamal Macaraya**
- Total budget (₱) `1,000,000.00` (commas and ₱ are accepted)
- Start date `2026-01-01`, End date `2026-12-31`
- Memorandum of Agreement: MOA number `MOA-2026-045`, MOA date `2026-01-05`,
  Approved by the funder's R&D committee `2025-12-15`
- Fund source `MOST-BARMM GAAB 2026 Program Funds`
- Implementing Agency: leave blank (Form B prints the `IMPLEMENTING_AGENCY`
  setting, `MSU` by default)
- **Create project** → "Project created."

Check: a date outside 2000–2100 is refused; the code must be unique.

### 1.2 Project documents
On the project page, **Upload document** → Document type **MOA** → the MOA
file → upload. Expect "Document uploaded." and the file in the list with a
preview. Only the Program Leader has this button.

### 1.3 Form B line items
Line-item budget (R&D Form B) → **Add line item**, three times, using the
table above (implementing agency share ₱0.00, funder share = allocated):
- PS · Researcher Honoraria · ₱216,000.00 (monthly ₱18,000.00 × 12)
- MOOE · Supplies and Materials Expenses · ₱500,000.00
- MOOE · Traveling Expenses · ₱284,000.00

Expect the Form B total **₱1,000,000.00**, funder column ₱1,000,000.00.
**Export Form B**: the header shows the MOA number, fund source ("Chargeable
against MOST-BARMM GAAB 2026 Program Funds") and MSU as implementing agency;
the funder column is titled MOST-BARMM (never another funder's name).

### 1.4 Releases split 70/30
Releases → **Split 70% / 30%**. Expect "The 70% and 30% releases were
added.": **Release 1 ₱700,000.00** on 2026-01-01 and **Release 2
₱300,000.00** halfway through the period, both not released yet. The page
says "Release", never "tranche".

### 1.5 Team
Team → **Add member**: Norjannah Haron (Research Assistant), Faisal Sarip
(Finance/Admin), Sittie Dimaporo (Researcher). Engr. Macaraya is already on
the team as Project Leader.

### 1.6 Personnel (honoraria)
Personnel → **Add personnel**:
- Project member **Sittie Dimaporo**, PS line item **Researcher Honoraria**
- Monthly honorarium `18,000.00`
- Effective from `2026-07-01`, Effective to `2026-12-31`
- Special Order number `SO-2026-117`, Special Order file: any file
- Save → "Personnel added."

Check: the Researcher Honoraria line now pays through Payroll only. An
expense or purchase request item on it is refused.

| After Part 1 | Allocated | Spent | Pending | Available | Released |
|---|---|---|---|---|---|
| everywhere | ₱1,000,000.00 | ₱0.00 | ₱0.00 | ₱1,000,000.00 | ₱0.00 |

---

## Part 2. Project Leader: request the first fund release

Sign in as Engr. Macaraya. His dashboard opens on WT-2026-001.

1. Overview → next release → **Request release** (or My requests → **Submit
   budget request** → Type **Fund release**) → Release **Release 1** →
   Justification `Start of implementation per MOA-2026-045.` → **Send to
   Program Leader**.
2. Program Leader: bell → Approvals → the request → **Approve**. A user can
   never approve a request they created.

| After Part 2 | |
|---|---|
| Program Leader overview | Released **₱700,000.00** (70%), not yet released ₱300,000.00 |
| Project Leader | Release 1 **Released**; My requests shows the decision |
| Spent / pending | unchanged (₱0.00 / ₱0.00): a release is money received, not spent |

---

## Part 3. Research Assistant: purchase request, expense, payroll

Sign in as Norjannah Haron and pick WT-2026-001.

### 3.1 Multi-item purchase request
Purchase requests → **New purchase request**:
- Purpose `Field survey supplies`, Supplier / payee `Marawi Lab Supply`
- Items:

  | Line item | Qty | Unit | Description | Est. unit cost | Est. cost |
  |---|---|---|---|---|---|
  | Supplies and Materials Expenses | 10 | box | Nitrile gloves | 450.00 | 4,500.00 |
  | Supplies and Materials Expenses | 2 | pc | Handheld GPS unit | 12,500.00 | 25,000.00 |

- Total **₱29,500.00** (computed, not typed) → **Save as draft**.
- Attach the quotation (Document type **Quotation**), then **Submit**.
  Submitting with no quotation is refused.

Check: choosing **Researcher Honoraria** for an item is refused (honoraria
lines are paid through Payroll).

### 3.2 Project Leader approves it
Project Leader: Submissions → Waiting for you → the purchase request →
**Approve**.

| After 3.2 | Spent | Pending | Available |
|---|---|---|---|
| Supplies line | ₱0.00 | ₱29,500.00 (approved purchase request) | ₱470,500.00 |
| Project | ₱0.00 | ₱29,500.00 | ₱970,500.00 |

### 3.3 Finance/Admin records PR, SAI and ALOBS numbers
Finance/Admin: Purchase requests → the request → **Set PR / SAI / ALOBS** →
PR No. `2026-11-001`, SAI No. `SAI-2026-0311`, ALOBS No. `ALOBS-2026-0457`,
with dates → save. The Purchase Request download (**Purchase Request (PDF)**
or **CSV**) now prints these numbers, the items, "Mindanao State University /
Marawi City", and the Requested by / Noted by / Approved by signatories.

### 3.4 Expense linked to some of the items
Research Assistant: Expenses → **Record expense**:
- Line item **Supplies and Materials Expenses**
- Link to approved purchase request: **PR-<id> · Field survey supplies**
- Items this expense pays: tick **Nitrile gloves** only
- Amount `4,300.00` (the receipt; lower than the ₱4,500.00 estimate, never
  higher), Expense date `2026-11-20`, Reference no. `OR-88812`; the payee
  comes from the supplier
- Attach the receipt → **Save and submit**

Then the Project Leader approves it (Submissions → **Approve**) and
Finance/Admin verifies it (Verification queue → **Verify**).

| | Spent | Pending | Available (project) |
|---|---|---|---|
| after submit / approve | ₱0.00 | ₱4,300.00 expense + ₱25,000.00 open GPS item = ₱29,300.00 | ₱970,700.00 |
| after **Verify** | **₱4,300.00** | ₱25,000.00 | ₱970,700.00 |

The request shows **Partly fulfilled**: gloves **Paid**, GPS unit **Open**.

### 3.5 Quarterly payroll
Payroll → First month **2026-07**, Last month **2026-09** → PS line item
**Researcher Honoraria** → **Prepare 1 person**. One row:

| Name | Computation | Gross | Net pay |
|---|---|---|---|
| Sittie Dimaporo | ₱18,000.00 × 3 months | ₱54,000.00 | ₱54,000.00 |

There are no deduction or allowance columns (no GSIS, PhilHealth, Pag-IBIG
or tax). **Upload payroll file** → **Submit batch** (disabled until a payroll
file is attached).

Then the Project Leader approves the batch and Finance/Admin verifies it.

| | Spent | Pending | Available (project) |
|---|---|---|---|
| after submit / approve | ₱4,300.00 | ₱25,000.00 + ₱54,000.00 = ₱79,000.00 | ₱916,700.00 |
| after **Verify** | **₱58,300.00** | ₱25,000.00 | ₱916,700.00 |

Payroll counts as spent once verified, charged to Researcher Honoraria.

---

## Part 4. Project Leader: review, cancel, liquidate

### 4.1 Review (already done above)
Every expense, payroll batch and purchase request reached the Project
Leader first (Submissions). Optional: **Return** one with a reason; it goes
back to the Research Assistant ("Edit and resubmit") and counts nowhere until
resubmitted.

### 4.2 Cancel the rest of the purchase request
The GPS units will not be bought. Submissions → Approved by you → the
purchase request → **Cancel request** → reason `GPS units provided by the
partner LGU.` Only the open item is released; the gloves stay with their
expense. Status **Cancelled (partly fulfilled)**.

| After 4.2 | Spent | Pending | Available |
|---|---|---|---|
| Supplies line | ₱4,300.00 | ₱0.00 | ₱495,700.00 |
| Project | **₱58,300.00** | **₱0.00** | **₱941,700.00** |

### 4.3 Liquidation of Release 1
My requests → **Submit budget request** → Type **Liquidation** → Release
**Release 1** → Amount liquidated `58,300.00` → Justification → attach a
supporting document → **Send to Program Leader**. Program Leader: Approvals →
**Approve**.

Spent does not change: liquidation reports money already spent.

---

## Part 5. Finance/Admin checks

- Verification queue → **Verified by you** lists OR-88812 (₱4,300.00) and the
  July–September payroll (₱54,000.00).
- Overview: Total spent **₱58,300.00**, Total budget ₱1,000,000.00.
- Purchase requests: PR 2026-11-001 with its PR, SAI and ALOBS numbers,
  **Cancelled (partly fulfilled)**.

## Part 6. Researcher (read-only)

Sign in as Sittie Dimaporo, pick WT-2026-001.
- Dashboard and **Budget**: budget ₱1,000,000.00, spent **₱58,300.00**,
  remaining ₱941,700.00; per line: Honoraria ₱54,000.00, Supplies
  ₱4,300.00, Traveling ₱0.00.
- No Record, Upload, Approve, Verify, Cancel or Delete button anywhere.
- Reports: Form B, utilization and ledger download; the **payroll register
  is not offered** (pay per person is not shown to Researchers).

## Part 7. Reports (Project Leader or Finance/Admin)

Reports, project WT-2026-001:

| Report | Expect |
|---|---|
| R&D Form B | Three lines, total ₱1,000,000.00; MOA details, fund source; funder column MOST-BARMM |
| Budget utilization | Honoraria 216,000 / 54,000 (25%); Supplies 500,000 / 4,300 (0.9%); Traveling 284,000 / 0; total spent 58,300.00 |
| Expense ledger | OR-88812 ₱4,300.00 Verified and the July–September payroll ₱54,000.00 Verified; verified rows add up to ₱58,300.00 |
| Payroll register | July–September 2026: Sittie Dimaporo, ₱18,000.00 × 3, gross = net ₱54,000.00; verified batches only |
| Monthly financial summary | Verified rows only (drafts and waiting items are left out): PS ₱54,000.00, MOOE ₱4,300.00, each in the month of its row date |
| Purchase request export | From the request's panel: **Purchase Request (PDF)** prints the MSU form with both items and the PR/SAI/ALOBS numbers; **CSV** has the same rows |

Program Leader: Program reports show the same project with released
₱700,000.00, spent ₱58,300.00 and its health status. The overview is
descriptive only: no forecast or projection.

The Research Assistant's **Balancing query** for the project: allocated
₱1,000,000.00, spent ₱58,300.00, pending ₱0.00, available ₱941,700.00, the
same as every page above.

## Part 8. Audit trail

Program Leader: Audit trail, filter project WT-2026-001. Expect one entry
per action, newest first, each with who, when and what changed: project
created, MOA uploaded, three line items, two releases, three members,
personnel and Special Order, fund release requested and approved
(Release 1 released), purchase request created / submitted / approved /
numbers set / fulfilled / cancelled (with its reason), expense created /
submitted / approved / verified, payroll prepared / submitted / approved /
verified, liquidation requested and approved. Project Leader and
Finance/Admin see the same entries for this project under **Audit**.

## Reset

`php artisan migrate:fresh --seed` deletes every row in the local database.
Use it only on a local test database, never on Aiven.
