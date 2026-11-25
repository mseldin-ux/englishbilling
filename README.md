# English Billing: Laravel rewrite

Laravel 13 / PHP 8.5 rewrite of the Montessori training billing system.
See the [project plan](../LARAVEL-MIGRATION-PLAN.md) for the complete rewrite scope.

## Current milestone

Implemented:

- The nine current billing tables and Eloquent relationships, without replaying
  Django migrations.
- Fixed-precision monetary columns, preserved nullable flags and calendar dates.
- Username/password login, logout, and admin-only user management.
- A protected foundation page displaying imported table counts.
- Transactional, bounded-memory import and full snapshot verification.
- Invoice foreign keys that unlink transactions when an invoice is deleted.
- Synthetic tests for permissions, rounding, import safety, rollback, and PDFs.
- Browse/find filters with persisted searches, pagination, and previous/next
  navigation; learner creation and editing of all current model fields.
- Transaction, invoice-metadata, and LOA creation/editing/deletion. Ledger
  mutations recompute balances atomically using exact cents.
- Payment-plan template management, schedule generation and editing, ordered
  row insertion/reordering, final-cent allocation, and balance recalculation.
- Authenticated original-PDF viewing/downloading, without loading PDF blobs
  into invoice lists.
- Optimistic edit checks, unsaved-change indicators, preserved unrelated drafts,
  and explicit validation/session/server error messages.
- Compact learner fields and explicit invoice creation/editing buttons with
  floating dialogs and multiline notes.
- Authenticated invoice/statement HTML previews, printing, and PDF downloads.
  New invoices and their stored PDFs are created atomically.
- Attributable Income Report with detailed/fewer-field HTML and CSV output,
  exact-cent financial totals, program-service calculations, and live comparisons.
- Expected Income and Transactions reports with legacy filters, cent totals,
  HTML/CSV output, and read-only live detail comparisons.
- Earned Income, Collections, Invoices, Receivables, and rebuilt Prepays
  with HTML/CSV output, exact money, and legacy/source comparisons.
- Student-wide editing locks, automatic inactivity logout, and admin lock recovery.
- Program enrollment history, staged Full Certificate enrollment, service-day
  configuration, and enrollment-aware financial reporting.

All eight reports are implemented. Rebuilt Prepays still requires accounting
acceptance; production deployment is not implemented.
Document rendering is implemented but still needs broader owner-approved
visual/content comparisons. This is a development milestone, not a production
cutover.

## Billing screens and behavior

After login, start at `/browse`, `/create`, or `/paymentplans`. Learner details
use `/detail/{id}`. Find, previous/next, and list navigation retain the current
search. Invoice, LOA, and schedule sections load on demand.

Staff and Billing Staff (No Reports) have these billing features. `/users` and
`/admin/locks` are restricted to admins. Student balance is computed, not directly editable. New invoice
links must belong to the learner; the known imported cross-student link can
remain during unrelated edits.

The balance is the signed ledger sum. Detail-screen fees/paid totals retain
the Django conventions rather than inventing a common rule for every report.
The invoice-payment shortcut also retains Django's positive amount prefill;
staff must review its sign before saving. Payments normally reduce the balance.

Schedule generation creates a draft that requires an explicit save. Dates
retain Django/JavaScript month-end overflow (January 31 plus one month can
be March 3), and the final installment receives the cent remainder.
Application-fee exclusion defaults to $100.00. Saving is atomic and preserves
displayed row order by regenerating scheduled-payment IDs, as Django did.
Unedited invoice-date/percentage metadata is retained. Historical invalid or
missing dates are not silently repaired; entered changes are validated.

## Enrollment and required student information

**Has enrolled** distinguishes an intake prospect from an enrolled learner.
The migration marks all existing learners as enrolled; legacy imports default
to enrolled as well. A new detailed-view learner defaults to enrolled, while
a new brief-records learner defaults to not enrolled. Both views allow changing
the checkbox after a confirmation.
On the detailed and brief edit forms, hover over or focus the ⓘ beside a
checkbox to see an explanation.

Non-enrolled learners remain in Student Records. Browse normally hides them,
but an explicit name or ID search or **Include non-enrolled** shows them.
All eight existing financial reports exclude them and their financial activity,
including historical transactions; changing the checkbox back restores that
activity to reports. The underlying records and balances are not deleted.

On every detailed-view save, enrolled learners need Program, Start Date, and
Expected Grad Date. Inactive, LOA, Withdrew, Suspended, and On Extension each
require their corresponding date; In group requires Group name; Sponsored
requires Sponsor contact and email. All entered graduation and status dates
must be strictly after Start Date. These checks cover the whole saved record,
so existing missing or inconsistent values must be corrected before saving
unrelated detail edits. Brief-records saves do not enforce those detail rules.
New graduations require enrollment. Removing enrollment does not erase an
existing graduation or prevent changing the checkbox.

Leave-of-absence records require a start after the learner's Start Date. An
end, if supplied, must be strictly after the leave start. Leaves may be
open-ended and cannot overlap another leave for the same learner, regardless
of program or stage; one leave may end on the day the next starts.

## User levels

Admins assign one of five levels in **Users**:

| Level | Access |
| --- | --- |
| Admin | All billing, reports, user management, lock recovery, and Workflow Settings |
| Staff | Existing full billing and report access, without admin functions |
| Billing Staff (No Reports) | Billing access without report pages/CSV exports or global payment-plan template management |
| records | Approved Access student fields: view, search, copy, create, and edit; no billing-only fields or deletion. Also Forms and Workflows (staff stages except Finance clearance) |
| forms | Forms only: create, edit, submit, and download forms; learner type-ahead shows name, cohort, and User ID only |

Only Admin and Staff may create, edit, or delete transactions over 30 calendar
days old, or delete an invoice when doing so would unlink such transactions.
Age uses Our Date (processed date), falling back to Customer Date when Our Date
is missing. Exactly 30 days is allowed; if both dates are missing, the
transaction is treated as historical. Lower-level billing staff cannot change
a recent transaction into a historical one.
New transactions require Our Date. Dated transaction reports require the
relevant records to have Our Date; correct missing dates before running them.

Existing users retain their previous access. The **records** level uses the
dedicated Student Records workspace only. Its server-side field whitelist covers
the approved Access fields (excluding userid), both phone slots, and both labels.
Billing screens, documents, reports, user management, and lock recovery are denied.
Report restrictions are enforced on direct URLs as well as hiding navigation.
Changing a user's level releases their existing editing leases; old pages cannot
continue saving with those tokens. Admin status overrides the staff tier, and
the existing last-admin protection remains in effect.

Default home views: Admin retains the billing foundation page; Staff and Billing
Staff (No Reports) redirect to Browse; records users redirect to Student Records;
forms users redirect to Forms. Every level sees a **Forms** navigation link.
For billing users, **Brief Records View** appears immediately after **Create an
AL** in navigation. Records-only users retain the **Student Records** link.
Billing Staff (No Reports) cannot open or modify global `/paymentplans` templates,
including through direct requests. Their student-specific schedules remain
available with the existing student-lock requirements.

## Additional student fields from Access

Learner create/detail forms include **Phone 2**, **Phone Label**, and **Phone 2
Label** alongside the existing phone. Both numbers allow 32 characters; labels
allow 255 and are nullable. No labels are automatically assigned.

The collapsible **Additional student records information (Access)** section has:

- `maiden_name`, `birth_date`, `address_line_2`
- `access_status_code`, `access_change_date`
- `self_pay`, `combination_payment`, `self_directed`, `comp_college`
- `associate`

All new fields are nullable. The four new boolean fields explicitly allow
Not specified / Yes / No. Birth date uses a calendar date; Access change date
stores date/time and is not automatically changed on save. Source labels
Comp College and Associate are retained without assigning additional meanings.

`access_status_code` references `student_access_statuses`:

| Code | Status |
| --- | --- |
| 1 | applying |
| 2 | in process |
| 3 | enrolled |
| 4 | withdrew |
| 5 | expelled |
| 6 | leave of absence |
| 7 | graduated |
| 8 | incomplete |
| 9 | extension |
| 10 | grad-ASSOC |
| 11 | audit completed |

This single status is independent of the existing billing flags. Setting it to
graduated, withdrew, or another value does not alter billing statuses, reports,
balances, or schedules. School Sponsored uses the existing `sponsored` field.
New fields use existing session/lock/version protection and current user levels.
Records users edit these and the approved shared fields through their workspace.

See the [complete Access mapping](../ACCESS-STUDENT-FIELD-MAPPING.csv).
Access `userid` is dropped; the owner handles student matching and selects which
phone slot/label receives each number. Existing ZIP capacity is sufficient.
Existing records keep null new fields until explicitly edited/imported.
Address line 2 appears in current invoice/statement addresses; stored PDFs
remain unchanged. No Access records are imported by this migration.

`students.originalcohort` is nullable text (255 characters, matching `cohort`)
reserved for preserving the pre-merge MySQL cohort. Adding it does not populate
history or change existing cohorts. It is not an editable field in either student
workspace. The Django snapshot importer excludes it from legacy comparisons and
leaves existing history intact. A future Access importer must preserve the first
saved original value rather than overwrite it on subsequent imports.

