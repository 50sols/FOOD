# V2 #3912 — P290 Invoice open-item settlement retry protection

Linked parent #2620, master #2503. Original P290 PR #3316 already merged into Foodex_V2. Route/UX placement: reuse existing invoice details modal and Customer 360 invoice actions; no new Sidebar/tab/route.

Authority: existing invoice GET/POST and Customer360 scope/`finance.manage` checks unchanged. Channel B2B only. No access by B2C retail, cross-store, roleless, disabled module beyond existing effective checks.

Request identity: fresh server-generated UUID embedded in each rendered invoice/Customer360 settle form; Customer360 JS copies distinct row UUID to modal when invoice selected. Browser/network resend reuses request identity, while a fresh page has a fresh one.

Storage: nullable uuid `customer_account_ledger_entries.settlement_request_id` and composite unique with B2B customer. Existing rows and other ledger operations remain null-compatible. Under invoice row lock, a matching identity is read-only; verify exact invoice, store, source, amount/currency and customer before returning original ledger entry. Wrong-body reuse throws validation, no ledger write. No duplicate audit `invoice.settled` on replay. The database constraint provides final concurrent different-invoice protection; it may return DB constraint failure instead of 422 in simultaneous malicious races, but will never create two ledger records.

Single source of financial truth remains B2bAccountLedgerService, invoice amounts and append-only customer ledger. No unsanctioned GL posting or receipt-origin attestation. Existing payment/settlement references are metadata, never authority.

Customer mobile: no API/Flutter changes; Driver: no API/Flutter changes; Van: no API/Flutter changes. Dashboard only; AR/EN/RTL/LTR existing invoices/Customer360 markup and server validation messages remain authorized.

Tests: B2bAccountLedgerContractTest partial replay, full-paid replay, distinct second payment, mismatched invoice/amount reuse rejection, invalid UUID no-write, existing original ledger tests. Required exact-head Laravel/Pint/PHPStan/security/AR-EN/MySQL Redis acceptance, branch-policy/CI, HIGH-risk file audit. No main/release/production.

Rollback warning: do not drop a populated column after deployment; a backup must preserve keys and ledger for recovery. All code/PR stays isolated in Foodex_V2.
