<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 * TikTok: https://www.tiktok.com/@aiwithrameez (Follow for more!)
 */
require_once 'config.php';

// auth check
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'about';

$branding = getBranding();
$logo = $branding['site_logo'];
$appName = $branding['site_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" href="<?php echo htmlspecialchars(getBranding()['site_logo']); ?>"><!-- tab icon, stops the browser asking for /favicon.ico -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>About - <?php echo htmlspecialchars($appName); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>
</head>
<body>
<script>/* theme before paint */(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))document.body.classList.add('dark-mode');})();</script>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <?php
            $pageIcon  = 'fa-circle-info';
            $pageTitle = 'About App';
            $crumbs    = [];
            include 'page_header.php';
            ?>

            <!-- app identity strip -->
            <section class="ab-hero">
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="" class="ab-logo">
                <div class="ab-id">
                    <h1><?php echo htmlspecialchars($appName); ?> <span class="ab-ver">v5.0</span></h1>
                    <p>A complete motor-insurance policy &amp; accounting management system · Built by <strong>Mohammad Rameez Imdad</strong> · Rameez Scripts</p>
                </div>
                <ul class="ab-stats">
                    <li><strong>3</strong><span>Roles</span></li>
                    <li><strong>10</strong><span>Modules</span></li>
                    <li><strong>31</strong><span>Rules</span></li>
                </ul>
            </section>

            <div class="ab-tabs" role="tablist">
                <button type="button" class="ab-tab" data-tab="permissions" role="tab"><i class="fas fa-user-shield"></i> Permissions <em>3</em></button>
                <button type="button" class="ab-tab" data-tab="logic" role="tab"><i class="fas fa-calculator"></i> Business logic <em>31</em></button>
                <button type="button" class="ab-tab" data-tab="developer" role="tab"><i class="fas fa-code"></i> Developer</button>
            </div>

            <!-- role vs feature matrix -->
            <section class="ab-pane" data-pane="permissions" role="tabpanel">
                <p class="ab-legend"><i class="fas fa-check-circle text-success"></i> Full <i class="fas fa-eye text-warning"></i> Own records only <i class="fas fa-times-circle text-danger"></i> No access</p>
<div class="about-table-wrapper">
                    <table class="about-roles-table">
                        <thead>
                            <tr>
                                <th>Feature / Action</th>
                                <th><span class="role-badge role-admin">Admin</span></th>
                                <th><span class="role-badge role-emp">Manager</span></th>
                                <th><span class="role-badge role-sf">Agent</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Dashboard &amp; Reports</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-times-circle text-danger"></i></td>
                            </tr>
                            <tr>
                                <td>Policy &amp; Vehicle Entry</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-eye text-warning"></i> Own Records</td>
                            </tr>
                            <tr>
                                <td>Agents &amp; Buyers</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-eye text-warning"></i> Own Profile</td>
                            </tr>
                            <tr>
                                <td>Accounting (Banks / Expenses / Income)</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-times-circle text-danger"></i></td>
                            </tr>
                            <tr>
                                <td>Payout Charts</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-eye text-warning"></i> Own Payout</td>
                            </tr>
                            <tr>
                                <td>Claims (report, survey, settle)</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-eye text-warning"></i> All, no delete</td>
                                <td><i class="fas fa-eye text-warning"></i> Own policies: report &amp; upload</td>
                            </tr>
                            <tr>
                                <td>Policy documents &amp; endorsements</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-eye text-warning"></i> Add, no delete</td>
                                <td><i class="fas fa-eye text-warning"></i> View own</td>
                            </tr>
                            <tr>
                                <td>WhatsApp renewal / dues reminders</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-eye text-warning"></i> Own policies</td>
                            </tr>
                            <tr>
                                <td>Policy Trash (restore / delete forever)</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-times-circle text-danger"></i></td>
                                <td><i class="fas fa-times-circle text-danger"></i></td>
                            </tr>
                            <tr>
                                <td>Expenses: add / approve</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-eye text-warning"></i> Add, edit own pending</td>
                                <td><i class="fas fa-times-circle text-danger"></i></td>
                            </tr>
                            <tr>
                                <td>User Management &amp; Settings (backups, email, WhatsApp texts)</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-times-circle text-danger"></i></td>
                                <td><i class="fas fa-times-circle text-danger"></i></td>
                            </tr>
                            <tr>
                                <td>My Account &amp; Notifications</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                                <td><i class="fas fa-check-circle text-success"></i> Full</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="ab-pane" data-pane="logic" role="tabpanel" hidden>