## Program enrollments and stages

Ordinary imported learners retain their existing fields and legacy calculations
until staff explicitly use **Start new program enrollment** on the billing
detail page. Migration does not convert existing learners, change financial
amounts, create tuition fees, or rewrite PDFs.

The new history supports sequential whole-program enrollments (full/associate)
and staged Full Certificate enrollments under the same learner ID and ledger.
The current student program is the selected stage program for catalog-based
stages; the student start date remains the start of the shared certificate
enrollment. Stage start/end dates are recorded separately.

Concurrent stages are enabled for staging with `BILLING_CONCURRENT_STAGES=true`
after running the stage-allocation migration. The flag defaults to false.
Staff should compare financial reports with the existing system before production
use. Once overlapping stages or allocations exist, do not disable the flag:
the older workflow cannot safely represent them.

- Admins use **Utilities > Manage programs and stage sequences**
  (`/admin/programs`) to create/edit programs. `staged` and `certleading` are
  nullable booleans. Null `staged` means non-staged; null `certleading` preserves
  the legacy `type=cert` eligibility rule. Explicit true/false overrides type.
  Migration initializes existing `type=cert` entries as certificate leading.
- Create one program per stage, with that stage's positive `servicedays`.
  Point `nextprog` to the following stage; null or zero means final stage.
  Select the same non-staged, certificate-leading `certificate_program_id`
  on every stage in a sequence. This retains one shared academic enrollment,
  grade sheet, and certificate program across all stages. Stage programs are
  not separate certificate choices on graduation forms.
- Selecting a shared certificate program makes legacy type, short name, director
  team emails, and all three survey/employment URLs read-only. These values are
  linked live, not copied: changes (including clearing a value) on the shared
  certificate apply to its stages. Submitted overrides are ignored. Workflow
  Settings also edits these values only on the shared certificate, not each
  linked stage. Existing local values are retained but are not used while linked.
- Configure the complete sequence before use (creating the final stage first
  makes linking easy). Missing targets, cycles, shared successors, mismatched
  certificate programs, and sequences longer than three stages are rejected.
  Program edits use stale-version checks; programs cannot be deleted here.
  Legacy service-day defaults still used directly by historical learners cannot
  be changed here because that would change past reports.
- **Create an AL** automatically starts the selected staged program and its
  shared certificate enrollment, atomically, using the entered start date.
  There is no separate mode selector or second enrollment step. Missing/invalid
  stage configuration prevents creation rather than leaving a partial learner.
  Whole-program creation and imported learners retain their existing behavior.
- Choosing a staged program while editing an unbilled record also starts its
  enrollment. If the learner already has invoices or transactions, staff must
  use **Start new program enrollment** instead; a direct program change is
  rejected rather than silently reassigning historical billing.
- Each enrolled stage snapshots its service days, program name, and next program.
  Later catalog changes do not rewrite these agreements or historical results.
- Stages are Online coursework, In-person residency, and Practicum internship.
  Staff may choose them out of order and, when enabled, overlap different stages.
  Staff confirmation of prior financial settlement is required when starting a
  stage or another program.
  The system does not equate settlement with a zero balance or automatically
  charge for future stages.
- **Complete stage** records its date without affecting other stages or
  graduating the learner. **Enroll in next stage** proposes the recorded next
  program and requires an explicit start date and settlement confirmation.
  Graduation is a separate learner-wide action after active stages are closed.
  No fees are generated automatically. Academic completion is distinct from
  reaching 100% financially earned.
- Rare out-of-order/repeated stages remain possible through **Exception: enroll
  in a different stage**. Staff must select a stage for the same certificate and
  give a reason, which is recorded in history. Normal transitions still follow
  the agreed next-program link; no old stage or financial record is erased.
- Staff, Billing Staff (No Reports), and admins edit this section with the
  existing student-wide lock and stale-version protection. Records users see
  only a read-only current-stage summary in their table/form; they cannot
  access enrollment controls or financial data.
- Existing pre-catalog staged enrollments retain their earlier accounting,
  stage numbering, and defaults without automatic conversion. If any remain
  open, the Programs page links to their legacy `/admin/stage-settings` editor.
  That editor is not needed for new program-linked enrollments.
- Each non-legacy stage/whole-program record has prefilled edit controls even
  after completion or withdrawal. Eligible ended records offer two separate
  actions, subject to overlap and history validation:
  **Resume study** records a return date, excludes the closed interval from
  service days (unioned with LOAs), and preserves earlier reports and valid
  settlement markers. **Undo closure** corrects a mistaken latest closure:
  it retains and marks that event undone, removes only its automatic settlement
  effect, and restores the preceding academic state. Earlier independent
  settlements remain valid. Undo requires a reason and explicit confirmation
  that historical earned-income reports may change; billing rows never change.
  Program and stage start/end dates remain visible on closed records.
- **Cancel (retain billing)** keeps the record, its invoices/transactions, and
  audit history rather than deleting them. Cancellation stops further service
  on an active record without creating a refund, adjustment, or new settlement;
  existing settlement events remain effective. All changes require a reason
  and the student editing lock. Completion does not prevent transaction CRUD.
- Transactions, invoices, LOAs, and payment schedules identify their enrollment
  and stage where relevant. When multiple stages are active, select the stage
  explicitly or allocate a transaction or invoice across stages. Allocations
  must add exactly to the record amount; payments linked to an invoice may use
  only stages included on that invoice. They do not create extra ledger entries.
  Transaction/invoice selectors allow prior enrollments for corrections.
  New leave applies to all stages of the current staged enrollment.
  Application-fee transactions are explicitly marked **Application fee
  (immediate)**; labels/notes are never used to guess this classification.
- Payment-plan editing/generation concerns the selected current stage.
  Drafts default to that stage's start date and its explicitly classified
  application-fee total (zero if there is none), not the legacy $100 assumption.
  Previous schedules are retained. The overall learner balance remains a single
  signed ledger total, without stage-specific payment balances. Staff continue
  to maintain the learner-wide next-payment date and amount manually for
  Collections, including any new stage obligation.
- On the first new enrollment for a returning learner, the existing program,
  learner accounting dates/status, service-day settings and LOAs are preserved
  as legacy context. Existing billing rows acquire a legacy-enrollment link
  without changing any financial values. Subsequent enrollments do not replace
  this context.
- Completion, graduation, withdrawal, suspension, inactivity and extension
  actions have effective dates. Existing status/date controls and the automatic
  graduation workflow also record enrollment events. These actions signify
  prior settlement: the associated unit is 100% earned from that date, not
  retroactively. LOA is not settlement; PIF alone is not settlement.
  Clearing a settlement flag cannot reopen a record while another closure
  flag remains set. Clearing the last closure flag resumes academic activity
  but retains the original financial settlement event; use **Undo closure**
  to correct an erroneous settlement. Correcting a status date changes that
  event's date with an audit entry.
  The graduation workflow refuses a form from an earlier enrollment or a
  staged learner with an active stage, rather than graduating a
  different/current program accidentally.
- For new enrollment activity, service recognition is straight-line over the
  saved service days, capped at 100%, excluding learner-wide LOA intervals
  intersecting that enrollment/stage. Overlapping leave intervals are counted
  only once. New or edited leave dates cannot overlap another learner leave.
  Extensions add no service days and are 100% earned on their effective date.
  Application fees are immediately earned. No service is accrued for an
  unpurchased future stage or during a gap.
- Reports select financial activity using Our Date for transaction periods,
  calculate each enrollment/stage's contribution separately, and sum it into
  one learner row. Completed/settled units contribute 100% of their selected
  activity even when the learner is now in another program or stage. Completion
  does not pull transactions outside the report's selection into the report.
  Attributable Income retains its transaction window; Earned Income includes fees
  with Our Date before its as-of cutoff for all enrollments, including
  legacy activity. Prepays retains its transaction window
  and remains subject to its existing accounting-acceptance notice.
- Existing report type/sign classifications and rounding remain unchanged.
  Invoice report hide-graduated/inactive filters use the owning enrollment's
  status as of the report end date (today when omitted), not a later return
  enrollment's status. Legacy graduation uses Actual Grad Date, or Expected
  Grad Date when actual is missing. For an inactive legacy learner whose
  enrollment started before 2022 and whose inactive date is missing, reports
  infer it from the latest transaction Our Date, recorded status date, or leave
  start/end. Inference is not used if a transaction lacks Our Date, the start
  or all evidence is missing, or the latest evidence is in 2022 or later.
  Invoices fall back to the learner's current status when an effective date
  remains unresolved; other calculations retain their existing missing-date
  behavior. Inferred dates do not update learner records. Correcting historical
  activity can change the inference and hence historical reports.
  Mixed-enrollment progress is labeled **Per enrollment/stage**, since one
  service percentage cannot describe several fees with different service
  periods. The preserved legacy context is used for historical progress fields.
