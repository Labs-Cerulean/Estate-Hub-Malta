<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || 
    !isset($_SESSION['user']) || $_SESSION['user'] !== 'admin') {
  session_destroy();
  header("Location: index.php");
  exit;
}

require_once 'config.php';
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
    
    // Insert PA numbers
    if (!empty($_POST['panumber'])) {
      $paNumbers = array_map('trim', explode(',', $_POST['panumber']));
      $paStmt = $pdo->prepare("
        INSERT INTO project_pa_numbers (project_id, pa_number, pa_status)
        VALUES (?, ?, ?)
      ");
      foreach ($paNumbers as $pa) {
        if (!empty($pa)) {
          $paStmt->execute([$projectId, $pa, 'Endorsed']);
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Project – Estate Hub Malta</title>
  <link rel="icon" href="logo.png">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="header">
    <div class="header-container">
      <div style="display: flex; align-items: center; gap: 1rem;">
        <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo.png'">
        <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
      </div>
      <div class="header-right">
        <a href="mobilization.php" class="nav-link">Dashboard</a>
        <a href="clients.php" class="nav-link">Clients</a>
        <a href="apiauth.php?logout=1" class="nav-link">Logout</a>
      </div>
    </div>
  </header>

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

        <div class="form-group" style="grid-column: 1 / -1;">
          <label>PA Numbers (comma-separated)</label>
          <textarea name="panumber" placeholder="PA123, PA456, PA789"></textarea>
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
            <option value="Common Parts Only">Common Parts Only</option>
            <option value="Semi Finished">Semi Finished</option>
            <option value="Finished">Finished</option>
          </select>
        </div>

        <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem;">
          Create Project
        </button>
      </form>
    </section>

    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
      <a href="mobilization.php" class="nav-link" style="padding: 1rem 2rem; flex: 1; text-align: center;">
        Back to Dashboard
      </a>
    </div>
  </div>

  <script>
    // City data
    const locations = {
      Malta: [
        { label: 'Northern', cities: ['Gargur', 'Mellieha', 'Mosta', 'Naxxar', 'Rabat', 'San Pawl il-Bahar'] },
        { label: 'Central', cities: ['Attard', 'Balzan', 'Birkirkara', 'Gzira', 'Iklin', 'Lija', 'Luqa', 'Marsa', 'Pembroke', 'Qormi', 'San Giljan', 'Sliema', 'Swieqi', 'Valletta', 'Ta Xbiex'] },
        { label: 'Southern', cities: ['Birgu', 'Bormla', 'Fgura', 'Ghaxaq', 'Kirkop', 'Safi', 'Zebbug', 'Luqa', 'Marsascala', 'Marsaxlokk', 'Mqabba', 'Paola', 'Santa Lucia', 'Senglea', 'Siggiewi', 'Tarxien', 'Xgajra', 'Zabbar', 'Zejtun', 'Qrendi', 'Surreq'] }
      ],
      Gozo: [
        { label: 'Gozo', cities: ['Fontana', 'Gajnsielem', 'Gharb', 'Ghasri', 'Kercem', 'Munxar', 'Nadur', 'Qala', 'Rabat Victoria', 'San Lawrenz', 'Sannat', 'Xaghra', 'Xewkija', 'Zebbug Gozo'] }
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

    document.addEventListener('DOMContentLoaded', function() {
      const islandSelect = document.getElementById('island-select');
      if (islandSelect) {
        islandSelect.addEventListener('change', updateCities);
      }
    });
  </script>
</body>
</html>
