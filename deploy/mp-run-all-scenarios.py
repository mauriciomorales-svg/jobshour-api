#!/usr/bin/env python3
import json
import subprocess
import uuid

PK = "APP_USR-6b951956-4cd6-451e-bfbb-ec1ce3913a13"
AT = "APP_USR-7599981164195226-022214-73aa16d2f786119e6cba519d467cd5cb-1750453149"
EMAIL = "comprador_prueba_jobshours@gmail.com"

SCENARIOS = [
    ("APRO", {}, ["approved", "authorized"]),
    ("OTHE", {}, ["rejected"]),
    ("CONT", {}, ["pending", "in_process", "rejected"]),
    ("CALL", {}, ["rejected", "pending"]),
    ("FUND", {}, ["rejected"]),
    ("SECU", {"security_code": "000"}, ["rejected"]),
    ("EXPI", {"expiration_year": "2020", "expiration_month": "01"}, ["rejected"]),
    ("FORM", {}, ["rejected"]),
    ("INST", {"installments": 99}, ["rejected"]),
    ("LOCK", {}, ["rejected"]),
    ("CTNA", {}, ["rejected"]),
    ("ATTE", {}, ["rejected"]),
    ("BLAC", {}, ["rejected"]),
    ("UNSU", {}, ["rejected"]),
    ("TEST", {"amount": 1000}, ["rejected", "approved", "authorized"]),
]


def curl(method, url, headers=None, data=None):
    cmd = ["curl", "-sS", "-X", method, url]
    if headers:
        for k, v in headers.items():
            cmd += ["-H", f"{k}: {v}"]
    if data is not None:
        cmd += ["-H", "Content-Type: application/json", "-d", json.dumps(data)]
    return json.loads(subprocess.check_output(cmd, text=True))


def jh_status(mp_status):
    if mp_status == "approved":
        return "completed"
    if mp_status == "authorized":
        return "pending"
    if mp_status in ("rejected", "cancelled"):
        return "failed"
    if mp_status in ("pending", "in_process"):
        return "pending (sync no actualiza)"
    return "?"


print("Credenciales: cuenta real MP (test) app 7599981164195226")
print("-" * 110)
print(f"{'Holder':6} {'MP status':12} {'Detail':36} {'JH map':22} {'OK'}")
print("-" * 110)

passed = 0
rows = []

for holder, overrides, expected in SCENARIOS:
    card = {
        "card_number": "4168818844447115",
        "expiration_year": overrides.get("expiration_year", "2030"),
        "expiration_month": overrides.get("expiration_month", "11"),
        "security_code": overrides.get("security_code", "123"),
        "cardholder": {
            "name": holder,
            "identification": {"type": "Otro", "number": "123456789"},
        },
    }
    tok = curl("POST", f"https://api.mercadopago.com/v1/card_tokens?public_key={PK}", data=card)
    if not tok.get("id"):
        rows.append((holder, "token_fail", tok.get("message", ""), "-", "FAIL"))
        continue

    payload = {
        "transaction_amount": overrides.get("amount", 1080),
        "token": tok["id"],
        "description": "JobsHours scenario test",
        "installments": overrides.get("installments", 1),
        "payment_method_id": "visa",
        "capture": True,
        "payer": {
            "email": EMAIL,
            "identification": {"type": "Otro", "number": "123456789"},
        },
    }
    pay = curl(
        "POST",
        "https://api.mercadopago.com/v1/payments",
        {
            "Authorization": f"Bearer {AT}",
            "X-Idempotency-Key": str(uuid.uuid4()),
        },
        payload,
    )
    mp = pay.get("status") or "error"
    detail = pay.get("status_detail") or pay.get("message") or ""
    ok = mp in expected
    if ok:
        passed += 1
    rows.append((holder, mp, detail, jh_status(mp), "PASS" if ok else "FAIL"))

for r in rows:
    print(f"{r[0]:6} {str(r[1]):12} {str(r[2])[:36]:36} {str(r[3]):22} {r[4]}")

card_fail = curl(
    "POST",
    f"https://api.mercadopago.com/v1/card_tokens?public_key={PK}",
    data={
        "card_number": "",
        "expiration_year": "2030",
        "expiration_month": "11",
        "security_code": "123",
        "cardholder": {"name": "CARD", "identification": {"type": "Otro", "number": "123456789"}},
    },
)
card_ok = not card_fail.get("id")
print(f"{'CARD':6} {'token_fail':12} {str(card_fail.get('message',''))[:36]:36} {'n/a':22} {'PASS' if card_ok else 'FAIL'}")
if card_ok:
    passed += 1

tok = curl(
    "POST",
    f"https://api.mercadopago.com/v1/card_tokens?public_key={PK}",
    data={
        "card_number": "4168818844447115",
        "expiration_year": "2030",
        "expiration_month": "11",
        "security_code": "123",
        "cardholder": {"name": "APRO", "identification": {"type": "Otro", "number": "123456789"}},
    },
)
idem = str(uuid.uuid4())
payload = {
    "transaction_amount": 1080,
    "token": tok["id"],
    "description": "dupl",
    "installments": 1,
    "payment_method_id": "visa",
    "capture": True,
    "payer": {"email": EMAIL, "identification": {"type": "Otro", "number": "123456789"}},
}
p1 = curl("POST", "https://api.mercadopago.com/v1/payments", {"Authorization": f"Bearer {AT}", "X-Idempotency-Key": idem}, payload)
p2 = curl("POST", "https://api.mercadopago.com/v1/payments", {"Authorization": f"Bearer {AT}", "X-Idempotency-Key": idem}, payload)
dupl_ok = p1.get("status") in ("approved", "authorized", "rejected") and p2.get("id") == p1.get("id")
print(f"{'DUPL':6} {str(p2.get('status','?')):12} {str(p2.get('status_detail','idem'))[:36]:36} {jh_status(p2.get('status','')):22} {'PASS' if dupl_ok else 'FAIL'}")
if dupl_ok:
    passed += 1

total = len(SCENARIOS) + 2
print("-" * 110)
print(f"Resumen: {passed}/{total} escenarios OK")

print("\nComparativa app nueva (9415328210117) — APRO:")
pk2 = "APP_USR-e371c604-bf2c-4857-b08c-a48442a63f2d"
at2 = "APP_USR-9415328210117-052712-247db62ac11f584e1bfe6986de288972-3218634427"
tok2 = curl(
    "POST",
    f"https://api.mercadopago.com/v1/card_tokens?public_key={pk2}",
    data={
        "card_number": "4168818844447115",
        "expiration_year": "2030",
        "expiration_month": "11",
        "security_code": "123",
        "cardholder": {"name": "APRO", "identification": {"type": "Otro", "number": "123456789"}},
    },
)
if tok2.get("id"):
    pay2 = curl(
        "POST",
        "https://api.mercadopago.com/v1/payments",
        {"Authorization": f"Bearer {at2}", "X-Idempotency-Key": str(uuid.uuid4())},
        {
            "transaction_amount": 1080,
            "token": tok2["id"],
            "description": "new app",
            "installments": 1,
            "payment_method_id": "visa",
            "capture": True,
            "payer": {"email": EMAIL},
        },
    )
    print("  status=", pay2.get("status"), "message=", pay2.get("message"))
else:
    print("  token fail", tok2)