- Browse adds historical program/stage, program-or-stage-start, and explicit
  stage-start date filters. Ranges include both dates; matching uses existence
  queries so multiple qualifying stages do not duplicate learners. When a stage
  is selected, its date filters apply to that same stage.
- All reports can filter by the overall program, not an individual stage. A
  matching learner's otherwise qualifying activity remains included even when
  it relates to another program. Attributable Income, Transactions, Expected
  Income, and Invoices match program enrollments overlapping their activity
  window; an open window is unbounded. Collections uses today, Earned Income
  uses its as-of date, and Receivables and Prepays use the day before their
  exclusive as-of cutoff (today for Receivables without a cutoff). Existing
  enrollment-cohort, activity-date, and other report filters still apply.
  Learner-row reports list all recorded overall programs in that period, with
  incomplete legacy history marked. Imported records only retain their last
  recorded program and known dates; missing earlier program changes cannot be
  reconstructed. Enrollment start and end dates are inclusive for membership.
  A return after withdrawal is recorded as a separate enrollment; program
  matching treats each enrollment record as one continuous interval.
  Without a program filter, existing rows and numeric calculations are unchanged.
- Reasoned corrections to agreed service days and start/end dates are available
  in enrollment history. They are audited, cannot introduce invalid overlaps, and
  intentionally may change prior report results. Preserved legacy context
  cannot be rewritten through those controls. Backdated stage starts that cross
  earlier learner-wide status history, and completions before later stage status
  activity, are rejected for review rather than silently changing recognition.

### Repeating staging refreshes

You may test enrollment/stage tracking and refresh the local staging database
as often as needed. Each applied `billing:refresh` discards test program/stage
enrollments and their audit events, clears billing attribution and immediate
application-fee classifications, and resets current-enrollment pointers and
revision counters. Original billing data is then refreshed from Django.
Stage-duration settings and logins are retained. The preview lists the history
to discard without changing any database rows. A failed data refresh rolls
back the history reset too. Both importers recognize destination-only columns;
no stage data is expected from Django.
Existing graduation forms and grade sheets are retained. Their discarded
enrollment links become historical `refresh:` groups so separate sheets do not
collide or get reused for a subsequent enrollment. Such historical forms cannot
automatically graduate refreshed learner data; create a new form for the new
enrollment instead.

Refreshes are for local staging only and must never be run against production.
The command refuses environments other than `APP_ENV=local` and requires
local MySQL source/destination connections.

## Program configuration fields

`programs` has these additional nullable columns:

- `type` (8 characters)
- `shortname` (32 characters)
- `director_team_email`
- `course_completion_survey_url`, `employer_survey_url` and
  `employment_form_url` (500 characters)

The last four are edited on **Workflow Settings**. There is no program-editing
screen yet, so `type` and `shortname` are populated directly in the database.
Existing programs keep null values until populated. The Django snapshot
importer excludes all of these columns from legacy comparisons.

Programs are reference data maintained in Laravel after import. `billing:import
--verify-only` allows edited codes and descriptions and newly added programs,
and lists them in the reconciliation JSON (`tables.programs.edited` and
`added_ids`). It still fails if a legacy program id is missing or a legacy
program's `servicedays` changes. Students reference those ids, and income reports
depend on `servicedays`. All other tables keep exact row-hash verification.

## Student Records workspace

**Student Records** opens `/records`; records-only logins land here automatically.
Other staff/admins can also use it. **Form** opens a dedicated editor with only
the 25 approved fields, including phone labels; **Create student** uses that same
field set and initializes the billing balance to zero. Student deletion is not
available. Unexpected fields in create/edit requests are rejected, not silently
ignored. Reading/exporting rows never exposes billing-only fields.

The table shows 40 rows per page with a compact 12-pixel font and faint grey
gridlines separating rows and columns, similar to an Access datasheet. **User ID** is
the existing `students.id`, displayed read-only in the table/form and included
in copied/downloaded rows. It is not the dropped Access userid.

Search starts with one condition; **+ Add field** adds conditions (up to 20),
combined with AND. Each condition can be removed. Text fields offer Contains,
Exact, or Blank; Contains treats `%` and `_` as literal text. User ID and date
fields offer Equals or inclusive Range, with number/date controls for both
endpoints. Access change-date filtering uses calendar days including the entire
end day. Boolean values offer True, False, and Empty (null); status values offer
the named statuses and Empty (null). Search, pagination, copying, and downloads
use the same conditions. **View** opens the dedicated form.

Records-only boolean controls are checkboxes in both table and form. Null is
shown unchecked (with a null tooltip in the read-only table). Saving a complete
records form/row writes unchecked booleans as false, even if previously null.
Merely browsing/copying does not change nulls; partial API edits preserve
unsubmitted fields. The billing forms retain their previous nullable controls.

**Edit Row** acquires a student-wide lock and replaces that row's cells with
edit controls. One row per page may be edited at a time. **Save** persists the
whole row atomically and releases its lock; **Cancel / Done** discards the draft
and releases it. Table browsing, searching, selection, and copying acquire no
leases. Lock-owner labels refresh every ten seconds without extending inactivity.
The same lease blocks simultaneous changes from the billing screens. On forced
release, expiry, or verification failure, saving is disabled but the row draft
remains visible. Cancellation/reopening or login/reload is required to edit again.
Navigation releases are best-effort with inactivity as the fallback.

Select individual rows or all rows on the page, then **Copy selected rows**,
**Copy current page**, or **Copy all matching rows**. Copies include column
headings and saved values, not unsaved drafts. TSV output retains status codes
and numeric Yes/No values (the table displays their names). All-matching copying
includes rows beyond the current page. Clipboard access needs browser permission
and HTTPS (localhost works); errors are shown explicitly. **Download matching
rows (TSV)** is the fallback and can be opened in Excel or pasted into a spreadsheet.
Formula-like text is escaped using the existing export protection. Copying all
matches loads the result into browser memory; the download streams server output.
This version supports copying out, not multi-row spreadsheet pasting into the app.

## Graded items

`gradeditems` holds the graded items for each program: `id` (auto-increment),
`program_id` (required; foreign key to `programs.id`), `itemorder` (tinyint
unsigned, 0–255, nullable; display order within a program), and `gradeitem`
(varchar 256, required; the item name), and `clockhours` (integer, nullable;
only some programs record clock hours). Programs that share items, such as the
Full and Associate certificates of a level, each have their own copy of the rows.
A program that still has graded items cannot be deleted. The data is loaded
directly into the database and drives the **Final Grades** form. A graded item
that has recorded grades cannot be deleted.

## Forms

`/forms` lists the available form types and one searchable table of every saved
form of every type (learner name, User ID, form number, or typed signature;
filters for form, status, and program). Graduation Initiation forms and Final
Grades sheets are numbered separately, so the Form column identifies which a
number belongs to. Each Graduation Initiation form is one `formsdata` row: the form type,
learner, program, status, revision, and the full answers as a JSON document.
The document snapshots the learner's name and cohort and the program description
as saved. Forms never change student records.

**Adult Learner Graduation Initiation** (`/forms/graduation-initiation/create`):

The certificate-order notice uses the **16th of each month** for both
ordering and the submission deadline. The related invitation and grades
reminder use the same deadline. A migration updates the old built-in
deadline sentences in saved email templates, preserving other wording.

- The learner is chosen by type-ahead (name or User ID; shows name, cohort, User ID).
- The program list shows programs whose `type` is `cert`, ordered by description.
  It pre-fills from the learner's program when that program is in the list.
  A form whose program is later removed from the `cert` list keeps it selectable.
- **Save Draft** requires only the learner. **Submit Form** requires every field,
  Yes to all three questions, valid dates, and residency and practicum dates
  strictly before the graduation date.
- The typed director name is the signature. Submission also records the logged-in
  user and time; changing the typed name later re-records them.
- Submitted forms stay editable, but edits must remain complete (no return to draft).
  Every save adds a `formsdata_history` row with the full document, user, and
  revision. The edit page lists the history with changed fields. Saving without
  changes records nothing. Stale revisions are rejected.
- **Download PDF** produces a letter-size PDF; drafts are marked DRAFT.
- Delete is admin-only: it requires the form lock and a confirmation checkbox.
  History rows are retained after deletion.

**Final Grades** (`/forms/final-grades/create`):

- Stored relationally: `finalgrades` holds one sheet per learner per program enrollment
  (`studentid`, `program_id`, `enrollment_id`, `enrollment_key`, `gradername`, `gradedate`, `gradenotes`, plus
  status, revision, and signature/audit columns). `finalgradesitems` holds one
  row per graded item (`finalgradeid`, `studentid`, `gradeditemid`, `grade`
  varchar 64). A composite foreign key keeps each item's `studentid` equal to
  its sheet's learner.
- The learner is chosen by type-ahead. Choosing a learner shows any grade sheets
  they already have (draft or submitted), newest first, with links. Choosing a
  program enrollment that already has a sheet for that learner disables saving;
  the server and database also reject duplicates. All stages of one enrollment
  share a sheet. A return to the same program requires a new sheet. Original
  legacy sheets and histories are retained and are never silently reused.
