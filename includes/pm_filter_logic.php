<?php
/**
 * Shared PM filter helpers — normalised GET params and filter application.
 */

function pmGetFilterParams(array $defaults = []) {
    return [
        'filter_stage'   => $_GET['filter_stage'] ?? ($defaults['filter_stage'] ?? 'all'),
        'filter_status'  => $_GET['filter_status'] ?? ($defaults['filter_status'] ?? 'all'),
        'filter_type'    => $_GET['filter_type'] ?? ($defaults['filter_type'] ?? 'all'),
        'filter_city'    => $_GET['filter_city'] ?? ($defaults['filter_city'] ?? 'all'),
        'filter_client'  => $_GET['filter_client'] ?? ($defaults['filter_client'] ?? 'all'),
        'filter_island'  => $_GET['filter_island'] ?? ($defaults['filter_island'] ?? 'all'),
        'filter_finish'  => $_GET['filter_finish'] ?? ($defaults['filter_finish'] ?? 'all'),
        'filter_db_status' => $_GET['filter_db_status'] ?? ($defaults['filter_db_status'] ?? 'Active'),
        'filter_prof'    => $_GET['filter_prof'] ?? ($defaults['filter_prof'] ?? 'all'),
        'filter_pm'      => $_GET['filter_pm'] ?? ($defaults['filter_pm'] ?? 'all'),
        'filter_sub'     => $_GET['filter_sub'] ?? ($defaults['filter_sub'] ?? 'all'),
    ];
}

function pmMatchesClientFilter(array $project, $filterClient) {
    if ($filterClient === 'all') return true;
    if ($filterClient === 'group_excel') {
        return stripos($project['client_name'] ?? '', 'Excel') !== false;
    }
    if ($filterClient === 'group_blue_clay') {
        $name = $project['client_name'] ?? '';
        return stripos($name, 'Blue Clay') !== false || stripos($name, 'Blueclay') !== false;
    }
    return ($project['clientid'] ?? null) == $filterClient;
}

function pmBuildFilterQuery(array $params, array $extra = []) {
    return http_build_query(array_merge($params, $extra));
}

function pmGetStageGroups() {
    return [
        'pre'   => ['Feasibility', 'Tracking', 'Permit', 'Mobilisation'],
        'exec'  => ['Demolition', 'Excavation', 'Construction', 'Finishes'],
        'final' => ['Compliance', 'Condominium', 'Handed Over'],
    ];
}

function pmMatchesStageFilter($stage, $filterStage, $filterStatus = 'all') {
    if ($filterStage !== 'all' && $stage !== $filterStage) return false;
    if ($filterStatus === 'all') return true;
    $groups = pmGetStageGroups();
    if ($filterStatus === 'group_pre') return in_array($stage, $groups['pre']);
    if ($filterStatus === 'group_exec') return in_array($stage, $groups['exec']);
    if ($filterStatus === 'group_final') return in_array($stage, $groups['final']);
    return $stage === $filterStatus;
}

function getAccurateProjectStagesBatch($pdo, array $projectIds) {
    $stages = [];
    foreach ($projectIds as $id) {
        $stages[(int)$id] = getAccurateProjectStage($pdo, (int)$id);
    }
    return $stages;
}

function getDeliverySchedulesBatch($pdo, array $projectIds) {
    if (empty($projectIds)) return [];
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM project_delivery_schedule WHERE project_id IN ($placeholders)");
    $stmt->execute(array_values($projectIds));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['project_id']] = $row;
    }
    return $out;
}

function getScheduleRagClass($planned, $forecast, $actual) {
    if (!empty($actual)) return 'rag-green';
    $ref = !empty($forecast) ? $forecast : $planned;
    if (empty($ref)) return 'rag-neutral';
    $today = date('Y-m-d');
    if ($ref < $today) return 'rag-red';
    if (!empty($forecast) && !empty($planned) && $forecast > $planned) return 'rag-amber';
    return 'rag-green';
}

function formatScheduleDate($date) {
    if (empty($date)) return '—';
    return date('d M Y', strtotime($date));
}
