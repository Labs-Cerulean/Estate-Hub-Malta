-- Force-reset temp passwords for batch sales agents
-- Use if login fails after the create script (e.g. INSERT skipped because username already existed).
-- Same temps: Welcome2026!EhN (N=1..21). Hashes re-verified with bcrypt (PHP password_verify compatible $2y$ cost 10).
-- Login with username (e.g. GeorgeF), not email. Then change password in Profile.

-- Diagnostic (run first): expect CHAR_LENGTH(password_hash)=60 and is_active=Yes
SELECT username, role, is_active, CHAR_LENGTH(password_hash) AS hash_len, LEFT(password_hash,7) AS hash_prefix
FROM users WHERE username IN (
  'GeorgeF', 'GeorgeC', 'ConnieH', 'DennisC', 'MarkS', 'KurtS', 'IvanM', 'AnthonyP', 'NathanP', 'GarethB', 'AngeleA', 'RamonF', 'ToniD', 'SteveB', 'RobertG', 'MartheseS', 'MarleneA', 'JosephR', 'DavidG', 'AyrtonC', 'AdrianA'
);

START TRANSACTION;

-- GeorgeF / Welcome2026!Eh1
UPDATE users SET password_hash = '$2y$10$q5OpHXc47hlyzxDJ5EPYPeSPJWNyDHJUhZ.EjiAE3mjqixV7JhJwy', is_active = 'Yes' WHERE username = 'GeorgeF';

-- GeorgeC / Welcome2026!Eh2
UPDATE users SET password_hash = '$2y$10$5wW5QGV45SJJYZ2tgP.xQer/OgrTlNbJ69X4kOVaFXnFnyRZUBtfC', is_active = 'Yes' WHERE username = 'GeorgeC';

-- ConnieH / Welcome2026!Eh3
UPDATE users SET password_hash = '$2y$10$zl53cGdWV7Zb9ZnbG63nYODOaDdcsuj60U/yAijbfQA0Snk/46IGq', is_active = 'Yes' WHERE username = 'ConnieH';

-- DennisC / Welcome2026!Eh4
UPDATE users SET password_hash = '$2y$10$d0CIID9XkFFvd..JTX1LwepRvcda7JHoIXO7dVwCeZxOnob1hcir2', is_active = 'Yes' WHERE username = 'DennisC';

-- MarkS / Welcome2026!Eh5
UPDATE users SET password_hash = '$2y$10$5puzCpf9rh0Dm4hUN/08cu3fPBtBbfdyzU9rQvJeukweMVgCNPDii', is_active = 'Yes' WHERE username = 'MarkS';

-- KurtS / Welcome2026!Eh6
UPDATE users SET password_hash = '$2y$10$nfQOEWrBQ0YrbYTJ0rnhY.SEh3cAC6a8tFeO329IwajNW6IEV0ee6', is_active = 'Yes' WHERE username = 'KurtS';

-- IvanM / Welcome2026!Eh7
UPDATE users SET password_hash = '$2y$10$Nh4f15wD/XiavXP/WbvxXO05GHX/MqpK3R7yKGni8PuBxHIgytGuK', is_active = 'Yes' WHERE username = 'IvanM';

-- AnthonyP / Welcome2026!Eh8
UPDATE users SET password_hash = '$2y$10$nL7MOpTu3nHNgu8s4Fv5y.fqPNfqRClwM9ADyFznTcKseYDF.58bi', is_active = 'Yes' WHERE username = 'AnthonyP';

-- NathanP / Welcome2026!Eh9
UPDATE users SET password_hash = '$2y$10$YO5uHK0iT8QHoT1jkb53nuZHeXHSwMzjlKuWA3FSdxRaRONzLv0GG', is_active = 'Yes' WHERE username = 'NathanP';

