<?php
/**
 * Role-aware HRIS Assistant.
 * - Employee: own HRIS records + system guidance.
 * - HR Administrator: HR operations/process guidance and administrative data summaries.
 * - Super Admin: HR Administrator guidance plus system/security/management processes.
 * Language rule: Filipino/Tagalog question => Filipino answer; English => English answer.
 * Mixed Taglish is answered in Taglish.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Please log in first.']);
    exit;
}

$role = (string)($_SESSION['role'] ?? 'Employee');
$employeeId = currentEmployeeId();
$question = trim((string)($_POST['question'] ?? ''));
if ($question === '') {
    echo json_encode(['ok' => false, 'message' => 'Please enter a question.']);
    exit;
}

$q = mb_strtolower($question, 'UTF-8');

function assistant_has($q, array $terms) {
    foreach ($terms as $term) {
        if (mb_strpos($q, mb_strtolower($term, 'UTF-8')) !== false) return true;
    }
    return false;
}
function money($amount) { return '₱' . number_format((float)$amount, 2); }
function assistant_language($q) {
    $filipino = ['ano','paano','bakit','saan','kailan','magkano','pwede','puwede','gawin','gagamitin','gamitin','paano ko','ko ba','aking','ako','mo','natin','mga','para sa','kapag','kung','mayroon','wala','sino','alin','mag','pag','ng','ang','at','sa'];
    $english = ['what','how','why','where','when','how much','can i','how do i','my','your','show','please','explain','process','system','module'];
    $fp=0; $en=0;
    foreach($filipino as $w) if (preg_match('/(^|\s|[?!. ,])'.preg_quote($w,'/').'(?=\s|$|[?!. ,])/u',$q)) $fp++;
    foreach($english as $w) if (preg_match('/(^|\s|[?!. ,])'.preg_quote($w,'/').'(?=\s|$|[?!. ,])/u',$q)) $en++;
    if ($fp >= 2 && $fp > $en) return 'tl';
    if ($en >= 2 && $en > $fp) return 'en';
    return ($fp > $en) ? 'tl' : 'en';
}
function role_label($role) {
    return $role === 'Super Admin' ? 'Super Admin' : ($role === 'HR Administrator' ? 'HR Administrator' : 'Employee');
}

$lang = assistant_language($q);

// Common role-aware process answers.
function system_answer($pdo, $q, $role, $lang) {
    $admin = in_array($role, ['HR Administrator','Super Admin'], true);
    $super = $role === 'Super Admin';
    // HR Administrator and Super Admin AI are restricted to modules visible in their sidebar.
    // The assistant explains only how to use those modules; it does not provide unrelated system processes.
    $adminModules = [
        'dashboard' => ['dashboard','home','summary'],
        'employees' => ['employee','employees','add employee','edit employee','employee profile'],
        'login access' => ['login access','user access','account access'],
        'employee archive' => ['archive','archived employee','employee archive'],
        'positions' => ['position','position description','position status','job position'],
        'compliance' => ['compliance'],
        'reports' => ['report','reports','export'],
        'data import' => ['data import','import csv','csv import','import employee'],
        'backup & restore' => ['backup','restore'],
        'attendance' => ['attendance','timekeeping','time in','time out'],
        'leave management' => ['leave','vacation','sick leave','leave management','medical certificate','leave balance'],
        'payroll' => ['payroll','payslip','salary'],
        'payroll history' => ['payroll history','previous payroll','old payroll'],
        'employee contracts' => ['employee contract','contract','agreement','agreement terms','employment agreement'],
        'performance' => ['performance','evaluation','rating','kpi'],
    ];
    if ($admin) {
        $matchedAdminModule = null;
        foreach ($adminModules as $module => $terms) {
            if (assistant_has($q, $terms)) { $matchedAdminModule = $module; break; }
        }
        $isAdminGeneralHelp = assistant_has($q, ['what can you do','what can i ask','available modules','modules','ano ang kaya mo','ano pwede itanong','ano ang modules','anong module']);
        $isAdminGreeting = assistant_has($q, ['hello','hi','hey','good morning','good afternoon','good evening','kumusta','hello po','hi po']);
        if (!$matchedAdminModule && !$isAdminGeneralHelp && !$isAdminGreeting) {
            return $lang==='tl'
                ? 'Para sa '.$role.', ang AI Assistant ay nakatuon lamang sa paggamit ng mga module na available sa iyong side: Dashboard, Employees, Login Access, Employee Archive, Positions, Compliance, Reports & Export, Data Import, Backup & Restore, Attendance, Leave Management, Payroll, Payroll History, Employee Contracts, at Performance. Magtanong tungkol sa isang module para maibigay ko ang step-by-step na paggamit nito.'
                : 'For '.$role.', the AI Assistant is limited to the modules available on your side: Dashboard, Employees, Login Access, Employee Archive, Positions, Compliance, Reports & Export, Data Import, Backup & Restore, Attendance, Leave Management, Payroll, Payroll History, Employee Contracts, and Performance. Ask about a specific module and I will give you its step-by-step usage.';
        }
    }
    if (assistant_has($q, ['hello','hi','hey','good morning','good afternoon','good evening','kumusta','hello po','hi po'])) {
        return $lang==='tl'
            ? 'Kumusta! Ako ang AI HRIS Assistant. Maaari kitang gabayan sa paggamit ng HRIS, proseso ng Attendance, Leave, Payroll, Performance, Employees, Contracts, at iba pang module na available sa iyong role.'
            : 'Hello! I am the AI HRIS Assistant. I can guide you through the HRIS, including Attendance, Leave, Payroll, Performance, Employees, Contracts, and the modules available to your role.';
    }
    if (assistant_has($q, ['how do i use the hris','how to use hris','hris process','system process','process of the system','paano gamitin ang system','paano gamitin yung system','process ng system','proseso ng system','proseso ng hris'])) {
        if ($lang==='tl') {
            if ($role==='Employee') return 'Employee process: 1) Mag-login. 2) Tingnan ang Dashboard at My Profile. 3) Gumamit ng Attendance para sa Time In/Time Out at attendance history. 4) Gumamit ng Leave Management para mag-file at mag-check ng leave. 5) Tingnan ang Payroll/Payslip at Payroll History. 6) Tingnan ang Performance. 7) Gamitin ang Employee Contract para basahin at i-agree ang employment agreement. 8) Gamitin ang AI Assistant para magtanong tungkol sa system o sarili mong HRIS records.';
            if ($role==='HR Administrator') return 'HR Administrator process: 1) Mag-login. 2) Suriin ang Dashboard. 3) Manage Employees at employee profiles. 4) Manage Positions at position descriptions/status. 5) Manage Attendance at leave requests. 6) Generate/manage Payroll at Payroll History. 7) Create and manage Employee Contracts. 8) Review Performance evaluations. 9) Use Reports, Data Import, Compliance, at Backup/Restore ayon sa authorized access. 10) Monitor activity through available audit/compliance records.';
            return 'Super Admin process: 1) Mag-login at kumpletuhin ang Gmail OTP verification. 2) Suriin ang Dashboard. 3) May access sa HR Administrator functions. 4) Manage Employees, Positions, Attendance, Leave, Payroll, Contracts, at Performance. 5) Review Reports, Compliance, Data Import, at Backup/Restore. 6) Monitor system activity and security records. 7) Official performance evaluations are handled by the Super Admin. 8) Use the AI Assistant for system procedures and role-appropriate information.';
        }
        if ($role==='Employee') return 'Employee process: 1) Log in. 2) Review Dashboard and My Profile. 3) Use Attendance for Time In/Time Out and attendance history. 4) Use Leave Management to file and track leave. 5) Review Payroll/Payslip and Payroll History. 6) Review Performance. 7) Open Employee Contract to read and acknowledge the employment agreement. 8) Use the AI Assistant for system guidance or your own HRIS records.';
        if ($role==='HR Administrator') return 'HR Administrator process: 1) Log in. 2) Review the Dashboard. 3) Manage Employees and employee profiles. 4) Manage Positions, including descriptions and status. 5) Manage Attendance and leave requests. 6) Generate/manage Payroll and Payroll History. 7) Create and manage Employee Contracts. 8) Review Performance evaluations. 9) Use Reports, Data Import, Compliance, and Backup/Restore according to authorized access. 10) Monitor activity through available audit/compliance records.';
        return 'Super Admin process: 1) Log in and complete Gmail OTP verification. 2) Review the Dashboard. 3) Super Admin inherits HR Administrator functions. 4) Manage Employees, Positions, Attendance, Leave, Payroll, Contracts, and Performance. 5) Review Reports, Compliance, Data Import, and Backup/Restore. 6) Monitor system activity and security records. 7) Official performance evaluations are handled by the Super Admin. 8) Use the AI Assistant for system procedures and role-appropriate information.';
    }
    if (assistant_has($q, ['what can you do','what can i ask','help','available modules','modules','ano ang kaya mo','ano pwede itanong','ano ang modules','anong module'])) {
        if ($lang==='tl') return $admin ? 'Bilang '.$role.', maaari kitang gabayan sa step-by-step na paggamit ng mga module na nakikita sa iyong side. Sabihin lang ang pangalan ng module na gusto mong gamitin.' : 'Bilang Employee, maaari kitang gabayan sa Dashboard, My Profile, Attendance, Leave Management, Payroll, Payroll History, Employee Contracts, Performance, at paggamit ng HRIS. Maaari rin kitang bigyan ng impormasyon tungkol sa sarili mong records kapag available sa account mo.';
        return $admin ? 'As '.$role.', I can provide step-by-step guidance only for the modules visible on your side. Tell me the module name and I will explain how to use it.' : 'As an Employee, I can guide you through Dashboard, My Profile, Attendance, Leave Management, Payroll, Payroll History, Employee Contracts, Performance, and general HRIS usage. I can also provide your own records when available to your account.';
    }
    if (assistant_has($q, ['attendance','timekeeping','time in','time out','pasok','oras ng pasok'])) {
        return $lang==='tl' ? 'Attendance process: Employee mag-Time In/Time Out gamit ang facial verification. Nare-record ang date, Time In, Time Out, status, late, overtime, at work hours. Maaaring tingnan ang attendance history at reports. Sa admin side, maaaring i-review ang attendance records ayon sa authorized access.' : 'Attendance process: the Employee uses facial verification for Time In/Time Out. The system records date, Time In, Time Out, status, late, overtime, and work hours. Attendance history and reports can be reviewed. On the admin side, attendance records can be reviewed according to authorized access.';
    }
    if (assistant_has($q, ['leave','vacation','sick leave','leave management','medical certificate','leave balance'])) {
        return $lang==='tl' ? 'Leave process: Employee pipili ng leave type at dates, ilalagay ang reason, at magsusumite. Para sa Sick Leave, maaaring kailanganin ang medical certificate ayon sa system rule. HR/Admin ang nagre-review at nag-aapprove o reject; kapag approved, naa-update ang leave balance.' : 'Leave process: the Employee selects the leave type and dates, enters the reason, and submits the request. Sick Leave may require a medical certificate according to the system rule. HR/Admin reviews and approves or rejects the request; when approved, the leave balance is updated.';
    }
    if (assistant_has($q, ['payroll','payslip','salary','payroll history'])) {
        return $lang==='tl' ? 'Payroll process: ginagamit ang attendance/worked hours, overtime, leave at salary information para sa payroll record. Makikita ang earnings, gross pay, deductions, at net pay sa payslip. Ang Payroll History ay para makita ang mga dating payroll records ayon sa period.' : 'Payroll process: payroll records use attendance/worked hours, overtime, leave, and salary information. The payslip shows earnings, gross pay, deductions, and net pay. Payroll History lets you review previous payroll records by pay period.';
    }
    if (assistant_has($q, ['performance','evaluation','rating','kpi'])) {
        return $lang==='tl' ? 'Performance process: may evaluation period, KPI score/rating, strengths, areas for improvement, comments, at evaluation date. Sa system design, ang official performance evaluation ay ginagawa ng Super Admin, habang maaaring makita ng authorized HR/Admin ang evaluation.' : 'Performance process: evaluations contain an evaluation period, KPI score/rating, strengths, areas for improvement, comments, and evaluation date. In this system design, official performance evaluations are handled by the Super Admin, while authorized HR/Admin users can review evaluations.';
    }
    if (assistant_has($q, ['employee contract','contract','agreement','agreement terms','employment agreement','kontrata','kasunduan'])) {
        return $lang==='tl' ? 'Employee Contract process: HR/Admin gumagawa ng contract, inilalagay ang contract type, dates, salary, at Agreement Terms. Ire-review ng employee ang terms at pipili ng “I Agree” kung tanggap niya. Nare-record ang agreement status at action sa system.' : 'Employee Contract process: HR/Admin creates the contract and enters the contract type, dates, salary, and Agreement Terms. The employee reviews the terms and selects “I Agree” when they accept. The agreement status and action are recorded in the system.';
    }
    if (assistant_has($q, ['position','position description','position status','job position'])) {
        return $lang==='tl' ? 'Position process: Admin/HR ay maaaring mag-add o mag-edit ng Position Title, Position Description, Department, Base Salary, at Active/Inactive status. Sa Add Employee, active positions ang maaaring piliin.' : 'Position process: Admin/HR can add or edit the Position Title, Position Description, Department, Base Salary, and Active/Inactive status. In Add Employee, only active positions can be selected.';
    }
    if (assistant_has($q, ['privacy request','privacy requests'])) {
        return $lang==='tl' ? 'Ang Privacy Request ay hindi na available bilang Employee-side module sa current system interface.' : 'Privacy Request is no longer available as an Employee-side module in the current system interface.';
    }
    if ($super && assistant_has($q, ['backup','restore'])) {
        return $lang==='tl' ? 'Sa Backup & Restore, maaaring gumawa ng SQL backup at gamitin ang authorized restore function. Dahil critical ang restore, dapat tiyakin ang tamang backup file at authorization bago mag-restore.' : 'In Backup & Restore, an authorized administrator can create an SQL backup and use the restore function. Because restore is a critical operation, verify the correct backup file and authorization before restoring.';
    }
    if ($admin && assistant_has($q, ['reports','report','export'])) {
        return $lang==='tl' ? 'Sa Reports & Export, maaaring gumawa ng HR reports at mag-filter ng data ayon sa available report functions. Gamitin lamang ang records na sakop ng iyong role.' : 'In Reports & Export, authorized users can generate HR reports and filter data using the available report functions. Use only records covered by your role.';
    }
    return null;
}

try {
    $answer = system_answer($pdo, $q, $role, $lang);

    // Employee personal data is strictly scoped to the logged-in employee.
    if ($answer === null && $role === 'Employee' && $employeeId) {
        if (assistant_has($q, ['my attendance','my time','my status','attendance status','show my attendance','attendance record'])) {
            $stmt=$pdo->prepare("SELECT attendance_date,time_in,time_out,status,late_minutes,overtime_minutes FROM attendance WHERE employee_id=? ORDER BY attendance_date DESC LIMIT 5");
            $stmt->execute([$employeeId]); $rows=$stmt->fetchAll();
            if (!$rows) $answer=$lang==='tl'?'Wala pang Attendance records sa account mo.':'No Attendance records are available for your account yet.';
            else { $parts=[]; foreach($rows as $r) $parts[]=$r['attendance_date'].' — '.$r['status'].($r['time_in']?' | In: '.$r['time_in']:'').($r['time_out']?' | Out: '.$r['time_out']:'').((int)$r['late_minutes']?' | Late: '.$r['late_minutes'].' min':'').((int)$r['overtime_minutes']?' | OT: '.$r['overtime_minutes'].' min':''); $answer=($lang==='tl'?'Latest attendance records mo: ':'Your latest Attendance records: ').implode('; ',$parts).'.'; }
        } elseif (assistant_has($q, ['my leave','my leave balance','remaining leave','how many leave','show my leave','leave status','my leave request'])) {
            $bal=$pdo->prepare("SELECT leave_type,allocated_days,used_days,(allocated_days-used_days) remaining_days FROM leave_balances WHERE employee_id=? AND year=YEAR(CURDATE()) ORDER BY leave_type"); $bal->execute([$employeeId]); $balances=$bal->fetchAll();
            $req=$pdo->prepare("SELECT leave_type,start_date,end_date,total_days,status FROM leave_requests WHERE employee_id=? ORDER BY start_date DESC LIMIT 5"); $req->execute([$employeeId]); $requests=$req->fetchAll();
            $parts=[]; foreach($balances as $r) $parts[]=$r['leave_type'].': '.(int)$r['remaining_days'].' day(s) remaining'; foreach($requests as $r) $parts[]='Request '.$r['leave_type'].' '.$r['start_date'].' to '.$r['end_date'].' ('.$r['total_days'].' day(s), '.$r['status'].')';
            $answer=$parts ? (($lang==='tl'?'Leave information mo: ':'Your Leave Management information: ').implode('; ',$parts).'.') : ($lang==='tl'?'Wala pang Leave records sa account mo.':'No Leave Management records are available for your account yet.');
        } elseif (assistant_has($q, ['my payroll','my salary','my payslip','show my payroll','my pay'])) {
            $stmt=$pdo->prepare("SELECT pay_period_start,pay_period_end,basic_salary,overtime_pay,bonuses,gross_pay,total_deductions,net_pay FROM payroll WHERE employee_id=? ORDER BY pay_period_end DESC,payroll_id DESC LIMIT 3"); $stmt->execute([$employeeId]); $rows=$stmt->fetchAll();
            if (!$rows) $answer=$lang==='tl'?'Wala pang Payroll records sa account mo.':'No Payroll records are available for your account yet.';
            else { $parts=[]; foreach($rows as $r) $parts[]=$r['pay_period_start'].' to '.$r['pay_period_end'].': Basic '.money($r['basic_salary']).', Gross '.money($r['gross_pay']).', Deductions '.money($r['total_deductions']).', Net Pay '.money($r['net_pay']); $answer=($lang==='tl'?'Latest payroll records mo: ':'Your latest Payroll records: ').implode('; ',$parts).'.'; }
        } elseif (assistant_has($q, ['my performance','my rating','my evaluation','show my performance'])) {
            $stmt=$pdo->prepare("SELECT evaluation_period,kpi_score,rating,strengths,areas_for_improvement,evaluation_date FROM performance WHERE employee_id=? ORDER BY evaluation_date DESC,performance_id DESC LIMIT 3"); $stmt->execute([$employeeId]); $rows=$stmt->fetchAll();
            if (!$rows) $answer=$lang==='tl'?'Wala pang Performance records sa account mo.':'No Performance records are available for your account yet.';
            else { $parts=[]; foreach($rows as $r) $parts[]=$r['evaluation_period'].': '.$r['rating'].' ('.number_format((float)$r['kpi_score'],2).'%) on '.$r['evaluation_date']; $answer=($lang==='tl'?'Latest performance evaluations mo: ':'Your latest Performance evaluations: ').implode('; ',$parts).'.'; }
        } elseif (assistant_has($q, ['my employee','my profile','my information','who am i','my details'])) {
            $stmt=$pdo->prepare("SELECT e.employee_id,e.first_name,e.middle_name,e.last_name,e.email,e.phone,d.department_name,p.position_title,e.employment_status,e.date_hired FROM employees e LEFT JOIN departments d ON d.department_id=e.department_id LEFT JOIN positions p ON p.position_id=e.position_id WHERE e.employee_id=? LIMIT 1"); $stmt->execute([$employeeId]); $r=$stmt->fetch();
            if (!$r) $answer=$lang==='tl'?'Hindi available ang Employee profile mo.':'Your Employee profile is not available yet.';
            else { $name=trim($r['first_name'].' '.($r['middle_name'] ? $r['middle_name'].' ' : '').$r['last_name']); $answer=$lang==='tl'?'Profile mo: '.$name.' ('.$r['employee_id'].'), Department: '.($r['department_name']?:'Not set').', Position: '.($r['position_title']?:'Not set').', Status: '.($r['employment_status']?:'Not set').', Hire Date: '.($r['date_hired']?:'Not set').'.':'Your Employee profile: '.$name.' ('.$r['employee_id'].'), Department: '.($r['department_name']?:'Not set').', Position: '.($r['position_title']?:'Not set').', Status: '.($r['employment_status']?:'Not set').', Hire Date: '.($r['date_hired']?:'Not set').'.'; }
        }
    }

    if ($answer === null) {
        $answer = $lang==='tl'
            ? 'Maaari kitang tulungan sa HRIS process. Halimbawa: “Paano gamitin ang system?”, “Paano mag-file ng leave?”, “Ano ang payroll process?”, “Paano ang employee contract?”, o “Ano ang Position Description?”'
            : 'I can help with the HRIS process. Try: “How do I use the system?”, “How do I apply for leave?”, “What is the payroll process?”, “How does the employee contract work?”, or “What is a Position Description?”';
    }
    echo json_encode(['ok'=>true,'message'=>$answer,'role'=>role_label($role),'language'=>$lang]);
} catch (Throwable $e) {
    error_log('[HRIS Assistant] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>$lang==='tl'?'Hindi ko makuha ang HRIS information ngayon. Pakisubukan ulit.':'I could not retrieve that HRIS information right now. Please try again.']);
}
