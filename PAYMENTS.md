# Payments — processor choice & Stripe setup (Australia)

Snip/Moonxt is an **Australian** business (Pty Ltd) selling low-priced
subscriptions (Pro ~A$7/mo, Premium ~A$12/mo, yearly = 12% off, Enterprise =
contact sales). The app already has a **complete Stripe integration** — hosted
Checkout Sessions (subscription mode), Customer Portal, and signature-verified
webhooks in `inc/stripe.php`. This doc records the payments decision and the
Stripe registration checklist.

> **Bottom line:** keep **Stripe** (it's built, AU-native, and cheapest of the
> mature subscription options at this price point) → add **Stripe Tax** to
> collect GST/VAT correctly → offer **PayPal** only if checkout data justifies
> it → consider a Merchant-of-Record (ideally **Stripe Managed Payments**) only
> once global tax *filing* becomes a real burden.

## What Stripe requires to register (Australia)

To activate a Stripe account for **Moonxt Pty Ltd**:

- **ABN** (+ **ACN** since it's a Pty Ltd) — Stripe verifies both against the ABR.
- An **Australian AUD business bank account** (BSB + account number) for payouts.
- **Business details**: legal/trading name, registered address, phone, industry
  (MCC), and a **live product URL** describing what you sell.
- **Beneficial-owner + representative KYC**: full name, DOB, residential address,
  job title, and ≥25% ownership for each owner, plus **government photo ID**
  (passport/licence) on request.
- A **public website** carrying pricing, terms, refund policy and contact info —
  Stripe reviews it before/shortly after activation.

No setup or monthly fee for standard Payments. **Timeline:** the account is
usable almost immediately once submitted info verifies, but the **first payout is
held ~7–14 days** after the first live charge while Stripe reviews the account;
steady state is **T+2 rolling payouts** in AUD. This is fully self-serve — no
invite, IEC, or cross-border approval (those are India-specific and do not apply).

## Comparison (effective fee on a A$7/mo sub — the fixed fee dominates at low ASP)

| Provider | Type | AU registration | Fee on **A$7** | Fee on **A$12** | Subscriptions | Who remits tax | Fit |
|---|---|---|---|---|---|---|---|
| **Stripe AU** (domestic card) | Gateway (you = MoR) | Medium (ABN/ACN, bank, KYC, site) | **~6.0%** (1.7%+$0.30); ~7% with Billing 0.5% + Stripe Tax 0.5% | ~4.2–4.7% | Best-in-class, **already built** | **You** (Stripe Tax calculates, never remits) | **Primary / incumbent** |
| Stripe AU (international card) | Gateway | (same account) | **~7.8%** (3.5%+$0.30) +2% if FX | ~5.4% | same | You | Primary (unavoidable for global cards) |
| PayPal AU (standard) | Gateway (you = MoR) | Low–Medium (ABN, bank, KYC) | **~7.2%** (2.9%+$0.30) | ~5.4% | Weaker (clunky Subscriptions API, no polished portal/dunning) | You | **Secondary add-on** only |
| Paddle / Lemon Squeezy | **Merchant-of-Record** | High (KYB/UBO, ID, AUP review) | **~16%** (5%+US$0.50≈A$0.75) | ~11% | Full stack, but **replaces Stripe code** | **MoR remits** (AU GST + global VAT) | Weak at this ASP |
| Square AU | Gateway (you = MoR) | Low | **~2.2%** flat (no cent fee) | ~2.2% | Retail-shaped, **AUD-only** | You | Only if ~all customers are Australian |
| Airwallex | Gateway (you = MoR) | Medium–High | ~6.4% (+ poss. $29/mo) | ~4.5% | Real recurring; best multi-currency FX | You | Only if seriously multi-currency |
| GoCardless / PayTo | Gateway (bank debit) | Medium + per-mandate | ~6.7% (cap $4) | ~3.3% | Bank-debit recurring only, no cards | You | Secondary for the $12 tier |
| Zeller / Wise | POS / FX account | — | n/a | n/a | **No online SaaS recurring** | You | **Exclude** |

### Effective-fee reality check — A$7/mo

| Rail | Fee math | Taken | % lost | Net |
|---|---|---|---|---|
| **Stripe domestic (bare)** | 1.7% + $0.30 | **$0.42** | **6.0%** | $6.58 |
| Stripe domestic + Billing + Tax | +0.5% +0.5% | ~$0.49 | ~7.0% | $6.51 |
| Stripe **international** card | 3.5% + $0.30 | $0.55 | 7.8% | $6.45 |
| PayPal standard | 2.9% + $0.30 | $0.50 | 7.2% | $6.50 |
| PayPal Micropayments (opt-in) | 5% + $0.05 | $0.40 | 5.7% | $6.60 |
| **MoR (Paddle / Lemon Squeezy)** | 5% + US$0.50 (~A$0.75) | **$1.10** | **15.7%** | **$5.90** |
| Square (AUD-only) | 2.2% flat | $0.15 | 2.2% | $6.85 |

The flat per-transaction fee is the whole game at A$7: Stripe and PayPal sit in a
tight ~6–7% band; a Merchant-of-Record costs **2–2.5× more (~16%)** because of its
fixed ~US$0.50. MoR economics only start to work at much higher ASP.

## Recommendation

1. **Stay on Stripe. Do not rebuild.** The integration is written, tested, and
   AU-native (AUD payouts, ABN onboarding, GST-inclusive displayed rates). Time-to-
   launch and zero migration cost win.
2. **Add Stripe Tax** (~0.5%/txn) so you *collect* correct GST/VAT worldwide from
   day one and get filing-ready reports (pushes domestic all-in to ~7%). Avoids a
   painful retrofit later.
3. **PayPal — add-on, not replacement.** Only if checkout drop-off shows buyers
   won't card an unknown brand. Separate build; slightly worse fees at this ASP.
4. **Merchant-of-Record** (Paddle preferred over Lemon Squeezy, which Stripe
   acquired) — only worth the ~16% if worldwide VAT/GST *remittance* becomes a real
   operational burden. **Better path: Stripe Managed Payments** (Stripe's own MoR,
   launched Feb 2026) — layers onto the *existing* Stripe account and offloads tax
   remittance without ripping out the integration.

## Australia tax notes

- **GST is not required until turnover reaches A$75,000/yr.** Below that, don't
  charge or remit Australian GST at all — a genuine early-stage saving. Register
  with the ATO once you cross (or expect to within 12 months) the threshold; then
  charge 10% to AU customers and file BAS.
- **Stripe's AU rates are GST-inclusive** — the 10% is already inside 1.7%+A$0.30
  (unlike US Stripe pricing). Don't double-count it.
- **Stripe Tax calculates/collects but does not remit or assume liability.** As
  merchant of record you must register for GST/VAT in each foreign jurisdiction
  where you cross its threshold (EU/UK VAT, US state nexus, …) and file/pay
  yourself. This ongoing global-compliance burden is the strongest argument for an
  MoR — but at this ASP the fee cost outweighs it until international volume is large.
- **Invoicing:** GST-registered AU sellers must issue **tax invoices** showing the
  ABN and GST component; Stripe Billing/Invoicing can generate compliant ones.
  Income/company tax on payouts is always yours regardless of processor (an MoR
  only offloads *consumption* tax, never income tax).

---

*Researched 2026-07-12. Figures are current-as-of that date — reconfirm Stripe/PayPal/MoR
pricing pages before signing up, as processor fees change.*