- Graduation Initiation forms capture the same program enrollment; grade
  reminders, workflow checks and automatic graduation use that captured link.
  Existing forms retain their original enrollment when edited.
- The program list shows only programs that have graded items. The program's
  items appear in `itemorder` order, with clock hours shown when any item has them.
- Grades are free text (number, letter, Pass/Fail, ...), up to 64 characters.
  No averages are calculated.
- The date defaults to today. **Save Draft** needs only the learner and program.
  **Submit Grades** requires a grade for every item, the typed name, and the
  date. All missing fields are reported together.
- After the first save the learner and program are fixed; an admin can delete a
  sheet started in error. Everything else (signature, history in
  `finalgrades_history`, revision checks, PDF, admin-only delete with retained
  history) works as for Graduation Initiation.

Each form has its own editing lock in `form_edit_locks`, keyed by form kind
(`formsdata` or `finalgrades`) and form id, held per form rather than per
learner. It uses the same inactivity timeout, Done Editing, navigation release,
logout release, and admin recovery on **Editing Locks**.

## Workflows and the Course Completion Form

Submitting an **Adult Learner Graduation Initiation** form, either directly or
by submitting a saved draft, starts the **Course Completion** workflow once for
that form. Later edits never start it again. The Graduation Initiation form
also collects the **instructor's email** (copied on the final congratulations).

### Stages

Each stage is one of the following: an automatic email, a wait for a form, a
staff stage (questions, checklists or a file upload), a timer, or a system
action. Stages run in this order:

| # | Stage | Kind | Who acts |
| --- | --- | --- | --- |
| 1 | Final Grades reminder to the director (login that submitted the initiation) | Email (optional: a failure does not stop the workflow) | — |
| 2 | Course Completion Form invitation to the learner | Email | — |
| 3 | Learner submits the Course Completion Form | Wait for form | Learner |
| 4 | International mailing notice to Finance, including the form data (only if the learner answered **No** to the United States question) | Email | — |
| 5 | Final Grades submitted for the learner and program | Wait for form | Director |
| 6 | Finance clearance request | Email | — |
| 7 | **Finance clearance**: has the learner satisfied their financial requirements? | Staff | Billing staff / admin |
| 8 | **Records review**: four questions, all **Yes** | Staff | Records and up |
| 9 | **Credential ready to order?** | Staff (approval) | Records and up |
| 10 | Verified graduate information to the configured list | Email | — |
| 11 | Congratulations to the graduate, with transcript PDF (Final Grades) and congratulations letter PDF | Email | — |
| 12 | Employer Graduate Survey link to the employer (`sponsor_email`; if missing, staff enter an address or skip) | Email | Records and up, if no address |
| 13 | Mark the learner **Graduated**; `actualdategrad` = today | System | — |
| 14 | Status-sheet reminder to the admin team | Email | — |
| 15 | Alumni list request (name, email, mailing address) | Email | — |
| 16 | **Credential check**: two questions, both **Yes** | Staff | Records and up |
| 17 | **Upload the credential scan** (PDF, up to 20 MB) | Staff (file) | Records and up |
| 18 | **Prepare the mailing packet** (six-item checklist) | Staff (checklist) | Records and up |
| 19 | Final congratulations with the credential PDF and the address on file. CC: the program's director team and the instructor | Email | — |
| 20 | Correction waiting period (5 days by default) | Timer | — |
| 21 | Waiting period over: notice to the admin team | Email | — |
| 22 | **No corrections received** | Staff | Records and up |
| 23 | **Credential packet mailed**: confirmation, optional tracking, date mailed | Staff | Records and up |
| 24 | **Final checks**: two questions, both **Yes** | Staff | Records and up |
| 25 | Workflow complete: notice to the admin team | Email | — |

When the last stage finishes, the run is marked **Completed** with its date.

**Rules for staff stages**

- Each approval (a staff stage with Yes/No questions) emails its approvers
  once when it becomes active, with a direct link to the approval form.
  Reminders use an editable template per stage and are recorded on the run
  and in its history. A successful reminder is not sent again by scheduler
  checks or pause/resume; sending the stage back reopens it and sends a new
  reminder. Missing recipients fail the stage with an explanation; saving
  recipients retries it. Temporary mail failures use the normal retries.
  Existing active approvals receive their first reminder on the next
  scheduler pass after deployment.
- Answering **No** to any question requires a note. The run is then
  **paused** and shows the note. Anyone with access can **Resume** it; the
  stage is asked again.
- Staff can also pause a run manually.
- Each answer, note, file and email (exact text sent, recipients and
  attachments) is kept on the stage. Changes are logged in the run history.
- Admins can **Skip** a stage, **Send back** to an earlier stage, and
  **Cancel** a run.
- An uploaded credential can be replaced with **Replace file**. The final
  congratulations email attaches whichever file is current when it is sent.
  Replacing the file later does not resend the email.
  Uploads use unique private paths, retain prior versions with their audit
  metadata, and report disk-write failures explicitly. A stale/rejected upload
  or rolled-back replacement cannot overwrite the previous PDF.

**Finance**

- Finance is notified when a learner wants international mailing.
- If the learner later submits again with a different international address,
  Finance is notified again.

### Screens

- **Workflows** (nav, records level and up):
  - Lists each workflow with counts by status and a learner search.
  - Links to **My tasks**.
- **Workflow runs** (`/workflows/course-completion`): filters for learner,
  status, current stage and who it is waiting on, plus counts per stage.
  Each stage in the stage list has an ⓘ button. It opens a guide with:
  - what the stage does;
  - how it is completed (automatic, or who answers which questions);
  - links to the email and letter templates it uses.
- **My tasks** (`/workflows/tasks`): staff stages waiting on you, grouped into:
  - attention needed (failed or paused);
  - finance;
  - records.

  Admins see everything.
- **Run page** (`/workflows/runs/{id}`):
  - learner, program and status;
  - links to the Graduation Initiation, Final Grades and Course Completion
    forms;
  - the current stage's form;
  - every stage with its answers, emails and files;
  - forms submitted early show **Submitted — waiting for earlier stages**,
    with a submission date and link. This is display-only: the workflow
    still processes its stages in order, and drafts do not count;
  - the history.
- The Graduation Initiation page shows a summary panel with a link to the
  run.

### Workflow Settings (admin)

`/admin/workflow-settings` controls:

- Recipient lists (comma separated):
  - Finance team (default `financeteam@cgms.edu`)
  - Records approval team (default `adminteam@cgms.edu`)
  - Admin team (default `adminteam@cgms.edu`)
  - Verified graduate information recipients
  - Alumni coordinator and marketing
- Default links: Employer Graduate Survey, Course Completion Survey, and
  Employment Question Form.
- The correction waiting period in days (0–60).
- **Staff notification recipients:** optional per-stage recipient lists for
  approvals and staff-directed emails. Blank uses the indicated team
  default (or the submitting director's login email for the grades
  reminder). These addresses do not grant approval permissions.
  Learner and employer emails still use their record addresses; final
  congratulations CC addresses still come from the program and initiation
  form.
- Per program, in the **Programs** table:
  - **director team email**;
  - **Course Completion Survey link**;
  - **Employer Graduate Survey link**;
  - **Employment Question Form link** (sent one year after graduation).

  A program's survey link is used for learners in that program, both in the
  invitation and on the Course Completion Form, and for their employer email.
  When the program's link is blank, the default link is used.
- The title, instructions and question wording of each staff stage. Stage
  keys and question types are fixed.

Email boxes accept any standard address, including `+` tags such as
`name+tag@cgms.edu`. The error message for a rejected entry shows it in quotes,
exactly as received.

The verified graduate information list, the alumni list, the Employer Graduate
Survey links and the director team emails start empty. A stage that needs a
missing setting fails with a message that says what to fill in. It continues
automatically once the setting is saved.

### Course Completion Form (public, no login)

`/course-completion/{token}`

- The 64-character token is random. Only its SHA-256 hash and an encrypted copy
  are stored, the encrypted copy so that resending can reuse the same link.
- The link is valid for 180 days (`COURSE_COMPLETION_LINK_DAYS`). Resending an
  expired invitation renews it.
- The learner may submit as many times as needed. Every submission is kept.
  The workflow uses the latest one.
- The form is pre-filled from the learner's latest submission, otherwise from
  their student record and the graduation date.
- The survey question must be answered **Yes** to submit.
- **Are you currently:** is required, with one choice from: Employed in the
  Montessori Field, Employed in the Education Field, Employed in another field,
  or Not employed. It is stored in `course_completions.employment_status` as
  `montessori`, `education`, `other` or `not_employed`. Submissions made before
  this question existed show "Not asked". Email templates can use
  `{employment_status}`, and the Finance international mailing email includes it.
- Submissions are stored in `course_completions` and appear in the Forms list
  as read-only **Course Completion Form** entries with a PDF.
- The page sends `no-referrer`, `noindex`, and `no-store` headers and is
  rate-limited to 30 requests per minute per address.
- Invalid links return 404. Expired or withdrawn links show a "no longer
  active" page (410).