<div class="about-table-wrapper">
                    <table class="about-roles-table">
                        <thead>
                            <tr>
                                <th>What</th>
                                <th>Formula / Logic</th>
                                <th>Where Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Agent balance</td>
                                <td>wallet = &Sigma; credit &minus; &Sigma; debit (from the agent ledger)</td>
                                <td>Agents, Agent Transactions</td>
                            </tr>
                            <tr>
                                <td>Buyer balance</td>
                                <td>wallet = &Sigma; credit &minus; &Sigma; debit (from the buyer ledger)</td>
                                <td>Buyers, Buyer Transactions</td>
                            </tr>
                            <tr>
                                <td>Bank balance</td>
                                <td>current = opening + &Sigma; credit &minus; &Sigma; debit (from the bank ledger)</td>
                                <td>Bank Accounts, Bank Transactions</td>
                            </tr>
                            <tr>
                                <td>Bank &harr; Agent link</td>
                                <td>Agent <em>Payment In</em> &rarr; bank <strong>Credit</strong>; <em>Payment Out</em> &rarr; bank <strong>Debit</strong>. Recording either side posts a linked entry on the other.</td>
                                <td>Agent Transactions, Bank Transactions</td>
                            </tr>
                            <tr>
                                <td>Bank &harr; Buyer link</td>
                                <td>Buyer <em>Payment Received</em> &rarr; bank <strong>Credit</strong>; <em>Payment Made</em> &rarr; bank <strong>Debit</strong>. Recording either side posts a linked entry on the other.</td>
                                <td>Buyer Transactions, Bank Transactions</td>
                            </tr>
                            <tr>
                                <td>Linked mirror rule</td>
                                <td>The auto-created leg is locked (&#128279;) and edited/deleted only from the screen that created it; changes cascade to its pair and both balances recalculate. Commissions &amp; adjustments never touch a bank.</td>
                                <td>All transaction screens</td>
                            </tr>
                            <tr>
                                <td>Policy Payment In / Out</td>
                                <td>A policy carries its own <em>Payment In Account</em> (money received &rarr; bank <strong>Credit</strong>) and <em>Payment Out Account</em> (money paid &rarr; bank <strong>Debit</strong>), which may be two different accounts. Editing/deleting a policy reverses &amp; re-posts these legs and recomputes every affected account.</td>
                                <td>Policy Entries, Bank Transactions</td>
                            </tr>
                            <tr>
                                <td>Bulk uploads</td>
                                <td>Policy Excel resolves <em>Payment In Bank</em> / <em>Payment Out Bank</em> by <strong>account number</strong> (or <code>Bank Name - AccountNumber</code> / IFSC); a plain bank name is accepted only when unique, and rejected when several accounts share it. Bank Transactions Excel posts plain credit/debit rows. Agents &amp; Buyers Excel/CSV lands in the Bulk Add grid for review (flexible headers, <code>+</code>/<code>&minus;</code> opening balance) and saves in chunks of 40 — note the opening-balance sign is <strong>mirrored</strong> between the two ledgers: agent <code>+</code> = we owe the agent, buyer <code>+</code> = the buyer owes us. Customers, Vehicles, Users and Branches import the same way. Two resolution rules worth knowing: a <strong>vehicle</strong> row matches its owner on mobile, then on exact name, and creates the customer when the mobile is new and a name is supplied; a <strong>user</strong> row with role <code>agent</code> also creates its Agent record (code <code>AGT-####</code>), exactly like the single Add User form. Duplicate keys (customer mobile, branch name, vehicle number, agent/company code, user email/phone, bank account number) are skipped rather than duplicated, so re-uploading the same sheet is safe. <strong>Expenses</strong> and <strong>Other Income</strong> imports post their bank leg exactly like the single-entry forms — expense &rarr; <strong>debit</strong>, income &rarr; <strong>credit</strong> (carrying the same <code>[OI#id]</code> marker so edit/delete still finds it) — and each touched account is recalculated <strong>once at the end of the batch</strong>, not per row. A bank account is resolved by account number or <code>Bank Name - AccountNumber</code>; a bare bank name is rejected when several accounts share it. <strong>Agent</strong> and <strong>Buyer Transactions</strong> import the same way — the type fixes the credit/debit side, a bank account links only on payment in/out (posting the mirror leg), and every touched party/account is recalculated once per batch. <strong>Point Transactions</strong> read each customer's live balance per row so stacked rows accumulate correctly, and reject a redemption larger than the balance. <strong>Payout charts</strong> keep their server-side CSV pipeline and upload audit record — Excel files are converted to CSV in the browser before upload, so <code>.xlsx</code> now works there too. All dedup/validate per row, skip &amp; report bad rows, and recalc each touched account once.</td>
                                <td>Policy Entries, Bank Transactions, Agents, Buyers, Customers, Vehicles, Users, Branches</td>
                            </tr>
                            <tr>
                                <td>Agent required (no direct sales)</td>
                                <td>Every policy must be assigned to a <strong>listed agent</strong> — manual Add/Edit and Excel upload reject a blank or unknown agent (rows are skipped with a &ldquo;not in the agent list&rdquo; note). No &ldquo;Company Direct&rdquo; fallback is created for new policies.</td>
                                <td>Policy Entries (Add / Edit / Bulk Upload)</td>
                            </tr>
                            <tr>
                                <td>Agent opening balance</td>
                                <td>Bulk Add Agents (typed grid or Excel/CSV upload) posts an <em>Opening Balance</em> adjustment per agent: <strong>+</strong> amount &rarr; credit (we owe the agent), <strong>&minus;</strong> amount &rarr; debit (agent owes us); the agent wallet then recalculates from its ledger.</td>
                                <td>Agents (Bulk Add), Agent Transactions</td>
                            </tr>
                            <tr>
                                <td>Vehicle categories</td>
                                <td>The category list is admin-managed (Vehicles &rarr; Categories) &mdash; add / rename / deactivate. Every category dropdown &amp; upload validation reads the active list; deactivated categories stay on old records but drop out of new-entry dropdowns. Renaming updates the label on existing vehicles &amp; policies.</td>
                                <td>Vehicles, Policy Entries, Payout Charts</td>
                            </tr>
                            <tr>
                                <td>Premium at risk</td>
                                <td>&Sigma; premium of <strong>active</strong> policies whose end date falls between today and today + 30 days. <em>Due this week</em> = same, today + 7 days. <em>Largest policy</em> = the biggest premium in that 30-day set; <em>Next expiry</em> = its earliest end date. Bars = premium booked per month for the last 8 months (cancelled excluded).</td>
                                <td>Dashboard (hero card; agents see only their own policies)</td>
                            </tr>
                            <tr>
                                <td>Period tiles &amp; change %</td>
                                <td>Policies booked / premium booked / commission count entry dates inside the selected period, cancelled excluded. Net margin = (buyer commission &minus; agent commission) &minus; expenses + other income. Change % = (this period &minus; previous period) &divide; |previous| &times; 100, where previous = yesterday / last week / last month or quarter <strong>up to the same day</strong> / the equal-length span before a custom range. All Time has no previous period.</td>
                                <td>Dashboard (period card)</td>
                            </tr>
                            <tr>
                                <td>Attention required</td>
                                <td>Policies that are not cancelled and end within 30 days <strong>either side</strong> of today, nearest to today first, then bigger premium first (top 6 shown, the badge counts all). Time left = days to the end date (0 once expired) on a 30-day bar: &gt; 14 days green, 4&ndash;14 amber, &le; 3 red. When nothing falls in that window the list shows the next 6 active policies by end date instead (no badge).</td>
                                <td>Dashboard (attention list)</td>
                            </tr>
                            <tr>
                                <td>TDS on payouts &amp; receipts</td>
                                <td>Agent <em>Payment Out</em>: agent ledger debit = <strong>gross</strong>, linked bank debit = gross &minus; TDS. Buyer <em>Payment Received</em>: buyer ledger debit = gross (clears the receivable), linked bank credit = gross &minus; TDS. Suggested TDS = amount &times; the party's TDS rate &divide; 100 (agents default 5%, buyers 2%, range 0&ndash;30, editable). TDS is allowed only on those two types and must be 0 &le; TDS &lt; gross. Edit / delete re-post the bank leg at the new net.</td>
                                <td>Agent Transactions, Buyer Transactions, Bank Transactions</td>
                            </tr>
                            <tr>
                                <td>Commission invoice GST</td>
                                <td>Buyer rows carry the commission invoice number and the GST on it — reference only, no balance changes (GST &ge; 0).</td>
                                <td>Buyer Transactions</td>
                            </tr>
                            <tr>
                                <td>Policy GST, IDV, NCB</td>
                                <td>GST = Total premium &minus; Net premium (min 0) — suggested and used when left blank, editable. IDV &ge; 0, NCB 0&ndash;100%.</td>
                                <td>Policy Entries (form, view, print, bulk upload)</td>
                            </tr>
                            <tr>
                                <td>Renewals</td>
                                <td>Renewing links the new policy to the old one (<em>business type = renewal</em>, previous policy). A policy can be renewed once; a <em>new</em> policy cannot have a previous one. A renewed policy no longer counts in Expiring in 30 days, Expired, Premium at risk, Due this week or Attention required.</td>
                                <td>Policy Entries, Dashboard</td>
                            </tr>
                            <tr>
                                <td>Cancellation</td>
                                <td>Status Cancelled needs a reason; the cancellation date is stamped the first time and cleared if the policy goes back to active / expired.</td>
                                <td>Policy Entries</td>
                            </tr>
                            <tr>
                                <td>Expense GST &amp; approval</td>
                                <td>GST is included in the amount; taxable value = amount &minus; GST (voucher only). Expenses added by an admin are approved on save, by a manager <em>pending</em>. Only <strong>approved</strong> expenses count in totals (dashboard, P&amp;L, branch performance) and debit their bank account &mdash; approving posts the bank entry, rejecting removes it. A manager can edit its own pending or rejected expense, which sends it back as pending. Admins see the pending count on the Expenses menu.</td>
                                <td>Expenses</td>
                            </tr>
                            <tr>
                                <td>Bank reconciliation</td>
                                <td>Ticking a bank entry sets reconciled + the time, unticking clears both; balances are not affected. Editing an other-income amount or account clears its leg's reconciled flag. Other-income legs link by id (old rows by their [OI#id] marker).</td>
                                <td>Bank Transactions, Other Income</td>
                            </tr>
                            <tr>
                                <td>Agent licence alert</td>
                                <td>Days = licence expiry &minus; today: below 0 shows <em>Expired</em>, 0&ndash;30 shows <em>Expires in N days</em>.</td>
                                <td>Agents list, agent view, My Profile</td>
                            </tr>
                            <tr>
                                <td>Customer dues</td>
                                <td>Due = Payment Out &minus; Payment In, for policies that are not cancelled where we paid the insurer more than the customer has paid us. Days open = today &minus; entry date (amber after 7 days, red after 30). Shown as of today, not by date range.</td>
                                <td>Reports (Customer Dues), Dashboard pill</td>
                            </tr>
                            <tr>
                                <td>WhatsApp reminders</td>
                                <td>Opens WhatsApp with the Settings text, placeholders filled from the policy. Number = customer WhatsApp, else mobile; a 10-digit number gets the country code (default 91); no number lets WhatsApp pick the contact. A renewal reminder stamps the policy (last reminded, times sent); dues reminders are logged only.</td>
                                <td>Policy Entries, Dashboard, Reports (Renewals, Customer Dues)</td>
                            </tr>
                            <tr>
                                <td>Endorsements</td>
                                <td>Dated inside the policy period. Premium change is for reference &mdash; policy totals and commissions do not change. A fee can post to Other Income (policy branch, plus a bank credit when an account is picked); deleting the endorsement removes that income and its bank entry.</td>
                                <td>Policy Entries (Endorsements tab)</td>
                            </tr>
                            <tr>
                                <td>Claims</td>
                                <td>Claim no = CLM-&lt;year of intimation&gt;-&lt;4-digit sequence&gt; (highest that year + 1). The policy must be visible to you and not cancelled; policy start &le; incident date &le; policy end; intimation date on or after the incident and not in the future; survey date on or after the incident; settlement date on or after the intimation. Approved needs an approved amount above 0 (not above the IDV when set); Settled needs the settled amount and date; Rejected needs a reason. Amounts are never negative. An agent reports claims on its own policies and can change only the intimation details while the claim is Intimated; reopening a Settled or Rejected claim is admin only. A new claim notifies the admins and the policy's agent, a status change notifies the agent (never the person who made it). Open claims (Intimated, Survey, Approved) are counted on the sidebar. Claims never post to any ledger or bank, and a policy with claims cannot be deleted.</td>
                                <td>Claims, Policy Entries, Vehicles</td>
                            </tr>
                            <tr>
                                <td>Delete &rarr; Trash</td>
                                <td>Deleting a policy keeps a snapshot (policy, commission and bank lines, documents, endorsements, links) and reverses its entries; every agent, buyer and bank account it touched &mdash; both payment accounts &mdash; is recalculated. Restore re-posts everything under the same id when it is free and recalculates again; a policy number used again meanwhile blocks restore. Delete forever removes the snapshot and its files.</td>
                                <td>Policy Entries (Trash)</td>
                            </tr>
                            <tr>
                                <td>Private files</td>
                                <td>Policy documents, claim documents and expense receipts are never public links: each opens through the app after a login check, and an agent opens only files on its own policies. New uploads get random names.</td>
                                <td>Policy Entries, Claims, Expenses</td>
                            </tr>
                            <tr>
                                <td>Daily jobs &amp; backups</td>
                                <td>Once a day (cron_expiry.php if scheduled, otherwise the first page load of the day): expiry alerts for active, not-renewed policies ending within 30 days (to admins and the agent, not repeated within 7 days), active &rarr; expired after the end date, and a gzip database copy every 7 days in a private folder (newest 8 kept). Admins can also download a full backup any time.</td>
                                <td>Settings, cron_expiry.php</td>
                            </tr>
                            <tr>
                                <td>Password reset</td>
                                <td>Needs email (SMTP) on and the Site URL set. One email per account every 2 minutes and 5 per hour per network address; the reply is the same whether or not the email exists. The link works once for 1 hour; changing the password closes every other open link and clears failed-login lockout.</td>
                                <td>Login, Settings</td>
                            </tr>
                            <tr>
                                <td>Login lockout</td>
                                <td>An account locks after the allowed number of <strong>failed</strong> logins within the lockout window; successful logins are logged too but never count, and a success clears the failures.</td>
                                <td>Login</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="ab-pane" data-pane="developer" role="tabpanel" hidden>
                <div class="ab-dev">
                    <img src="https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg" alt="Mohammad Rameez Imdad" class="ab-dev-img">
                    <div class="ab-dev-txt">
                        <h3>Mohammad Rameez Imdad</h3>
                        <p>Rameez Scripts · custom web apps, dashboards and automation</p>
                        <p class="ab-work"><i class="fas fa-envelope-open-text"></i> Let's Work Together</p>
                        <div class="ab-links">
                            <a href="https://whatsapp.rameezscripts.com" target="_blank" rel="noopener" class="btn ab-wa"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                            <a href="mailto:Contact@rameezscripts.com" class="btn btn-secondary"><i class="fa-solid fa-envelope"></i> Contact@rameezscripts.com</a>
                        </div>
                    </div>
                </div>

                <!-- app overview -->
                <div class="about-table-wrapper">
                    <table class="about-roles-table">
                        <thead><tr><th>Application</th><th>Details</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><i class="fas fa-info-circle"></i> About</td>
                                <td>
                                    This Insurance Management System is a web-based dashboard for managing motor-insurance
                                    operations end to end — policy entry, vehicle records, agents and buyers, commission
                                    payouts, bank accounts, expenses and other income, customer referrals, reminders and
                                    reports. Every party keeps its own running ledger, and balances are recomputed directly
                                    from those ledgers so figures always reconcile. Role-based access keeps administrators,
                                    managers and agents to the screens and data each is allowed to see.
                                </td>
                            </tr>
                            <tr>
                                <td><i class="fas fa-star"></i> Key features</td>
                                <td>
                                    <i class="fas fa-file-invoice"></i> Policy Entry &amp; Tracking ·
                                    <i class="fas fa-car"></i> Vehicle Management ·
                                    <i class="fas fa-car-burst"></i> Claims Tracking ·
                                    <i class="fas fa-file-signature"></i> Endorsements ·
                                    <i class="fas fa-user-tie"></i> Agent Accounts &amp; Payouts ·
                                    <i class="fas fa-building"></i> Buyer Accounts &amp; Payouts ·
                                    <i class="fas fa-university"></i> Bank Accounts &amp; Statements ·
                                    <i class="fas fa-money-bill-wave"></i> Expenses &amp; Other Income ·
                                    <i class="fas fa-gift"></i> Referrals &amp; Reward Points ·
                                    <i class="fas fa-bell"></i> Notifications &amp; WhatsApp Reminders ·
                                    <i class="fas fa-hand-holding-dollar"></i> Customer Dues ·
                                    <i class="fas fa-database"></i> Backups &amp; Trash ·
                                    <i class="fas fa-chart-pie"></i> Reports &amp; Activity Logs
                                </td>
                            </tr>
                            <tr>
                                <td><i class="fas fa-layer-group"></i> Technology stack</td>
                                <td>
                                    <i class="fab fa-php"></i> PHP 8 (Backend) ·
                                    <i class="fas fa-database"></i> MySQL Database ·
                                    <i class="fab fa-js"></i> jQuery ·
                                    <i class="fas fa-table"></i> DataTables ·
                                    <i class="fas fa-chart-line"></i> Chart.js ·
                                    <i class="fas fa-bell"></i> SweetAlert2 ·
                                    <i class="fab fa-font-awesome"></i> Font Awesome 6 ·
                                    <i class="fas fa-shield-halved"></i> CSRF + Bcrypt Security ·
                                    <i class="fas fa-mobile-screen"></i> Mobile Responsive
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <script>
    // tabs — #hash keeps the open one
    (function () {
        var tabs = document.querySelectorAll('.ab-tab'), panes = document.querySelectorAll('[data-pane]');
        function show(k) {
            if (!document.querySelector('[data-pane="' + k + '"]')) k = 'permissions';
            tabs.forEach(function (t) { var on = t.dataset.tab === k; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); });
            panes.forEach(function (p) { p.hidden = p.dataset.pane !== k; });
            history.replaceState(null, '', '#' + k);
        }
        tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });
        show(location.hash.slice(1));
    })();
    </script>
</body>
</html>
