-- Bulk place 53 units On Hold (single-statement; phpMyAdmin-friendly)
-- Skipped: Avanti P09, Quin G03, Quin 103 (sold); Moro One 2017, Soleia C10 (Proceeding)
-- hold_expiry: Fri 2026-07-24 + 7 days = 2026-07-31 23:59:00 (Europe/Malta)
-- Only updates rows still status=Available.

-- 1) Pre-check (run alone; expect 53 Available):
SELECT id, project_id, unit_name, status, held_by_agent_id, hold_expiry FROM sales_properties WHERE id IN (172,192,201,230,237,290,297,450,524,525,600,601,602,620,642,643,644,645,755,752,731,733,735,736,110,113,118,119,122,123,129,131,134,135,319,322,329,378,379,397,529,658,662,666,668,669,672,673,674,788,784,786,779) ORDER BY id;

-- 2) Apply holds (run this statement alone):
UPDATE sales_properties
SET status = 'On Hold',
    held_by_agent_id = CASE id
        WHEN 172 THEN 92  -- Arkana Suites / G301  (sheet G301 / EricS)
        WHEN 192 THEN 92  -- Arkana Suites / G322  (sheet G322 / EricS)
        WHEN 201 THEN 92  -- Arkana Suites / G331  (sheet G331 / EricS)
        WHEN 230 THEN 89  -- Arkana Suites / 101  (sheet 101 / DavidG)
        WHEN 237 THEN 47  -- Arkana Suites / 108  (sheet 108 / KBM)
        WHEN 290 THEN 90  -- Arkana Suites / 319  (sheet 319 / AyrtonC)
        WHEN 297 THEN 47  -- Arkana Suites / 405  (sheet 405 / KBM)
        WHEN 450 THEN 80  -- Moro One / 1007  (sheet 1007 / GarethB)
        WHEN 524 THEN 91  -- Moro One / 5015  (sheet 5015 / AdrianA)
        WHEN 525 THEN 89  -- Moro One / 5016  (sheet 5016 / DavidG)
        WHEN 600 THEN 47  -- Avanti / M01 - Blk A  (sheet M01 / KBM)
        WHEN 601 THEN 47  -- Avanti / M02 - Blk A  (sheet M02 / KBM)
        WHEN 602 THEN 47  -- Avanti / M03 - Blk B  (sheet M03 / KBM)
        WHEN 620 THEN 72  -- Avanti / B05  (sheet B05 / GeorgeC)
        WHEN 642 THEN 47  -- Avanti / A13  (sheet A13 / KBM)
        WHEN 643 THEN 47  -- Avanti / A14  (sheet A14 / KBM)
        WHEN 644 THEN 47  -- Avanti / B13  (sheet B13 / KBM)
        WHEN 645 THEN 47  -- Avanti / B14  (sheet B14 / KBM)
        WHEN 755 THEN 47  -- Soleia Suites / G14  (sheet G14 / KBM)
        WHEN 752 THEN 72  -- Soleia Suites / G10  (sheet G10 / GeorgeC)
        WHEN 731 THEN 90  -- Soleia Suites / C04  (sheet C04 / AyrtonC)
        WHEN 733 THEN 90  -- Soleia Suites / C06  (sheet C06 / AyrtonC)
        WHEN 735 THEN 91  -- Soleia Suites / C08  (sheet C08 / AdrianA)
        WHEN 736 THEN 72  -- Soleia Suites / C09  (sheet C09 / GeorgeC)
        WHEN 110 THEN 75  -- Quin Residences / G02  (sheet G02 / MarkS)
        WHEN 113 THEN 75  -- Quin Residences / G06  (sheet G06 / MarkS)
        WHEN 118 THEN 75  -- Quin Residences / SHOP 02  (sheet Shop 02 / MarkS)
        WHEN 119 THEN 75  -- Quin Residences / SHOP 03  (sheet Shop 03 / MarkS)
        WHEN 122 THEN 75  -- Quin Residences / 101  (sheet 101 / MarkS)
        WHEN 123 THEN 47  -- Quin Residences / 102  (sheet 102 / KBM)
        WHEN 129 THEN 83  -- Quin Residences / 204  (sheet 204 / ToniD)
        WHEN 131 THEN 91  -- Quin Residences / 302  (sheet 302 / AdrianA)
        WHEN 134 THEN 82  -- Quin Residences / 401  (sheet 401 / RamonF)
        WHEN 135 THEN 82  -- Quin Residences / 402  (sheet 402 / RamonF)
        WHEN 319 THEN 75  -- The View / 5  (sheet Apt 05 / MarkS)
        WHEN 322 THEN 75  -- Harbea Residences / G27  (sheet G27 / MarkS)
        WHEN 329 THEN 47  -- Harbea Residences / G34  (sheet G34 / KBM)
        WHEN 378 THEN 91  -- Harbea Residences / C02  (sheet C02 / AdrianA)
        WHEN 379 THEN 91  -- Harbea Residences / D01  (sheet D01 / AdrianA)
        WHEN 397 THEN 89  -- Harbea Residences / A07  (sheet A07 / DavidG)
        WHEN 529 THEN 90  -- 5 Houses / HOUSE 03 (Nadur)  (sheet House 3 / AyrtonC)
        WHEN 658 THEN 93  -- Flamingo / G11  (sheet G11 / jessicasaid)
        WHEN 662 THEN 84  -- Flamingo / Apt. A101  (sheet A101 / SteveB)
        WHEN 666 THEN 93  -- Flamingo / Apt. A201  (sheet A201 / jessicasaid)
        WHEN 668 THEN 79  -- Flamingo / Apt. B201  (sheet B201 / NathanP)
        WHEN 669 THEN 71  -- Flamingo / Apt. B202  (sheet B202 / GeorgeF)
        WHEN 672 THEN 47  -- Flamingo / Apt. B301  (sheet B301 / KBM)
        WHEN 673 THEN 71  -- Flamingo / Apt. B302  (sheet B302 / GeorgeF)
        WHEN 674 THEN 47  -- Flamingo / PH A401  (sheet A401 / KBM)
        WHEN 788 THEN 83  -- 5 Houses Triq il Kbira / House 5  (sheet House 5 / ToniD)
        WHEN 784 THEN 89  -- 5 Houses Triq il Kbira / House 1  (sheet House 1 / DavidG)
        WHEN 786 THEN 89  -- 5 Houses Triq il Kbira / House 3  (sheet House 3 / DavidG)
        WHEN 779 THEN 86  -- 5 Houses Sandro Triq Ta Cenc / House 1  (sheet House 1 / MartheseS)
    END,
    hold_expiry = '2026-07-31 23:59:00'
WHERE id IN (172,192,201,230,237,290,297,450,524,525,600,601,602,620,642,643,644,645,755,752,731,733,735,736,110,113,118,119,122,123,129,131,134,135,319,322,329,378,379,397,529,658,662,666,668,669,672,673,674,788,784,786,779)
  AND status = 'Available';

-- 3) Verify (run alone; expect 53 On Hold):
SELECT id, unit_name, status, held_by_agent_id, hold_expiry FROM sales_properties WHERE id IN (172,192,201,230,237,290,297,450,524,525,600,601,602,620,642,643,644,645,755,752,731,733,735,736,110,113,118,119,122,123,129,131,134,135,319,322,329,378,379,397,529,658,662,666,668,669,672,673,674,788,784,786,779) ORDER BY id;
