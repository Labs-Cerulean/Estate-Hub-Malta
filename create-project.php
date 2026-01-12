<?php
require_once 'init.php';
require_once 'session-check.php';

// Only admins and managers can create projects
if (!isAdmin() && getCurrentRole() !== 'manager') {
    header('Location: dashboard.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO projects (clientid, name, city, island, type, finishlevel)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['clientid'],
            $_POST['name'],
            $_POST['city'],
            $_POST['island'],
            $_POST['type'],
            ($_POST['type'] === 'in-house' ? $_POST['finishlevel'] : null)
        ]);
        
        $projectId = $pdo->lastInsertId();
        
        // Insert PA numbers with status and professionals
        if (!empty($_POST['pa_entries'])) {
            $paStmt = $pdo->prepare("
                INSERT INTO project_pa_numbers (project_id, pa_number, pa_status, architect_id, structural_engineer_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($_POST['pa_entries'] as $paEntry) {
                $paNumber = trim($paEntry['number'] ?? '');
                if (!empty($paNumber)) {
                    $paStatus = $paEntry['status'] ?? 'Endorsed';
                    $architectId = !empty($paEntry['architect']) ? $paEntry['architect'] : null;
                    $engineerId = !empty($paEntry['engineer']) ? $paEntry['engineer'] : null;
                    
                    $paStmt->execute([
                        $projectId,
                        $paNumber,
                        $paStatus,
                        $architectId,
                        $engineerId
                    ]);
                }
            }
        }
        
        // Create default mobilisation record
        $mobStmt = $pdo->prepare("INSERT INTO project_mobilisation (project_id) VALUES (?)");
        $mobStmt->execute([$projectId]);
        
        // Auto-assign creator to project's client
        autoAssignCreatorToProjectClient($pdo, $projectId);
        
        $message = 'Project created successfully! You now have access to this project.';
    } catch (PDOException $e) {
        $message = 'Error creating project: ' . $e->getMessage();
    }
}

// Get accessible clients for dropdown
$userId = getCurrentUserId();
$isAdmin = isAdmin();

if ($isAdmin) {
    // Admins see all clients
    $clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
} else {
    // Non-admins see only their assigned clients
    $clients = getUserClients($pdo, $userId);
}