- Deleting a Graduation Initiation form cancels its workflow and withdraws the
  learner's link.

### Email Templates

Nav link for records level and up.

- Every email and the congratulations letter PDF have an editable subject and
  body with `{placeholders}`. Each template lists its placeholders.
- Unknown placeholders and missing required links are rejected.
- **Preview** shows sample values. **Reset** restores the original text.
- Changes apply to future drafts/sends. Workflow emails keep a copy of their
  sent text; invoice emails record only the send date.
- **Invoice email** supplies the editable subject/message defaults in the
  invoice dialog. It supports learner, invoice, enrollment program/stage, and
  logged-in sender fields; its editor lists all supported placeholders.

### Delivery, retries and the scheduler

Stages run right after the web response. The scheduler runs
`workflows:run-pending` every minute. That command:

- advances timers;
- retries temporary send errors after 1, 5, 15 and 60 minutes;
- recovers interrupted sends.

Production therefore needs this cron entry:
`* * * * * cd /path/to/app && php artisan schedule:run`.

Problems that need a person fail the stage with an explanation, and **Retry**
appears. Examples: no valid learner address, a missing setting, or the learner
record being locked by another user when marking it graduated.

### Employment Follow-up workflow

A separate workflow, so the graduation run is marked complete when graduation
is done. It starts automatically when a Course Completion run completes, for
the same Graduation Initiation form, and has two stages:

1. **One year after graduation**: waits one calendar year from completion
   (a completion on Feb 29 waits until Feb 28).
2. **Employment Question Form email** to the graduate (template
   `employment-question-form`, placeholder `{employment_form_link}`). The link
   is the program's Employment Question Form link, or the default link when the
   program's is blank. If neither is set, the stage fails until one is saved on
   Workflow Settings, then sends automatically.

It appears in Workflows like any other run, and each run page links to the
other workflow for the same learner. Deleting the Graduation Initiation form
cancels it. Sending a graduation run back and completing it again does not
start a second follow-up.

### Adding a workflow

1. Implement `App\Workflows\Workflow`: key, trigger, subject and ordered stages.
2. Build its stages from `EmailStep`/`NotifyEmail`, `AwaitStep`, `ManualStep`,
   `TimerStep`, or a custom `Step`.
3. Write `guide()`: a plain-language description of each stage, keyed like
   `steps()`, for the stage guide. A test checks every stage has one.
4. Register it in `config/workflows.php`.

A workflow whose trigger is `Workflows::completedTrigger('other-key')` starts
when a run of `other-key` completes, for the same subject.

Its templates and staff stages then appear on Email Templates and Workflow
Settings automatically. Stage keys are stored on runs, so never rename them.

Tables: `workflow_runs`, `workflow_steps`, `workflow_events`, `app_settings`,
`email_templates`, `course_completion_invitations`, `course_completions`.

## Editing locks and inactivity

Opening learner details automatically acquires one lock for that learner and
all related invoices, transactions, LOAs, and payment schedules. The owner may
edit; other sessions see the editor's name and read-only controls. Server-side
guards reject unauthorized writes even if browser controls are bypassed.
Optimistic version checks remain as additional protection.

**Done Editing**, navigation away, and logout release the page's lock.
Opening the same learner again replaces the page token: older tabs cannot save
or release the replacement lock. Returning through browser history requires a
reload. Navigation release is best-effort; inactivity expiry is the fallback.
Browse archive/unarchive remains an atomic action when no active lock exists.
Balance recalculation is blocked while any learner has an active editing lock.

Default inactivity is **60 minutes**. Admins use **Editing Locks** (`/admin/locks`)
to set 1-1440 minutes, inspect current locks, and release one or all locks.
Changes apply to existing sessions. Forced release does not log the editor out,
but their existing page can no longer save. Admin recovery actions are logged.

Trusted keyboard, pointer, input, and scroll activity refreshes the session;
automatic status polling does not. Status is checked every ten seconds.
Expiry logs the user out, releases locks, disables editing, and preserves
displayed unsaved drafts. Network/status failures also disable editing explicitly;
status requests time out after five seconds. Reload after reconnecting to edit.

On Ubuntu, run Laravel's scheduler once per minute, for example:

```cron
* * * * * cd /path/to/laravel && php artisan schedule:run >> /var/log/billing-scheduler.log 2>&1
```

The scheduler is also required for workflow email retries (`workflows:run-pending`).
The scheduled `billing:clear-expired-locks` command removes expired leases.
Expired leases also become available on access even without the scheduler.
The database-backed session storage retention is separate from the configured
business inactivity timeout; retaining a session row does not keep it authorized.

## Invoices and statements

On the learner detail screen, click **Create New Invoice** to open a floating
editor. Invoice lists use **Edit Invoice** dialogs instead of a new/edit row.
Notes are multiline and resizable; their 255-character limit follows the
existing schema and is shown explicitly rather than silently truncating text.
Closing a dialog retains its unsaved draft; saving closes it and refreshes the
list without discarding other drafts.

Each invoice has **HTML**, **Download Current PDF**, and (when available) **Stored PDF**
links. HTML/current PDFs use current invoice values and the current ledger.
Stored PDFs remain byte-for-byte unchanged when invoice metadata is edited.
Creating an invoice renders and stores its PDF in the same transaction; a
rendering failure rolls back the invoice.

Select **Email the stored invoice PDF after creating it** to include a message.
The invoice/PDF is committed first, then its exact stored bytes are emailed.
Set the shared defaults under **Email Templates → Invoice email**. Both subject
and message can be customized per send, including retries/resends, without
changing the shared template. Placeholders such as `{learner_first_name}`,
`{invoice_reference}`, `{invoice_amount}`, `{invoice_due_date}`, and
`{sender_name}` are filled into an editable subject/message preview when email
is selected or an existing invoice's email panel is opened. New invoices use
the current draft invoice fields; existing invoices use saved metadata.
Only `{invoice_id}` remains deferred for a new invoice and is replaced after
creation, without adding a separate review/send step.

Staff edits are sent as composed, not re-expanded as another template; literal
braces in learner data or custom text are preserved. **Refresh from template**
rebuilds the preview using current values and asks before replacing edited email
text. Changing invoice details does not overwrite that text: a notice asks staff
to refresh or update the message themselves. Refresh also picks up changes to
the shared template. The template editor still rejects unknown placeholders
and supports sample previews/reset. Saved invoice metadata may differ from an
older stored PDF if that metadata was subsequently edited.
From and Reply-To use the logged-in user's profile email; recipients come from
the learner's saved `emailaddr` (not the sponsor). Addresses are split on commas
and trimmed, without application-level address validation. Save contact changes
before creating an invoice. All recipients are included in To.

**Last emailed** records the latest time the mailer accepted the message, not
confirmed inbox delivery. There is no bounce/delivery-failure tracking or stored
message history. Immediate send errors remain visible and do not remove the
invoice; **Retry email only** cannot create another invoice. **Edit Invoice →
Email stored PDF** also permits a later retry/resend without editing billing.
Later bounces are handled through the sender's mailbox, subject to the mail
provider's bounce/return-path configuration. The provider must authorize every
profile From address (normally through domain authentication).

A delivery mailer must be configured; log/array transports and non-delivery
fallbacks cannot mark an invoice emailed. The array transport is allowed only
in automated tests. No queue worker is required. Staging refreshes clear these
local email timestamps together with the replaced billing test data.

Learner details offer **HTML Statement** and **Statement PDF**, and browse-page
balances link to the HTML statement. Previews include print/download controls.
All document routes require login and invoice routes enforce learner ownership.

Dompdf renders locally using the original application logo and bundled fonts.
Remote fetching, embedded PHP, and JavaScript are disabled for PDF rendering.
Ensure `public/images/cgms-logo.png` is deployed and
`storage/app/private/pdf/` is writable. No callback to the live application is
required. Missing assets and rendering failures are explicit errors.
Long ledger notes use continuation rows to avoid Dompdf's inability to split
a single table cell over multiple pages.

Document calculations retain the legacy zero-invoice-amount fallback to the
learner's next payment, sponsor bill-to fields, customer dates, signed amounts,
and statement thanks messages counted from negative ledger entries.
Documents are current snapshots, not historical as-of reconstructions.

## Reports

Use **Reports** in the navigation. Attributable Income is implemented first,
following the owner's priority. Expected Income and Transactions are also
available, along with Earned Income, Collections, Invoices, Receivables, and
rebuilt Prepays (implemented last). All reports require login and use read-only
queries. Prepays is explicitly marked as requiring accounting acceptance.

### Collections

`/reports/collections` excludes graduated learners. Optional inclusive minimum/
maximum amounts filter `payment_amount_due`, not remaining balance. Enrollment
start is inclusive; enrollment end and next-payment-due cutoff are exclusive.
The owner-approved hide-inactive checkbox now actually excludes inactive
learners (the legacy form/controller field names were mismatched).

Details sort by enrollment date then ID. Total owed sums signed current
computed balances, including negative/zero balances. Missing balances raise
an explicit validation error requesting balance recalculation; missing due dates
and next-payment amounts remain blank when not excluded by filters.
CSV preserves its seven legacy columns and the legacy name concatenation;
HTML uses readable names with separators.

