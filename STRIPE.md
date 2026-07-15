# Stripe — setup & configuration

Everything to configure Stripe for Snip, in the order you'd do it going live.
Companion to `PAYMENTS.md` (why Stripe), `BUSINESS-SETUP.md` (the ABN/account
behind it), and `DEPLOY.md` (the server runbook). Business is Australian
(Moonxt); prod domain `jmpz.cc`.

> **General info, not legal/tax advice.** Reconfirm Dashboard labels/pricing on
> stripe.com — Stripe's UI changes.

## What Snip uses
Hosted **Checkout Sessions in `subscription` mode** + the **Customer Portal**,
kept in sync by a **signature-verified webhook**. Code: `inc/stripe.php`,
`checkout.php`, `billing-success.php`, `billing-portal.php`, `stripe-webhook.php`.
Prices are **inline `price_data`** (no pre-created Stripe Products) — see the
Portal note below. API version is pinned in `config.php`
(`STRIPE_API_VERSION`).

## The 4 config values (in `.env`)
| Env var | What | Where from |
|---|---|---|
| `STRIPE_SECRET_KEY` | Server API key (`rk_…` restricted, or `sk_…`) | API keys page (§2) |
| `STRIPE_PUBLISHABLE_KEY` | Public key (`pk_…`) — safe to expose | API keys page |
| `STRIPE_WEBHOOK_SECRET` | Webhook signing secret (`whsec_…`) | Webhook endpoint (§3) |
| `STATEMENT_DESCRIPTOR` | Card-statement label (default `SNIP.APP`) | must match §5.1 |

If these are blank the app runs in "billing not configured" mode (no crash).

---

