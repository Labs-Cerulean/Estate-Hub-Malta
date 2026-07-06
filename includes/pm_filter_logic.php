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
    $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $projects = [];
    $stmt = $pdo->prepare("SELECT id, type, finishlevel, project_status, is_tracking FROM projects WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $projects[(int)$row['id']] = $row;
    }

    $paByProject = [];
    $stmt = $pdo->prepare("SELECT project_id, pa_number, pa_status FROM project_pa_numbers WHERE project_id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paByProject[(int)$row['project_id']][] = $row;
    }

    $mobByProject = [];
    $stmt = $pdo->prepare("SELECT project_id, demo_status, excavation_status, mob_demolition, mob_excavation, mob_construction FROM project_mobilisation WHERE project_id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mobByProject[(int)$row['project_id']] = $row;
    }

    $blocksByProject = [];
    $blockIds = [];
    $stmt = $pdo->prepare("SELECT project_id, id, block_type, finish_level, compliance_submitted, compliance_certified, condominium_formed, cp_meters_installed, finishes_overall_status, progress FROM project_blocks WHERE project_id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $blocksByProject[(int)$row['project_id']][] = $row;
        $blockIds[] = (int)$row['id'];
    }

    $levelsByBlockId = [];
    if (!empty($blockIds)) {
        $blockPlaceholders = implode(',', array_fill(0, count($blockIds), '?'));
        $stmt = $pdo->prepare("SELECT block_id, construction_status FROM block_levels WHERE block_id IN ($blockPlaceholders)");
        $stmt->execute($blockIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $levelsByBlockId[(int)$row['block_id']][] = $row;
        }
    }

    $stages = [];
    foreach ($ids as $id) {
        $proj = $projects[$id] ?? null;
        if (!$proj) {
            $stages[$id] = 'Feasibility';
            continue;
        }
        $stages[$id] = computeAccurateProjectStage(
            $proj,
            $paByProject[$id] ?? [],
            $mobByProject[$id] ?? null,
            $blocksByProject[$id] ?? [],
            $levelsByBlockId
        );
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

function pmBuildPaUrl(?string $paNumber): ?string {
    if (empty($paNumber)) return null;
    if (!preg_match('/(PA|PC|DN)\/(\d+)\/(\d+)/', $paNumber, $m)) return null;
    return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}";
}

function pmFormatPaDisplay(?string $paNumber): ?string {
    if (empty($paNumber)) return null;
    if (preg_match('/(PA|PC|DN)\/(\d+)\/(\d+)/', $paNumber, $m)) {
        return "{$m[1]} {$m[2]}/{$m[3]}";
    }
    return $paNumber;
}

function pmRenderPaChip(array $pa): string {
    $paText = htmlspecialchars(pmFormatPaDisplay($pa['pa_number'] ?? '') ?? '');
    $paUrl = pmBuildPaUrl($pa['pa_number'] ?? '');
    $status = trim($pa['pa_status'] ?? '');
    $statusHtml = $status !== '' ? ' <span class="pa-status-chip">' . htmlspecialchars($status) . '</span>' : '';
    if ($paUrl) {
        return '<a href="' . htmlspecialchars($paUrl) . '" target="_blank" rel="noopener noreferrer" class="pa-link">' . $paText . '</a>' . $statusHtml;
    }
    return $paText . $statusHtml;
}

function pmProjectHasEndorsedPa(array $paRecords): bool {
    foreach ($paRecords as $pa) {
        $status = strtolower(trim($pa['pa_status'] ?? ''));
        if ($status === '' || $status === 'tracking') {
            continue;
        }
        if (strpos($status, 'decided') !== false || strpos($status, 'endorsed') !== false || strpos($status, 'approved') !== false) {
            return true;
        }
    }
    return false;
}

function pmGroupProjects(array $projects, string $groupMode, array $paByProject = [], array $stageEnum = []): array {
    $groups = [];
    foreach ($projects as $project) {
        switch ($groupMode) {
            case 'client':
                $key = trim($project['client_name'] ?? '') ?: 'Unassigned Client';
                break;
            case 'perit':
                $pas = $paByProject[$project['id']] ?? [];
                $firms = array_values(array_unique(array_filter(array_column($pas, 'arch_firm'))));
                $key = !empty($firms) ? $firms[0] : 'No Perit Assigned';
                break;
            case 'pa_review':
                $pas = $paByProject[$project['id']] ?? [];
                if (empty($pas)) {
                    $key = 'zz_no_pa';
                } else {
                    $statuses = array_values(array_unique(array_filter(array_column($pas, 'pa_status'))));
                    $key = !empty($statuses) ? $statuses[0] : 'Status Unknown';
                }
                break;
            case 'flat':
                $key = 'all';
                break;
            case 'stage':
            default:
                $key = $project['stage'] ?? 'Unknown';
                break;
        }
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $key === 'zz_no_pa' ? 'No PA Number' : ($key === 'all' ? 'All Projects' : $key), 'projects' => []];
        }
        $groups[$key]['projects'][] = $project;
    }

    if ($groupMode === 'stage') {
        uksort($groups, function ($a, $b) use ($stageEnum) {
            return ($stageEnum[$a] ?? 99) <=> ($stageEnum[$b] ?? 99);
        });
    } elseif ($groupMode === 'pa_review') {
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        if (isset($groups['zz_no_pa'])) {
            $noPa = $groups['zz_no_pa'];
            unset($groups['zz_no_pa']);
            $groups['zz_no_pa'] = $noPa;
        }
    } else {
        uksort($groups, function ($a, $b) {
            if ($a === 'No Perit Assigned') return 1;
            if ($b === 'No Perit Assigned') return -1;
            if ($a === 'Unassigned Client') return 1;
            if ($b === 'Unassigned Client') return -1;
            return strcasecmp($a, $b);
        });
    }

    return $groups;
}
