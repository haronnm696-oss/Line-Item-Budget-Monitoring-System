# Manual test: the whole chain on one project (2026-11-17)

> **Superseded by [2026-11-22-full-walkthrough.md](2026-11-22-full-walkthrough.md).** Its payroll steps use the removed Pay rates card and deduction settings, and say payroll is not part of LIB spent; none of these is true any more.

The browser version of `backend/tests/Feature/ConnectionWalkthroughTest.php`.
It checks that every role sees the **same project** once the Program Leader
assigns them (the "You do not lead a project yet" bug), and that each hand-off
reaches the right person.

At every hand-off, check:

- **one** new notification (bell) for the person named, and nobody else;
- **one** new row in the Program Leader's **Audit trail**.

## 0. Setup

1. Run locally (see `2026-11-16-role-walkthrough.md`, section 0). `backend/.env`
   needs the payroll **TEST VALUES** block, otherwise the payroll **Submit**
   stays disabled with "Deduction rates are not set yet". Then run
   `php artisan config:clear`.
2. Open one browser window (or private window) per account. The password for
   every account is `password123`.

| Role | Email |
|---|---|
| Program Leader | `program.leader@gmail.com` |
| Project Leader | `project.leader2@gmail.com` (the demo leader of RP-2026-009) |
| Research Assistant | `assistant@gmail.com` |
| Finance/Admin | `finance@gmail.com` |
| Researcher | `researcher@gmail.com` |

**Project:** Halal Goat Value Chain Development (**RP-2026-009**). On every page,
pick it in the **Project** selector. It stays in the URL as `?project=`.

## 1. Assignments: Program Leader

1. **Program Leader** → **Users and roles**. For each of the four accounts
   above, open **Manage** → **Edit project assignments**:
   - If RP-2026-009 is listed with the right role, leave it as it is.
   - If it is listed with another role, choose the right role and click
     **Save role**.
   - If it is not listed, use **Manage** → **Assign to project** →
     RP-2026-009 with the right role → **Assign**.

   Roles: `project.leader2` = Project Leader, `assistant` = Research Assistant,
   `finance` = Finance/Admin Staff, `researcher` = Researcher.

   **Expected:** each change adds one row to the **Audit trail**
   ("Assignment created" or "Assignment updated").
2. **Program Leader** → **Projects** → RP-2026-009: the Project Leader shown
   is `project.leader2`'s name.
3. **Project Leader** (sign in again or refresh). Open each page below and
   check that it shows **RP-2026-009** and never "You are not assigned as
   leader of a project yet":
   **Overview**, **Line-item budget**, **Transactions**, **Submissions**,
   **My requests**, **Reports**.
   Every page should list the same projects in its **Project** selector.

## 2. Expense: submit, return, resubmit, approve, verify

| # | Account → page | Do | Expected |
|---|---|---|---|
| 2.1 | **Research Assistant** → **Expenses and receipts** | **Record expense**: Supplies, ₱2,500.50, attach a receipt, then submit it | Status: Submitted. **Project Leader**: 1 notification; the **Submissions** badge goes up by 1; the expense is in **Submissions** → **Waiting for you** |
| 2.2 | **Project Leader** → **Submissions** | Open the expense → **Return** with the reason "The receipt is unreadable." | **RA**: 1 notification. **My submissions** shows it as **Returned** with that reason and "Returned by" the Project Leader. The RA's **My submissions** badge goes up by 1 |
| 2.3 | **Research Assistant** → **My submissions** | Open it → **Resubmit** | **Project Leader**: 1 notification; the expense is back in **Waiting for you** |
| 2.4 | **Project Leader** → **Submissions** | **Approve** | **Finance/Admin**: 1 notification; the **Verification queue** badge goes up by 1; the expense is in **Verification queue** → **Waiting for you** |
| 2.5 | Every role | Write down **Total spent** (see the table below) | Not changed yet: an approved expense is not spent |
| 2.6 | **Finance/Admin** → **Verification queue** | Open it → **Verify** | **RA**: 1 notification. **Total spent** goes up by **₱2,500.50**, the same on every page in the table below |

Where to read **Total spent**:

| Account | Page |
|---|---|
| Project Leader | **Overview** → Total spent |
| Research Assistant | **Balancing query** → total Spent |
| Finance/Admin | **Overview** → Total spent |
| Researcher | **Overview** → Total spent |
| Program Leader | **Projects** → RP-2026-009 → Spent |

