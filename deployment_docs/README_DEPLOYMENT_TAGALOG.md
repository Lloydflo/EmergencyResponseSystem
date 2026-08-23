# ERS Operational Audit Trail v5 — Deployment Guide

## Layunin

Ang update na ito ay nag-aayos ng **Admin > Operational Audit** upang maging malinaw, searchable, at nakaayos ayon sa aktuwal na process ng dispatcher at responder.

Pangunahing pagbabago:

- ang dating front-facing `ID` ay pinalitan ng **Log No.**;
- ang Log No. ay `1, 2, 3, ...` ayon sa kasalukuyang filtered result at hindi employee/responder/database ID;
- may structured actor, role, source, process category, outcome, incident reference, timestamp, at technical context;
- may incident lifecycle at duration breakdown mula call intake hanggang report review;
- awtomatikong nare-record ang mahahalagang dispatcher website at responder app events;
- may filters, pagination, summary cards, at CSV export;
- lahat ng oras sa screen ay ipinapakita sa **Asia/Manila**.

## Sakop ng audit

Kasama sa critical operational trail ang:

1. incoming call received/presented sa dispatcher queue;
2. call accepted, rejected, at ended;
3. incident record created o updated;
4. dispatch confirmed o failed;
5. assignment received ng responder;
6. navigation started/cancelled at first route-tracking point;
7. responder on scene / route arrival;
8. incident completion/resolution;
9. after-action report saved, submitted, approved, o returned for revision;
10. backup at resource requests;
11. responder at web login/logout;
12. coordination/chat events na dumadaan sa existing activity endpoint.

Hindi nilalagay sa main audit table ang bawat GPS point, keystroke, o ordinaryong UI click. Nananatili ang lahat ng route points sa `responder_route_history`; ang audit trail ay nagtatala lamang ng auditable milestones gaya ng navigation start at arrival. Iniiwasan nito ang sobrang ingay, mabilis na paglaki ng database, at hindi kailangang exposure ng location history.

## Mahahalagang deployment file

- `data/sql/2026_08_05_operational_audit_trail.sql`
- `data/sql/2026_08_05_verify_operational_audit.sql`
- `admin/audit.php`
- `includes/activity_log.php`
- `api/call_audit_event.php`
- mga dispatcher at `api/api_app` endpoint na kasama sa patch

## Deployment order

### 1. Gumawa muna ng backup

I-backup ang:

- production database;
- kasalukuyang website/application folder;
- server configuration at `.env` kung mayroon.

Huwag mag-deploy nang walang tested restore procedure.

### 2. I-run muna ang database migration

Sa phpMyAdmin, piliin ang tamang production database, buksan ang **Import** o **SQL**, at patakbuhin:

```text
data/sql/2026_08_05_operational_audit_trail.sql
```

CLI alternative:

```bash
mysql -u DB_USER -p DB_NAME \
  < data/sql/2026_08_05_operational_audit_trail.sql
```

Ang migration ay:

- ginagawang auto-increment ang internal `activity_log.id`;
- nagpapalawak sa `action` at `entity_type`;
- nagdadagdag ng structured audit columns at indexes;
- nagba-backfill ng legacy actor/source/category/outcome kung kaya;
- hindi nagbubura ng existing audit rows.

Target nito ang MariaDB 10.4+ na tumutugma sa supplied database dump.

### 3. I-deploy ang source files

Pinakaligtas ang patch package dahil intentional changed files lamang ang laman nito. I-copy ang patch sa project document root habang pinapanatili ang relative folders, halimbawa:

```text
public_html/
├── admin/audit.php
├── includes/activity_log.php
├── dispatcher/call.php
├── api/call_audit_event.php
├── api/calls_create.php
└── api/api_app/...
```

Huwag burahin ang ibang production files na wala sa patch.

### 4. Clear server cache

Kapag gumagamit ang hosting ng PHP OPcache o application cache, i-restart ang PHP-FPM o gamitin ang hosting control panel para ma-clear ito. Hindi kailangang baguhin ang Firebase Realtime Database rules para sa audit feature na ito.

### 5. Patakbuhin ang verification SQL

```text
data/sql/2026_08_05_verify_operational_audit.sql
```

