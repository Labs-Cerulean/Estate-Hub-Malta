-- Bulk place 53 units On Hold (launch sheet matched to sales_properties dump)
-- Skipped: Avanti P09, Quin G03, Quin 103 (sold); Moro One 2017, Soleia C10 (Proceeding)
-- hold_expiry: Fri 2026-07-24 (this week) + 7 days = 2026-07-31 23:59:00 (Europe/Malta)
-- Only updates rows still status=Available (safe if re-run). Review SELECT first.

START TRANSACTION;

-- Pre-check (expect 53 rows, all Available):
SELECT id, project_id, unit_name, status, held_by_agent_id, hold_expiry FROM sales_properties WHERE id IN (172,192,201,230,237,290,297,450,524,525,600,601,602,620,642,643,644,645,755,752,731,733,735,736,110,113,118,119,122,123,129,131,134,135,319,322,329,378,379,397,529,658,662,666,668,669,672,673,674,788,784,786,779) ORDER BY id;

-- Arkana Suites / G301  (sheet G301 / EricS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 92, hold_expiry = '2026-07-31 23:59:00' WHERE id = 172 AND status = 'Available';

-- Arkana Suites / G322  (sheet G322 / EricS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 92, hold_expiry = '2026-07-31 23:59:00' WHERE id = 192 AND status = 'Available';

-- Arkana Suites / G331  (sheet G331 / EricS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 92, hold_expiry = '2026-07-31 23:59:00' WHERE id = 201 AND status = 'Available';

-- Arkana Suites / 101  (sheet 101 / DavidG)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 89, hold_expiry = '2026-07-31 23:59:00' WHERE id = 230 AND status = 'Available';

-- Arkana Suites / 108  (sheet 108 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 237 AND status = 'Available';

-- Arkana Suites / 319  (sheet 319 / AyrtonC)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 90, hold_expiry = '2026-07-31 23:59:00' WHERE id = 290 AND status = 'Available';

-- Arkana Suites / 405  (sheet 405 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 297 AND status = 'Available';

-- Moro One / 1007  (sheet 1007 / GarethB)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 80, hold_expiry = '2026-07-31 23:59:00' WHERE id = 450 AND status = 'Available';

-- Moro One / 5015  (sheet 5015 / AdrianA)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 91, hold_expiry = '2026-07-31 23:59:00' WHERE id = 524 AND status = 'Available';

-- Moro One / 5016  (sheet 5016 / DavidG)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 89, hold_expiry = '2026-07-31 23:59:00' WHERE id = 525 AND status = 'Available';

-- Avanti / M01 - Blk A  (sheet M01 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 600 AND status = 'Available';

-- Avanti / M02 - Blk A  (sheet M02 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 601 AND status = 'Available';

-- Avanti / M03 - Blk B  (sheet M03 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 602 AND status = 'Available';

-- Avanti / B05  (sheet B05 / GeorgeC)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 72, hold_expiry = '2026-07-31 23:59:00' WHERE id = 620 AND status = 'Available';

-- Avanti / A13  (sheet A13 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 642 AND status = 'Available';

-- Avanti / A14  (sheet A14 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 643 AND status = 'Available';

-- Avanti / B13  (sheet B13 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 644 AND status = 'Available';

-- Avanti / B14  (sheet B14 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 645 AND status = 'Available';

-- Soleia Suites / G14  (sheet G14 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 755 AND status = 'Available';

-- Soleia Suites / G10  (sheet G10 / GeorgeC)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 72, hold_expiry = '2026-07-31 23:59:00' WHERE id = 752 AND status = 'Available';

-- Soleia Suites / C04  (sheet C04 / AyrtonC)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 90, hold_expiry = '2026-07-31 23:59:00' WHERE id = 731 AND status = 'Available';

-- Soleia Suites / C06  (sheet C06 / AyrtonC)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 90, hold_expiry = '2026-07-31 23:59:00' WHERE id = 733 AND status = 'Available';

-- Soleia Suites / C08  (sheet C08 / AdrianA)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 91, hold_expiry = '2026-07-31 23:59:00' WHERE id = 735 AND status = 'Available';

-- Soleia Suites / C09  (sheet C09 / GeorgeC)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 72, hold_expiry = '2026-07-31 23:59:00' WHERE id = 736 AND status = 'Available';

-- Quin Residences / G02  (sheet G02 / MarkS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 75, hold_expiry = '2026-07-31 23:59:00' WHERE id = 110 AND status = 'Available';

-- Quin Residences / G06  (sheet G06 / MarkS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 75, hold_expiry = '2026-07-31 23:59:00' WHERE id = 113 AND status = 'Available';

-- Quin Residences / SHOP 02  (sheet Shop 02 / MarkS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 75, hold_expiry = '2026-07-31 23:59:00' WHERE id = 118 AND status = 'Available';

-- Quin Residences / SHOP 03  (sheet Shop 03 / MarkS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 75, hold_expiry = '2026-07-31 23:59:00' WHERE id = 119 AND status = 'Available';

-- Quin Residences / 101  (sheet 101 / MarkS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 75, hold_expiry = '2026-07-31 23:59:00' WHERE id = 122 AND status = 'Available';

-- Quin Residences / 102  (sheet 102 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 123 AND status = 'Available';

-- Quin Residences / 204  (sheet 204 / ToniD)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 83, hold_expiry = '2026-07-31 23:59:00' WHERE id = 129 AND status = 'Available';

-- Quin Residences / 302  (sheet 302 / AdrianA)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 91, hold_expiry = '2026-07-31 23:59:00' WHERE id = 131 AND status = 'Available';

-- Quin Residences / 401  (sheet 401 / RamonF)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 82, hold_expiry = '2026-07-31 23:59:00' WHERE id = 134 AND status = 'Available';

-- Quin Residences / 402  (sheet 402 / RamonF)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 82, hold_expiry = '2026-07-31 23:59:00' WHERE id = 135 AND status = 'Available';

-- The View / 5  (sheet Apt 05 / MarkS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 75, hold_expiry = '2026-07-31 23:59:00' WHERE id = 319 AND status = 'Available';

-- Harbea Residences / G27  (sheet G27 / MarkS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 75, hold_expiry = '2026-07-31 23:59:00' WHERE id = 322 AND status = 'Available';

-- Harbea Residences / G34  (sheet G34 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 329 AND status = 'Available';

-- Harbea Residences / C02  (sheet C02 / AdrianA)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 91, hold_expiry = '2026-07-31 23:59:00' WHERE id = 378 AND status = 'Available';

-- Harbea Residences / D01  (sheet D01 / AdrianA)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 91, hold_expiry = '2026-07-31 23:59:00' WHERE id = 379 AND status = 'Available';

-- Harbea Residences / A07  (sheet A07 / DavidG)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 89, hold_expiry = '2026-07-31 23:59:00' WHERE id = 397 AND status = 'Available';

-- 5 Houses / HOUSE 03 (Nadur)  (sheet House 3 / AyrtonC)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 90, hold_expiry = '2026-07-31 23:59:00' WHERE id = 529 AND status = 'Available';

-- Flamingo / G11  (sheet G11 / jessicasaid)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 93, hold_expiry = '2026-07-31 23:59:00' WHERE id = 658 AND status = 'Available';

-- Flamingo / Apt. A101  (sheet A101 / SteveB)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 84, hold_expiry = '2026-07-31 23:59:00' WHERE id = 662 AND status = 'Available';

-- Flamingo / Apt. A201  (sheet A201 / jessicasaid)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 93, hold_expiry = '2026-07-31 23:59:00' WHERE id = 666 AND status = 'Available';

-- Flamingo / Apt. B201  (sheet B201 / NathanP)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 79, hold_expiry = '2026-07-31 23:59:00' WHERE id = 668 AND status = 'Available';

-- Flamingo / Apt. B202  (sheet B202 / GeorgeF)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 71, hold_expiry = '2026-07-31 23:59:00' WHERE id = 669 AND status = 'Available';

-- Flamingo / Apt. B301  (sheet B301 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 672 AND status = 'Available';

-- Flamingo / Apt. B302  (sheet B302 / GeorgeF)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 71, hold_expiry = '2026-07-31 23:59:00' WHERE id = 673 AND status = 'Available';

-- Flamingo / PH A401  (sheet A401 / KBM)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 47, hold_expiry = '2026-07-31 23:59:00' WHERE id = 674 AND status = 'Available';

-- 5 Houses Triq il Kbira / House 5  (sheet House 5 / ToniD)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 83, hold_expiry = '2026-07-31 23:59:00' WHERE id = 788 AND status = 'Available';

-- 5 Houses Triq il Kbira / House 1  (sheet House 1 / DavidG)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 89, hold_expiry = '2026-07-31 23:59:00' WHERE id = 784 AND status = 'Available';

-- 5 Houses Triq il Kbira / House 3  (sheet House 3 / DavidG)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 89, hold_expiry = '2026-07-31 23:59:00' WHERE id = 786 AND status = 'Available';

-- 5 Houses Sandro Triq Ta Cenc / House 1  (sheet House 1 / MartheseS)
UPDATE sales_properties SET status = 'On Hold', held_by_agent_id = 86, hold_expiry = '2026-07-31 23:59:00' WHERE id = 779 AND status = 'Available';

COMMIT;

-- Verify:
SELECT id, unit_name, status, held_by_agent_id, hold_expiry FROM sales_properties WHERE id IN (172,192,201,230,237,290,297,450,524,525,600,601,602,620,642,643,644,645,755,752,731,733,735,736,110,113,118,119,122,123,129,131,134,135,319,322,329,378,379,397,529,658,662,666,668,669,672,673,674,788,784,786,779) AND status = 'On Hold' ORDER BY id;