## 3. Finance rejects a second expense

1. **RA**: record and submit another expense (₱999, with a receipt).
   **Project Leader**: **Approve** it.
2. **Finance/Admin** → **Verification queue** → open it → **Reject** with the
   reason "Amount does not match the OR."
3. **Expected:** the **RA** gets 1 notification, and **My submissions** shows
   it as Returned with that reason, "Returned by" Finance/Admin.
   **Total spent** does not change.

## 4. Payroll

1. **Finance/Admin** → **Payroll** → **Pay rates** card. Set the Research
   Assistant to ₱20,000.00 and the Researcher to ₱30,000.00. Leave the
   Project Leader and Finance/Admin as **Not set**. Each save adds one audit
   row.
2. **RA** → **Payroll**, month **November 2026**:
   - Pick the PS line item → **Prepare**. The banner lists the people left
     out: "Not included: no pay rate set for …".
   - **Upload payroll file**, then **Submit batch**.
   - **Expected:** the **Project Leader** gets 1 notification, and the batch
     is in **Submissions** (type Payroll).
3. **Project Leader** → **Submissions** → the batch → **Approve**.
   **Expected:** **Finance/Admin** gets 1 notification, and the batch is in
   the **Verification queue**.
4. **Finance/Admin** → **Verification queue** → **Verify**.
   **Expected:** the **RA** gets 1 notification. **Total spent** does not
   change, because payroll is not part of LIB spent.

**A batch that pays the Project Leader goes to the Program Leader:**

5. **Finance/Admin** → **Pay rates**: set the Project Leader to ₱45,000.00.
6. **RA** → **Payroll**, month **December 2026**: **Prepare**, upload the
   file, then **Submit batch**.
   **Expected:**
   - The **Program Leader** gets 1 notification and sees the batch in
     **Approvals** → **Pending**.
   - The **Project Leader** gets 1 notification explaining "Waiting for
     Program Leader (includes your pay)" and cannot approve it.
7. **Program Leader** → **Approvals** → the batch → **Approve**.
   **Expected:** **Finance/Admin** gets 1 notification. Then **Finance/Admin**
   → **Verify**.

## 5. Fund release

1. **Project Leader** → **Overview** → **Submit budget request**. In the
   dialog, choose a fund release for the next tranche and write a
   justification → submit.
   **Expected:** the **Program Leader** gets 1 notification, and the request
   is in **Approvals** → **Pending**.
2. **Program Leader** → **Approvals** → the request → **Approve**.
   **Expected:**
   - The **Project Leader** gets 1 notification, and **My requests** shows the
     request as **Approved** (the **My requests** badge goes up by 1 until
     the page is opened).
   - On **Overview**, that tranche shows as released. The released amount
     goes up by the tranche amount.

## 6. Researcher: read only

1. **Researcher** → **Overview**, **Line-item budget**, **Transactions**,
   **Reports**: shows RP-2026-009 with the verified expense from step 2. The
   rejected and unverified expenses are not listed.
2. The Researcher has no Record, Submit, Approve or Verify buttons anywhere,
   and no **Payroll** menu, so pay rates are never shown.
   (`ConnectionWalkthroughTest` checks that every write endpoint returns 403.)

## 7. Unassigning takes effect at once

1. **Program Leader** → **Users and roles** → `assistant@gmail.com` →
   **Manage** → **Edit project assignments** → RP-2026-009 →
   **Remove from project**, with a reason.
   **Expected:** one "Removed from project" row in the **Audit trail**.
2. **Research Assistant**: refresh any page. RP-2026-009 is gone from the
   **Project** selector. With no other project, every RA page shows "You are
   not assigned as Research Assistant of a project yet. Ask the Program Leader
   to assign you.", and **Record expense**, **Purchase request** and
   **Prepare** are not shown.
3. To restore the setup, use **Assign to project** again (Research Assistant
   on RP-2026-009).

## 8. No project at all (Project Leader)

With a Project Leader account that has no project
(e.g. remove `project.leader3` from its project, after assigning another leader
there first):

- **Overview** shows "You are not assigned as leader of a project yet. Ask the
  Program Leader to assign you.", with no **Generate report**, **Record
  transaction** or **Submit budget request** buttons.
- **My requests**, **Submissions**, **Line-item budget** and **Transactions**
  show the same message.
- **Reports**: the **Project** selector shows the message, and the generate
  buttons are disabled.
