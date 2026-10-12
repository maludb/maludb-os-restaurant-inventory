#!/usr/bin/env python3
"""The forecaster (docs/build-specs/projection-and-ordering.md §1.2): additive Holt-Winters with a weekly cycle, in pure Python (no NumPy —
this VM has no AVX for the wheels that need it and the data is small), per menu item.

It is a PURE function of its input: PHP (app/features/projection/write.php, run_forecast()) reads the history from the database, shells here
with the series on stdin, and writes the run itself — so this script holds no connection, no credential and no write (build spec division of
labour: Python forecasts menu items, SQL does everything after). One JSON document in, one JSON line out, exit 1 with a JSON error on failure.

  stdin  {"as_of": "YYYY-MM-DD", "horizon": 21, "closed_future": ["YYYY-MM-DD", ...],
          "items": [{"menu_item_id": 7, "start": "YYYY-MM-DD", "y": [12, 15, null, ...]}]}
         y runs day by day from `start` to the day before `as_of`; null = a day the fit must skip (a closed day, a day the site recorded no
         sales at all). The series starts at the item's first sale. An item with fewer than 84 days (twelve weeks) of series is NOT forecast —
         it is listed under "skipped" and the SQL baseline answers for it.
  stdout {"as_of", "horizon", "items": {"7": {"alpha", "beta", "gamma", "mae28", "mae28_default", "sigma", "n_obs", "days": [{"on_date", "qty", "lo", "hi"}]}},
          "skipped": [{"menu_item_id", "reason"}]}

  L_t = a (y_t - S_{t-7}) + (1-a)(L_{t-1} + b_{t-1});  b_t = B (L_t - L_{t-1}) + (1-B) b_{t-1};  S_t = g (y_t - L_t) + (1-g) S_{t-7}
  F_{t+k} = L_t + k b_t + S_{t+k-7(h+1)}   (h = (k-1) // 7)
Initialisation from the first two weeks (L_0 = mean of week 1, b_0 = (mean week 2 - mean week 1) / 7, S_i = y_i - L_0 for the first seven days);
a, B, g by a grid over {0.1..0.9 step 0.1} x {0, 0.05, 0.1, 0.2} x {0.1, 0.3, 0.5, 0.7, 0.9} minimising the one-step MAE of the last 28 days; sigma the
sample standard deviation of those 28 one-step residuals; lo/hi = F -/+ 1.645 sigma, clipped at 0; negatives clipped at 0. Days a closure covers
forecast 0.
"""
import argparse
import datetime
import json
import math
import sys

MIN_DAYS = 84                                  # twelve weeks
ALPHAS = [i / 10 for i in range(1, 10)]
BETAS = [0.0, 0.05, 0.1, 0.2]
GAMMAS = [0.1, 0.3, 0.5, 0.7, 0.9]
DEFAULT = (0.3, 0.3, 0.3)
Z90 = 1.645                                    # a 90 % two-sided band


def _mean(xs):
    xs = [x for x in xs if x is not None]
    return sum(xs) / len(xs) if xs else None


def init_state(y):
    m1, m2 = _mean(y[0:7]), _mean(y[7:14])
    if m1 is None or m2 is None:
        return None
    return m1, (m2 - m1) / 7.0, [(v - m1) if v is not None else 0.0 for v in y[0:7]]


def fit(y, a, b, g, init):
    """Run the recursion over y from day 7; returns (level, trend, seasonal list, [(t, one-step error)])."""
    level, trend, seasonal = init[0], init[1], list(init[2])
    errs = []
    for t in range(7, len(y)):
        s_prev = seasonal[t - 7]
        v = y[t]
        if v is None:                          # skipped: the level moves on by the trend, the seasonal figure stays
            level += trend
            seasonal.append(s_prev)
            continue
        errs.append((t, v - (level + trend + s_prev)))
        new_level = a * (v - s_prev) + (1 - a) * (level + trend)
        trend = b * (new_level - level) + (1 - b) * trend
        seasonal.append(g * (v - new_level) + (1 - g) * s_prev)
        level = new_level
    return level, trend, seasonal, errs


def mae_last(errs, n, days=28):
    tail = [abs(e) for t, e in errs if t >= n - days]
    return (sum(tail) / len(tail)) if tail else None, len(tail)


def forecast_item(item, as_of, horizon, closed_future):
    y = item["y"]
    n = len(y)
    first = next((i for i, v in enumerate(y) if v is not None), None)
    if first is None:
        return None, "no sales"
    y = y[first:]
    n = len(y)
    if n < MIN_DAYS:
        return None, "fewer than twelve weeks of history"
    init = init_state(y)
    if init is None:
        return None, "a missing week at the start"
    best = None
    for a in ALPHAS:
        for b in BETAS:
            for g in GAMMAS:
                _, _, _, errs = fit(y, a, b, g, init)
                m, k = mae_last(errs, n)
                if m is not None and (best is None or m < best[0] - 1e-12):
                    best = (m, a, b, g)
    _, _, _, derrs = fit(y, *DEFAULT, init)
    dmae, _ = mae_last(derrs, n)
    if best is None:
        return None, "too few recent days to fit"
    mae, a, b, g = best
    level, trend, seasonal, errs = fit(y, a, b, g, init)
    resid = [e for t, e in errs if t >= n - 28]
    if len(resid) > 1:
        mu = sum(resid) / len(resid)
        sigma = math.sqrt(sum((e - mu) ** 2 for e in resid) / (len(resid) - 1))
    else:
        sigma = 0.0
    days = []
    for k in range(1, horizon + 1):
        on = as_of + datetime.timedelta(days=k - 1)
        if on.isoformat() in closed_future:
            days.append({"on_date": on.isoformat(), "qty": 0.0, "lo": 0.0, "hi": 0.0})
            continue
        idx = (n - 1) + k - 7 * ((k - 1) // 7 + 1)
        f = max(level + k * trend + seasonal[idx], 0.0)
        days.append({"on_date": on.isoformat(), "qty": round(f, 4), "lo": round(max(f - Z90 * sigma, 0.0), 4), "hi": round(f + Z90 * sigma, 4)})
    return {"alpha": a, "beta": b, "gamma": g, "mae28": round(mae, 4), "mae28_default": None if dmae is None else round(dmae, 4),
            "sigma": round(sigma, 4), "n_obs": sum(1 for v in y if v is not None), "days": days}, None


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--as-of", help="the first day forecast (default: the document's as_of)")
    ap.add_argument("--horizon", type=int, help="days to forecast (default: the document's horizon, 21)")
    args = ap.parse_args()
    try:
        doc = json.load(sys.stdin)
        as_of = datetime.date.fromisoformat(args.as_of or doc["as_of"])
        horizon = int(args.horizon or doc.get("horizon") or 21)
        if not 1 <= horizon <= 92:
            raise ValueError("the horizon is 1 to 92 days")
        closed = set(doc.get("closed_future") or [])
        out = {"as_of": as_of.isoformat(), "horizon": horizon, "items": {}, "skipped": []}
        for item in doc.get("items") or []:
            res, why = forecast_item(item, as_of, horizon, closed)
            if res is None:
                out["skipped"].append({"menu_item_id": item["menu_item_id"], "reason": why})
            else:
                out["items"][str(item["menu_item_id"])] = res
        print(json.dumps(out, separators=(",", ":")))
    except Exception as e:                      # one JSON line, exit 1: the PHP caller logs the sentence
        print(json.dumps({"error": str(e)[:300]}))
        sys.exit(1)


if __name__ == "__main__":
    main()