A read-only live comparison for enrollment January–October 2026 and payment
due before November 1 matched all 36 rows and total owed 209,885.11.
Checking hide-inactive removes one learner in that window.

### Invoices

`/reports/invoices` filters invoice metadata without reading stored PDF blobs.
Enrollment start is inclusive. Both sent-date bounds include midnight exactly;
the upper bound is **not the end of the selected day**. Hide-inactive/graduated
options and optional received payments/credits are available.

Invoice and received totals use absolute amounts, while detail rows retain
their signed amounts. Received rows match Payment/Credit substrings for the
selected learner, not only invoice-linked transactions. Both payment date
bounds use **Our Date**. Hide-graduated/inactive filters use the status at the
upper sent-date bound, or today if omitted. For legacy statuses with no effective
date, they fall back to the learner's current status. Learner outstanding is clamped at zero; excess is
prepayment. Overall outstanding is invoiced minus received without clamping.
Without Show Received, legacy learner outstanding remains zero while the
overall outstanding is total invoiced. Groups sort by string learner ID.

CSV preserves its 12-column header and legacy variable-length invoice, payment,
separator, and subtotal rows. Internal SQL is not exposed in report output.
The supplied legacy template has no Invoices report form; its date helper also
fails on the Python dates passed by this route. Validation therefore compared
the underlying source data directly.

Before the Our Date correction, for sent dates 2026-01-01 through midnight 2026-11-01, independent SQL on the
untouched source matched all 629 learner groups' invoice/payment counts and
amounts, plus all 2,608 invoice and 2,355 received-payment detail rows (IDs,
names, dates, types, and signed amounts). Totals: invoiced 2,997,581.25; received 2,197,952.72;
prepayments 95,742.42; outstanding 799,628.53.

Missing invoice/selected-payment amounts raise explicit errors identifying the
record instead of silently treating unknown financial values as zero. The
snapshot contains seven null-amount invoices from November 18, 2020; an
unrestricted invoice report will flag these until corrected.

### Enrolled Students / Receivables

`/reports/receivables` groups signed ledger amounts by learner. Its optional
start date filters **student enrollment**, not transaction dates. Its optional
as-of date requires **Our Date** to precede midnight. A relevant transaction
missing Our Date stops a dated report with a correction message. All transaction types contribute.
Without an as-of cutoff, rows with missing dates remain eligible; null amounts
do not contribute, matching legacy calculation.

Legacy “Hide zero” actually shows only positive rows, excluding negative rows
too; the overall total remains unfiltered. The screen labels this explicitly.
Per owner approval, unassigned transactions appear as an UNKNOWN group and
contribute to the total unless an enrollment filter excludes them.
CSV retains the three legacy detail columns and dated filename.

Before the Our Date correction, the live route returned HTTP 500, consistent with its broken Python date
helper. Independent SQL on the untouched source matched all 296 learner amounts
for enrollment on/after 2026-01-01 and both transaction dates before 2026-11-01;
total 850,174.79. Positive-row filtering leaves 147 rows, without changing total.

### Prepays (rebuilt; accounting acceptance required)

`/reports/prepays` replaces the missing `getCGMSPrepays` call using the surviving
`getPrepaysExperiment` calculation approved as the rebuild basis. Both HTML
and CSV show a prominent pending-accounting-acceptance notice. This is not
asserted to reproduce a functioning live report: the live endpoint returns
HTTP 500.

Requires enrollment start (inclusive), enrollment end (exclusive), first
Our Date (inclusive), and as-of date (exclusive). Expected
graduation must be on/after enrollment start or missing. Duration is expected
graduation minus enrollment, **not program service days**. Missing graduation
uses as-of for calculation only; a zero duration falls back to 547 days after
service clipping, as in the surviving experiment. Reversed enrollment/
graduation raises an explicit error.

Leaves are clipped at as-of; future starts do not count and overlapping days
are counted once. Completely blank leave records are ignored. Missing starts
with a recorded end use enrollment for this legacy calculation;
reversed intervals before the cutoff produce an explicit correction error.

Transaction categories retain the experiment's conventions:

- Owed = absolute net signed sum of Fee, Declined Fee, Fee Adjustment.
- Discounts = absolute net signed sum of Adjustment, Credit, Correction,
  Discount, Waived (not plural Discounts/Adjustments).
- Paid = absolute net sum of normalized payment categories: Check, Payment,
  Reversal, Payment/Pago, Pago, Refund. All are made negative except exact
  spelling `Refund`, which is made positive. The historical case-sensitive
  refund distinction is preserved, not changed silently.
- Writeoffs = absolute net signed Write off sum.
- Accrual earned = abs((owed − discounts) × clipped service ratio);
  prepaid accrual = abs(owed − discounts) − accrual earned.
- Prorata owed = accrual earned unless the actual graduation date (or expected
  when actual is missing), suspension, inactive, or withdrawal date is
  on/before as-of; then it equals paid + writeoffs. The legacy experiment
  considers recorded dates regardless of status flags, except that an inferred
  inactive date requires the inactive flag.
- Cash prepaid = max(0, paid + writeoffs − prorata owed).
  Skip Zero selects positive cash prepaid.
- The legacy accrual-earned **summary** additionally subtracts discounts;
  the per-learner accrual field does not. This unusual rule remains visible.

Missing lifecycle dates remain blank instead of the experiment's nondeterministic
“now” placeholder. Sorting handles missing values first and breaks ties by ID.
The 22-column CSV preserves the old field order. Derived per-learner amounts
use six decimals; aggregate rational totals are rounded once to cents.

Before the date and leave corrections, executed the surviving Python experiment read-only against the untouched local
source database, with debug-file writes redirected to in-memory buffers.
For January–October 2026 enrollment/transactions, as of November 1, all 299
learners' financial values matched at six decimals, along with inferred dates,
program/LOA durations, and all seven summary totals at cents. This validates the chosen rebuild basis, but does
not replace owner accounting acceptance.

Reporting milestone validation: 92 tests, 869 assertions, formatting, Composer,
JavaScript syntax, compiled Blade templates, and full imported-data verification
passed. No live billing records were changed during report comparisons.

### Expected Income

`/reports/expected-income` selects scheduled payments using `paymentduedate`,
with an inclusive start and exclusive end date. Either date can be blank to
leave that boundary unrestricted. Includes all scheduled rows, including
archived/graduated students, signed amounts, and zero payments; it does not
deduct invoices or payments already received. Totals and year/month subtotals
use exact cents. Details are ordered by due date, then ID to break ties.
CSV retains the legacy `cgms-plannedincome-report.csv` filename and columns:
Payment Due Date, Student Id, First, Last, Amount, Notes.

Missing due dates or amounts in selected rows cause an explicit validation
message naming the scheduled-payment ID, rather than the legacy calculation
crash. Unassigned rows retain their amounts and show an unknown learner.

Live comparison for 2026-01-01 through before 2026-04-01 matched all 165 detail
rows, the 142,132.90 total, and monthly subtotals: January 51,447.51,
February 26,980.98, March 63,704.41.

### Transactions

`/reports/transactions` filters by **Our Date**, inclusive start and
exclusive end, and sorts detail rows by Our Date, then ID.
Dates, signed minimum/maximum amount, and type filters are optional. Amount
bounds are inclusive; decimal cents are supported instead of the legacy
integer-only parsing. Zero is a valid bound.

Type selections use literal case-insensitive substring matches. Fees only
matches `fee` (including declined fees and fee adjustments); Payments only
matches `payment`, not Check or Pago. Totals use signed amounts and preserve
type capitalization rather than Attributable Income's absolute amounts.
Type subtotals are alphabetically ordered as in the legacy CSV.

CSV retains `cgms-transactions-report.csv` and its eight legacy detail columns.
Unassigned transactions remain included, with an unknown name. Null dates and
types export as blank detail cells; a null type's subtotal is labeled `None`.
Missing amounts in selected rows produce explicit validation messages naming
the transaction ID rather than a legacy calculation crash.

Live comparison for processed dates 2026-01-01 through before 2026-02-01 matched
all 598 exported rows (including names, dates, types, amounts at cents, notes,
and learner/transaction IDs) and every type subtotal.

Both reports require login, support HTML and CSV, and use read-only queries
rather than per-row learner lookups. CSV uses the same literal-text
formula protection as Attributable Income; this can add a leading apostrophe
to unsafe text cells, including the legacy `+` subtotal marker.

### Earned Income

`/reports/earned-income` requires an inclusive enrollment start, exclusive
enrollment end, and as-of date (midnight). Expected graduation must be on/after
the enrollment start or missing, matching legacy selection. Student status
does not otherwise exclude a learner.