// Get architects
$architects = $pdo->query("
    SELECT id, name, firm_name 
    FROM professionals 
    WHERE role_type = 'architect' 
    ORDER BY name
")->fetchAll();

// Get structural engineers
$engineers = $pdo->query("
    SELECT id, name, firm_name 
    FROM professionals 
    WHERE role_type = 'structural_engineer' 
    ORDER BY name
")->fetchAll();

// Set page title
$pageTitle = 'Create Project';

// Now output HTML
require_once 'header.php';
?>


    <div class="main-container">
        <h1 class="page-title">Create New Project</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if (empty($clients)): ?>
            <div class="alert alert-warning">
                <p>You don't have access to any clients yet. You need to either:</p>
                <ul>
                    <li>Create a new client first (you'll automatically get access)</li>
                    <li>Ask an admin to assign you to existing clients</li>
                </ul>
                <a href="clients.php" class="btn">Go to Client Management</a>
            </div>
        <?php else: ?>
            <form method="POST" class="project-form">
                <!-- Basic Information -->
                <div class="form-section">
                    <h2>Basic Information</h2>
                    
                    <div class="form-group">
                        <label>Client:*</label>
                        <select name="clientid" required>
                            <option value="">-- Select Client --</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$isAdmin): ?>
                            <small class="help-text">You can only create projects for clients you have access to.</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Project Name:*</label>
                        <input type="text" name="name" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Island:*</label>
                            <select name="island" id="island" onchange="updateCities()" required>
                                <option value="">Select Island</option>
                                <option value="Malta">Malta</option>
                                <option value="Gozo">Gozo</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>City / Locality:*</label>
                            <select name="city" id="city-select" required disabled>
                                <option value="">Select Island First</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Type:*</label>
                            <select name="type" id="projectType" required>
                                <option value="in-house">In-House</option>
                                <option value="3rd-party">3rd Party</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="finishLevelGroup">
                            <label>Finish Level:*</label>
                            <select name="finishlevel" id="finishLevel">
                                <option value="Common Parts Only">Common Parts Only</option>
                                <option value="Semi Finished">Semi Finished</option>
                                <option value="Finished">Finished</option>
                                <option value="Shell">Shell</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- PA Numbers Section -->
                <div class="form-section">
                    <h2>PA Numbers</h2>
                    <div id="paEntriesContainer">
                        <div class="pa-entry">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>PA Number:</label>
                                    <input type="text" name="pa_entries[0][number]" placeholder="e.g., PA01234/25">
                                </div>
                                
                                <div class="form-group">
                                    <label>Status:</label>
                                    <select name="pa_entries[0][status]">
                                        <option value="Endorsed">Endorsed</option>
                                        <option value="Decided">Decided</option>
                                        <option value="Fee Payment">Fee Payment</option>
                                        <option value="Refused">Refused</option>
                                        <option value="Pending/Awaiting Decision">Pending/Awaiting Decision</option>
                                        <option value="Recommended for Approval">Recommended for Approval</option>
                                        <option value="Recommended for Refusal">Recommended for Refusal</option>
                                        <option value="Under Appeal">Under Appeal</option>
                                        <option value="Revoked/Annulled">Revoked/Annulled</option>
                                        <option value="Withdrawn">Withdrawn</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Architect:</label>
                                    <select name="pa_entries[0][architect]">
                                        <option value="">-- Select Architect --</option>
                                        <?php foreach ($architects as $arch): ?>
                                            <option value="<?= $arch['id'] ?>">
                                                <?= htmlspecialchars($arch['name']) ?> 
                                                (<?= htmlspecialchars($arch['firm_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Structural Engineer:</label>
                                    <select name="pa_entries[0][engineer]">
                                        <option value="">-- Select Engineer --</option>
                                        <?php foreach ($engineers as $eng): ?>
                                            <option value="<?= $eng['id'] ?>">
                                                <?= htmlspecialchars($eng['name']) ?> 
                                                (<?= htmlspecialchars($eng['firm_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" onclick="addPAEntry()" class="btn btn-secondary">Add Another PA Number</button>
                </div>
                
                <?php if (!$isAdmin): ?>
                    <div class="info-box">
                        <p><strong>Note:</strong> When you create this project, you will automatically get access to it through the client assignment.</p>
                    </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Project</button>
                    <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <script>
        let paEntryCount = 1;
        
        function addPAEntry() {
            const container = document.getElementById('paEntriesContainer');
            const newEntry = document.createElement('div');
            newEntry.className = 'pa-entry';
            newEntry.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label>PA Number:</label>
                        <input type="text" name="pa_entries[${paEntryCount}][number]" placeholder="e.g., PA01234/25">
                    </div>
                    
                    <div class="form-group">
                        <label>Status:</label>
                        <select name="pa_entries[${paEntryCount}][status]">
                            <option value="Endorsed">Endorsed</option>
                            <option value="Decided">Decided</option>
                            <option value="Fee Payment">Fee Payment</option>
                            <option value="Refused">Refused</option>
                            <option value="Pending/Awaiting Decision">Pending/Awaiting Decision</option>
                            <option value="Recommended for Approval">Recommended for Approval</option>
                            <option value="Recommended for Refusal">Recommended for Refusal</option>
                            <option value="Under Appeal">Under Appeal</option>
                            <option value="Revoked/Annulled">Revoked/Annulled</option>
                            <option value="Withdrawn">Withdrawn</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Architect:</label>
                        <select name="pa_entries[${paEntryCount}][architect]">
                            <option value="">-- Select Architect --</option>
                            <?php foreach ($architects as $arch): ?>
                                <option value="<?= $arch['id'] ?>">
                                    <?= htmlspecialchars($arch['name']) ?> 
                                    (<?= htmlspecialchars($arch['firm_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Structural Engineer:</label>
                        <select name="pa_entries[${paEntryCount}][engineer]">
                            <option value="">-- Select Engineer --</option>
                            <?php foreach ($engineers as $eng): ?>
                                <option value="<?= $eng['id'] ?>">
                                    <?= htmlspecialchars($eng['name']) ?> 
                                    (<?= htmlspecialchars($eng['firm_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <button type="button" onclick="this.parentElement.remove()" class="btn btn-danger btn-small">Remove</button>
            `;
            container.appendChild(newEntry);
            paEntryCount++;
        }
        
        // Toggle finish level visibility based on project type
        document.getElementById('projectType').addEventListener('change', function() {
            const finishLevelGroup = document.getElementById('finishLevelGroup');
            const finishLevelSelect = document.getElementById('finishLevel');
            
            if (this.value === 'in-house') {
                finishLevelGroup.style.display = 'block';
                finishLevelSelect.required = true;
            } else {
                finishLevelGroup.style.display = 'none';
                finishLevelSelect.required = false;
            }
        });

        // Malta and Gozo locations data
        // City data
          const locations = {
            'Malta': [
              { label: 'Northern', cities: ['Dingli', 'Ghargur', 'Mellieha', 'Mosta', 'Naxxar', 'Qawra', 'Rabat', 'San Pawl il-Bahar'] },
              { label: 'Central', cities: ['Attard', 'Balzan', 'Birkirkara', 'Floriana', 'Gzira', 'Iklin', 'Lija', 'Luqa', 'Marsa', 'Msida', 'Pembroke', 'Pieta', 'Qormi', 'San Giljan', 'Sliema', 'St Venera', 'Swieqi', 'Valletta', 'Ta Xbiex'] },
              { label: 'Southern', cities: ['Birgu', 'Bormla', 'Fgura', 'Ghaxaq', 'Kirkop', 'Safi', 'Haz-Zebbug', 'Luqa', 'Marsascala', 'Marsaxlokk', 'Mqabba', 'Paola', 'Santa Lucia', 'Senglea', 'Siggiewi', 'Tarxien', 'Xghajra', 'Zabbar', 'Zejtun', 'Qrendi', 'Zurrieq'] }
            ],
            'Gozo': [
              { label: 'Gozo', cities: ['Fontana', 'Ghajnsielem', 'Gharb', 'Ghasri', 'Kercem', 'Marsalforn', 'Munxar', 'Nadur', 'Qala', 'Rabat Victoria', 'San Lawrenz', 'Sannat', 'Xaghra', 'Xewkija', 'Zebbug Gozo'] }
            ]
          };
        
        function updateCities() {
            const islandSelect = document.getElementById('island');
            const citySelect = document.getElementById('city-select');
            const island = islandSelect.value;
        
            // Clear current options
            citySelect.innerHTML = '';
        
            if (!island || !locations[island]) {
              const option = document.createElement('option');
              option.value = '';
              option.textContent = 'Select Island First';
              citySelect.appendChild(option);
              citySelect.disabled = true;
              return;
            }
        
            // Add a default option
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Select City / Locality';
            citySelect.appendChild(defaultOption);
        
            const list = locations[island];
            list.forEach(group => {
              if (group.label && group.cities) {
                const optgroup = document.createElement('optgroup');
                optgroup.label = group.label;
                group.cities.forEach(city => {
                  const opt = document.createElement('option');
                  opt.value = city;
                  opt.textContent = city;
                  optgroup.appendChild(opt);
                });
                citySelect.appendChild(optgroup);
              } else {
                const opt = document.createElement('option');
                opt.value = group;
                opt.textContent = group;
                citySelect.appendChild(opt);
              }
            });
        
            citySelect.disabled = false;
        }
        
        // Initialize on page load if island is already selected
        document.addEventListener('DOMContentLoaded', function() {
            const islandSelect = document.getElementById('island');
            if (islandSelect && islandSelect.value) {
                updateCities();
            }
            });
    </script>
<?php require_once 'footer.php'; ?>
