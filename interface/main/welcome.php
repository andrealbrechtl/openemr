<?php
/**
 * Custom Patient Care Dashboard Homepage
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once("../globals.php");
require_once(__DIR__ . "/../../library/appointments.inc.php");

use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\PatientService;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

// Authorization guard
if (!$session->get('userauthorized')) {
    echo "Unauthorized access.";
    exit;
}

// Handle AJAX Patient Search
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    $q = $_GET['q'] ?? '';
    if (strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }
    $term = "%" . $q . "%";
    $sql = "SELECT pid, fname, mname, lname, DOB, pubpid FROM patient_data 
            WHERE fname LIKE ? OR lname LIKE ? OR pubpid LIKE ? OR DOB LIKE ? 
            ORDER BY lname, fname LIMIT 8";
    $res = sqlStatement($sql, [$term, $term, $term, $term]);
    $results = [];
    while ($row = sqlFetchArray($res)) {
        $results[] = [
            'pid' => $row['pid'],
            'name' => trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')),
            'dob' => $row['DOB'] ? oeFormatShortDate($row['DOB']) : '',
            'pubpid' => $row['pubpid'] ?? ''
        ];
    }
    echo json_encode($results);
    exit;
}

// Gather Statistics
// 1. Total Patients
$ptCountRes = sqlQuery("SELECT COUNT(*) AS cnt FROM patient_data");
$totalPatients = $ptCountRes['cnt'] ?? 0;

// 2. Appointments Today
$todayStr = date('Y-m-d');
$apptCountRes = sqlQuery("SELECT COUNT(*) AS cnt FROM openemr_postcalendar_events WHERE pc_eventDate = ?", [$todayStr]);
$appointmentsToday = $apptCountRes['cnt'] ?? 0;

// 3. Active Prescriptions
$rxCountRes = sqlQuery("SELECT COUNT(*) AS cnt FROM prescriptions WHERE active = 1");
$activePrescriptions = $rxCountRes['cnt'] ?? 0;

// 4. Total Lab Orders (Exams)
$labsCountRes = sqlQuery("SELECT COUNT(*) AS cnt FROM procedure_order");
$totalLabOrders = $labsCountRes['cnt'] ?? 0;

// Fetch Today's Appointments List
$todayAppointments = fetchAppointments($todayStr, $todayStr);
if ($todayAppointments) {
    $todayAppointments = sortAppointments($todayAppointments, 'time');
} else {
    $todayAppointments = [];
}

// Fetch Recent Patients
$patientService = new PatientService();
$recentPatientsRaw = $patientService->getRecentPatientList();
$recentPatients = [];
if ($recentPatientsRaw) {
    foreach ($recentPatientsRaw as $rp) {
        $pData = $patientService->findByPid($rp['pid']);
        if ($pData) {
            $recentPatients[] = $pData;
        }
    }
}

// Limit lists for better dashboard display
$todayAppointments = array_slice($todayAppointments, 0, 5);
$recentPatients = array_slice($recentPatients, 0, 5);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patient Care Dashboard</title>
    <!-- Import Outfit Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Setup Standard OpenEMR styles -->
    <?php Header::setupHeader(['common', 'utility']); ?>
    
    <style>
        :root {
            --bg-gradient: radial-gradient(circle at top right, #0f172a, #090d16);
            --card-bg: rgba(30, 41, 59, 0.45);
            --card-bg-hover: rgba(30, 41, 59, 0.65);
            --card-border: rgba(255, 255, 255, 0.08);
            --card-border-hover: rgba(45, 212, 191, 0.4);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent-teal: #2dd4bf;
            --accent-purple: #a78bfa;
            --accent-blue: #38bdf8;
            --accent-pink: #f472b6;
            --accent-gradient: linear-gradient(135deg, #2dd4bf, #a78bfa);
            --glow-color: rgba(45, 212, 191, 0.15);
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-gradient) !important;
            color: var(--text-primary);
            min-height: 100vh;
            padding: 2.5rem 1.5rem;
            margin: 0;
            overflow-x: hidden;
        }

        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Header design */
        .dashboard-header {
            margin-bottom: 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-title h1 {
            font-size: 2.25rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
            background: linear-gradient(to right, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome-title p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 1.05rem;
            font-weight: 400;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.75rem;
            backdrop-filter: blur(12px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            border-color: var(--card-border-hover);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 0 15px 0 var(--glow-color);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent-gradient);
            opacity: 0.8;
        }

        .stat-info h3 {
            font-size: 0.9rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 0 0 0.5rem 0;
            font-weight: 600;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin: 0;
            background: linear-gradient(135deg, #ffffff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-icon {
            font-size: 2.25rem;
            opacity: 0.85;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* 3-Column main layout */
        .main-layout {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
        }

        .column-left {
            grid-column: span 8;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .column-right {
            grid-column: span 4;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .column-left { grid-column: span 12; }
            .column-right { grid-column: span 12; }
        }

        /* Card panels */
        .panel-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.75rem;
            backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }

        .panel-card:hover {
            border-color: rgba(255, 255, 255, 0.15);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 0.75rem;
        }

        .panel-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .panel-title i {
            color: var(--accent-teal);
        }

        /* Quick Search */
        .search-container {
            position: relative;
            width: 100%;
        }

        .search-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--card-border);
            color: var(--text-primary);
            padding: 0.85rem 1.25rem 0.85rem 3rem;
            border-radius: 12px;
            font-size: 1rem;
            outline: none;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .search-input:focus {
            border-color: var(--accent-teal);
            box-shadow: 0 0 10px 0 rgba(45, 212, 191, 0.2);
            background: rgba(15, 23, 42, 0.8);
        }

        .search-icon {
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
        }

        .search-results {
            margin-top: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-height: 350px;
            overflow-y: auto;
        }

        .search-result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 1.25rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.04);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .search-result-item:hover {
            background: rgba(45, 212, 191, 0.08);
            border-color: rgba(45, 212, 191, 0.25);
            transform: translateX(4px);
        }

        .patient-meta {
            display: flex;
            flex-direction: column;
        }

        .patient-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .patient-id-dob {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.15rem;
        }

        /* Action Buttons Grid */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .action-btn-card {
            background: rgba(30, 41, 59, 0.35);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
        }

        .action-btn-card:hover {
            background: rgba(45, 212, 191, 0.06);
            border-color: var(--card-border-hover);
            transform: translateY(-2px);
        }

        .action-btn-icon {
            font-size: 1.75rem;
            color: var(--accent-blue);
        }

        .action-btn-card:nth-child(2) .action-btn-icon { color: var(--accent-purple); }
        .action-btn-card:nth-child(3) .action-btn-icon { color: var(--accent-teal); }
        .action-btn-card:nth-child(4) .action-btn-icon { color: var(--accent-pink); }

        .action-btn-label {
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Tables & Lists */
        .dashboard-table {
            width: 100%;
            border-collapse: collapse;
        }

        .dashboard-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-weight: 600;
        }

        .dashboard-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            font-size: 0.925rem;
            vertical-align: middle;
        }

        .dashboard-table tr:last-child td {
            border-bottom: none;
        }

        .dashboard-table tr:hover td {
            background: rgba(255, 255, 255, 0.01);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .status-confirmed {
            background: rgba(45, 212, 191, 0.15);
            color: #2dd4bf;
        }

        .status-pending {
            background: rgba(251, 146, 60, 0.15);
            color: #fb923c;
        }

        .status-default {
            background: rgba(148, 163, 184, 0.15);
            color: #94a3b8;
        }

        .btn-view-patient {
            background: linear-gradient(135deg, #0d9488, #0f766e);
            color: #fff !important;
            border: none;
            padding: 0.45rem 0.9rem;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view-patient:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
        }

        /* Recent Patients List */
        .recent-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .recent-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 1rem;
            background: rgba(15, 23, 42, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .recent-item:hover {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.1);
            transform: translateX(4px);
        }

        .no-records {
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
            padding: 2rem 0;
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <div class="welcome-title">
            <h1>Patient Care Center</h1>
            <p>Welcome back, <?= text(($session->get('authUser') ?? 'User')) ?>. Focus on your patients, appointments, and care.</p>
        </div>
        <div>
            <!-- Custom Date badge -->
            <span style="background: rgba(255,255,255,0.05); padding: 0.6rem 1.2rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); font-weight: 500; font-size: 0.95rem;">
                <i class="fa fa-calendar-alt text-teal mr-2" style="color: var(--accent-teal);"></i><?= date('F d, Y') ?>
            </span>
        </div>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <h3>Total Patients</h3>
                <p class="stat-value"><?= number_format($totalPatients) ?></p>
            </div>
            <div class="stat-icon"><i class="fa fa-users"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Appointments Today</h3>
                <p class="stat-value"><?= number_format($appointmentsToday) ?></p>
            </div>
            <div class="stat-icon"><i class="fa fa-calendar-check" style="background: linear-gradient(135deg, #a78bfa, #f472b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Lab Orders (Exams)</h3>
                <p class="stat-value"><?= number_format($totalLabOrders) ?></p>
            </div>
            <div class="stat-icon"><i class="fa fa-flask" style="background: linear-gradient(135deg, #38bdf8, #2dd4bf); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Active Prescriptions</h3>
                <p class="stat-value"><?= number_format($activePrescriptions) ?></p>
            </div>
            <div class="stat-icon"><i class="fa fa-pills" style="background: linear-gradient(135deg, #f472b6, #a78bfa); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i></div>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="main-layout">
        
        <!-- Left Side Column -->
        <div class="column-left">
            
            <!-- Today's Schedule -->
            <div class="panel-card">
                <div class="panel-header">
                    <h2 class="panel-title"><i class="fa fa-clock"></i> Today's Appointment Schedule</h2>
                </div>
                
                <?php if (empty($todayAppointments)): ?>
                    <div class="no-records">No appointments scheduled for today.</div>
                <?php else: ?>
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Patient Name</th>
                                <th>Appointment Type</th>
                                <th>Provider</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayAppointments as $appt): 
                                $timeStr = date("h:i A", strtotime((string)$appt['pc_startTime']));
                                $status = trim((string)$appt['pc_apptstatus']);
                                $badgeClass = 'status-default';
                                if ($status === 'Confirmed' || $status === '2' || $status === '@') {
                                    $badgeClass = 'status-confirmed';
                                } elseif ($status === 'Pending' || $status === '1') {
                                    $badgeClass = 'status-pending';
                                }
                            ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--accent-blue);"><?= text($timeStr) ?></td>
                                    <td style="font-weight: 500;"><?= text(trim(($appt['fname'] ?? '') . ' ' . ($appt['lname'] ?? ''))) ?></td>
                                    <td><span style="color: #cbd5e1;"><?= text($appt['pc_catname'] ?? 'General') ?></span></td>
                                    <td><?= text(trim(($appt['ufname'] ?? '') . ' ' . ($appt['ulname'] ?? ''))) ?></td>
                                    <td>
                                        <span class="status-badge <?= $badgeClass ?>">
                                            <?= text($status ? $status : 'Scheduled') ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <button class="btn-view-patient" onclick="openPatientDashboard(<?= (int)$appt['pid'] ?>)">
                                            View Care File
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Quick Navigation Action Cards -->
            <div class="actions-grid">
                <div class="action-btn-card" onclick="openTabByName('fin', '/interface/main/finder/dynamic_finder.php')">
                    <div class="action-btn-icon"><i class="fa fa-search"></i></div>
                    <div class="action-btn-label">Find Patients</div>
                </div>
                <div class="action-btn-card" onclick="openTabByName('pat', '/interface/new/new.php')">
                    <div class="action-btn-icon"><i class="fa fa-user-plus"></i></div>
                    <div class="action-btn-label">Add New Patient</div>
                </div>
                <div class="action-btn-card" onclick="openTabByName('cal', '/interface/main/main_info.php')">
                    <div class="action-btn-icon"><i class="fa fa-calendar-alt"></i></div>
                    <div class="action-btn-label">Clinic Calendar</div>
                </div>
                <div class="action-btn-card" onclick="openTabByName('msg', '/interface/main/messages/messages.php?form_active=1')">
                    <div class="action-btn-icon"><i class="fa fa-envelope"></i></div>
                    <div class="action-btn-label">Message Inbox</div>
                </div>
            </div>

        </div>

        <!-- Right Side Column -->
        <div class="column-right">
            
            <!-- Quick Patient Search -->
            <div class="panel-card">
                <div class="panel-header">
                    <h2 class="panel-title"><i class="fa fa-search"></i> Quick Care Finder</h2>
                </div>
                
                <div class="search-container">
                    <i class="fa fa-search search-icon"></i>
                    <input type="text" id="dashboard-search-input" class="search-input" placeholder="Type name, DOB or Patient ID..." autocomplete="off">
                </div>

                <div id="search-results-box" class="search-results">
                    <!-- Results populated dynamically -->
                </div>
            </div>

            <!-- Recent Patients -->
            <div class="panel-card">
                <div class="panel-header">
                    <h2 class="panel-title"><i class="fa fa-history"></i> Recent Care Files</h2>
                </div>
                
                <div class="recent-list">
                    <?php if (empty($recentPatients)): ?>
                        <div class="no-records">No recently viewed patient care files.</div>
                    <?php else: ?>
                        <?php foreach ($recentPatients as $patient): 
                            $name = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
                            $dob = $patient['DOB'] ? oeFormatShortDate($patient['DOB']) : 'Unknown';
                        ?>
                            <div class="recent-item" onclick="openPatientDashboard(<?= (int)$patient['pid'] ?>)">
                                <div class="patient-meta">
                                    <span class="patient-name"><?= text($name) ?></span>
                                    <span class="patient-id-dob">ID: <?= text($patient['pubpid']) ?> • DOB: <?= text($dob) ?></span>
                                </div>
                                <div>
                                    <i class="fa fa-chevron-right text-teal" style="color: var(--accent-teal); font-size: 0.85rem;"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>
</div>

<script>
    // Tab switching using the parent tabs VM
    function openTabByName(target, url) {
        if (parent && parent.navigateTab) {
            parent.navigateTab(parent.webroot_url + url, target, function() {
                parent.activateTabByName(target, true);
            });
        } else {
            window.location.href = url;
        }
    }

    function openPatientDashboard(pid) {
        if (parent && parent.navigateTab) {
            // Set pid using Ajax helper (like getSessionValue works in OpenEMR)
            let csrf = "<?= CsrfUtils::collectCsrfToken($session) ?>";
            fetch(parent.webroot_url + `/library/ajax/set_pt.php?csrf_token_form=${csrf}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `mode=session_key&key=pid&value=${pid}`
            }).then(() => {
                let url = parent.webroot_url + '/interface/patient_file/summary/demographics.php?set_pid=' + pid;
                parent.navigateTab(url, 'pat', function() {
                    parent.activateTabByName('pat', true);
                });
            });
        } else {
            window.location.href = `../patient_file/summary/demographics.php?set_pid=${pid}`;
        }
    }

    // Dynamic Live Patient Search
    const searchInput = document.getElementById('dashboard-search-input');
    const resultsBox = document.getElementById('search-results-box');

    searchInput.addEventListener('input', function() {
        const query = searchInput.value.trim();
        if (query.length < 2) {
            resultsBox.innerHTML = '';
            return;
        }

        fetch(`welcome.php?action=search&q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                resultsBox.innerHTML = '';
                if (data.length === 0) {
                    resultsBox.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">No matching patients found.</div>';
                    return;
                }

                data.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'search-result-item';
                    div.onclick = function() {
                        openPatientDashboard(item.pid);
                    };

                    div.innerHTML = `
                        <div class="patient-meta">
                            <span class="patient-name">${escapeHTML(item.name)}</span>
                            <span class="patient-id-dob">ID: ${escapeHTML(item.pubpid)} • DOB: ${escapeHTML(item.dob)}</span>
                        </div>
                        <div>
                            <i class="fa fa-chevron-right" style="color: var(--accent-teal); font-size: 0.85rem;"></i>
                        </div>
                    `;
                    resultsBox.appendChild(div);
                });
            })
            .catch(err => {
                console.error("Search error: ", err);
            });
    });

    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, 
            tag => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[tag] || tag)
        );
    }
</script>

</body>
</html>
