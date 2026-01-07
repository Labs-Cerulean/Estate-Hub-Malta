<?php
$pageTitle = 'Create Project';
include 'header.php';
$pdo = getDB();
$message = '';

// CREATE PROJECT
if (($_POST['action'] ?? null) === 'create') {
  try {
    // Insert project (no PA or status fields)
    $stmt = $pdo->prepare("
      INSERT INTO projects (clientid, name, city, type, finishlevel)
      VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
      $_POST['clientid'],
      $_POST['name'],
      $_POST['city'],
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
    
    $message = 'Project created successfully!';
  } catch (PDOException $e) {
    $message = 'Error creating project: ' . $e->getMessage();
  }
}

// Get clients for dropdown
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Project – Estate Hub Malta</title>
  <link rel="icon" href="logo.png">
  <link rel="stylesheet" href="styles.css">
  <style>
    .pa-entry {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      padding: 2rem;
      margin-bottom: 1.5rem;
      border-radius: 20px;
      backdrop-filter: blur(20px);
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
      transition: all 0.3s ease;
    }
    
    .pa-entry:hover {
      border-color: rgba(255,255,255,0.2);
      transform: translateY(-2px);
    }
    
    .pa-entry-header {
      grid-column: 1 / -1;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid var(--border-glass);
    }
    
    .pa-entry-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--text-primary);
      background: var(--corporate-gradient);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    
    .pa-number-field {
      grid-column: 1 / -1;
    }
    
    .remove-pa-btn {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.3);
      padding: 0.5rem 1rem;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.85rem;
      font-weight: 500;
      transition: all 0.3s ease;
      backdrop-filter: blur(10px);
    }
    
    .remove-pa-btn:hover {
      background: rgba(239, 68, 68, 0.3);
      transform: translateY(-1px);
    }
    
    .add-pa-btn {
      background: rgba(34, 197, 94, 0.2);
      color: #22c55e;
      border: 1px solid rgba(34, 197, 94, 0.3);
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      cursor: pointer;
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 1.5rem;
      transition: all 0.3s ease;
      backdrop-filter: blur(10px);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .add-pa-btn:hover {
      background: rgba(34, 197, 94, 0.3);
      transform: translateY(-2px);
    }
    
    .pa-section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid var(--border-glass);
    }
    
    .pa-section-title {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--text-primary);
    }
    
    @media (max-width: 768px) {
      .pa-entry {
        grid-template-columns: 1fr;
        padding: 1.5rem;
      }
      
      .pa-entry-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
      }
      
      .remove-pa-btn {
        width: 100%;
        text-align: center;
      }
    }
  </style>