Invoiced is the sum of absolute `Fee` and `Declined Fee` transactions whose
Our Date is strictly before the as-of date. Fees dated on or after the
cutoff are excluded for both legacy and managed enrollments. An eligible fee
with a missing Our Date stops the report with a correction message.
Payments, discounts, adjustments, and writeoffs do not reduce invoiced. Null
fee amounts are ignored. Earned uses the service ratio for each enrollment or
stage; unearned equals invoiced minus earned. Historic fee corrections are
reflected when a report is rerun; historical versions of edited or deleted
transactions are not retained. Legacy leave records starting on or after the
cutoff do not alter the earlier leave calculation. Other mutable legacy
status and program data may still limit reconstruction of historical results.
Student-level earned/unearned values show six decimals in HTML and CSV;
aggregate earned/unearned totals sum exact rational values and round once to
cents. CSV preserves the 29 legacy detail columns, adds the program column,
and keeps its dated filename.

The legacy “Skip Zero Balance Records” option actually skips learners whose
service ratio is zero, not those with a zero balance or no fees. The new label
explains that behavior. Sort options are ID, last name, and expected graduation
date. Missing sort values appear first and ID breaks ties, avoiding the legacy
null comparison crash; unsorted output is deterministically ID-ordered.
Empty nullable detail fields export as blank cells, with shared CSV formula
protection.

Before the as-of fee cutoff correction, a read-only live comparison of enrollment
2026-01-01 through before 2026-11-01, as of 2026-11-01, matched all 299
learners' financial values (earned/unearned to
six decimals), dates, status flags, names, identifiers, and service/LOA durations.
Totals matched: invoiced 2,589,320.40; earned 780,117.50;
unearned 1,809,202.90. Service ratios retain up to 12 decimal places rather
than binary-float artifacts.

### Attributable Income calculation details

Attributable Income requires explicit start/end dates, includes the start date,
and excludes the end date at midnight. Its optional enrollment cutoff is
inclusive. Both the full and fewer-field variants support HTML and CSV.
Unassigned transactions and null amounts do not contribute. A relevant
transaction with no Our Date stops the report for correction. Archive/status flags
do not filter learners out of the financial totals.

Key preserved report-specific rules:

- Sum the absolute amount of each transaction by lowercase type, not its signed
  balance effect. Do not reuse the learner detail-screen fee/paid conventions.
- Fees include `fee` and `declined fee`; adjustments include `adjustment`,
  `adjustments`, and `fee adjustment`; discounts include `correction`, `waived`,
  and singular `discount`, not plural `discounts`.
- Paid includes `check`, `payment`, `payment/pago`, and `pago`; credits include
  singular/plural credit; payment adjustments reduce adjusted paid.
  Refunds/reversals remain visible in raw totals but do not reduce this report's
  paid calculation.
- Preserve the legacy `nonexistant` placeholder in both writeoff and payment-
  adjustment categories rather than changing accounting categorization.
- Overall AR takes the absolute value of adjusted owed. Per-learner AR uses
  signed adjusted owed. These deliberately differ.
- Program completion uses configured service days or the legacy 547-day
  fallback, naive-calendar elapsed time less LOA durations, and a 0–100% ratio.
  Missing dates use the legacy three-year inference without writing corrections.
- Preserve dated lifecycle completion overrides and the pre-2022 archive rule.
  Leave intervals are clipped at as-of and overlapping days count once. Missing
  leave starts are ignored in this legacy progress calculation; reversed leave
  intervals require correction. Suspension's last
  date is diagnostic; it does not truncate service time. PIF alone does not
  force completion.

Money is calculated exactly. Earned income uses rational service fractions;
the overall total is rounded once, so it may differ by cents from the sum of
rounded learner rows. Per-learner `earnedincome` shows six decimal places,
rounded HalfUp directly from the exact rational calculation, in both full and
fewer-field HTML/CSV for accounting reconciliation. Summary totals and other
money remain in cents; the service ratio shows up to 12 decimal places.
Fewer-field CSV retains the legacy two-decimal numeric truncation except for
`earnedincome`, which keeps all six decimals. Zero/missing transaction categories become explicit zeros instead
of the legacy fewer-field crash. Type columns and their summary totals use the
owner-approved legacy sample order: `fee`, `note`, `payment`, `discount`,
`credit`, `write off`, `refund`, `declined fee`, `fee adjustment`. Only types
present in the report are included; additional types follow in first-encounter
order. This avoids depending on the legacy database's unspecified query order.
CSV text is protected from spreadsheet formula execution.

Verified read-only against the live system:

| Transaction period (exclusive end) | Enrollment cutoff | Learners | Adjusted owed | Adjusted paid | Total earned |
| --- | --- | ---: | ---: | ---: | ---: |
| 2026-10-01 to 2026-11-01 | 2026-01-01 | 129 | 586,212.00 | 273,605.47 | -684.88 |
| 2026-01-01 to 2026-02-01 | None | 265 | 423,495.43 | 342,728.38 | 15,559.43 |

Both comparisons matched totals and normalized exported rows: financial values
at cents, service ratios to 10 decimals, dates, lifecycle flags, and service/LOA
durations. This does not replace broader owner acceptance and report examples.

The owner's supplied full CSV examples for 2024-01-01 to 2026-01-01 also match
all 1,233 learner rows by header: monetary values rounded to cents, ratios
within the exported 12-decimal precision, and remaining fields after CSV text
escaping. This comparison exposed a column-order difference; the owner approved
using the sample's type ordering consistently so financial columns align with
the legacy export.

## Local setup

