#!/usr/bin/env bash
# End-to-end verification of the reservation -> payment -> contract workflow.
API=${API:-http://localhost/api}
PASS=0; FAIL=0

login() {
  curl -s -X POST "$API/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "{\"email\":\"$1\",\"password\":\"$2\"}" | python -c "import sys,json;print(json.load(sys.stdin).get('token',''))" 2>/dev/null
}

# api METHOD PATH TOKEN [BODY]  ->  "STATUS\nBODY"
api() {
  local method=$1 path=$2 token=$3 body=${4:-}
  if [ -n "$body" ]; then
    curl -s -w '\n%{http_code}' -X "$method" "$API$path" -H 'Accept: application/json' \
      -H 'Content-Type: application/json' -H "Authorization: Bearer $token" -d "$body"
  else
    curl -s -w '\n%{http_code}' -X "$method" "$API$path" -H 'Accept: application/json' \
      -H "Authorization: Bearer $token"
  fi
}

# status of a call
st() { local r; r=$(api "$@"); echo "$r" | tail -n 1; }
# body of a call
bd() { local r; r=$(api "$@"); echo "$r" | head -n -1; }
# dotted path lookup in a json body
pick() { python -c "
import sys, json
try:
    cur = json.load(sys.stdin)
    for key in sys.argv[1].split('.'):
        if isinstance(cur, dict):
            cur = cur.get(key)
        elif isinstance(cur, list) and key.lstrip('-').isdigit():
            cur = cur[int(key)] if -len(cur) <= int(key) < len(cur) else None
        else:
            cur = None
        if cur is None:
            break
    print('' if cur is None else cur)
except Exception:
    print('')
" "$1" 2>/dev/null; }

check() { # label expected actual
  if [ "$2" = "$3" ]; then echo "  PASS  $1"; PASS=$((PASS+1));
  else echo "  FAIL  $1 (expected [$2], got [$3])"; FAIL=$((FAIL+1)); fi
}

# notification titles of a user, newline separated
titles() { bd GET /notifications "$1" | python -c "
import sys, json
d = json.load(sys.stdin)
rows = d.get('data', d) if isinstance(d, dict) else d
for r in rows: print(r['title'])" 2>/dev/null; }

has_title() { titles "$1" | grep -qx "$2" && echo yes || echo no; }

days() { python -c "import datetime;print((datetime.date.today()+datetime.timedelta(days=$1)).isoformat())"; }

echo "=================================================="
echo " 1. AUTHENTICATION"
echo "=================================================="
TENANT=$(login fatima@sakania.ma tenant123)
OWNER=$(login owner@sakania.ma owner123)
OTHER_OWNER=$(login e2e-owner@sakania.ma e2eowner123)
OTHER_TENANT=$(login tenant@sakania.ma tenant123)
check "tenant login"          yes "$([ -n "$TENANT" ] && echo yes || echo no)"
check "owner login"           yes "$([ -n "$OWNER" ] && echo yes || echo no)"
check "second owner login"    yes "$([ -n "$OTHER_OWNER" ] && echo yes || echo no)"
check "second tenant login"   yes "$([ -n "$OTHER_TENANT" ] && echo yes || echo no)"

PROP=$(bd GET /properties "$TENANT" | pick 'data.1.id')
PROP_TITLE=$(bd GET /properties "$TENANT" | pick 'data.1.title')
echo "  using property #$PROP ($PROP_TITLE)"
START=$(days 40)

echo
echo "=================================================="
echo " 2. RESERVATION CREATION"
echo "=================================================="
check "owner cannot create a reservation" 403 "$(st POST /reservations "$OWNER" "{\"property_id\":$PROP,\"start_date\":\"$START\",\"duration_months\":6}")"
check "past start date rejected" 422 "$(st POST /reservations "$TENANT" "{\"property_id\":$PROP,\"start_date\":\"2020-01-01\",\"duration_months\":6}")"

CREATE=$(api POST /reservations "$TENANT" "{\"property_id\":$PROP,\"start_date\":\"$START\",\"duration_months\":6}")
RES_ID=$(echo "$CREATE" | head -n -1 | pick 'reservation.id')
check "reservation created" 201 "$(echo "$CREATE" | tail -n 1)"
check "new reservation is pending" pending "$(echo "$CREATE" | head -n -1 | pick 'reservation.status')"
check "reservation carries the tenant" "$(bd GET /user "$TENANT" | pick 'id')" "$(echo "$CREATE" | head -n -1 | pick 'reservation.tenant_id')"
check "reservation carries the property" "$PROP" "$(echo "$CREATE" | head -n -1 | pick 'reservation.property_id')"
check "end date = start + 6 months" "$(python -c "import datetime;d=datetime.date.fromisoformat('$START');m=d.month-1+6;y=d.year+m//12;print(datetime.date(y,m%12+1,d.day))")" \
  "$(echo "$CREATE" | head -n -1 | pick 'reservation.end_date' | cut -c1-10)"
check "duplicate request rejected" 422 "$(st POST /reservations "$TENANT" "{\"property_id\":$PROP,\"start_date\":\"$START\",\"duration_months\":6}")"
check "overlapping period rejected" 422 "$(st POST /reservations "$TENANT" "{\"property_id\":$PROP,\"start_date\":\"$(days 43)\",\"duration_months\":2}")"

echo
echo "=================================================="
echo " 3. NOTIFICATION: OWNER IS TOLD"
echo "=================================================="
check "owner notified of the new request" yes "$(has_title "$OWNER" 'New Reservation Request')"
check "tenant not notified of own request"  no  "$(has_title "$TENANT" 'New Reservation Request')"
check "other owner not notified"           no  "$(has_title "$OTHER_OWNER" 'New Reservation Request')"

echo
echo "=================================================="
echo " 4. PAYMENT BLOCKED BEFORE ACCEPTANCE"
echo "=================================================="
check "checkout blocked while pending" 422 "$(st GET /payments/checkout/$RES_ID "$TENANT")"
check "payment blocked while pending" 422 "$(st POST /payments/process "$TENANT" "{\"reservation_id\":$RES_ID,\"amount\":100,\"method\":\"card\"}")"
check "non tenant cannot pay" 403 "$(st POST /payments/process "$OWNER" "{\"reservation_id\":$RES_ID,\"amount\":100,\"method\":\"card\"}")"

echo
echo "=================================================="
echo " 5. OWNER ACCEPTS"
echo "=================================================="
check "unrelated owner cannot accept" 404 "$(st PUT /owner/reservations/$RES_ID/accept "$OTHER_OWNER")"
check "tenant cannot accept own request" 403 "$(st PUT /owner/reservations/$RES_ID/accept "$TENANT")"
check "owner accepts" 200 "$(st PUT /owner/reservations/$RES_ID/accept "$OWNER")"
check "accepted twice is rejected" 422 "$(st PUT /owner/reservations/$RES_ID/accept "$OWNER")"
check "status is accepted" accepted "$(bd GET /tenant/reservations "$TENANT" | python -c "
import sys, json
d = json.load(sys.stdin)
rows = d.get('data', d)
print([r for r in rows if r['id'] == $RES_ID][0]['status'])" 2>/dev/null)"
check "tenant notified of acceptance"      yes "$(has_title "$TENANT" 'Reservation Accepted')"
check "tenant told payment is required"   yes "$(has_title "$TENANT" 'Payment Required')"
check "owner not notified of own action"  no  "$(has_title "$OWNER" 'Reservation Accepted')"

echo
echo "=================================================="
echo " 6. PAYMENT (verified server side)"
echo "=================================================="
CO=$(bd GET /payments/checkout/$RES_ID "$TENANT")
TOTAL=$(echo "$CO" | pick 'total_amount')
DUE=$(echo "$CO" | pick 'amount_due')
RATE=$(bd GET /properties "$TENANT" | pick 'data.1.price_per_month')
echo "  rate=$RATE duration=$(echo "$CO" | pick 'duration_months') server_total=$TOTAL due=$DUE"
check "total = price x duration" yes "$(python -c "print('yes' if abs(float('$TOTAL') - float('$RATE')*6) < 0.01 else 'no:'+'$TOTAL')")"
check "overpayment rejected" 422 "$(st POST /payments/process "$TENANT" "{\"reservation_id\":$RES_ID,\"amount\":999999,\"method\":\"card\"}")"
check "another tenant cannot pay" 404 "$(st POST /payments/process "$OTHER_TENANT" "{\"reservation_id\":$RES_ID,\"amount\":100,\"method\":\"card\"}")"
check "invalid method rejected" 422 "$(st POST /payments/process "$TENANT" "{\"reservation_id\":$RES_ID,\"amount\":100,\"method\":\"bitcoin\"}")"
check "zero amount rejected" 422 "$(st POST /payments/process "$TENANT" "{\"reservation_id\":$RES_ID,\"amount\":0,\"method\":\"card\"}")"

HALF=$(python -c "print(round($TOTAL/2,2))")
P1=$(api POST /payments/process "$TENANT" "{\"reservation_id\":$RES_ID,\"amount\":$HALF,\"method\":\"card\"}")
P1_BODY=$(echo "$P1" | head -n -1)
P1_ID=$(echo "$P1_BODY" | pick 'payment.id')
echo "  partial: id=$P1_ID paid=$(echo "$P1_BODY" | pick 'payment.paid_amount') remaining=$(echo "$P1_BODY" | pick 'payment.remaining_balance') status=$(echo "$P1_BODY" | pick 'payment.status')"
check "partial payment stays pending" pending "$(echo "$P1_BODY" | pick 'payment.status')"
check "paid amount tracked" yes "$(python -c "print('yes' if abs(float('$(echo "$P1_BODY" | pick 'payment.paid_amount')') - $HALF) < 0.01 else 'no')")"
check "contract issued on the first payment" 1 "$(bd GET /tenant/contracts "$TENANT" | python -c "import sys,json;d=json.load(sys.stdin);rows=d.get('data',d);print(len([c for c in rows if c['reservation_id']==$RES_ID]))")"
check "first payment carries the contract" yes "$([ -n "$(echo "$P1_BODY" | pick 'contract.id')" ] && echo yes || echo no)"
check "first payment carries a receipt" yes "$([ -n "$(echo "$P1_BODY" | pick 'receipt.reference')" ] && echo yes || echo no)"
check "receipt reports the running totals" yes "$(python -c "print('yes' if abs(float('$(echo "$P1_BODY" | pick 'receipt.paid_amount')') - $HALF) < 0.01 else 'no')")"
check "receipt shows the balance left" yes "$(python -c "print('yes' if abs(float('$(echo "$P1_BODY" | pick 'receipt.remaining_balance')') - (float('$TOTAL') - $HALF)) < 0.01 else 'no')")"
CID=$(echo "$P1_BODY" | pick 'contract.id')
CONTRACT_FILE=$(echo "$P1_BODY" | pick 'contract.file_url')
FP1=$(curl -s -X GET "$API/contracts/$CID/download" -H "Authorization: Bearer $TENANT" | md5sum | cut -d' ' -f1)

REST=$(python -c "print(round($TOTAL-$HALF,2))")
P2=$(api POST /payments/process "$TENANT" "{\"reservation_id\":$RES_ID,\"amount\":$REST,\"method\":\"card\"}")
P2_BODY=$(echo "$P2" | head -n -1)
echo "  final:   id=$(echo "$P2_BODY" | pick 'payment.id') paid=$(echo "$P2_BODY" | pick 'payment.paid_amount') remaining=$(echo "$P2_BODY" | pick 'payment.remaining_balance') status=$(echo "$P2_BODY" | pick 'payment.status')"
check "same payment record reused" "$P1_ID" "$(echo "$P2_BODY" | pick 'payment.id')"
check "status becomes paid" paid "$(echo "$P2_BODY" | pick 'payment.status')"
check "paid amount equals total" yes "$(python -c "print('yes' if abs(float('$(echo "$P2_BODY" | pick 'payment.paid_amount')') - float('$TOTAL')) < 0.01 else 'no')")"
check "remaining balance is zero" yes "$(python -c "print('yes' if abs(float('$(echo "$P2_BODY" | pick 'payment.remaining_balance')')) < 0.01 else 'no')")"
check "transaction reference stored" yes "$([ -n "$(echo "$P2_BODY" | pick 'payment.transaction_ref')" ] && echo yes || echo no)"
check "duplicate payment rejected" 422 "$(st POST /payments/process "$TENANT" "{\"reservation_id\":$RES_ID,\"amount\":10,\"method\":\"card\"}")"
check "checkout blocked once settled" 422 "$(st GET /payments/checkout/$RES_ID "$TENANT")"
check "no new contract on the last payment" "" "$(echo "$P2_BODY" | pick 'contract.id')"
check "last payment carries a receipt" yes "$([ -n "$(echo "$P2_BODY" | pick 'receipt.reference')" ] && echo yes || echo no)"
check "receipt closes the balance" yes "$(python -c "print('yes' if abs(float('$(echo "$P2_BODY" | pick 'receipt.remaining_balance')')) < 0.01 else 'no')")"
check "receipt reference follows the reused payment record" "$(echo "$P1_BODY" | pick 'receipt.reference')" "$(echo "$P2_BODY" | pick 'receipt.reference')"
check "receipt covers the last installment" yes "$(python -c "print('yes' if abs(float('$(echo "$P2_BODY" | pick 'receipt.amount')') - float('$REST')) < 0.01 else 'no')")"
check "receipt settles the running total" yes "$(python -c "print('yes' if abs(float('$(echo "$P2_BODY" | pick 'receipt.paid_amount')') - float('$TOTAL')) < 0.01 else 'no')")"

echo
echo "=================================================="
echo " 7. NOTIFICATION: OWNER IS TOLD ABOUT PAYMENT"
echo "=================================================="
check "owner notified of payment"  yes "$(has_title "$OWNER" 'Payment Received')"
check "tenant notified of payment"  yes "$(has_title "$TENANT" 'Payment Processed')"
check "other owner not notified"    no  "$(has_title "$OTHER_OWNER" 'Payment Received')"

echo
echo "=================================================="
echo " 8. SINGLE CONTRACT ISSUED ON THE FIRST PAYMENT"
echo "=================================================="
echo "  contract #$CID"
check "contract generated automatically" yes "$([ -n "$CID" ] && echo yes || echo no)"
check "contract is active" active "$(echo "$P1_BODY" | pick 'contract.status')"
check "contract file attached" yes "$([ -n "$CONTRACT_FILE" ] && echo yes || echo no)"
check "one contract per reservation" 1 "$(bd GET /tenant/contracts "$TENANT" | python -c "import sys,json;d=json.load(sys.stdin);rows=d.get('data',d);print(len([c for c in rows if c['reservation_id']==$RES_ID]))")"
check "contract file not regenerated" "$FP1" "$(curl -s -X GET "$API/contracts/$CID/download" -H "Authorization: Bearer $TENANT" | md5sum | cut -d' ' -f1)"
check "owner sees the contract" "$CID" "$(bd GET /owner/contracts "$OWNER" | python -c "
import sys, json
rows = json.load(sys.stdin).get('data', [])
print([c for c in rows if c['reservation_id'] == $RES_ID][0]['id'])" 2>/dev/null)"
check "contract data for the dashboard" "$PROP" "$(bd GET /tenant/contracts/$CID "$TENANT" | pick 'property.id')"
check "owner notified of contract" yes "$(has_title "$OWNER" 'Contract Ready for Download')"
check "tenant notified of contract" yes "$(has_title "$TENANT" 'Contract Ready for Download')"
COUNT=$(titles "$OWNER" | grep -c 'Contract Ready for Download')
check "contract notification sent once" 1 "$COUNT"

echo
echo "=================================================="
echo " 9. CONTRACT DOWNLOAD (authorised only)"
echo "=================================================="
check "tenant download http" 200 "$(curl -s -o /tmp/tenant.pdf -w '%{http_code}' -X GET "$API/contracts/$CID/download" -H 'Accept: application/json' -H "Authorization: Bearer $TENANT")"
check "tenant file is a pdf" "%PDF" "$(head -c 4 /tmp/tenant.pdf)"
check "owner download http" 200 "$(curl -s -o /tmp/owner.pdf -w '%{http_code}' -X GET "$API/contracts/$CID/download" -H 'Accept: application/json' -H "Authorization: Bearer $OWNER")"
check "owner file is a pdf" "%PDF" "$(head -c 4 /tmp/owner.pdf)"
check "unrelated user blocked" 403 "$(st GET /contracts/$CID/download "$OTHER_OWNER")"
check "unauthenticated blocked" 401 "$(curl -s -o /dev/null -w '%{http_code}' -X GET "$API/contracts/$CID/download" -H 'Accept: application/json')"
check "unknown contract" 404 "$(st GET /contracts/99999/download "$OWNER")"

echo
echo "=================================================="
echo " 9b. PAYMENT RECEIPT (every payment, no new contract)"
echo "=================================================="
check "tenant receipt http" 200 "$(curl -s -o /tmp/receipt.pdf -w '%{http_code}' -X GET "$API/payments/$P1_ID/receipt" -H 'Accept: application/json' -H "Authorization: Bearer $TENANT")"
check "tenant receipt is a pdf" "%PDF" "$(head -c 4 /tmp/receipt.pdf)"
check "owner receipt http" 200 "$(curl -s -o /tmp/owner-receipt.pdf -w '%{http_code}' -X GET "$API/payments/$P1_ID/receipt" -H 'Accept: application/json' -H "Authorization: Bearer $OWNER")"
check "owner receipt is a pdf" "%PDF" "$(head -c 4 /tmp/owner-receipt.pdf)"
check "receipt served as an attachment" yes "$(curl -s -D - -o /dev/null -X GET "$API/payments/$P1_ID/receipt" -H "Authorization: Bearer $TENANT" | grep -ci 'content-disposition: attachment')"
check "receipt named after its reference" yes "$(curl -s -D - -o /dev/null -X GET "$API/payments/$P1_ID/receipt" -H "Authorization: Bearer $TENANT" | grep -ci 'sakania-receipt-RC-')"
check "contract still unique after receipt" 1 "$(bd GET /tenant/contracts "$TENANT" | python -c "import sys,json;d=json.load(sys.stdin);rows=d.get('data',d);print(len([c for c in rows if c['reservation_id']==$RES_ID]))")"
check "unrelated owner blocked from receipt" 403 "$(st GET /payments/$P1_ID/receipt "$OTHER_OWNER")"
check "unauthenticated receipt blocked" 401 "$(curl -s -o /dev/null -w '%{http_code}' -X GET "$API/payments/$P1_ID/receipt" -H 'Accept: application/json')"
check "unknown receipt" 404 "$(st GET /payments/99999/receipt "$OWNER")"

echo
echo "=================================================="
echo "10. REJECTION PATH"
echo "=================================================="
START2=$(days 300)
R2=$(api POST /reservations "$TENANT" "{\"property_id\":$PROP,\"start_date\":\"$START2\",\"duration_months\":3}")
R2_ID=$(echo "$R2" | head -n -1 | pick 'reservation.id')
check "second reservation created" 201 "$(echo "$R2" | tail -n 1)"
check "owner rejects" 200 "$(st PUT /owner/reservations/$R2_ID/reject "$OWNER")"
check "rejected twice is rejected" 422 "$(st PUT /owner/reservations/$R2_ID/reject "$OWNER")"
check "tenant notified of rejection" yes "$(has_title "$TENANT" 'Reservation Rejected')"
check "rejected reservation cannot be paid" 422 "$(st POST /payments/process "$TENANT" "{\"reservation_id\":$R2_ID,\"amount\":100,\"method\":\"card\"}")"
check "rejected reservation cannot be accepted" 422 "$(st PUT /owner/reservations/$R2_ID/accept "$OWNER")"
check "no contract for rejected reservation" 0 "$(bd GET /tenant/contracts "$TENANT" | python -c "import sys,json;d=json.load(sys.stdin);rows=d.get('data',d);print(len([c for c in rows if c['reservation_id']==$R2_ID]))")"

echo
echo "=================================================="
echo "11. CANCELLATION"
echo "=================================================="
R3=$(api POST /reservations "$TENANT" "{\"property_id\":$PROP,\"start_date\":\"$(days 500)\",\"duration_months\":2}")
R3_ID=$(echo "$R3" | head -n -1 | pick 'reservation.id')
check "tenant cancels own pending request" 200 "$(st PUT /reservations/$R3_ID/cancel "$TENANT")"
check "owner role cannot cancel" 403 "$(st PUT /reservations/$R3_ID/cancel "$OTHER_OWNER")"
check "cancelled request frees the dates" 201 "$(st POST /reservations "$OTHER_TENANT" "{\"property_id\":$PROP,\"start_date\":\"$(days 500)\",\"duration_months\":2}")"
check "cancelled request cannot be paid" 404 "$(st POST /payments/process "$OTHER_TENANT" "{\"reservation_id\":$R3_ID,\"amount\":100,\"method\":\"card\"}")"

echo
echo "=================================================="
printf " RESULT: %d passed, %d failed\n" "$PASS" "$FAIL"
echo "=================================================="
[ "$FAIL" -eq 0 ]
