# Registering the business (Australia) → getting Stripe live

How a single person registers a business in Australia and the cheapest/fastest
path to activate Stripe for Snip/Moonxt. Companion to `PAYMENTS.md` (processor
choice) and `DEPLOY.md` (deploy runbook).

> **General information only — not legal or tax advice.** ASIC fees are
> CPI-indexed every 1 July; reconfirm current amounts with ASIC/ATO or an
> accountant before you rely on them. Figures below are **FY2026-27 (from 1 July
> 2026)**.

## Can one person register a business? Yes — two options

- **Sole trader** — you register a free ABN in your own name. No partners,
  directors, or shareholders. Fastest and cheapest. **Recommended starting point.**
- **Proprietary company (Pty Ltd)** — Australia allows a **one-person company**:
  the same individual is sole director *and* sole shareholder. Needs a free
  **Director ID** first, and at least one director ordinarily resident in Australia.
  More cost/admin, but limited liability.

## Costs (AUD, GST-free)

| Item | Cost |
|---|---|
| **ABN** | **Free** |
| TFN (sole trader uses personal TFN) | Free |
| GST registration | Free (only required at **$75,000/yr** turnover) |
| **Business name "Moonxt"** (ASIC) — only if trading under a brand vs your own name | **$47 / 1yr** or **$108 / 3yr** |
| Director ID | Free |
| Pty Ltd registration (one-off, ASIC) | **$636** |
| Pty Ltd annual review fee | **$342 / year** |

**Total to get started:**
- **Sole trader, own legal name:** **$0**
- **Sole trader, trading as "Moonxt":** **~$47** (or $108 for 3 years)
- **Pty Ltd, DIY:** **$636** upfront + **$342/yr** (≈$735–850 via Lawpath/Cleardocs; add ~$1–2k/yr accountant realistically)

## Best setup for Stripe — sole trader + free ABN is enough

**Stripe Australia fully accepts sole traders.** At signup you pick business type
**"Individual / Sole trader"** and enter your ABN + personal ID. **No company and
no ACN required** (a sole trader can't even get an ACN — it only exists for
companies). This is the same-day path to taking payments.

### Step-by-step (minimum spend ~$47, or $0 under your own name)

1. **Get a free ABN** at [business.gov.au](https://business.gov.au) → Australian
   Business Register → entity type **"Individual/Sole Trader"**. Have your TFN + ID
   ready. Issued instantly in most cases (~15–30 min).
2. **Register the business name "Moonxt"** with ASIC (same business.gov.au flow) —
   **$47/1yr or $108/3yr.** Skip only if you'd trade under your own legal name.
3. **Skip GST** for now — only mandatory once turnover reaches/expects **$75k/yr**.
   Free to add later via the same portal.
4. **Open a business transaction account in your name** (Up Business, Tyro, Wise
   Business, or a big-four business account — usually free/low fee). A personal
   account technically works for Stripe payouts, but big-four personal terms
   restrict commercial use and can flag frequent gateway deposits.
5. **Sign up at Stripe** ([dashboard.stripe.com/register](https://dashboard.stripe.com/register))
   → business type **"Individual / Sole trader"** → enter ABN + personal KYC (name,
   DOB, residential address, ID) + the payout bank account. The ABN isn't strictly
   mandatory for the individual type but strongly speeds verification. The Stripe
   account-holder name must **match the bank account** — for a sole trader that's
   your legal name (the sole trader *is* you), so a same-name account passes.

## When to upgrade to a Pty Ltd

The main downside of sole trader is **unlimited personal liability** — you and the
business are one legal entity, so business debts/chargebacks/claims can reach your
personal assets. For a low-risk digital subscription product this is modest early
on (and PI/public-liability insurance mitigates it). Incorporate once **any** apply:

- **Liability** — real revenue, enterprise contracts, or chargeback exposure you
  don't want reaching personal assets. A Pty Ltd is a separate legal entity
  (caveats: personal guarantees, insolvent trading, Director Penalty Notices for
  unpaid PAYG/super/GST, your own negligence).
- **Tax** — company profit is taxed at a flat **25%** small-business rate and can be
  retained in the company; above the mid personal brackets that can beat your
  marginal rate. (A company has *no* tax-free threshold, so at very low profit a
  sole trader is cheaper.)
- **Credibility / growth** — B2B/enterprise buyers, partners, and investors take
  "Moonxt Pty Ltd" more seriously; shares make adding co-founders/raising easy.

This matches the intent to run Snip under **Moonxt Pty Ltd** — incorporating is the
eventual destination, just not a launch-day requirement. When you do, re-onboard
Stripe as business type **"Company"** with both ABN + ACN and a bank account in the
**company's** name.

## "Do this" checklist — minimum to take Stripe payments

- [ ] Register a **free ABN** as Individual/Sole Trader — **$0**
- [ ] Register the **"Moonxt" business name** with ASIC — **$47** (1yr)
- [ ] Skip GST until turnover nears **$75k/yr** — **$0**
- [ ] Open a **business transaction account in your name** — usually **$0**
- [ ] Sign up for **Stripe** as "Individual / Sole trader" (ABN + ID + bank) — **$0**

**Minimum to be live on Stripe: ~$47 AUD.** Move to a Pty Ltd later (~$636 + $342/yr)
once liability, tax, or credibility justify it.

---

*Researched 2026-07-12. Reconfirm ASIC/ATO fees + Stripe onboarding before relying on them.*