-- GarethB / Welcome2026!Eh10
UPDATE users SET password_hash = '$2y$10$nhK3i9T.TRIkp0G8rnWEtersU147OWBLjdHxFSg0zDJ78Qm32X/Ma', is_active = 'Yes' WHERE username = 'GarethB';

-- AngeleA / Welcome2026!Eh11
UPDATE users SET password_hash = '$2y$10$QyxK.ryFpo3uEMESLay/8Oz.aX.U2P.cEzqewlXtiIczSNdm0N2Ie', is_active = 'Yes' WHERE username = 'AngeleA';

-- RamonF / Welcome2026!Eh12
UPDATE users SET password_hash = '$2y$10$LL1OAq9Y3xuerz2GhIpoxewJRQ.KltT/6biee79xOhdcoh7NamdUK', is_active = 'Yes' WHERE username = 'RamonF';

-- ToniD / Welcome2026!Eh13
UPDATE users SET password_hash = '$2y$10$vJSDbGnkNSfNyi2wDBvJfeULEOcfU8gP4ldmwqsouzndzz5i397M2', is_active = 'Yes' WHERE username = 'ToniD';

-- SteveB / Welcome2026!Eh14
UPDATE users SET password_hash = '$2y$10$eU4a75rbGT03ExwItZ.6muIWhclUgQSgPe88qVj/q6LZfnQrDtPbu', is_active = 'Yes' WHERE username = 'SteveB';

-- RobertG / Welcome2026!Eh15
UPDATE users SET password_hash = '$2y$10$.UPp.XJcX6NkHjtY6E1WguvFsOUDq.8ALvhkndlJkJUh.LpVVvQde', is_active = 'Yes' WHERE username = 'RobertG';

-- MartheseS / Welcome2026!Eh16
UPDATE users SET password_hash = '$2y$10$TfJsza0g43VvNYlWFbxnxelo3b/Omh/MklhuwbN8rMtDfWH5xZnh6', is_active = 'Yes' WHERE username = 'MartheseS';

-- MarleneA / Welcome2026!Eh17
UPDATE users SET password_hash = '$2y$10$fzx7tgZ53LjiODj33LE36OJZuG1/0tYEUn51ulfkWaC/GX.cUbPdK', is_active = 'Yes' WHERE username = 'MarleneA';

-- JosephR / Welcome2026!Eh18
UPDATE users SET password_hash = '$2y$10$3egUYt.0kF5Vt.cYU6bSJ.4jGSR7fE1xBhP8jNgkjUv5/9wt0He2y', is_active = 'Yes' WHERE username = 'JosephR';

-- DavidG / Welcome2026!Eh19
UPDATE users SET password_hash = '$2y$10$LVU5CUh0CL1sJqzffnIk.uN2.8GbXbypG0jHS.4evI/qCWoN/CsGG', is_active = 'Yes' WHERE username = 'DavidG';

-- AyrtonC / Welcome2026!Eh20
UPDATE users SET password_hash = '$2y$10$7yJFBXp5nfPfyZRA4fJpyuW4JWy0R5iYc0ooNbyOvvpEh3oEZD4T2', is_active = 'Yes' WHERE username = 'AyrtonC';

-- AdrianA / Welcome2026!Eh21
UPDATE users SET password_hash = '$2y$10$NDbFtjjFubyAi9qq1Tac6e9JqVGfJNiIeLKSg.HH.MtPuOcpTmcd2', is_active = 'Yes' WHERE username = 'AdrianA';

COMMIT;

-- Re-check lengths:
SELECT username, CHAR_LENGTH(password_hash) AS hash_len FROM users WHERE username IN (
  'GeorgeF', 'GeorgeC', 'ConnieH', 'DennisC', 'MarkS', 'KurtS', 'IvanM', 'AnthonyP', 'NathanP', 'GarethB', 'AngeleA', 'RamonF', 'ToniD', 'SteveB', 'RobertG', 'MartheseS', 'MarleneA', 'JosephR', 'DavidG', 'AyrtonC', 'AdrianA'
);
