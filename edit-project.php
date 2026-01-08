<?php
require_once 'init.php';
require_once 'session-check.php';

// Only admins and managers can edit projects
if (!isAdmin() && getCurrentRole() != 'manager') {
    header('Location: dashboard.php');
    exit;
}

$projectId = $_GET['id'] ?? null;
if (!$projectId) {
    header('Location: dashboard.php');
    exit;
}

// Check project access
if (!hasProjectAccess($pdo, $projectId)) {
    header('Location: dashboard.php?error=accessdenied');
    exit;
}

// Get project details
$project = getProjectWithClient($pdo, $projectId);
if (!$project) {
    header('Location: dashboard.php');
    exit;
}

// Initialize message variables
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    try {
        // Get form data
        $clientId = $_POST['clientid'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $island = $_POST['island'] ?? '';
        $type = $_POST['type'] ?? '';
        $finishLevel = ($_POST['finishlevel'] ?? '') ?: null;
        
        // Validate required fields
        if (empty($clientId) || empty($name) || empty($city) || empty($island) || empty($type)) {
            $error = 'Please fill in all required fields.';
        } else {
            // Update project
            $stmt = $pdo->prepare("
                UPDATE projects 
                SET clientid = ?, name = ?, city = ?, island = ?, type = ?, finishlevel = ?
                WHERE id = ?
            ");
            $stmt->execute([$clientId, $name, $city, $island, $type, $finishLevel, $projectId]);
            
            // Delete existing PA numbers
            $deleteStmt = $pdo->prepare("DELETE FROM project_pa_numbers WHERE project_id = ?");
            $deleteStmt->execute([$projectId]);
            
            // Insert new PA numbers
            if (isset($_POST['paentries']) && is_array($_POST['paentries'])) {
                $paStmt = $pdo->prepare("
                    INSERT INTO project_pa_numbers (project_id, pa_number, pa_status, architect_id, structuralengineer_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($_POST['paentries'] as $paEntry) {
                    if (!empty($paEntry['number'])) {
                        $paNumber = trim($paEntry['number']);
                        $paStatus = $paEntry['status'] ?? 'Endorsed';
                        $architectId = !empty($paEntry['architect']) ? $paEntry['architect'] : null;
                        $engineerId = !empty($paEntry['engineer']) ? $paEntry['engineer'] : null;
                        
                        $paStmt->execute([$projectId, $paNumber, $paStatus, $architectId, $engineerId]);
                    }
                }
            }
            
            $message = 'Project updated successfully!';
            
            // Refresh project data
            $project = getProjectWithClient($pdo, $projectId);
        }
    } catch (PDOException $e) {
        $error = 'Error updating project: ' . $e->getMessage();
    }
}

// Get data for dropdowns
$userId = getCurrentUserId();
$isAdmin = isAdmin();

if ($isAdmin) {
    $clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
} else {
    $clients = getUserClients($pdo, $userId);
}

$architects = $pdo->query("
    SELECT id, name, firm_name 
    FROM professionals 
    WHERE role_type = 'architect' 
    ORDER BY name
")->fetchAll();

$engineers = $pdo->query("
    SELECT id, name, firm_name 
    FROM professionals 
    WHERE role_type = 'structural_engineer' 
    ORDER BY name
")->fetchAll();

// Get existing PA numbers
$paNumbers = getProjectPANumbers($pdo, $projectId);

// Set page title
$pageTitle = 'Edit Project - ' . $project['name'];

// Now output HTML
require_once 'header.php';
?>


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


<div class="main-container">
  <h1 class="page-title">Edit Project: <?php echo htmlspecialchars($project['name']); ?></h1>

  <?php if ($message): ?>
    <div class="message success"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <?php if ($project): ?>
  <section class="form-section">
    <form method="POST" class="form-grid">
      <input type="hidden" name="action" value="update">

      <div class="form-group">
        <label>Client</label>
        <select name="clientid" required>
          <option value="">Select Client</option>
          <?php foreach ($clients as $client): ?>
            <option value="<?php echo $client['id']; ?>" 
              <?php echo ($client['id'] == $project['clientid']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($client['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Project Name</label>
        <input type="text" name="name" value="<?php echo htmlspecialchars($project['name']); ?>" 
               placeholder="Project name" required>
      </div>

      <div class="form-group">
          <label>Island</label>
          <select name="island" id="island" onchange="updateCities()" required>
            <option value="">Select Island</option>
            <option value="Malta" <?php echo (isset($project['island']) && $project['island'] === 'Malta') ? 'selected' : ''; ?>>Malta</option>
            <option value="Gozo" <?php echo (isset($project['island']) && $project['island'] === 'Gozo') ? 'selected' : ''; ?>>Gozo</option>
          </select>
        </div>

      <div class="form-group">
        <label>City / Locality</label>
        <select name="city" id="city-select" required>
          <option value="<?php echo htmlspecialchars($project['city']); ?>" selected>
            <?php echo htmlspecialchars($project['city']); ?>
          </option>
        </select>
      </div>

      <div class="form-group">
        <label>Project Type</label>
        <select name="type" id="project-type" onchange="toggleFinishLevel()" required>
          <option value="">Select Type</option>
          <option value="in-house" <?php echo ($project['type'] == 'in-house') ? 'selected' : ''; ?>>In-House</option>
          <option value="3rd-party" <?php echo ($project['type'] == '3rd-party') ? 'selected' : ''; ?>>3rd Party</option>
        </select>
      </div>

      <div class="form-group" id="finish-level-group" style="display: <?php echo ($project['type'] == 'in-house') ? 'block' : 'none'; ?>;">
        <label>Finish Level</label>
        <select name="finishlevel" id="finish-level">
          <option value="">Select Finish Level</option>
        <option value="Shell" <?php echo ($project['finishlevel'] == 'Shell') ? 'selected' : ''; ?>>Shell (houses/villas)</option>
          <option value="Common Parts Only" <?php echo ($project['finishlevel'] == 'Common Parts Only') ? 'selected' : ''; ?>>Common Parts Only</option>
          <option value="Semi Finished" <?php echo ($project['finishlevel'] == 'Semi Finished') ? 'selected' : ''; ?>>Semi Finished</option>
          <option value="Finished" <?php echo ($project['finishlevel'] == 'Finished') ? 'selected' : ''; ?>>Finished</option>
        </select>
      </div>

      <!-- PA Numbers Section -->
      <div style="grid-column: 1 / -1; margin-top: 2rem;">
        <div class="pa-section-header">
          <h3 class="pa-section-title">PA Numbers</h3>
          <button type="button" class="add-pa-btn" onclick="addPAEntry()">
            <span>➕</span> Add PA Number
          </button>
        </div>

        <div id="pa-entries-container">
          <!-- Existing PA entries will be loaded here -->
        </div>
      </div>

      <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem; margin-top: 2rem;">
        Update Project
      </button>
    </form>
  </section>

  <div style="display: flex; gap: 1rem; margin-top: 2rem;">
    <a href="dashboard.php" class="nav-link" style="padding: 1rem 2rem; flex: 1; text-align: center;">
      Back to Dashboard
    </a>
  </div>
  <?php endif; ?>
</div>

<script>
  let paEntryCount = 0;

  const architects = <?php echo json_encode($architects); ?>;
  const engineers = <?php echo json_encode($engineers); ?>;
  const existingPANumbers = <?php echo json_encode($paNumbers); ?>;

  function addPAEntry(paData = null) {
    const container = document.getElementById('pa-entries-container');
    const entryDiv = document.createElement('div');
    entryDiv.className = 'pa-entry';
    entryDiv.id = `pa-entry-${paEntryCount}`;

    const paNumber = paData ? paData.pa_number : '';
    const paStatus = paData ? paData.pa_status : 'Endorsed';
    const architectId = paData ? paData.architect_id : '';
    const engineerId = paData ? paData.structural_engineer_id : '';

    entryDiv.innerHTML = `
      <div class="pa-entry-header">
        <span class="pa-entry-title">PA Entry ${paEntryCount + 1}</span>
        <button type="button" class="remove-pa-btn" onclick="removePAEntry(${paEntryCount})">Remove</button>
      </div>

      <div class="pa-number-field">
        <label>PA Number</label>
        <input type="text" name="pa_entries[${paEntryCount}][number]" 
               value="${escapeHtml(paNumber)}"
               placeholder="e.g., PA/01275/23" required>
      </div>

      <div>
        <label>PA Status</label>
        <select name="pa_entries[${paEntryCount}][status]">
          <option value="Endorsed" ${paStatus === 'Endorsed' ? 'selected' : ''}>Endorsed</option>
          <option value="Decided" ${paStatus === 'Decided' ? 'selected' : ''}>Decided</option>
          <option value="Fee Payment" ${paStatus === 'Fee Payment' ? 'selected' : ''}>Fee Payment</option>
          <option value="Refused" ${paStatus === 'Refused' ? 'selected' : ''}>Refused</option>
          <option value="Pending/Awaiting Decision" ${paStatus === 'Pending/Awaiting Decision' ? 'selected' : ''}>Pending/Awaiting Decision</option>
          <option value="Recommended for Approval" ${paStatus === 'Recommended for Approval' ? 'selected' : ''}>Recommended for Approval</option>
          <option value="Recommended for Refusal" ${paStatus === 'Recommended for Refusal' ? 'selected' : ''}>Recommended for Refusal</option>
          <option value="Under Appeal" ${paStatus === 'Under Appeal' ? 'selected' : ''}>Under Appeal</option>
          <option value="Revoked/Annulled" ${paStatus === 'Revoked/Annulled' ? 'selected' : ''}>Revoked/Annulled</option>
          <option value="Withdrawn" ${paStatus === 'Withdrawn' ? 'selected' : ''}>Withdrawn</option>
        </select>
      </div>

      <div style="grid-column: 1 / -1;"></div>

      <div>
        <label>Architect</label>
        <select name="pa_entries[${paEntryCount}][architect]">
          <option value="">Select Architect (Optional)</option>
          ${architects.map(arch => 
            `<option value="${arch.id}" ${arch.id == architectId ? 'selected' : ''}>${escapeHtml(arch.name)}${arch.firm_name ? ' - ' + escapeHtml(arch.firm_name) : ''}</option>`
          ).join('')}
        </select>
      </div>

      <div>
        <label>Structural Engineer</label>
        <select name="pa_entries[${paEntryCount}][engineer]">
          <option value="">Select Engineer (Optional)</option>
          ${engineers.map(eng => 
            `<option value="${eng.id}" ${eng.id == engineerId ? 'selected' : ''}>${escapeHtml(eng.name)}${eng.firm_name ? ' - ' + escapeHtml(eng.firm_name) : ''}</option>`
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
    div.textContent = text || '';
    return div.innerHTML;
  }

  // Load existing PA numbers when page loads
  document.addEventListener('DOMContentLoaded', function() {
    if (existingPANumbers && existingPANumbers.length > 0) {
      existingPANumbers.forEach(pa => {
        addPAEntry(pa);
      });
    } else {
      // Add one empty entry if no PA numbers exist
      addPAEntry();
    }

    // Trigger city update to populate cities based on selected island
    updateCities();
  });

  // City data
  const locations = {
    'Malta': [
      { label: 'Northern', cities: ['Ghargur', 'Mellieha', 'Mosta', 'Naxxar', 'Rabat', 'San Pawl il-Bahar'] },
      { label: 'Central', cities: ['Attard', 'Balzan', 'Birkirkara', 'Gzira', 'Iklin', 'Lija', 'Luqa', 'Marsa', 'Msida', 'Pembroke', 'Pieta', 'Qormi', 'San Giljan', 'Sliema', 'St Venera', 'Swieqi', 'Valletta', 'Ta Xbiex'] },
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
    const currentCity = citySelect.value; // Preserve current selection

    // Clear all options except the first (current selection)
    const firstOption = citySelect.options[0];
    citySelect.innerHTML = '';

    if (!island || !locations[island]) {
      citySelect.appendChild(firstOption);
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
          if (city === currentCity) {
            opt.selected = true;
          }
          optgroup.appendChild(opt);
        });
        citySelect.appendChild(optgroup);
      } else {
        const opt = document.createElement('option');
        opt.value = group;
        opt.textContent = group;
        if (group === currentCity) {
          opt.selected = true;
        }
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

<?php require_once 'footer.php'; ?>
