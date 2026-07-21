-- Batch create in-house sales_agent accounts (run in production phpMyAdmin)
-- Role: sales_agent | capability: view_property_sales=1 | doc_sales=2
-- Dummy emails: {username}@noreply.estatehub.local
-- Temp passwords: Welcome2026!EhN (N=1..21) — plaintext list kept out of this file; see agent reply
-- password_hash: PHP password_hash()/password_verify()-compatible bcrypt ($2y$, cost 10)
-- Skips existing usernames. Capabilities inserted only if missing (safe re-run).
-- If a username already existed, password_hash was NOT updated — run
-- sql/2026-07-21_batch_reset_sales_agent_passwords.sql to force-set temps.
-- After create: assign client/project access in Users Management.
-- Login with username (e.g. GeorgeF) + temp password → Profile → change password.

START TRANSACTION;

-- George Fenech / GeorgeF
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'George', 'Fenech', 'GeorgeF', 'georgef@noreply.estatehub.local', '$2y$10$5oVmySEqMbxJ6OafG79bw.bnpoYQyp01/X/8OQmIocJ0cZsGDLUZi', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'GeorgeF');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'GeorgeF'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- George Caruana / GeorgeC
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'George', 'Caruana', 'GeorgeC', 'georgec@noreply.estatehub.local', '$2y$10$R9ce6DH9h5yCl8zgjOMuqetWFEHCtOAKcTNV2hMG6BI20guFZyv.S', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'GeorgeC');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'GeorgeC'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Connie Hili / ConnieH
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Connie', 'Hili', 'ConnieH', 'connieh@noreply.estatehub.local', '$2y$10$P.jAH7YIA8eXhZVJmdGxLeZgGv4zTm5mnVhMs7PfPLjpKQGFrmQiu', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'ConnieH');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'ConnieH'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Dennis Cilia / DennisC
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Dennis', 'Cilia', 'DennisC', 'dennisc@noreply.estatehub.local', '$2y$10$vWp0ChO/PAPQTo3OCzI/7e3ABt3E6qzeJK6pmxo3Ce.Go3X.dxIRu', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'DennisC');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'DennisC'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Mark Saliba / MarkS
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Mark', 'Saliba', 'MarkS', 'marks@noreply.estatehub.local', '$2y$10$N9lBrTgRoEb/J8oNf2f0F.msw41DME4ITTIeOnB7UFSNqPCIY8Nzy', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'MarkS');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'MarkS'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Kurt Sciortino / KurtS
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Kurt', 'Sciortino', 'KurtS', 'kurts@noreply.estatehub.local', '$2y$10$MqMXX5PK8jrFLlUy6S53bezp1Jtvfq5evOBPkZn95mUvVVyjW8CMe', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'KurtS');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'KurtS'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Ivan Mizzi / IvanM
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Ivan', 'Mizzi', 'IvanM', 'ivanm@noreply.estatehub.local', '$2y$10$k5hW0apz4b6FAO62aj/OpeWTAtQgcOJx6fsT7yQmRVo4SScLEZ1Ca', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'IvanM');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'IvanM'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Anthony Portelli / AnthonyP
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Anthony', 'Portelli', 'AnthonyP', 'anthonyp@noreply.estatehub.local', '$2y$10$me2U0JFpf92KiiZ/Io0PZeTOtdAW5xduRVcvw/QKNHsZp7uMopZde', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'AnthonyP');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'AnthonyP'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Nathan Pace / NathanP
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Nathan', 'Pace', 'NathanP', 'nathanp@noreply.estatehub.local', '$2y$10$XYBqkq8wtGUyT8Ah9.FzLOhB8NUh41PPv4WJ7dP.zmPwt9cPUoR.S', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'NathanP');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'NathanP'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Gareth Buttigieg / GarethB
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Gareth', 'Buttigieg', 'GarethB', 'garethb@noreply.estatehub.local', '$2y$10$EqdPAvXUQNcUI2/7nj7FJ.nNke79fJJqg2ygwuWLeOH/n9i0DtLGu', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'GarethB');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'GarethB'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Angele Agent / AngeleA
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Angele', 'Agent', 'AngeleA', 'angelea@noreply.estatehub.local', '$2y$10$mymZtm2LCwaZ1YCSndZbnOUbawxF8hdQCJ692ShlvDOw3tmFbgaxW', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'AngeleA');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'AngeleA'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Ramon Fenech / RamonF
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Ramon', 'Fenech', 'RamonF', 'ramonf@noreply.estatehub.local', '$2y$10$K6mR6j2mOXhEuu3u7xyIBesi5nECVS4jvDufnjMZ8yeSr1DFTfoeO', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'RamonF');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'RamonF'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Toni Dimech / ToniD
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Toni', 'Dimech', 'ToniD', 'tonid@noreply.estatehub.local', '$2y$10$v21E7JTwBdB/OYEttcwU..g2/uhfF25w5h7qOPPRG.VKYC6bpeyT2', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'ToniD');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'ToniD'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Steve Borg / SteveB
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Steve', 'Borg', 'SteveB', 'steveb@noreply.estatehub.local', '$2y$10$b/QHi10ZNKdk4WpiSUNCtO/HKseq.AiU5WsfGw02vEEtKFhP/UJ2S', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'SteveB');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'SteveB'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Robert Grima / RobertG
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Robert', 'Grima', 'RobertG', 'robertg@noreply.estatehub.local', '$2y$10$BjxPrPp/cHI4rF6850Rw2.vWapkW8efXSHWONcQFBNWyn70M19fRS', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'RobertG');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'RobertG'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Marthese Sultana / MartheseS
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Marthese', 'Sultana', 'MartheseS', 'martheses@noreply.estatehub.local', '$2y$10$5NjmNTp/JVRNVua1BIWA9.N44G.MLDJFAzI3rvEfPFgoBdWaUXTzS', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'MartheseS');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'MartheseS'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Marlene Axiak / MarleneA
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Marlene', 'Axiak', 'MarleneA', 'marlenea@noreply.estatehub.local', '$2y$10$tonkqho/97KZMorljHkd0eshwQgtuGwtN8SW6Uv3j08vCmfyvS8kS', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'MarleneA');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'MarleneA'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Joseph Rapinett / JosephR
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Joseph', 'Rapinett', 'JosephR', 'josephr@noreply.estatehub.local', '$2y$10$4TbYjCa7rXU5U79CD5YC1uM9AJVX13P/XNKwncD9gk4G18NitHm22', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'JosephR');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'JosephR'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- David Gauci / DavidG
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'David', 'Gauci', 'DavidG', 'davidg@noreply.estatehub.local', '$2y$10$/2JTpqd7sNtdntktHERCv.dix.rKtJ7qX3nKh8NMt1PECZVqH3syK', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'DavidG');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'DavidG'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Ayrton Chetcuti / AyrtonC
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Ayrton', 'Chetcuti', 'AyrtonC', 'ayrtonc@noreply.estatehub.local', '$2y$10$O0n.JvzrYUAaAUYe9rKMt.U1omly6nd8TxzgrmKZc51B2Zk.bdWzi', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'AyrtonC');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'AyrtonC'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

-- Adrian Attard / AdrianA
INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active, doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training)
SELECT 'Adrian', 'Attard', 'AdrianA', 'adriana@noreply.estatehub.local', '$2y$10$hIXq9Elev5AQ0HrZOVzDteaMuqyvUBvusANJNWY/SXDHFrQfEzvli', 'sales_agent', 'Yes', 0, 0, 0, 0, 0, 2, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'AdrianA');

INSERT INTO user_capabilities (user_id, view_tracking, add_project, edit_project_details, update_project_status, edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors, view_subcontractor_accounts, manage_subcontractor_accounts, view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors, view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, view_sales_ohsa, manage_sales_ohsa, approve_quotes, view_plant_bookings, manage_plant_fleet, view_plant_ledger, view_all_projects, edit_project_schedule)
SELECT u.id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
FROM users u
WHERE u.username = 'AdrianA'
  AND NOT EXISTS (SELECT 1 FROM user_capabilities uc WHERE uc.user_id = u.id);

COMMIT;

-- Verify: SELECT id, username, email, role, LEFT(password_hash,7) AS hash_prefix FROM users
-- WHERE username LIKE ... Expect hash_prefix $2y$10$
