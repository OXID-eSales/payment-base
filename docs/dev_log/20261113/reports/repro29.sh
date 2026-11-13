#!/bin/bash
# Sprint 29 S6: the three O-08 steps over HTTP against the local shop (through its tunnel).
S=https://daniil.oxiddev.de; UA='Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0'; AID=10cc6c01da168fa86c1dc16fd1effb69
DC="docker compose -f /home/dtkachev/osc/strpwt7-nov26/docker-compose.yml exec -T mysql mysql -uroot -proot -N example"
rows() { $DC -e "select count(*) from oe_payments_idempotency where oxkey like 'validate:%'" 2>/dev/null; }
session() { rm -f "$1"; curl -s -A "$UA" -c "$1" -b "$1" -o "$1.html" "$S/index.php?cl=details&anid=$AID"; grep -o 'name="stoken" value="[^"]*"' "$1.html" | head -1 | sed 's/.*value="//;s/"//'; }
call() { curl -s -A "$UA" -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H "Origin: $S" -d cl=oepaymentvalidationapi -d fnc=validate -d pluginModuleId="$3" -d stoken="$2" -d city=x "$S/index.php"; }
T=$(session jar1)
while [ "$(date +%S | sed 's/^0//')" -gt 2 ]; do :; done   # start right after a minute boundary
echo "start $(date +%T)"
R0=$(rows)
echo "Step 1: control, 40 calls, existing plugin id, one session"
out=""; first=""; for i in $(seq 1 40); do c=$(call jar1 "$T" oe_payments_stripe_wallet); out="$out $c"; [ -z "$first" ] && [ "$c" = 429 ] && first=$i; done
echo "  statuses:$(echo $out | tr ' ' '\n' | sort | uniq -c | awk '{printf " %sx%s", $1, $2}')  first 429 at call ${first:-none}"
R1=$(rows); echo "  new rows: $((R1-R0))"
echo "Step 2: 40 invented plugin ids, same session"
out=""; for i in $(seq 0 39); do out="$out $(call jar1 "$T" vrl29-repro-$i)"; done
echo "  statuses:$(echo $out | tr ' ' '\n' | sort | uniq -c | awk '{printf " %sx%s", $1, $2}')"
R2=$(rows); echo "  new rows: $((R2-R1))"
echo "Step 3: 5 fresh sessions right after the limit"
out=""; for i in 1 2 3 4 5; do t=$(session jar-f$i); out="$out $(call jar-f$i "$t" oe_payments_stripe_wallet)"; done
echo "  statuses:$out"
echo "end $(date +%T); rows with a key containing vrl29-repro: $($DC -e "select count(*) from oe_payments_idempotency where oxkey like '%vrl29-repro%'" 2>/dev/null)"