</head>
<body>

  <div class="main-container">
    <h1 class="page-title">Create New Project</h1>

    <?php if ($message): ?>
      <div class="message success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <section class="form-section">
      <form method="POST" class="form-grid">
        <input type="hidden" name="action" value="create">

        <div class="form-group">
          <label>Client</label>
          <select name="clientid" required>
            <option value="">Select Client</option>
            <?php foreach ($clients as $client): ?>
              <option value="<?php echo $client['id']; ?>">
                <?php echo htmlspecialchars($client['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Project Name</label>
          <input type="text" name="name" placeholder="Project name" required>
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
          <label>City / Locality</label>
          <select name="city" id="city-select" required>
            <option value="">Select Island first</option>
          </select>
        </div>

        <div class="form-group">
          <label>Project Type</label>
          <select name="type" id="project-type" onchange="toggleFinishLevel()" required>
            <option value="">Select Type</option>
            <option value="in-house">In-House</option>
            <option value="3rd-party">3rd Party</option>
          </select>
        </div>

        <div class="form-group" id="finish-level-group" style="display: none;">
          <label>Finish Level</label>
          <select name="finishlevel" id="finish-level">
            <option value="">Select Finish Level</option>
            <option value="Shell">Shell (houses/villas)</option>
            <option value="Common Parts Only">Common Parts Only</option>
            <option value="Semi Finished">Semi Finished</option>
            <option value="Finished">Finished</option>
          </select>
        </div>

        <!-- PA Numbers Section -->
        <div style="grid-column: 1 / -1; margin-top: 2rem;">
          <div class="pa-section-header">
            <h3 class="pa-section-title">PA Numbers</h3>
            <button type="button" class="add-pa-btn" onclick="addPAEntry()">
              <span>+</span> Add PA Number
            </button>
          </div>
          
          <div id="pa-entries-container">
            <!-- PA entries will be added here dynamically -->
          </div>
        </div>

        <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem; margin-top: 2rem;">
          Create Project
        </button>
      </form>
    </section>

    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
      <a href="dashboard.php" class="nav-link" style="padding: 1rem 2rem; flex: 1; text-align: center;">
        Back to Dashboard
      </a>
    </div>
  </div>

  <script>
    let paEntryCount = 0;

    const architects = <?php echo json_encode($architects); ?>;
    const engineers = <?php echo json_encode($engineers); ?>;

    function addPAEntry() {
      const container = document.getElementById('pa-entries-container');
      const entryDiv = document.createElement('div');
      entryDiv.className = 'pa-entry';
      entryDiv.id = `pa-entry-${paEntryCount}`;
      
      entryDiv.innerHTML = `
        <div class="pa-entry-header">
          <span class="pa-entry-title">PA Entry ${paEntryCount + 1}</span>
          <button type="button" class="remove-pa-btn" onclick="removePAEntry(${paEntryCount})">Remove</button>
        </div>
        
        <div class="pa-number-field">
          <label>PA Number</label>
          <input type="text" name="pa_entries[${paEntryCount}][number]" 
                 placeholder="e.g., PA/01275/23" required>
        </div>
        
        <div>
          <label>PA Status</label>
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
        
        <div style="grid-column: 1 / -1;"></div>
        
        <div>
          <label>Architect</label>
          <select name="pa_entries[${paEntryCount}][architect]">
            <option value="">Select Architect (Optional)</option>
            ${architects.map(arch => 
              `<option value="${arch.id}">${escapeHtml(arch.name)}${arch.firm_name ? ' - ' + escapeHtml(arch.firm_name) : ''}</option>`
            ).join('')}
          </select>
        </div>
        
        <div>
          <label>Structural Engineer</label>
          <select name="pa_entries[${paEntryCount}][engineer]">
            <option value="">Select Engineer (Optional)</option>
            ${engineers.map(eng => 
              `<option value="${eng.id}">${escapeHtml(eng.name)}${eng.firm_name ? ' - ' + escapeHtml(eng.firm_name) : ''}</option>`
            ).join('')}
          </select>
        </div>
      `;
      
      container.appendChild(entryDiv);
      paEntryCount++;
    }

    function removePAEntry(index) {
      const entry = document.getElementById(`pa-entry-${index}`);
      if (entry) {
        entry.style.opacity = '0';
        entry.style.transform = 'translateY(10px)';
        setTimeout(() => entry.remove(), 300);
      }
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Add one PA entry by default when page loads
    document.addEventListener('DOMContentLoaded', function() {
      addPAEntry();
    });

    // City data
    const locations = {
      Malta: [
        { label: 'Northern', cities: ['Ghargur', 'Mellieha', 'Mosta', 'Naxxar', 'Rabat', 'San Pawl il-Bahar'] },
        { label: 'Central', cities: ['Attard', 'Balzan', 'Birkirkara', 'Gzira', 'Iklin', 'Lija', 'Luqa', 'Marsa', 'Msida', 'Pembroke', 'Pieta', 'Qormi', 'San Giljan', 'Sliema', 'St Venera', 'Swieqi', 'Valletta', 'Ta Xbiex'] },
        { label: 'Southern', cities: ['Birgu', 'Bormla', 'Fgura', 'Ghaxaq', 'Kirkop', 'Safi', 'Haz-Zebbug', 'Luqa', 'Marsascala', 'Marsaxlokk', 'Mqabba', 'Paola', 'Santa Lucia', 'Senglea', 'Siggiewi', 'Tarxien', 'Xghajra', 'Zabbar', 'Zejtun', 'Qrendi', 'Zurrieq'] }
      ],
      Gozo: [
        { label: 'Gozo', cities: ['Fontana', 'Ghajnsielem', 'Gharb', 'Ghasri', 'Kercem', 'Marsalforn', 'Munxar', 'Nadur', 'Qala', 'Rabat Victoria', 'San Lawrenz', 'Sannat', 'Xaghra', 'Xewkija', 'Zebbug Gozo'] }
      ]
    };

    function updateCities() {
      const islandSelect = document.getElementById('island-select');
      const citySelect = document.getElementById('city-select');
      const island = islandSelect.value;
      
      citySelect.innerHTML = '<option value="">Select City / Locality</option>';
      
      if (!island || !locations[island]) {
        citySelect.disabled = true;
        return;
      }
      
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
  </script>
</body>
</html>
