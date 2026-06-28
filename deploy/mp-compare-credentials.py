#!/usr/bin/env python3
"""Compare MP credential pairs for test card payment."""
import json
import subprocess
import sys
import uuid

PAIRS = [
    {
        "name": "app_9415328210117 (actual VPS)",
        "pk": "APP_USR-e371c604-bf2c-4857-b08c-a48442a63f2d",
        "at": "APP_USR-9415328210117-052712-247db62ac11f584e1bfe6986de288972-3218634427",
    },
    {
        "name": "app_7599981164195226 (historico)",
        "pk": "APP_USR-6b951956-4cd6-451e-bfbb-ec1ce3913a13",
        "at": "APP_USR-7599981164195226-022214-73aa16d2f786119e6cba519d467cd5cb-1750453149",
    },
    {
        "name": "swapped_9415328210117",
        "pk": "APP_USR-9415328210117-052712-247db62ac11f584e1bfe6986de288972-3218634427",
        "at": "APP_USR-e371c604-bf2c-4857-b08c-a48442a63f2d",
    },
]


def curl_json(method, url, headers=None, data=None):
    cmd = ["curl", "-sS", "-X", method, url]
    if headers:
        for k, v in headers.items():
            cmd += ["-H", f"{k}: {v}"]
    if data is not None:
        cmd += ["-H", "Content-Type: application/json", "-d", json.dumps(data)]
    out = subprocess.check_output(cmd, text=True)
    try:
        return json.loads(out)
    except json.JSONDecodeError:
        return {"raw": out}


for pair in PAIRS:
    me = curl_json("GET", "https://api.mercadopago.com/users/me", {"Authorization": f"Bearer {pair['at']}"})
    token_body = {
        "card_number": "4168818844447115",
        "expiration_year": "2030",
        "expiration_month": "11",
        "security_code": "123",
        "cardholder": {"name": "APRO", "identification": {"type": "Otro", "number": "123456789"}},
    }
    tok = curl_json(
        "POST",
        f"https://api.mercadopago.com/v1/card_tokens?public_key={pair['pk']}",
        data=token_body,
    )
    pay = {"token_ok": False, "status": None, "message": None}
    if tok.get("id"):
        pay_resp = curl_json(
            "POST",
            "https://api.mercadopago.com/v1/payments",
            {
                "Authorization": f"Bearer {pair['at']}",
                "X-Idempotency-Key": str(uuid.uuid4()),
            },
            {
                "transaction_amount": 1080,
                "token": tok["id"],
                "description": "compare",
                "installments": 1,
                "payment_method_id": "visa",
                "capture": False,
                "payer": {"email": "buyer-test-jobshours@mailinator.com"},
            },
        )
        pay = {
            "token_ok": True,
            "status": pay_resp.get("status") or pay_resp.get("error"),
            "message": pay_resp.get("message") or (pay_resp.get("cause") or [{}])[0].get("description"),
            "mp_status": pay_resp.get("status"),
            "detail": pay_resp.get("status_detail"),
        }
    print(
        pair["name"],
        "| user=",
        me.get("id"),
        "| nick=",
        (me.get("nickname") or "")[:20],
        "| tok=",
        "ok" if tok.get("id") else tok.get("message"),
        "| pay=",
        pay,
    )
