<?php
require_once 'init.php';
require_once 'session-check.php';

// Get and validate project ID - accept both formats for compatibility
$projectId = $_GET['project_id'] ?? $_GET['projectid'] ?? null;

if (!$projectId) {
    header('Location: dashboard.php');
    exit;
}

// Check project access
if (!hasProjectAccess($pdo, $projectId)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// Get project details
$project = getProjectWithClient($pdo, $projectId);

if (!$project) {
    header('Location: dashboard.php');
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
        
        // Sequential Chain unlock condition: Geo = Complete OR NA, AND Condition = Complete OR NA
        $canSequential = ($geoTest === 'Complete' || $geoTest === 'NA') && 
                        ($condReports === 'Complete' || $condReports === 'NA');
        
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
        
        // BCA Clearance field unlock: Responsibility Form Complete
        $can = $respForm === 'Complete';
        
        // Build update statement
        $updates = [];
        $values = [];
        $allowedFields = [
            'acquisition_complete', 'acquisition_date', 'archaeologist_assigned',
            'change_of_applicant', 'geological_test', 'condition_report_contacts',
            'condition_reports', 'method_statements', 'insurance_status',
            'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee',
            'responsibility_form'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $updates[] = "$field = ?";
                $values[] = $_POST[$field];
            }
        }
        
        // ONLY allow  update if Responsibility Form Complete
        if ($can && isset($_POST['_clearance'])) {
            $updates[] = "_clearance = ?";
            $values[] = $_POST['_clearance'];
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

// Handle Services Engineer updates
if (($_POST['action'] ?? null) === 'update_services') {
    try {
        // Only allow if user is admin or services engineer
        if (isAdmin() || isServicesEngineer()) {
            $servicesStmt = $pdo->prepare("
                INSERT INTO project_services (
                    project_id,
                    existing_meters_required, existing_meters_complete,
                    enemalta_deviation_required, enemalta_deviation_complete,
                    go_deviation_required, go_deviation_complete,
                    melita_deviation_required, melita_deviation_complete,
                    lc_lamps_required, lc_lamps_complete,
                    temp_elec_meter_required, temp_elec_meter_complete,
                    temp_wsc_meter_required, temp_wsc_meter_complete
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    existing_meters_required = VALUES(existing_meters_required),
                    existing_meters_complete = VALUES(existing_meters_complete),
                    enemalta_deviation_required = VALUES(enemalta_deviation_required),
                    enemalta_deviation_complete = VALUES(enemalta_deviation_complete),
                    go_deviation_required = VALUES(go_deviation_required),
                    go_deviation_complete = VALUES(go_deviation_complete),
                    melita_deviation_required = VALUES(melita_deviation_required),
                    melita_deviation_complete = VALUES(melita_deviation_complete),
                    lc_lamps_required = VALUES(lc_lamps_required),
                    lc_lamps_complete = VALUES(lc_lamps_complete),
                    temp_elec_meter_required = VALUES(temp_elec_meter_required),
                    temp_elec_meter_complete = VALUES(temp_elec_meter_complete),
                    temp_wsc_meter_required = VALUES(temp_wsc_meter_required),
                    temp_wsc_meter_complete = VALUES(temp_wsc_meter_complete)
            ");
            
            $servicesStmt->execute([
                $projectId,
                $_POST['existing_meters_required'] ?? 'Not Required',
                $_POST['existing_meters_complete'] ?? 'Not Complete',
                $_POST['enemalta_deviation_required'] ?? 'Not Required',
                $_POST['enemalta_deviation_complete'] ?? 'Not Complete',
                $_POST['go_deviation_required'] ?? 'Not Required',
                $_POST['go_deviation_complete'] ?? 'Not Complete',
                $_POST['melita_deviation_required'] ?? 'Not Required',
                $_POST['melita_deviation_complete'] ?? 'Not Complete',
                $_POST['lc_lamps_required'] ?? 'Not Required',
                $_POST['lc_lamps_complete'] ?? 'Not Complete',
                $_POST['temp_elec_meter_required'] ?? 'Not Required',
                $_POST['temp_elec_meter_complete'] ?? 'Not Complete',
                $_POST['temp_wsc_meter_required'] ?? 'Not Required',
                $_POST['temp_wsc_meter_complete'] ?? 'Not Complete'
            ]);
            
            $message = 'Services & Utilities updated successfully!';
        }
    } catch (PDOException $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

$mobilisationStatus = deriveMobilisationStatus($pdo, $projectId);

// Recalculate unlock states for display
$geoComplete = ($mob['geological_test'] ?? 'NA') === 'Complete' || ($mob['geological_test'] ?? 'NA') === 'NA';
$condComplete = ($mob['condition_reports'] ?? 'Not Started') === 'Complete' || ($mob['condition_reports'] ?? 'Not Started') === 'NA';
$canSequential = $geoComplete && $condComplete;

$seqFieldsDisplay = ['method_statements', 'insurance_status', 'pavement_guarantee', 
                     'wellbeing_guarantee', 'umbrella_guarantee'];
$allSeqComplete = true;
foreach ($seqFieldsDisplay as $field) {
    if (($mob[$field] ?? 'Not Complete') !== 'Complete') {
        $allSeqComplete = false;
        break;
    }
}

$respComplete = ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete';
$canFinal = $allSeqComplete;
$can = $respComplete;

// Check if user can edit this project
$canEdit = canEditProject($pdo, $projectId);

// NEW: Check if user can edit services section
$canEditServices = $canEdit || isServicesEngineer();

// Set disabled and readonly attributes based on edit permissions
$disabledAttr = $canEdit ? '' : 'disabled';
$readonlyAttr = $canEdit ? '' : 'readonly';

// Set page title
$pageTitle = 'Mobilisation - ' . $project['name'];

// Now output HTML
require_once 'header.php';
?>

  <div class="main-container">
    <h1 class="page-title"><?php echo htmlspecialchars($project['name']); ?></h1>

    <?php if ($message): ?>
      <div class="message success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="projects-section" style="margin-bottom: 2rem;">
      <div class="project-meta">
        <div class="meta-item">
          <span class="meta-label">Client: </span>
          <span><?php echo htmlspecialchars($project['client_name'] ?? 'Unknown'); ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Type: </span>
          <span><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">City: </span>
          <span><?php echo htmlspecialchars($project['city']); ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Mobilisation Status: </span>
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
                  <select name="pa_status" <?php echo $disabledAttr; ?>>
                    <option value="Endorsed" <?php echo $pa['pa_status'] === 'Endorsed' ? 'selected' : ''; ?>>Endorsed</option>
                    <option value="Decided" <?php echo $pa['pa_status'] === 'Decided' ? 'selected' : ''; ?>>Decided</option>
                    <option value="Fee Payment" <?php echo $pa['pa_status'] === 'Fee Payment' ? 'selected' : ''; ?>>Fee Payment</option>
                    <option value="Refused" <?php echo $pa['pa_status'] === 'Refused' ? 'selected' : ''; ?>>Refused</option>
                    <option value="Pending/Awaiting Decision" <?php echo $pa['pa_status'] === 'Pending/Awaiting Decision' ? 'selected' : ''; ?>>Pending/Awaiting Decision</option>
                    <option value="Recommended for Approval" <?php echo $pa['pa_status'] === 'Recommended for Approval' ? 'selected' : ''; ?>>Recommended for Approval</option>
                    <option value="Recommended for Refusal" <?php echo $pa['pa_status'] === 'Recommended for Refusal' ? 'selected' : ''; ?>>Recommended for Refusal</option>
                    <option value="Under Appeal" <?php echo $pa['pa_status'] === 'Under Appeal' ? 'selected' : ''; ?>>Under Appeal</option>
                    <option value="Revoked/Annulled" <?php echo $pa['pa_status'] === 'Revoked/Annulled' ? 'selected' : ''; ?>>Revoked/Annulled</option>
                    <option value="Withdrawn" <?php echo $pa['pa_status'] === 'Withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                  </select>
                </div>
                <input type="hidden" name="action" value="update_pa">
                <input type="hidden" name="pa_id" value="<?php echo $pa['id']; ?>">
                <?php if ($canEdit): ?> <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">Update</button> <?php endif; ?>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Mobilisation Steps -->
    <section class="projects-section">
      <div class="section-title" style="margin-bottom: 1.5rem;">BCA Mobilisation Steps</div>
      
      <form method="POST" class="form-grid">
        <input type="hidden" name="action" value="update_mobilisation">

        <!-- ACQUISITION (In-house only) -->
        <?php if ($project['type'] === 'in-house'): ?>
          <fieldset style="grid-column: 1 / -1; border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; background: var(--bg-card); margin-bottom: 1.5rem;">
            <legend style="font-weight: 600; margin-bottom: 1rem;">🏠 Acquisition Complete</legend>
            
            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
              <div class="form-group">
                <label>Status</label>
                <select name="acquisition_complete" <?php echo $disabledAttr; ?>>
                  <option value="No" <?php echo ($mob['acquisition_complete'] ?? 'No') === 'No' ? 'selected' : ''; ?>>No (Red)</option>
                  <option value="Yes" <?php echo ($mob['acquisition_complete'] ?? 'No') === 'Yes' ? 'selected' : ''; ?>>Yes (Green)</option>
                </select>
              </div>

              <div class="form-group">
                <label>Date <?php echo ($mob['acquisition_complete'] ?? 'No') === 'No' ? '(Target)' : '(Actual)'; ?></label>
                <input type="date" name="acquisition_date" value="<?php echo $mob['acquisition_date'] ?? ''; ?>" <?php echo $disabledAttr; ?>>
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
              <select name="archaeologist_assigned" <?php echo $disabledAttr; ?>>
                <option value="NA" <?php echo ($mob['archaeologist_assigned'] ?? 'NA') === 'NA' ? 'selected' : ''; ?>>N/A</option>
                <option value="Yes" <?php echo ($mob['archaeologist_assigned'] ?? 'NA') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                <option value="No" <?php echo ($mob['archaeologist_assigned'] ?? 'NA') === 'No' ? 'selected' : ''; ?>>No</option>
              </select>
            </div>

            <div class="form-group">
              <label>Change of Applicant</label>
              <select name="change_of_applicant" <?php echo $disabledAttr; ?>>
                <option value="NA" <?php echo ($mob['change_of_applicant'] ?? 'NA') === 'NA' ? 'selected' : ''; ?>>N/A</option>
                <option value="Complete" <?php echo ($mob['change_of_applicant'] ?? 'NA') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                <option value="Not Complete" <?php echo ($mob['change_of_applicant'] ?? 'NA') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Geological Test</label>
              <select name="geological_test" <?php echo $disabledAttr; ?>>
                <option value="NA" <?php echo ($mob['geological_test'] ?? 'NA') === 'NA' ? 'selected' : ''; ?>>N/A</option>
                <option value="Complete" <?php echo ($mob['geological_test'] ?? 'NA') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                <option value="Not Complete" <?php echo ($mob['geological_test'] ?? 'NA') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                <option value="Awaiting Result" <?php echo ($mob['geological_test'] ?? 'NA') === 'Awaiting Result' ? 'selected' : ''; ?>>Awaiting Result</option>
              </select>
            </div>

            <div class="form-group">
              <label>Condition Report Contacts</label>
              <select name="condition_report_contacts" <?php echo $disabledAttr; ?>>
                <option value="Not Started" <?php echo ($mob['condition_report_contacts'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['condition_report_contacts'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['condition_report_contacts'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                <option value="NA" <?php echo ($mob['condition_report_contacts'] ?? 'Not Started') === 'NA' ? 'selected' : ''; ?>>NA</option>
              </select>
            </div>

            <div class="form-group">
              <label>Condition Reports</label>
              <select name="condition_reports" <?php echo $disabledAttr; ?>>
                <option value="Not Started" <?php echo ($mob['condition_reports'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['condition_reports'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['condition_reports'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                <option value="NA" <?php echo ($mob['condition_reports'] ?? 'Not Started') === 'NA' ? 'selected' : ''; ?>>NA</option>
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
              <select name="method_statements" <?php echo !$canSequential ? 'disabled' : ''; ?> <?php echo $disabledAttr; ?>>
                <option value="Not Complete" <?php echo ($mob['method_statements'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                <option value="Complete" <?php echo ($mob['method_statements'] ?? 'Not Complete') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Insurance</label>
              <select name="insurance_status" <?php echo !$canSequential ? 'disabled' : ''; ?> <?php echo $disabledAttr; ?>>
                <option value="Not Started" <?php echo ($mob['insurance_status'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['insurance_status'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['insurance_status'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Pavement Guarantee</label>
              <select name="pavement_guarantee" <?php echo !$canSequential ? 'disabled' : ''; ?> <?php echo $disabledAttr; ?>>
                <option value="Not Started" <?php echo ($mob['pavement_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['pavement_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['pavement_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Wellbeing Guarantee</label>
              <select name="wellbeing_guarantee" <?php echo !$canSequential ? 'disabled' : ''; ?> <?php echo $disabledAttr; ?>>
                <option value="Not Started" <?php echo ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Process" <?php echo ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                <option value="Complete" <?php echo ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>Umbrella Guarantee</label>
              <select name="umbrella_guarantee" <?php echo !$canSequential ? 'disabled' : ''; ?> <?php echo $disabledAttr; ?>>
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
              <select name="responsibility_form" <?php echo !$canFinal ? 'disabled' : ''; ?> <?php echo $disabledAttr; ?>>
                <option value="Not Complete" <?php echo ($mob['responsibility_form'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                <option value="Complete" <?php echo ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
              </select>
            </div>

            <div class="form-group">
              <label>BCA Clearance (Manual)</label>
              <select name="bca_clearance" <?php echo !$canBCA ? 'disabled' : ''; ?> <?php echo $disabledAttr; ?>>
                <option value="No" <?php echo ($mob['bca_clearance'] ?? 'No') === 'No' ? 'selected' : ''; ?>>No</option>
                <option value="Yes" <?php echo ($mob['bca_clearance'] ?? 'No') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
              </select>
            </div>
          </div>
        </fieldset>

          <div class="form-actions" style="margin-top: 1.5rem;">
              <?php if (hasRole('admin') || hasRole('manager')): ?>
              <button type="submit" class="btn btn-primary" style="padding: 1rem 2rem; font-size: 1rem; margin-bottom: 2rem;">
                Save BCA Updates
              </button>
             <?php endif; ?>
            </div>
          </form>
    </section>

          <?php
            // Check if project has endorsed PA for services section visibility
            $hasEndorsedPA = hasEndorsedPA($pdo, $projectId);
            
            // Get services data
            $services = getProjectServices($pdo, $projectId);
            
            // Check if user can edit services section
            $canEditServices = isAdmin() || isServicesEngineer();
            ?>
            
            <!-- Services Engineer Section -->

            
<?php
// Check if project has endorsed PA for services section visibility
$hasEndorsedPA = hasEndorsedPA($pdo, $projectId);

// Get services data
$services = getProjectServices($pdo, $projectId);

// Check if user can edit services section (separate from main mobilisation edit permission)
$canEditServices = $canEdit || isServicesEngineer();
?>

<!-- Services Engineer Section -->
<section class="projects-section">
      <div class="section-title" style="margin-bottom: 1.5rem;">Service Engineer Steps</div>
        <section class="card">
          <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="margin: 0;">Services & Utilities</h3>
          </div>
        
          <?php if (!$canEditServices): ?>
          <!-- Read-only view for non-authorized users -->
          <div class="form-grid">
            <div class="form-group readonly-item">
              <label>Existing Meter/s for Removal</label>
              <div class="readonly-value">
                <?php echo htmlspecialchars($services['existing_meters_required'] ?? 'Not Required'); ?>
                <?php if (($services['existing_meters_required'] ?? 'Not Required') === 'Required'): ?>
                  - <?php echo htmlspecialchars($services['existing_meters_complete'] ?? 'Not Complete'); ?>
                <?php endif; ?>
              </div>
            </div>
        
            <div class="form-group readonly-item">
              <label>Enemalta Lines for Deviation</label>
              <div class="readonly-value">
                <?php echo htmlspecialchars($services['enemalta_deviation_required'] ?? 'Not Required'); ?>
                <?php if (($services['enemalta_deviation_required'] ?? 'Not Required') === 'Required'): ?>
                  - <?php echo htmlspecialchars($services['enemalta_deviation_complete'] ?? 'Not Complete'); ?>
                <?php endif; ?>
              </div>
            </div>
        
            <div class="form-group readonly-item">
              <label>GO Lines for Deviation</label>
              <div class="readonly-value">
                <?php echo htmlspecialchars($services['go_deviation_required'] ?? 'Not Required'); ?>
                <?php if (($services['go_deviation_required'] ?? 'Not Required') === 'Required'): ?>
                  - <?php echo htmlspecialchars($services['go_deviation_complete'] ?? 'Not Complete'); ?>
                <?php endif; ?>
              </div>
            </div>
        
            <div class="form-group readonly-item">
              <label>Melita Lines for Deviation</label>
              <div class="readonly-value">
                <?php echo htmlspecialchars($services['melita_deviation_required'] ?? 'Not Required'); ?>
                <?php if (($services['melita_deviation_required'] ?? 'Not Required') === 'Required'): ?>
                  - <?php echo htmlspecialchars($services['melita_deviation_complete'] ?? 'Not Complete'); ?>
                <?php endif; ?>
              </div>
            </div>
        
            <div class="form-group readonly-item">
              <label>LC Lamps</label>
              <div class="readonly-value">
                <?php echo htmlspecialchars($services['lc_lamps_required'] ?? 'Not Required'); ?>
                <?php if (($services['lc_lamps_required'] ?? 'Not Required') === 'Required'): ?>
                  - <?php echo htmlspecialchars($services['lc_lamps_complete'] ?? 'Not Complete'); ?>
                <?php endif; ?>
              </div>
            </div>
        
            <div class="form-group readonly-item">
              <label>Temp Elec Meter Installation</label>
              <div class="readonly-value">
                <?php echo htmlspecialchars($services['temp_elec_meter_required'] ?? 'Not Required'); ?>
                <?php if (($services['temp_elec_meter_required'] ?? 'Not Required') === 'Required'): ?>
                  - <?php echo htmlspecialchars($services['temp_elec_meter_complete'] ?? 'Not Complete'); ?>
                <?php endif; ?>
              </div>
            </div>
        
            <div class="form-group readonly-item">
              <label>Temp WSC Meter Installation</label>
              <div class="readonly-value">
                <?php echo htmlspecialchars($services['temp_wsc_meter_required'] ?? 'Not Required'); ?>
                <?php if (($services['temp_wsc_meter_required'] ?? 'Not Required') === 'Required'): ?>
                  - <?php echo htmlspecialchars($services['temp_wsc_meter_complete'] ?? 'Not Complete'); ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        
          <?php else: ?>
          <!-- Editable view for services engineers and admins -->
          <form method="POST" action="">
            <input type="hidden" name="action" value="update_services">
            
            <div class="form-grid">
              <!-- Existing Meters -->
              <div class="form-group service-item">
                <label>Existing Meter/s for Removal</label>
                <div class="service-controls">
                  <select name="existing_meters_required" class="requirement-toggle">
                    <option value="Not Required" <?php echo ($services['existing_meters_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : ''; ?>>Not Required</option>
                    <option value="Required" <?php echo ($services['existing_meters_required'] ?? '') === 'Required' ? 'selected' : ''; ?>>Required</option>
                  </select>
                  <select name="existing_meters_complete" class="completion-status" <?php echo ($services['existing_meters_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : ''; ?>>
                    <option value="Not Complete" <?php echo ($services['existing_meters_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                    <option value="Complete" <?php echo ($services['existing_meters_complete'] ?? '') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                  </select>
                </div>
              </div>
        
              <!-- Enemalta Deviation -->
              <div class="form-group service-item">
                <label>Enemalta Lines for Deviation</label>
                <div class="service-controls">
                  <select name="enemalta_deviation_required" class="requirement-toggle">
                    <option value="Not Required" <?php echo ($services['enemalta_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : ''; ?>>Not Required</option>
                    <option value="Required" <?php echo ($services['enemalta_deviation_required'] ?? '') === 'Required' ? 'selected' : ''; ?>>Required</option>
                  </select>
                  <select name="enemalta_deviation_complete" class="completion-status" <?php echo ($services['enemalta_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : ''; ?>>
                    <option value="Not Complete" <?php echo ($services['enemalta_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                    <option value="Complete" <?php echo ($services['enemalta_deviation_complete'] ?? '') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                  </select>
                </div>
              </div>
        
              <!-- GO Deviation -->
              <div class="form-group service-item">
                <label>GO Lines for Deviation</label>
                <div class="service-controls">
                  <select name="go_deviation_required" class="requirement-toggle">
                    <option value="Not Required" <?php echo ($services['go_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : ''; ?>>Not Required</option>
                    <option value="Required" <?php echo ($services['go_deviation_required'] ?? '') === 'Required' ? 'selected' : ''; ?>>Required</option>
                  </select>
                  <select name="go_deviation_complete" class="completion-status" <?php echo ($services['go_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : ''; ?>>
                    <option value="Not Complete" <?php echo ($services['go_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                    <option value="Complete" <?php echo ($services['go_deviation_complete'] ?? '') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                  </select>
                </div>
              </div>
        
              <!-- Melita Deviation -->
              <div class="form-group service-item">
                <label>Melita Lines for Deviation</label>
                <div class="service-controls">
                  <select name="melita_deviation_required" class="requirement-toggle">
                    <option value="Not Required" <?php echo ($services['melita_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : ''; ?>>Not Required</option>
                    <option value="Required" <?php echo ($services['melita_deviation_required'] ?? '') === 'Required' ? 'selected' : ''; ?>>Required</option>
                  </select>
                  <select name="melita_deviation_complete" class="completion-status" <?php echo ($services['melita_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : ''; ?>>
                    <option value="Not Complete" <?php echo ($services['melita_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                    <option value="Complete" <?php echo ($services['melita_deviation_complete'] ?? '') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                  </select>
                </div>
              </div>
        
              <!-- LC Lamps -->
              <div class="form-group service-item">
                <label>LC Lamps</label>
                <div class="service-controls">
                  <select name="lc_lamps_required" class="requirement-toggle">
                    <option value="Not Required" <?php echo ($services['lc_lamps_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : ''; ?>>Not Required</option>
                    <option value="Required" <?php echo ($services['lc_lamps_required'] ?? '') === 'Required' ? 'selected' : ''; ?>>Required</option>
                  </select>
                  <select name="lc_lamps_complete" class="completion-status" <?php echo ($services['lc_lamps_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : ''; ?>>
                    <option value="Not Complete" <?php echo ($services['lc_lamps_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                    <option value="Complete" <?php echo ($services['lc_lamps_complete'] ?? '') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                  </select>
                </div>
              </div>
        
              <!-- Temp Elec Meter -->
              <div class="form-group service-item">
                <label>Temp Elec Meter Installation</label>
                <div class="service-controls">
                  <select name="temp_elec_meter_required" class="requirement-toggle">
                    <option value="Not Required" <?php echo ($services['temp_elec_meter_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : ''; ?>>Not Required</option>
                    <option value="Required" <?php echo ($services['temp_elec_meter_required'] ?? '') === 'Required' ? 'selected' : ''; ?>>Required</option>
                  </select>
                  <select name="temp_elec_meter_complete" class="completion-status" <?php echo ($services['temp_elec_meter_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : ''; ?>>
                    <option value="Not Complete" <?php echo ($services['temp_elec_meter_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                    <option value="Complete" <?php echo ($services['temp_elec_meter_complete'] ?? '') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                  </select>
                </div>
              </div>
        
              <!-- Temp WSC Meter -->
              <div class="form-group service-item">
                <label>Temp WSC Meter Installation</label>
                <div class="service-controls">
                  <select name="temp_wsc_meter_required" class="requirement-toggle">
                    <option value="Not Required" <?php echo ($services['temp_wsc_meter_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : ''; ?>>Not Required</option>
                    <option value="Required" <?php echo ($services['temp_wsc_meter_required'] ?? '') === 'Required' ? 'selected' : ''; ?>>Required</option>
                  </select>
                  <select name="temp_wsc_meter_complete" class="completion-status" <?php echo ($services['temp_wsc_meter_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : ''; ?>>
                    <option value="Not Complete" <?php echo ($services['temp_wsc_meter_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                    <option value="Complete" <?php echo ($services['temp_wsc_meter_complete'] ?? '') === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                  </select>
                </div>
              </div>
            </div>
        
            <div class="form-actions" style="margin-top: 1.5rem;">
              <button type="submit" class="btn btn-primary" style="padding: 1rem 2rem; font-size: 1rem;">
                Save Services & Utilities
              </button>
            </div>
          </form>
        
          <script>
          // Enable/disable completion dropdown based on requirement selection
          document.querySelectorAll('.requirement-toggle').forEach(function(select) {
            select.addEventListener('change', function() {
              const completionSelect = this.parentElement.querySelector('.completion-status');
              if (this.value === 'Required') {
                completionSelect.disabled = false;
              } else {
                completionSelect.disabled = true;
                completionSelect.value = 'Not Complete';
              }
            });
          });
          </script>
          <?php endif; ?>
        </section>
      </div>
    </section>
<?php require_once 'footer.php'; ?>
