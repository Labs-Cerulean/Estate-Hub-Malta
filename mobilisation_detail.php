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

$projectId = $_GET['project_id'] ?? null;
if (!$projectId || !is_numeric($projectId)) {
  header("Location: mobilization.php");
  exit;
}

// Get project
$project = getProjectWithClient($pdo, $projectId);
if (!$project) {
  header("Location: mobilization.php");
  exit;
}

// Get mobilisation data
$mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
$mobStmt->execute([$projectId]);
$mob = $mobStmt->fetch();

if (!$mob) {
  $pdo->prepare("INSERT INTO project_mobilisation (project_id) VALUES (?)")->execute([$projectId]);
  $mobStmt->execute([$projectId]);
  $mob = $mobStmt->fetch();
}

// Get PA numbers
$paNumbers = getProjectPANumbers($pdo, $projectId);

// Handle updates - SERVER-SIDE VALIDATION
$message = '';
if (($_POST['action'] ?? null) === 'update_mobilisation') {
  try {
    // Get prerequisite states for validation
    $geoTest = $_POST['geological_test'] ?? $mob['geological_test'] ?? 'NA';
    $condReports = $_POST['condition_reports'] ?? $mob['condition_reports'] ?? 'Not Started';
    
    // Sequential Chain unlock condition: Geo = Complete OR NA, AND Condition = Complete
    $canSequential = ($geoTest === 'Complete' || $geoTest === 'NA') && $condReports === 'Complete';
    
    // Check if ALL sequential fields are Complete
    $seqFields = ['method_statements', 'insurance_status', 'pavement_guarantee', 
                  'wellbeing_guarantee', 'umbrella_guarantee'];
    $allSequentialComplete = true;
    
    foreach ($seqFields as $field) {
      $value = $_POST[$field] ?? $mob[$field] ?? 'Not Complete';
      if ($value !== 'Complete') {
        $allSequentialComplete = false;
        break;
      }
    }
    
    $respForm = $_POST['responsibility_form'] ?? $mob['responsibility_form'] ?? 'Not Complete';
    
    // Final Clearance unlock: ALL sequential Complete AND Responsibility Form Complete
    $canFinal = $allSequentialComplete && $respForm === 'Complete';
    
    // Build update statement
    $updates = [];
    $values = [];
    $allowedFields = [
      'acquisition_complete', 'acquisition_date',
      'archaeologist_assigned', 'change_of_applicant', 
      'geological_test', 'condition_report_contacts', 'condition_reports',
      'method_statements', 'insurance_status', 'pavement_guarantee',
      'wellbeing_guarantee', 'umbrella_guarantee',
      'responsibility_form'
    ];

    foreach ($allowedFields as $field) {
      if (isset($_POST[$field]) && $_POST[$field] !== '') {
        $updates[] = "$field = ?";
        $values[] = $_POST[$field];
      }
    }

    // ONLY allow BCA update if prerequisites met
    if ($canFinal && isset($_POST['bca_clearance'])) {
      $updates[] = "bca_clearance = ?";
      $values[] = $_POST['bca_clearance'];
    }

    if (!empty($updates)) {
      $values[] = $projectId;
      $updateStmt = $pdo->prepare("
        UPDATE project_mobilisation 
        SET " . implode(', ', $updates) . "
        WHERE project_id = ?
      ");
      $updateStmt->execute($values);
      $message = 'Mobilisation steps updated successfully!';
      
      // Refresh
      $mobStmt->execute([$projectId]);
      $mob = $mobStmt->fetch();
    }
  } catch (PDOException $e) {
    $message = 'Error: ' . $e->getMessage();
  }
}

if (($_POST['action'] ?? null) === 'update_pa') {
  try {
    $paId = $_POST['pa_id'] ?? null;
    $paStatus = $_POST['pa_status'] ?? null;
    
    if ($paId && $paStatus) {
      $pdo->prepare("
        UPDATE project_pa_numbers 
        SET pa_status = ? 
        WHERE id = ? AND project_id = ?
      ")->execute([$paStatus, $paId, $projectId]);
      
      $message = 'PA status updated!';
      $paNumbers = getProjectPANumbers($pdo, $projectId);
    }
  } catch (PDOException $e) {
    $message = 'Error: ' . $e->getMessage();
  }
}

$mobilisationStatus = deriveMobilisationStatus($pdo, $projectId);

// Recalculate unlock states for display
$geoComplete = ($mob['geological_test'] ?? 'NA') === 'Complete' || ($mob['geological_test'] ?? 'NA') === 'NA';
$condComplete = ($mob['condition_reports'] ?? 'Not Started') === 'Complete';
$canSequential = $geoComplete && $condComplete;

$seqFields = ['method_statements', 'insurance_status', 'pavement_guarantee', 
              'wellbeing_guarantee', 'umbrella_guarantee'];
$allSeqComplete = true;
foreach ($seqFields as $field) {
  if (($mob[$field] ?? 'Not Complete') !== 'Complete') {
    $allSeqComplete = false;
    break;
  }
}
$respComplete = ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete';
$canFinal = $allSeqComplete && $canSequential;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($project['name']); ?> – Estate Hub Malta</title>
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
        <a href="mobilization.php" class="nav-link">← Back</a>
        <a href="apiauth.php?logout=1" class="nav-link">Logout</a>
      </div>
    </div>
  </header>

  <div class="main-container">
    <h1 class="page-title"><?php echo htmlspecialchars($project['name']); ?></h1>

    <?php if ($message): ?>
      <div class="message success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="projects-section" style="margin-bottom: 2rem;">
      <div class="project-meta">
        <div class="meta-item">
          <span class="meta-label">Client</span>
          <span><?php echo htmlspecialchars($project['client_name'] ?? 'Unknown'); ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Type</span>
          <span><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">City</span>
          <span><?php echo htmlspecialchars($project['city']); ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Mobilisation Status</span>
          <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $mobilisationStatus)); ?>">
            <?php echo htmlspecialchars($mobilisationStatus); ?>
          </span>
        </div>
      </div>
    </div>

    <!-- PA Management -->
    <?php if (!empty($paNumbers)): ?>
      <section class="projects-section">
        <div class="section-title" style="margin-bottom: 1.5rem;">PA Numbers</div>
        <div class="projects-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
          <?php foreach ($paNumbers as $pa): ?>
            <form method="POST" class="card">
              <div class="card__body">
                <div style="margin-bottom: 1rem; font-weight: 600;">
                  <?php echo htmlspecialchars($pa['pa_number']); ?>
                </div>
                <div class="form-group">
                  <label>Status</label>
                  <select name="pa_status">
                    <option value="Endorsed" <?php echo $pa['pa_status'] === 'Endorsed' ? 'selected' : ''; ?>>Endorsed</option>
                    <option value="Approved" <?php echo $pa['pa_status'] === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Fee Payment" <?php echo $pa['pa_status'] === 'Fee Payment' ? 'selected' : ''; ?>>Fee Payment</option>
                    <option value="Not Approved" <?php echo $pa['pa_status'] === 'Not Approved' ? 'selected' : ''; ?>>Not Approved</option>
                  </select>
                </div>
                <input type="hidden" name="action" value="update_pa">
                <input type="hidden" name="pa_id" value="<?php echo $pa['id']; ?>">
                <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">Update</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Mobilisation Steps -->
    <section class="projects-section">
      <div class="section-title" style="margin-bottom: 1.5rem;">Mobilisation Steps</div>
      
      <form method="POST" class="form-grid">
        <input type="hidden" name="action" value="update_mobilisation">

        <!-- ACQUISITION (In-house only) -->
        <?php if ($project['type'] === 'in-house'): ?>
          <fieldset style="grid-column: 1 / -1; border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; background: var(--bg-card); margin-bottom: 1.5rem;">
            <legend style="font-weight: 600; margin-bottom: 1rem;">🏠 Acquisition Complete</legend>
            
            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
              <div class="form-group">
                <label>Status</label>
                <select name="acquisition_complete">
                  <option value="No" <?php echo ($mob['acquisition_complete'] ?? 'No') === 'No' ? 'selected' : ''; ?>>No (Red)</option>
                  <option value="Yes" <?php echo ($mob['acquisition_complete'] ?? 'No') === 'Yes' ? 'selected' : ''; ?>>Yes (Green)</option>
                </select>
              </div>

              <div class="form-group">
                <label>Date <?php echo ($mob['acquisition_complete'] ?? 'No') === 'No' ? '(Target)' : '(Actual)'; ?></label>
                <input type="date" name="acquisition_date" value="<?php echo $mob['acquisition_date'] ?? ''; ?>">
              </div>
            </div>
          </fieldset>
        <?php endif; ?>

        <!-- NON-SEQUENTIAL TASKS -->
        <fieldset style="grid-column: 1 / -1; border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; background: var(--bg-card); margin-bottom: 1.5rem;">
          <legend style="font-weight: 600; margin-bottom: 1rem;">📋 Non-Sequential Tasks</legend>
          
          <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <div class="form-group">
              <label>Archaeologist Assigned</label>
              <select name="archaeologist_assigned">
                <option value="NA" <?php echo ($mob['archaeologist_assigned'] ?? 'NA') === 'NA' ? 'selected' : ''; ?>>N/A</option>
                <option value="Yes" <?php echo ($mob['archaeologist_assigned'] ?? 'NA') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                <option value="No" <?php echo ($mob['archaeologist_assigned'] ?? 'NA') === 'No' ? 'selected' : ''; ?>>No</option>
              </select>
            </div>

            <div class="form-group">
              <label>Change of Applicant</label>
              <select name="change_of_applicant">
                <option value="NA" <?php echo ($mob['change_of_applicant'] ?? 'NA') === 'NA' ? 'selected' : ''; ?>>N/A</option>
                <option value="Complete" <?php echo ($mob['change_of_applicant'] ?? 'NA') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                <option value="Not Complete" <?php echo ($mob['change_of_applicant'] ?? 'NA') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Geological Test</label>
              <select name="geological_test">
                <option value="NA" <?php echo ($mob['geological_test'] ?? 'NA') === 'NA' ? 'selected' : ''; ?>>N/A</option>
                <option value="Complete" <?php echo ($mob['geological_test'] ?? 'NA') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                <option value="Not Complete" <?php echo ($mob['geological_test'] ?? 'NA') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                <option value="Awaiting Result" <?php echo ($mob['geological_test'] ?? 'NA') === 'Awaiting Result' ? 'selected' : ''; ?>>Awaiting Result</option>
              </select>
            </div>

            <div class="form-group">
              <label>Condition Report Contacts</label>
              <select name="condition_report_contacts">
                <option value="Not Started" <?php echo ($mob['condition_report_contacts'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['condition_report_contacts'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['condition_report_contacts'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Condition Reports</label>
              <select name="condition_reports">
                <option value="Not Started" <?php echo ($mob['condition_reports'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['condition_reports'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['condition_reports'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>
          </div>
        </fieldset>

        <!-- SEQUENTIAL CHAIN -->
        <fieldset style="grid-column: 1 / -1; border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; background: var(--bg-card); opacity: <?php echo $canSequential ? '1' : '0.6'; ?>; margin-bottom: 1.5rem;">
          <legend style="font-weight: 600; margin-bottom: 1rem;">
            🔗 Sequential Chain
            <?php if (!$canSequential): ?>
              <span style="font-size: 0.9rem; color: var(--warning); font-weight: 400;">
                (Unlock when Geological Test Complete/NA & Condition Reports Complete)
              </span>
            <?php endif; ?>
          </legend>
          
          <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <div class="form-group">
              <label>Method Statements</label>
              <select name="method_statements" <?php echo !$canSequential ? 'disabled' : ''; ?>>
                <option value="Not Complete" <?php echo ($mob['method_statements'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                <option value="Completed" <?php echo ($mob['method_statements'] ?? 'Not Complete') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
              </select>
            </div>

            <div class="form-group">
              <label>Insurance</label>
              <select name="insurance_status" <?php echo !$canSequential ? 'disabled' : ''; ?>>
                <option value="Not Started" <?php echo ($mob['insurance_status'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['insurance_status'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['insurance_status'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Pavement Guarantee</label>
              <select name="pavement_guarantee" <?php echo !$canSequential ? 'disabled' : ''; ?>>
                <option value="Not Started" <?php echo ($mob['pavement_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['pavement_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['pavement_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Wellbeing Guarantee</label>
              <select name="wellbeing_guarantee" <?php echo !$canSequential ? 'disabled' : ''; ?>>
                <option value="Not Started" <?php echo ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Umbrella Guarantee</label>
              <select name="umbrella_guarantee" <?php echo !$canSequential ? 'disabled' : ''; ?>>
                <option value="Not Started" <?php echo ($mob['umbrella_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['umbrella_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['umbrella_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>
          </div>
        </fieldset>

        <!-- FINAL CLEARANCE -->
        <fieldset style="grid-column: 1 / -1; border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; background: var(--bg-card); opacity: <?php echo $canFinal ? '1' : '0.6'; ?>;">
          <legend style="font-weight: 600; margin-bottom: 1rem;">
            ✓ Final Clearance
            <?php if (!$canFinal): ?>
              <span style="font-size: 0.9rem; color: var(--warning); font-weight: 400;">
                (Unlock when ALL Sequential Chain Complete)
              </span>
            <?php endif; ?>
          </legend>
          
          <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <div class="form-group">
              <label>Responsibility Form</label>
              <select name="responsibility_form" <?php echo !$canFinal ? 'disabled' : ''; ?>>
                <option value="Not Complete" <?php echo ($mob['responsibility_form'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                <option value="Complete" <?php echo ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>BCA Clearance (Manual)</label>
              <select name="bca_clearance" <?php echo !$canFinal ? 'disabled' : ''; ?>>
                <option value="No" <?php echo ($mob['bca_clearance'] ?? 'No') === 'No' ? 'selected' : ''; ?>>No</option>
                <option value="Yes" <?php echo ($mob['bca_clearance'] ?? 'No') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
              </select>
            </div>
          </div>
        </fieldset>

        <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem; margin-top: 1rem;">
          Save All Changes
        </button>
      </form>
    </section>

  </div>
</body>
</html>