## 1. Test vs Live mode — do TEST first
- **Local dev / staging → TEST-mode keys** (`pk_test_/rk_test_/sk_test_`, and a
  `whsec_…` from `stripe listen`). Use Stripe [test cards](https://docs.stripe.com/testing) —
  **no real charges.** Toggle **Test mode** (top-right of the Dashboard).
- **Production → LIVE-mode keys**, only on the server. Never test against live.
- Use **separate keys per environment** so a leak has a small blast radius.

## 2. API keys — create a restricted key (least privilege)
Dashboard → Developers → **API keys** → **Create restricted key** (preferred over
the full secret key). Grant only:

| Resource | Permission |
|---|---|
| Checkout Sessions | **Write** |
| Customers | **Write** |
| Customer portal (Billing) | **Write** |
| Subscriptions | **Read** |
| *everything else* | None |

Name it e.g. `snip-live-app`. Put it in `.env` as `STRIPE_SECRET_KEY` (the SDK
treats `rk_` and `sk_` the same). If you hit a `403` in the logs, Stripe names
the missing permission — add just that. The **publishable key** (`pk_…`) already
exists and is public → `STRIPE_PUBLISHABLE_KEY`.

**Security:** never commit or paste a secret/`rk_`/`whsec_` (a pre-commit hook
`.githooks/pre-commit` blocks it — enable with `git config core.hooksPath .githooks`).
Keep them in `.env` (git-ignored, `chmod 600`). Roll immediately if leaked
(**⋯ → Roll key**). Optional: set an **access policy / IP restriction** to the
server's Elastic IP.

## 3. Webhook endpoint
Dashboard → Developers → **Webhooks** → **Add endpoint**:
- **URL:** `https://jmpz.cc/stripe-webhook`
- **Events** (all four — the app handles them in `stripe-webhook.php`):
  - `checkout.session.completed` — activates the plan
  - `customer.subscription.created` / `customer.subscription.updated` — sync the
    renewal date + cancel flag (dashboard "Renews on / Access until X")
  - `customer.subscription.deleted` — downgrade to free
- Copy the endpoint's **Signing secret** (`whsec_…`) → `.env` as
  `STRIPE_WEBHOOK_SECRET`, then restart the app.

The app verifies the signature and is idempotent; a missing secret is logged loudly
and no subscription changes are processed.

## 4. Customer Portal (Dashboard → Settings → Billing → Customer portal)
This is where users self-serve, and where **plan changes** are routed (see the
gotcha). Enable:
- **Cancellation** — mode "at end of billing period" (matches the app: keep access
  to period end, downgrade on final `subscription.deleted`).
- **Payment method update.**
- **Invoice history** (on by default) — this is the customer's receipts/refunds view,
  so the app doesn't reimplement it.

**⚠️ Plan-change gotcha (important):** `checkout.php` refuses to start a *second*
Checkout for an existing subscriber (that would double-bill) and sends them to the
Portal instead. But the app uses **inline `price_data`, not saved Stripe Products**,
and the Portal's "switch plan" feature needs **real Products/Prices** configured.
So either:
- **(a)** Create matching **Products + Prices** in Stripe (Pro monthly/yearly,
  Premium monthly/yearly) and enable them under Portal → "Subscriptions → switch
  plans" — then upgrades/downgrades work in the Portal with proration; **or**
- **(b)** Leave plan-switching off — the Portal then offers cancel + update-card
  only, and a user changes tier by cancelling and re-subscribing.
Pick (a) for a smooth upgrade path before you push paid plans hard.

## 5. Dispute / chargeback prevention (Dashboard — no code, highest ROI)
A chargeback costs **~A$25 (non-refundable even if you win)** — at a $7–12 ASP,
prevention matters more than fighting. See `PAYMENTS.md` / the billing block in
`dashboard.php`.
1. **Statement descriptor** = `SNIP.APP` (Settings → Public details / account) —
   must match `STATEMENT_DESCRIPTOR` in `.env`. 5–22 chars, ≥5 letters, no `< > ' "`.
   Unrecognised charges are the #1 dispute cause.
2. **Email receipts** — Settings → Customer emails → successful payments: **on**.
3. **Upcoming-renewal reminder emails** — Billing settings: **on** (kills "surprise
   renewal" disputes).
4. **Failed-payment (dunning) emails + Smart Retries** — Billing → Revenue recovery:
   **on**.
5. **Radar** — confirm the free default rules are on (block highest risk, review
   elevated). Later: 3DS on first charge for unseen cards, exempting off-session
   renewals.

## 6. Tax / GST (Australia)
- **You don't need GST until turnover ≥ A$75,000/yr.** Below that, don't charge or
  collect GST.
- When you register: turn on **Stripe Tax** (calculates + collects; you still file/
  remit yourself), add your **ABN**, and enable **tax invoices** (must show ABN + GST).
  Then switch prices to **GST-inclusive** display and consider pricing in **AUD**
  (the code currently prices in USD).
- Stripe AU's own fee rates are already GST-inclusive.

## 7. Local testing (test mode)
```bash
# forward live events to your local app and get a test whsec_
stripe listen --forward-to localhost:8088/stripe-webhook
# put the printed whsec_… in .env (test), set test sk_/rk_ + pk_test_, restart
```
Then: register → verify email → upgrade → pay with test card `4242 4242 4242 4242`
→ confirm the plan activates and the dashboard "Billing" block shows the renewal
date; cancel via the Portal → confirm "Cancelling — access until …".

## 8. How the app behaves (so config matches expectations)
- **Activation** only on a `complete` + `paid` (or `no_payment_required` promo)
  session with an `active`/`trialing` subscription (`billing-success.php`,
  `stripe-webhook.php`). Promo codes are enabled (`allow_promotion_codes`).
- **Cancellation** = keep access to period end; downgrade on `subscription.deleted`.
  **No refund on cancel** — ACL-compliant; see `/refund` (`refund.php`).
- **Plan changes** for existing subscribers go via the Portal (§4), never a 2nd
  Checkout — this prevents double-billing.
- **Never set `payment_method_types`** in Checkout — the app omits it so Stripe shows
  dynamic payment methods (configure those in the Dashboard).

## 9. Go-live checklist (Stripe portion, in order)
- [ ] Business/ABN done, **Stripe AU account activated** (see `BUSINESS-SETUP.md`).
- [ ] LIVE **restricted key** (§2) + **publishable key** → `.env`.
- [ ] **Webhook** endpoint at `https://jmpz.cc/stripe-webhook` (4 events) → `whsec_` in `.env`.
- [ ] **Customer Portal** configured (cancel at period end, update card, invoices; plan-switch if doing §4a).
- [ ] Dispute-prevention settings (§5): descriptor, receipts, reminders, dunning, Radar.
- [ ] `STATEMENT_DESCRIPTOR` in `.env` matches the Dashboard descriptor.
- [ ] End-to-end test on the live site with a real card, then refund it from the Dashboard.
- [ ] (At A$75k) Stripe Tax + ABN + tax invoices + GST-inclusive pricing.

---
*Snip pins the Stripe API version in `config.php`; bump it deliberately after testing.*
