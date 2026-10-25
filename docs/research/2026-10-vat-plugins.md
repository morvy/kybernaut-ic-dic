# What comparable plugins do better (October 2026)

Compared: EU/UK VAT Validation Manager (WPFactory, ~7k installs), EU/UK VAT Compliance
Assistant (TeamUpdraft/Simba, ~3k), EU VAT Assistant (Aelia, 5k+, end of life since 2022),
EU VAT Guard (Stormlabs, 300+), WPify Woo's CRN/VAT module (5k+). Per-plugin notes with
sources: `.omc/research/*.md` (not committed).

None of them has national registers beyond ARES (WPify, CZ only): no Finančná správa, no
autofill beyond the company name, no IČO/DIČ/IČ DPH field model, no PL/HU tax numbers.
That stays our lead. What they have that we lack, ranked for B2B in SK, CZ, PL, HU, AT:

## 1. Proof of the check on the order (3 of 5 plugins)
Today the order keeps the numbers and a "check failed" flag. They keep the evidence a tax
audit asks for when an invoice has no VAT:
- the VIES consultation number (requestIdentifier): VIES returns it when the request names
  the shop's own VAT ID as the requester (WPFactory, Simba, Aelia);
- the check's date and result, the name and address VIES returned;
- the exemption reason (WPify `_wpify_vat_exempt_reason`: "reverse charge", "export").

Idea: a "Shop VAT ID" setting (default from WooCommerce's store address), Vies passes it as
requester, the result goes to order meta `_woolab_icdic_vat_evidence` (number, state,
consultation number, date, name, address, reason), shown in an admin order box and the
admin e-mail.

## 2. Re-check in the admin (4 of 5)
A "Validate VAT ID" action on the order screen: asks VIES again, stores the evidence, and
removes or restores the VAT (WPFactory "validate and remove taxes", also for orders created
in the admin; Aelia "collect VAT info"). Today an order created or edited in the admin gets
no exemption at all.

## 3. When the VAT stays despite a valid VAT ID (WPFactory, Guard, WPify)
Our exemption looks at the billing country only. They also cover:
- the country WooCommerce taxes by ("Calculate tax based on": shipping address for goods);
- goods shipped to the shop's own country, or shipping country ≠ VAT ID country;
- local pickup (the supply is domestic);
- a per-product "always with VAT" flag, VAT kept on shipping (WPFactory);
- by user role: always / never exempt (WPFactory).

These are correctness for physical goods, the main case in SK/CZ.

## 4. EC Sales List export (Simba, Aelia)
Exempt intra-EU B2B sales per customer VAT ID and period, CSV: what the shop files as
súhrnný výkaz (SK), souhrnné hlášení (CZ), VAT-UE (PL), A60 (HU), ZM (AT). Built from the
evidence of 1. Aelia splits goods / services per product.

## 5. The VAT ID on the customer account (WPFactory, Guard)
Field on registration and My Account, kept as user meta, prefilling the checkout; a
scheduled re-check of stored IDs (Guard Pro), and on subscription renewals (Simba, Aelia).

## 6. When VIES cannot answer (WPFactory, Aelia)
We have one switch ("Ignore when a check fails"). They add: a whitelist of VAT IDs,
skipping the check per country, separate policies for busy / down / too many requests,
throttling concurrent requests.

## 7. Smaller ones
- Orders list: a column and filter "with VAT ID" / "reverse charge" (WPFactory).
- Zero VAT for exports outside the EU, as its own switch (WPify).
- "I have a VAT ID but pay VAT" checkbox (WPFactory).
- Reverse-charge line on invoices via PDF Invoices & Packing Slips (Aelia, Simba); we
  already integrate that plugin and SuperFaktúra.
- VAT in the national currency at the central bank rate (Simba, Aelia: ECB, CNB, …), for
  shops selling in EUR and filing in CZK/PLN/HUF.
- A status / readiness page (Simba).

## Not worth copying for our markets
OSS/MOSS reports, IP geolocation evidence, price display by visitor country: B2C distance
sales and digital goods, not the B2B case this plugin serves. UK/CH/NO VAT numbers need a
paid API (VatSense) everywhere.

## Suggested order
1 + 2 together (evidence, then the admin action uses it), then 3 (correctness), 4 (built on
1), 5, then 6 and 7 as needed.