Expected:

- `structured_column_check` ay `PASS`;
- makikita ang audit indexes;
- lalabas ang grouped actor/source/category/outcome counts;
- makikita ang pinakahuling 20 internal audit records.

## Staging test sequence

Gamit ang test dispatcher, responder, at admin accounts, gawin nang sunod-sunod:

1. dispatcher login at OTP verification;
2. magpasok ng incoming call;
3. i-accept ang call;
4. gumawa ng incident record;
5. mag-dispatch ng unit/responder;
6. responder login sa kasalukuyang app build;
7. responder receives/acknowledges assignment;
8. responder starts navigation;
9. mag-save ng route point at mark arrival/on-scene;
10. complete ang incident kasama ang required evidence;
11. gumawa at magsumite ng after-action report;
12. admin approves o returns the report for revision;
13. admin opens **Operational Audit**;
14. i-filter gamit ang exact incident reference;
15. i-check ang timeline at duration cards;
16. mag-export ng filtered CSV at i-verify ang contents.

Expected incident timeline:

```text
Call Received
→ Call Accepted
→ Incident Logged
→ Units Dispatched
→ Assignment Received
→ Navigation Started
→ On Scene / Arrival
→ Completed
→ Report Submitted
→ Report Approved or Returned
```

## Call timestamp behavior

Sa dispatcher website:

- ang incoming payload `start`/`received_at` ang source ng **Call Received** time;
- ang aktuwal na pag-click ng dispatcher sa Accept ang source ng **Call Accepted** time;
- agad na ipinapadala ang received/accepted/rejected/ended milestones sa authenticated `api/call_audit_event.php`;
- ipinapasa rin ang call timestamps sa `api/calls_create.php` bilang fallback;
- client timestamps na higit 30 araw na luma o lampas limang minuto sa future ay hindi tinatanggap bilang authoritative audit time.

Kapag pansamantalang pumalya ang live audit request ngunit na-log ang incident, ang `calls_create.php` ang fallback para sa call receipt at acceptance timestamps.

## Android app requirement

Walang bagong Android source o APK change sa package na ito. Ang critical responder milestones ay server-side na nire-record sa mga endpoint na tinatawag na ng kasalukuyang app source:

- assignment receipt/status;
- navigation at route tracking;
- arrival;
- completion;
- report submission/review;
- resource at backup requests;
- login/logout.

Gamitin ang kasalukuyang production app build na tumatawag sa updated endpoints. Kapag napakaluma ng installed APK at hindi nito tinatawag ang mga endpoint na ito, hindi nito magagawa ang kaukulang milestone at natural na walang event na mare-record.

## Paggamit ng Admin Operational Audit

### Filters

Puwedeng mag-filter ayon sa:

- search text;
- incident/reference number;
- actor role;
- source: Responder App, Dispatcher Website, Admin Website, External API, o Server/System;
- process category;
- outcome;
- date range;
- 25, 50, o 100 rows bawat page.

### Log No.

Ang **Log No.** ay posisyon sa current filtered result. Halimbawa, kapag nasa page 2 at 50 rows bawat page, magsisimula ito sa 51. Hindi nito ipinapakita ang internal primary key ng database.

### Incident lifecycle

Maglagay ng exact incident reference sa filter para makita ang complete timeline at durations. Structured audit events ang pangunahing source; canonical timestamps mula calls, incidents, dispatches, route summary, at after-action reports ang fallback para sa older records.

## Rollback

Pinakaligtas na rollback:

1. ilagay ang site sa maintenance mode;
2. ibalik ang file backup;
3. ibalik ang pre-deployment database backup;
4. clear OPcache;
5. mag-login at magsagawa ng smoke test.

Hindi inirerekomenda ang basta pag-drop ng audit columns sa live database dahil maaaring may bagong logs nang naka-store pagkatapos ng deployment.

## Production cautions

- Admin-only at operationally sensitive ang audit page.
- Ang CSV export ay sensitibong record; huwag ipadala sa public channels.
- Gumamit ng HTTPS, least-privilege database credentials, at regular backups.
- I-set ang formal retention period ayon sa inyong organizational, contractual, at legal requirements.
- I-test muna sa staging bago production rollout.
