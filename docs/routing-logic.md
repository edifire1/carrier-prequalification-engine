# Prequalification Engine Decision Matrix

This document outlines the operational rules and decision boundary executed inside the JavaScript Code Node of the prequalification engine.

---

## 1. Input Variables

| Field | Source Type | Values | Description |
| :--- | :--- | :--- | :--- |
| `mc_dot` | Text String | e.g. `MC-123456` | Operating authority or USDOT number |
| `authority_age` | Radio Selection | `under_90_days`, `over_90_days` | Age of operating authority |
| `equipment` | Radio Selection | Dry Van, Reefer, Flatbed, Box Truck, Hotshot, Fleet | Equipment category |
| `monthly_gross` | Radio Selection | `under_10k`, `10k_25k`, `25k_50k_plus` | Estimated monthly freight billings |
| `factoring_status` | Radio Selection | `not_factoring`, `rate_too_high`, `locked_contract`, `exploring` | Current cash flow situation |

---

## 2. Decision Logic

```javascript
let qualifiedTier = 'SUMMAR';
let reason = 'Qualified for Summar Financial premium non-recourse credit protection and same-day funding.';

// Route to BasicBlock if ANY of these conditions are met:
if (authority_age === 'under_90_days' || monthly_gross === 'under_10k' || factoring_status === 'locked_contract') {
    qualifiedTier = 'BASICBLOCK';
    reason = 'Best match for BasicBlock 2.5% flat non-recourse rate with zero contracts and $5 same-day RTP.';
}
```

---

## 3. Route Rationales

### Why Divert New Authorities (<90 Days)?
Traditional factors like Summar Financial require verifiable commercial broker history. New authorities (<90 days) cannot pass credit underwriting without heavy collateral or personal guarantees. BasicBlock is explicitly built for new authorities.

### Why Divert Carriers Grossing Under $10,000/Month?
Institutional factoring desks charge minimum monthly fees ($100-$300/mo) if volume drops below thresholds. For part-time truckers or carriers starting out, minimums eat into profit margins. BasicBlock charges zero minimums.

### Why Divert Carriers Locked in Contracts?
Drivers who have experienced restrictive 12-month lock-in agreements often suffer from "contract phobia". BasicBlock's "Cancel Anytime / No Contract" model eliminates objections immediately.
