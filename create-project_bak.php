<?php
session_start();
// STRICT session validation
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['user']) || $_SESSION['user'] !== 'admin') {
    session_destroy();
    header('Location: index.php');
    exit;
}
require_once 'config.php';

$pdo = getDB();
$message = '';

// CREATE PROJECT
if ($_POST['action'] ?? '' === 'create') {
    $stmt = $pdo->prepare("INSERT INTO projects (client_id, name, city, pa_number, pa_status, type, finish_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Not Started')");
    $stmt->execute([
        $_POST['client_id'], $_POST['name'], $_POST['city'], 
        $_POST['pa_number'], $_POST['pa_status'],
        $_POST['type'], $_POST['type'] === 'in-house' ? $_POST['finish_level'] : null,
        $_POST['status'] ?? 'Pending'
    ]);
    $message = '✅ Project created successfully!';
}

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo_icon.png'">
                <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
            </div>
            <div class="header-right">
                <a href="mobilization.php" class="nav-link">📊 Dashboard</a>
                <a href="clients.php" class="nav-link">👥 Clients</a>
                <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
            </div>
        </div>
    </header>
    
    <div class="main-container">
        <h1 class="page-title">Create New Project</h1>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <section class="form-section">
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Client *</label>
                    <select name="client_id" required>
                        <option value="">Select Client</option>
                        <?php foreach ($clients as $client): ?>
                        <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Project Name *</label>
                    <input name="name" placeholder="Project name" required>
                </div>
                <div class="form-group">
                    <label>Island</label>
                    <select name="island" id="island-select" onchange="updateCities()" required>
                        <option value="">Select Island</option>
                        <option value="Malta">Malta</option>
                        <option value="Gozo">Gozo</option>
                    </select>
                </div>
               <div class="form-group">
                    <label>City/Locality</label>
                    <select name="city" id="city-select" required>
                        <option value="">Select Island first</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>PA Numbers (comma-separated)</label>
                    <textarea name="" placeholder="PA123, PA456"></textarea>
                </div>
                <div class="form-group">
                    <label>PA Status</label>
                    <select name="pa_status">
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Project Type *</label>
                    <select name="type" id="project-type" onchange="toggleFinishLevel()" required>
                        <option value="">Select Type</option>
                        <option value="in-house">🏠 In-House</option>
                        <option value="3rd-party">👥 3rd Party</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="Pending">⏳ Pending</option>
                        <option value="In Process">🔄 In Process</option>
                        <option value="Mobilised">✅ Mobilised</option>
                    </select>
                </div>
                <div class="form-group" id="finish-level-group" style="display: none;">
                    <label>Finish Level *</label>
                    <select name="finish_level" id="finish-level">
                        <option value="">Select Finish Level</option>
                        <option value="Common Parts Only">🏗️ Common Parts Only</option>
                        <option value="Semi Finished">⚒️ Semi Finished</option>
                        <option value="Finished">✅ Finished</option>
                    </select>
                </div>
                <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem;">🚀 Create Project</button>
            </form>
        </section>
        
        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <a href="mobilization.php" class="nav-link" style="padding: 1rem 2rem; flex: 1; text-align: center;">← Back to Dashboard</a>
        </div>
    </div>

    <script>
        function toggleFinishLevel() {
            const type = document.getElementById('project-type').value;
            const finishGroup = document.getElementById('finish-level-group');
            const finishLevel = document.getElementById('finish-level');
            if (type === 'in-house') {
                finishGroup.style.display = 'block';
                finishLevel.required = true;
            } else {
                finishGroup.style.display = 'none';
                finishLevel.required = false;
                finishLevel.value = '';
            }
        }

        // 1) Configure your localities here
        const locations = {
            'Malta': [
                { label: 'Northern', cities: [
                'Għargħur',
                'Mellieħa',
                'Mġarr',
                'Mosta',
                'Naxxar',
                'Rabat',
                'San Pawl il-Baħar'
                ]},
                { label: 'Central', cities: [
                'Attard',
                'Balzan',
                'Birżebbuġa',
                'Gżira',
                'Iklin',
                'Lija',
                'Ħal Luqa',
                'Ħamrun',
                'Pembroke',
                'Qormi',
                'San Ġiljan',
                'Sliema',
                'Swieqi',
                'Valletta',
                'Ta Xbiex'
                ]},
                { label: 'Southern', cities: [
                'Birgu',
                'Bormla',
                'Fgura',
                'Ħal Għaxaq',
                'Ħal Kirkop',
                'Ħal Safi',
                'Ħaż-Żebbuġ',
                'Luqa',
                'Marsascala',
                'Marsaxlokk',
                'Mqabba',
                'Paola',
                'Santa Luċija',
                'Senglea',
                'Siġġiewi',
                'Tarxien',
                'Xgħajra',
                'Żabbar',
                'Żebbuġ',
                'Żejtun',
                'Qrendi',
                'Żurrieq'       
            ]}
            ],
            'Gozo': [
                { label: 'Mainland', cities: [
                'Fontana',
                'Għajnsielem',
                'Għarb',
                'Għasri',
                'Kerċem',
                'Munxar',
                'Nadur',
                'Qala',
                'Rabat (Victoria)',
                'San Lawrenz',
                'Sannat',
                'Xagħra',
                'Xewkija',
                'Żebbuġ (Gozo)'
            ]}
            ]
        };
        
        // 2) Call this on island change
        function updateCities() {
        const islandSelect = document.getElementById('island-select');
        const citySelect = document.getElementById('city-select');
    
        const island = islandSelect ? islandSelect.value : '';
        const list = locations[island] || [];
    
        // Reset options
        citySelect.innerHTML = '<option value="">Select City/Locality</option>';
    
        // Group and populate options
        list.forEach(function(group) {
            if (group.label && group.cities) {
                // Create optgroup label (non-selectable)
                const optgroup = document.createElement('optgroup');
                optgroup.label = group.label;
                group.cities.forEach(function(city) {
                    const opt = document.createElement('option');
                    opt.value = city;
                    opt.textContent = city;
                    optgroup.appendChild(opt);
                });
                citySelect.appendChild(optgroup);
            } else {
                // Fallback for plain city strings
                const opt = document.createElement('option');
                opt.value = group;
                opt.textContent = group;
                citySelect.appendChild(opt);
            }
        });
    
        // Disable if empty
        citySelect.disabled = list.length === 0;
    }

        document.addEventListener('DOMContentLoaded', function() {
            const islandSelect = document.getElementById('island-select');
            if (islandSelect) {
                islandSelect.addEventListener('change', updateCities);
                updateCities(); // Initial call
            } else {
                updateCities(); // populate if an island is already selected (e.g. after POST-back)
            }
        });
        

    </script>
</body>
</html>