Requirements: PHP 8.5 with PDO MySQL, PDO SQLite, BCMath, mbstring, XML/DOM, GD, and normal
Laravel extensions; Composer; MySQL. SQLite is used for isolated automated tests,
not as the production financial database.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Configure `.env` for a **new destination**, never the legacy database:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=englishbilling_laravel
DB_USERNAME=your_destination_user
DB_PASSWORD=
APP_TIMEZONE=America/New_York
```

Create the destination database with `utf8mb4` / `utf8mb4_unicode_ci`, then:

```sh
php artisan migrate
```

Local configuration in this workspace already points to `englishbilling_laravel`
using the owner's local root/no-password MySQL and `/tmp/mysql.sock`. This is
development-only; do not deploy root/no-password credentials to Ubuntu.

The untouched full dump is loaded separately in
`englishbilling_legacy_d3cfe21d`. Configure its connection using:

```dotenv
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=englishbilling_legacy_d3cfe21d
LEGACY_DB_USERNAME=your_read_only_import_user
LEGACY_DB_PASSWORD=
```

Set `DB_SOCKET` and `LEGACY_DB_SOCKET` when using Unix sockets. The source
connection requests UTF-8 from MySQL, allowing MySQL to decode its `latin1`
columns for the destination. It does not shift legacy datetime values.
On production, grant the source connection SELECT access only.

## Import and reconciliation

Import into an empty, migrated destination:

```sh
php artisan billing:import
```

After importing, verify the unchanged snapshot independently:

```sh
php artisan billing:import --verify-only
```

The importer:

1. Refuses source/destination identity, missing/mismatched columns, or populated
   billing tables on an import attempt. It never truncates or overwrites data.
2. Reads MySQL in a repeatable-read, read-only transaction.
3. Imports only the nine modeled tables, preserving IDs, strings, nulls,
   timestamps, unusual signs/types, and invoice PDF blobs.
4. Uses MySQL `DECIMAL(15,2)` conversion for monetary source values and recomputes
   student balances from those cent-rounded ledger entries.
5. Verifies sorted row values with SHA-256 hashes, exact decimal totals, all
   historical PDF bytes, and each student's expected ledger balance.
6. Commits destination data only if verification succeeds. Data/verification
   failures roll back the entire import.
7. Writes a private JSON reconciliation under
   `storage/app/private/imports/`, including counts, hashes, financial totals,
   original/rebuilt balance differences, and legacy exception counts.

The thirteen additional Access/phone columns exist only in the destination.
The Django importer explicitly allows this known list, imports/compares only
original Django student columns, and records the excluded fields under
`destination_only_student_fields`. It neither imports nor verifies Access data
and never clears populated additional fields. Other schema mismatches still fail.

Run against a frozen snapshot with destination writes stopped. `--verify-only`
is not suitable after staff have edited the destination or the source changed.
It checks exact snapshot equality, not general operational health.
If saving reconciliation fails after commit, the command reports the failure;
fix storage permissions and rerun `--verify-only`.

The source dump and private reconciliation contain sensitive data. Do not
commit them, serve them publicly, or send them to external services.
Django auth/session tables and unrelated `su_*` tables are not imported.

To rehearse another import, create and migrate a **different empty destination**.
There is deliberately no destructive `--force` or reset option.

### Refreshing local billing data from production

`billing:refresh` is a separate, deliberately destructive refresh for the local
development database, not an Access merge or a production deployment tool.
It defaults to a read-only database preview. It requires `APP_ENV=local` and
local MySQL connections for both source and destination.

1. Make a **full backup of the Laravel database**, including users, forms,
   workflows, settings, and additional student/program fields. Keep it private.
2. Obtain a fresh, consistent production SQL dump including binary PDF data
   (`mysqldump --single-transaction --hex-blob` for InnoDB tables). Restore it
   into a **new, separate local source database**, never into Laravel.
   Inspect the dump first for `CREATE DATABASE` / `USE` statements so it cannot
   select a different database during restoration.
3. Point `LEGACY_DB_*` at that restored source and run `php artisan config:clear`.
   Keep the source frozen; SELECT-only source credentials are recommended.
   The command never writes to the source or connects to production.
4. Preview and review the counts and private JSON report, including removed IDs:

   ```sh
   php artisan billing:refresh
   ```

5. Stop local queue workers and scheduled jobs, close editing sessions / release
   student locks, and put the app into maintenance mode. Apply with the exact
   configured database names (replace the example source name):

   ```sh
   php artisan down
   php artisan billing:refresh --apply --source=englishbilling_fresh --target=englishbilling_laravel
   ```

6. Only after success, run `php artisan up` and restart local workers/jobs. On
   failure, inspect the error before reopening; data/verification errors roll
   back the refresh. A report-write failure **after commit** is explicitly
   identified and does not roll back an already verified refresh.

Rules:

- Test enrollment history is discarded on every applied refresh, including
  program enrollments, stages, audit events, current-enrollment pointers/revisions,
  billing enrollment/stage links, and application-fee classifications.
  Stage-duration settings and logins survive. Preview output and the private
  JSON report list the enrollment history being discarded. This is a repeatable
  staging reset, never a production operation.
- Match strictly by billing primary keys, not names or emails. Local test
  records can collide with newer production IDs: overlapping IDs are treated
  as the same record. Review local-created records and their Laravel-only
  fields/forms before proceeding; this is not identity resolution.
- Production overwrites every original billing column on matching IDs and
  inserts new rows. Local-only rows in the eight non-program billing tables
  are removed, child tables first. This includes local-only historical PDFs.
- Existing programs retain **all** Laravel values, including code, description,
  service days, and added fields. Missing production programs are inserted;
  local-only programs are retained. The report lists differences. Consequently
  locally changed service days can intentionally change report calculations.
- Added student fields (including `originalcohort`, except the reset enrollment
  pointers/revisions) survive on matching IDs.
  New production students get null added fields. Shared student fields such as
  cohort, email, phone, and sponsorship are overwritten from production; this
  is not the previously discussed Access merging policy.
- Users, passwords, roles, settings, forms, workflows, and other Laravel-only
  tables (except the explicitly discarded enrollment history) are not refreshed.
  If deleting a local-only billing row would affect
  any foreign-key-linked Laravel-only data (even via SET NULL or CASCADE), the
  entire operation is refused for manual resolution. Existing student edit
  locks also prevent applying the refresh; it never silently releases locks.
- Monetary conversion, ledger balance rebuilding, binary PDF verification,
  row hashes, and transactional rollback reuse the initial import machinery.
  All mirrored tables must match the source before commit, except rebuilt
  student balances and intentionally preserved programs/added columns.
- Schema mismatches and an empty source student table are refused. The preview
  counts rows that will be overwritten, not only rows whose values differ.
  Private reports are saved under `storage/app/private/imports/refresh-*.json`.
  The original `billing:import` still refuses populated destinations.

### Verified local snapshot

The first full import and independent verification both succeeded:

| Table | Rows |
| --- | ---: |
| `programs` | 10 |
| `globals` | 0 |
| `students` | 4,655 |
| `invoices` | 21,904 |
| `studenttransactions` | 58,287 |
| `loas` | 567 |
| `paymentplans` | 7 |
| `studentpaymentplans` | 236 |
| `anticipatedpayment` | 2,054 |

21,852 PDFs (1,358,490,928 bytes) verified unchanged.
Import peak PHP memory: 60 MiB; standalone verification: 34 MiB.
The destination's next auto-increment IDs match this source snapshot.

Reconciliation preserves 69 unassigned transactions, 11 null types, and one
cross-student invoice link. It records 1,042 balance value changes, mostly
removing tiny binary floating-point residues; 14 null balances became ledger
balances and one account changed by one displayed cent. No synthetic balancing
transaction or historical-date correction was introduced.

## Staff accounts

There are no seeded accounts or default passwords. Create the first admin
interactively, with passwords hidden from the terminal and shell history:

```sh
php artisan billing:create-admin
php artisan serve
```

Visit `/login`. Admins can create/edit/delete users at `/users`; everyone can
access all implemented billing features. Login attempts are rate-limited. No public signup,
password-reset email, or direct billing email is enabled.
An admin cannot delete their own login or demote the final admin.

The views use local CSS and JavaScript, so Node/npm and a frontend build are not
required for this milestone. A temporary browser validation server was stopped
after the check; no development server is intentionally left running.

## Validation

```sh
php artisan test
vendor/bin/pint --test
composer validate --strict
node --check public/js/billing.js
```

Tests force an in-memory SQLite destination and create a separate in-memory
source. They do not modify the local imported MySQL databases.
Import tests include binary PDFs, accented text, null flags, fractional cents,
rollback, overwrite/schema/identity safeguards, corruption detection, and
invoice unlinking.

Core workflow tests also cover child-record ownership, stale edits, partial
field/date/null preservation, signed balances, filters and navigation, LOA
validation, schedule rounding/ordering, and original-PDF delivery.
Browser checks covered the imported data read-only, plus persisted mutations
in a separate synthetic MySQL database: successive learner edits, rejected
sub-cent amounts, retention of another unsaved row, invoice unlinking without
balance changes, and generated/inserted/reordered schedules.
An authenticated, read-only comparison with the live Django system also matched
a representative learner's balance, fees/paid totals, and all 17 schedule rows
(dates, amounts, notes, and ordering). This is a spot check, not full staff or
report acceptance.
Document tests cover sponsor/missing-data cases, escaping, zero-amount fallback,
ownership/authentication, invoice/PDF atomic rollback, retained stored PDFs,
and multipage output. A separate PDF text extraction check verified all 160
synthetic ledger rows and all 300 words of a long note, plus balance/payment due.
An initial live HTML-invoice comparison matched 23 ledger rows, amount, due date,
and balance. Broader document acceptance remains pending.

## Deployment prerequisites

Use an Ubuntu staging environment before production: PHP-FPM/web server,
restricted MySQL users, TLS, private environment configuration, backups,
`APP_DEBUG=false`, writable private storage, and document tooling when added.
The web root must be `public/`, not the application or Django workspace root.
Do not expose the original dump or the legacy source database.

### Deploying the program-stage catalog update

Back up the database and deploy the code and additive migration together.
For an existing installation with the earlier enrollment migrations applied:

```sh
php artisan down &&
php artisan migrate --path=database/migrations/2026_11_20_000001_add_program_stage_catalog.php --force &&
php artisan route:clear &&
php artisan view:clear &&
php artisan route:cache &&
php artisan up
```

Stop on an error and resolve it before reopening. Reload PHP-FPM if OPcache
does not check file timestamps. The migration does not convert learners,
discard history, create programs, or change financial records. The new catalog
fields and locally maintained stage programs survive `billing:refresh`; only
test learner enrollment history is discarded as before.
If a later source dump introduces a program ID already used by a local staged
program, refresh refuses before changing data rather than attaching production
learners to the wrong program. Resolve that ID collision explicitly first.

### Admin utilities: mail connection test

Admins can open **Utilities** in the navigation (`/utilities`) to send an
immediate plain-text test email using the application's configured mailer.
The form accepts a sender, recipient, subject and body. It does not change
the default sender or workflow settings; the provider must authorize the
chosen sender. Test sends are limited to six per minute.

The page displays the configured mailer and transport, but never credentials.
With `log` or `array`, it explicitly reports that no email is delivered.
With a delivery transport, acceptance does not guarantee inbox delivery;
check the inbox and provider logs (a failover mailer can use its fallback).
Transport failures appear on the form, with diagnostics in the application
log. Server mail configuration is still managed through `.env`; rebuild
the configuration cache after changing it.

**Email (SendGrid).**

- In `.env` set:
  - `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.sendgrid.net`, `MAIL_PORT=587`
  - `MAIL_USERNAME=apikey`, `MAIL_PASSWORD=<SendGrid API key>`
  - `MAIL_FROM_ADDRESS=adminteam@cgms.edu`, `MAIL_FROM_NAME="Admin Team"`
- Authenticate the cgms.edu domain (or at least verify the sender) in SendGrid
  first, or delivery will be rejected or land in spam.
- Local development uses `MAIL_MAILER=log`, which writes emails to
  `storage/logs/laravel.log`.

**Learner link host.** `PORTAL_URL` sets the address used in learner links. It
defaults to `APP_URL`. If you use a separate hostname, point it at this same
application.

The Laravel framework is distributed under the MIT license.

### Quick Commands Cheat Sheet
php artisan down * maintenance mode
php artisan up * normal mode
php artisan billing:refresh * preview update
php artisan billing:refresh --apply --source=englishbilling_legacy_d3cfe21d --target=englishbilling_laravel * apply update
php artisan serve --host=127.0.0.1 --port=8001 * serve locally
php artisan route:clear * clear cached routes
php artisan view:clear * clear cached views
php artisan route:list --name=enrollments * example, show that a route called enrollments is known