<?php
/**
 * Database Migration - Estate Hub Mobilisation System
 * Run this once to update your database structure
 */

require_once 'config.php';

try {
    echo "🔄 Starting database migration...\n\n";

    // 1. ALTER projects table - rename bca_status to pa_status, remove old status field
    echo "1️⃣  Updating projects table...\n";
    
    // Check if pa_status exists, if not add it
    $columns = $pdo->query("DESCRIBE projects")->fetchAll(PDO::FETCH_ASSOC);
    $column_names = array_column($columns, 'Field');
    
    if (!in_array('pa_status', $column_names) && in_array('bca_status', $column_names)) {
        $pdo->exec("ALTER TABLE projects CHANGE COLUMN bca_status pa_status VARCHAR(50)");
        echo "   ✅ Renamed bca_status → pa_status\n";
    }
    
    // Remove old status field if it exists and is not needed
    if (in_array('status', $column_names)) {
        $pdo->exec("ALTER TABLE projects DROP COLUMN status");
        echo "   ✅ Removed old status column\n";
    }
    
    // 2. Create pa_numbers table for multiple PA numbers per project
    echo "\n2️⃣  Creating pa_numbers table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS pa_numbers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        project_id INT NOT NULL,
        pa_number VARCHAR(50) NOT NULL,
        pa_status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        UNIQUE KEY unique_pa (project_id, pa_number)
    )");
    echo "   ✅ pa_numbers table created\n";
    
    // 3. Create mobilisation_steps table
    echo "\n3️⃣  Creating mobilisation_steps table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobilisation_steps (
        id INT PRIMARY KEY AUTO_INCREMENT,
        project_id INT NOT NULL,
        step_order INT NOT NULL,
        step_name VARCHAR(100) NOT NULL,
        description TEXT,
        status ENUM('Not Started', 'In Progress', 'Completed') DEFAULT 'Not Started',
        completed_date DATETIME NULL,
        completed_by VARCHAR(50) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        UNIQUE KEY unique_step (project_id, step_order)
    )");
    echo "   ✅ mobilisation_steps table created\n";
    
    // 4. Create user_roles table for role-based access
    echo "\n4️⃣  Creating user_roles table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(100) UNIQUE NOT NULL,
        role ENUM('admin', 'manager', 'user') DEFAULT 'user',
        can_update_status BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "   ✅ user_roles table created\n";
    
    // 5. Set up default mobilisation steps for new projects
    echo "\n5️⃣  Creating mobilisation_step_templates table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobilisation_step_templates (
        id INT PRIMARY KEY AUTO_INCREMENT,
        step_order INT NOT NULL,
        step_name VARCHAR(100) NOT NULL,
        description TEXT,
        UNIQUE KEY unique_template_step (step_order)
    )");
    echo "   ✅ mobilisation_step_templates table created\n";
    
    // Insert default mobilisation steps
    echo "\n6️⃣  Inserting default mobilisation steps...\n";
    $default_steps = [
        [1, 'Site Preparation', 'Prepare site and infrastructure'],
        [2, 'Team Assembly', 'Assemble and brief team members'],
        [3, 'Resource Allocation', 'Allocate necessary resources'],
        [4, 'Documentation', 'Complete all required documentation'],
        [5, 'Stakeholder Approval', 'Obtain stakeholder sign-off'],
        [6, 'Mobilisation Complete', 'Project fully mobilised'],
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO mobilisation_step_templates (step_order, step_name, description) VALUES (?, ?, ?)");
    foreach ($default_steps as $step) {
        $stmt->execute($step);
    }
    echo "   ✅ Default steps inserted\n";
    
    // Alter pa_number column in projects table (keep for backwards compatibility)
    echo "\n7️⃣  Updating projects table constraints...\n";
    $pdo->exec("ALTER TABLE projects MODIFY COLUMN pa_number VARCHAR(50) NULL");
    echo "   ✅ PA number field updated\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\n📋 Next steps:\n";
    echo "   1. Update your create-project.php to use the new structure\n";
    echo "   2. Update your mobilization.php with project detail view\n";
    echo "   3. Add user role management to dashboard\n";

} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    die();
}
?>
