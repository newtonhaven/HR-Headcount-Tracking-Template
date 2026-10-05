<?php
declare(strict_types=1);

/**
 * Generates database/seed.sql with fictional demo data (organization, shifts, tasks, employees).
 *
 *   php database/generate-seed.php > database/seed.sql
 *
 * Output is deterministic (fixed random seed). Headcounts are derived from the computed norm
 * staffing need, so the analytics pages show a realistic mix of under-, over- and well-staffed positions.
 */

require __DIR__ . '/../src/Workload.php';

mt_srand(20261003);
const REFERENCE_DATE = '2026-09-30';

// ---------------------------------------------------------------------------------------------
// Shifts
// ---------------------------------------------------------------------------------------------
$shifts = [
    'Office' => ['Office Hours', '08:30', '17:30', 5, 8.0, 'Weekdays, includes 1 h lunch break'],
    'Day' => ['Day Shift', '08:00', '16:00', 7, 7.0, 'Seven days a week'],
    'Evening' => ['Evening Shift', '16:00', '00:00', 7, 7.0, 'Seven days a week'],
    'Night' => ['Night Shift', '00:00', '08:00', 7, 7.0, 'Seven days a week'],
    'Long Day' => ['Long Day', '08:00', '20:00', 7, 10.5, '12 h shift, 1.5 h breaks'],
    'Long Night' => ['Long Night', '20:00', '08:00', 7, 10.5, '12 h shift, 1.5 h breaks'],
];
// Night and evening shifts see less activity than day shifts.
$shiftVolume = ['Office' => 1.0, 'Day' => 1.0, 'Long Day' => 1.0, 'Evening' => 0.7, 'Night' => 0.5, 'Long Night' => 0.55];

// ---------------------------------------------------------------------------------------------
// Task library per position title: [task, unit of measure, duration min, duration max, period, freq min, freq max]
// period: d = per day, w = per week, m = per month
// ---------------------------------------------------------------------------------------------
$library = [
    'Clinic Secretary' => [
        ['Register patient arrivals in HIS', 'patient', 2, 4, 'd', 40, 70],
        ['Schedule and reschedule appointments', 'appointment', 3, 5, 'd', 20, 35],
        ['Answer phone enquiries', 'call', 2, 4, 'd', 25, 45],
        ['Prepare medical reports (sick leave, prescriptions)', 'report', 5, 8, 'd', 8, 15],
        ['File patient documents', 'file', 1, 2, 'd', 20, 40],
        ['Order clinic supplies', 'order', 15, 25, 'w', 1, 2],
        ['Prepare monthly activity statistics', 'report', 60, 120, 'm', 1, 1],
    ],
    'Outpatient Nurse' => [
        ['Take vital signs before examination', 'patient', 4, 6, 'd', 30, 50],
        ['Assist physician with procedures', 'procedure', 10, 20, 'd', 4, 10],
        ['Administer injections and medication', 'dose', 5, 8, 'd', 6, 14],
        ['Patient education and discharge instructions', 'patient', 5, 10, 'd', 5, 12],
        ['Prepare and sterilize instruments', 'set', 8, 12, 'd', 3, 8],
        ['Check emergency trolley and expiry dates', 'check', 15, 25, 'w', 1, 2],
    ],
    'Ward Nurse' => [
        ['Medication rounds', 'patient', 4, 7, 'd', 30, 48],
        ['Vital signs monitoring', 'patient', 3, 5, 'd', 40, 60],
        ['Wound care and dressing changes', 'dressing', 10, 20, 'd', 6, 12],
        ['Admission assessment', 'patient', 20, 30, 'd', 2, 5],
        ['Discharge preparation', 'patient', 15, 25, 'd', 2, 5],
        ['Shift handover report', 'handover', 20, 30, 'd', 1, 1],
        ['Nursing documentation in HIS', 'patient', 3, 6, 'd', 25, 40],
    ],
    'Nursing Aide' => [
        ['Patient hygiene and bathing', 'patient', 15, 25, 'd', 6, 10],
        ['Bed making and linen change', 'bed', 6, 10, 'd', 10, 18],
        ['Meal distribution and feeding support', 'patient', 5, 10, 'd', 10, 20],
        ['Patient positioning and mobilization', 'patient', 5, 8, 'd', 10, 20],
        ['Take specimens to the laboratory', 'trip', 10, 15, 'd', 3, 6],
    ],
    'Ward Secretary' => [
        ['Admission and discharge paperwork', 'patient', 8, 12, 'd', 6, 12],
        ['Update bed occupancy', 'update', 2, 4, 'd', 10, 20],
        ['Order consumables from central store', 'order', 15, 30, 'w', 2, 3],
        ['Phone and visitor enquiries', 'call', 2, 4, 'd', 20, 35],
        ['Prepare insurance documents', 'file', 10, 15, 'd', 3, 6],
    ],
    'Emergency Nurse' => [
        ['Initial assessment of emergency patients', 'patient', 4, 7, 'd', 25, 45],
        ['Resuscitation and critical care support', 'case', 20, 45, 'd', 2, 5],
        ['IV line insertion and infusions', 'procedure', 8, 12, 'd', 10, 20],
        ['Medication administration', 'dose', 4, 6, 'd', 20, 35],
        ['Patient documentation', 'patient', 3, 5, 'd', 25, 45],
        ['Restock emergency carts', 'check', 20, 30, 'd', 1, 1],
    ],
    'Triage Nurse' => [
        ['Initial triage and priority coding', 'patient', 3, 6, 'd', 50, 90],
        ['Re-assess waiting patients', 'patient', 2, 3, 'd', 20, 40],
        ['Direct patients to treatment areas', 'patient', 1, 2, 'd', 50, 90],
        ['Report triage statistics', 'report', 30, 45, 'w', 1, 1],
    ],
    'Admissions Clerk' => [
        ['Patient registration and insurance check', 'patient', 3, 6, 'd', 60, 110],
        ['Print wristbands and labels', 'patient', 1, 2, 'd', 60, 110],
        ['Collect co-payments and issue receipts', 'payment', 2, 4, 'd', 20, 40],
        ['Answer information desk queries', 'query', 2, 3, 'd', 30, 60],
        ['End-of-shift cash reconciliation', 'report', 20, 30, 'd', 1, 1],
    ],
    'Scrub Nurse' => [
        ['Prepare operating room and instruments', 'operation', 20, 30, 'd', 4, 7],
        ['Assist surgeon during operation', 'operation', 60, 120, 'd', 2, 4],
        ['Instrument count and documentation', 'operation', 10, 15, 'd', 4, 7],
        ['Post-operative cleaning and handover', 'operation', 15, 20, 'd', 4, 7],
    ],
    'Anesthesia Technician' => [
        ['Anesthesia machine check', 'machine', 10, 15, 'd', 4, 8],
        ['Prepare anesthesia drugs and equipment', 'case', 10, 15, 'd', 5, 9],
        ['Patient monitoring support', 'case', 30, 60, 'd', 2, 4],
        ['Restock anesthesia carts', 'cart', 15, 20, 'd', 2, 4],
    ],
    'Sterilization Technician' => [
        ['Decontaminate surgical instruments', 'set', 8, 12, 'd', 20, 40],
        ['Pack and label instrument sets', 'set', 5, 8, 'd', 20, 40],
        ['Run autoclave cycles and record logs', 'cycle', 10, 15, 'd', 6, 10],
        ['Biological indicator testing', 'test', 10, 15, 'w', 3, 5],
    ],
    'Radiology Technician' => [
        ['Patient positioning and image acquisition', 'exam', 8, 15, 'd', 15, 35],
        ['Image quality check and PACS upload', 'exam', 2, 4, 'd', 15, 35],
        ['Contrast media preparation', 'exam', 5, 10, 'd', 2, 8],
        ['Equipment quality control', 'test', 20, 30, 'w', 2, 3],
        ['Radiation dose log', 'report', 15, 20, 'w', 1, 2],
    ],
    'Radiology Secretary' => [
        ['Schedule imaging appointments', 'appointment', 3, 5, 'd', 30, 50],
        ['Print and distribute reports', 'report', 1, 2, 'd', 30, 60],
        ['Phone enquiries', 'call', 2, 3, 'd', 20, 35],
    ],
    'Laboratory Technician' => [
        ['Sample reception and barcode registration', 'sample', 1, 2, 'd', 80, 150],
        ['Run analyzer batches', 'batch', 10, 20, 'd', 6, 12],
        ['Analyzer calibration and QC', 'analyzer', 15, 25, 'd', 2, 4],
        ['Result validation', 'result', 0.5, 1, 'd', 150, 300],
        ['Reagent inventory and ordering', 'order', 20, 30, 'w', 1, 2],
    ],
    'Pathology Technician' => [
        ['Tissue grossing support', 'specimen', 8, 12, 'd', 10, 20],
        ['Tissue processing and embedding', 'block', 3, 5, 'd', 30, 50],
        ['Sectioning and staining', 'slide', 3, 5, 'd', 40, 70],
        ['Slide archiving', 'slide', 0.5, 1, 'd', 40, 70],
    ],
    'Blood Bank Technician' => [
        ['Blood grouping and cross-match', 'test', 15, 25, 'd', 8, 16],
        ['Issue blood products', 'unit', 5, 8, 'd', 8, 15],
        ['Donor screening support', 'donor', 10, 15, 'd', 5, 12],
        ['Temperature and inventory log', 'check', 10, 15, 'd', 2, 3],
    ],
    'Pharmacist' => [
        ['Prescription verification', 'prescription', 2, 4, 'd', 60, 110],
        ['Clinical medication review', 'patient', 10, 15, 'd', 6, 12],
        ['Controlled drugs inventory', 'check', 30, 45, 'w', 2, 3],
        ['Drug information enquiries', 'query', 5, 10, 'd', 5, 10],
        ['Monthly stock count', 'count', 120, 180, 'm', 1, 1],
    ],
    'Pharmacy Technician' => [
        ['Prepare unit-dose medication', 'order', 3, 5, 'd', 50, 90],
        ['Receive and shelve deliveries', 'delivery', 20, 40, 'd', 1, 3],
        ['Deliver medication to wards', 'trip', 15, 25, 'd', 3, 6],
        ['Expiry date checks', 'shelf', 5, 10, 'w', 10, 15],
    ],
    'HR Specialist' => [
        ['Process onboarding paperwork', 'employee', 30, 45, 'w', 3, 8],
        ['Leave and attendance records', 'request', 3, 5, 'd', 15, 30],
        ['Payroll input preparation', 'batch', 120, 180, 'm', 2, 2],
        ['Answer staff enquiries', 'query', 5, 10, 'd', 8, 15],
        ['Prepare HR reports', 'report', 60, 90, 'm', 3, 5],
        ['Exit interviews and offboarding', 'employee', 30, 45, 'm', 3, 8],
    ],
    'Accountant' => [
        ['Supplier invoice processing', 'invoice', 4, 8, 'd', 25, 45],
        ['Patient billing corrections', 'bill', 5, 10, 'd', 10, 20],
        ['Bank reconciliation', 'account', 30, 60, 'w', 2, 3],
        ['Month-end closing', 'report', 240, 360, 'm', 1, 1],
    ],
    'Medical Records Officer' => [
        ['Retrieve and return patient files', 'file', 3, 5, 'd', 30, 60],
        ['Scan documents to digital archive', 'batch', 5, 8, 'd', 20, 40],
        ['Release-of-information requests', 'request', 10, 20, 'd', 3, 8],
        ['Archive audit', 'audit', 60, 120, 'm', 1, 2],
    ],
    'Quality Officer' => [
        ['Internal audits', 'audit', 90, 180, 'm', 4, 8],
        ['Incident report analysis', 'report', 20, 40, 'w', 3, 8],
        ['Quality indicator data collection', 'indicator', 10, 20, 'w', 5, 10],
        ['Staff training sessions', 'session', 60, 90, 'm', 2, 4],
    ],
    'Patient Relations Officer' => [
        ['Handle patient complaints', 'complaint', 15, 30, 'd', 3, 8],
        ['Patient satisfaction surveys', 'survey', 3, 5, 'd', 10, 20],
        ['Guide patients and visitors', 'visitor', 2, 5, 'd', 15, 30],
    ],
    'IT Support Specialist' => [
        ['Resolve helpdesk tickets', 'ticket', 10, 25, 'd', 10, 20],
        ['User account management', 'request', 5, 10, 'd', 5, 12],
        ['Workstation installation', 'device', 45, 90, 'w', 2, 5],
        ['Backup verification', 'check', 20, 30, 'd', 1, 1],
        ['Network and server monitoring', 'check', 15, 20, 'd', 2, 3],
    ],
    'Cook' => [
        ['Meal preparation', 'portion', 0.5, 1, 'd', 250, 450],
        ['Special diet preparation', 'portion', 2, 3, 'd', 30, 60],
        ['Kitchen cleaning', 'cleaning', 30, 45, 'd', 2, 3],
        ['Food safety temperature logs', 'check', 5, 10, 'd', 4, 6],
    ],
    'Catering Assistant' => [
        ['Tray assembly', 'tray', 1, 2, 'd', 150, 250],
        ['Meal delivery to wards', 'trip', 15, 25, 'd', 6, 9],
        ['Dishwashing', 'rack', 3, 5, 'd', 30, 50],
    ],
    'Laundry Worker' => [
        ['Sort soiled linen', 'batch', 5, 8, 'd', 20, 35],
        ['Operate washers and dryers', 'load', 10, 15, 'd', 10, 18],
        ['Ironing and folding', 'batch', 8, 12, 'd', 15, 25],
        ['Distribute clean linen to wards', 'trip', 15, 20, 'd', 4, 8],
    ],
    'Housekeeper' => [
        ['Patient room cleaning', 'room', 15, 25, 'd', 10, 16],
        ['Discharge (terminal) cleaning', 'room', 30, 45, 'd', 2, 5],
        ['Corridor and common area cleaning', 'area', 20, 30, 'd', 3, 6],
        ['Waste collection', 'round', 15, 20, 'd', 3, 5],
    ],
    'Porter' => [
        ['Transport patients by wheelchair or stretcher', 'transfer', 10, 20, 'd', 15, 30],
        ['Transport specimens and documents', 'trip', 8, 12, 'd', 10, 20],
        ['Equipment transfers', 'trip', 10, 15, 'd', 4, 8],
    ],
    'Security Officer' => [
        ['Patrol rounds', 'round', 20, 30, 'd', 6, 10],
        ['Visitor screening at entrances', 'visitor', 0.5, 1, 'd', 200, 400],
        ['Incident response', 'incident', 15, 30, 'd', 1, 4],
        ['CCTV monitoring checks', 'check', 10, 15, 'd', 6, 10],
        ['Write incident reports', 'report', 15, 20, 'd', 1, 3],
    ],
    'Maintenance Technician' => [
        ['Corrective maintenance work orders', 'work order', 30, 90, 'd', 3, 6],
        ['Preventive maintenance checks', 'check', 20, 40, 'd', 2, 5],
        ['HVAC and generator inspection', 'inspection', 30, 60, 'w', 2, 4],
        ['Spare parts inventory', 'count', 60, 90, 'm', 1, 2],
    ],
    'Biomedical Engineer' => [
        ['Medical device repairs', 'repair', 45, 120, 'd', 1, 3],
        ['Preventive maintenance of devices', 'device', 30, 60, 'd', 2, 4],
        ['Device calibration', 'device', 20, 40, 'w', 5, 10],
        ['Vendor service coordination', 'call', 15, 30, 'w', 3, 6],
    ],
    'Driver' => [
        ['Patient transfer between facilities', 'trip', 45, 90, 'd', 2, 5],
        ['Document and specimen delivery', 'trip', 30, 60, 'd', 2, 4],
        ['Vehicle inspection and logbook', 'check', 15, 20, 'd', 1, 1],
        ['Fuel and clean vehicle', 'task', 20, 30, 'w', 2, 3],
    ],
];

// ---------------------------------------------------------------------------------------------
// Organization
// ---------------------------------------------------------------------------------------------
$clinic = [['Clinic Secretary', 'Office'], ['Outpatient Nurse', 'Day']];
$ward = [['Ward Nurse', 'Day'], ['Ward Nurse', 'Night'], ['Nursing Aide', 'Day'], ['Ward Secretary', 'Office']];
$organization = [
    'Outpatient Clinics' => [
        'Cardiology Clinic' => $clinic, 'Dermatology Clinic' => $clinic, 'ENT Clinic' => $clinic,
        'General Surgery Clinic' => $clinic, 'Internal Medicine Clinic' => $clinic, 'Neurology Clinic' => $clinic,
        'Ophthalmology Clinic' => $clinic, 'Orthopedics Clinic' => $clinic, 'Pediatrics Clinic' => $clinic,
    ],
    'Inpatient Wards' => [
        'Cardiology Ward' => $ward, 'General Surgery Ward' => $ward, 'Internal Medicine Ward' => $ward,
        'Maternity Ward' => $ward, 'Oncology Ward' => $ward, 'Orthopedics Ward' => $ward, 'Pediatric Ward' => $ward,
    ],
    'Emergency & Admissions' => [
        'Adult Emergency' => [['Emergency Nurse', 'Long Day'], ['Emergency Nurse', 'Long Night']],
        'Pediatric Emergency' => [['Emergency Nurse', 'Long Day'], ['Emergency Nurse', 'Long Night']],
        'Triage' => [['Triage Nurse', 'Long Day'], ['Triage Nurse', 'Long Night']],
        'Patient Admissions' => [['Admissions Clerk', 'Long Day'], ['Admissions Clerk', 'Long Night']],
    ],
    'Surgery & Anesthesia' => [
        'Central Operating Theatre' => [['Scrub Nurse', 'Day'], ['Scrub Nurse', 'Evening']],
        'Day Surgery Unit' => [['Scrub Nurse', 'Office'], ['Ward Nurse', 'Office']],
        'Anesthesia' => [['Anesthesia Technician', 'Day'], ['Anesthesia Technician', 'Night']],
        'Sterilization Unit' => [['Sterilization Technician', 'Day'], ['Sterilization Technician', 'Evening']],
    ],
    'Diagnostic Imaging' => [
        'X-Ray' => [['Radiology Technician', 'Day'], ['Radiology Technician', 'Night'], ['Radiology Secretary', 'Office']],
        'Computed Tomography' => [['Radiology Technician', 'Day'], ['Radiology Technician', 'Evening']],
        'Magnetic Resonance' => [['Radiology Technician', 'Day'], ['Radiology Technician', 'Evening']],
        'Ultrasound' => [['Radiology Technician', 'Office'], ['Radiology Secretary', 'Office']],
        'Mammography' => [['Radiology Technician', 'Office']],
    ],
    'Laboratory Services' => [
        'Central Laboratory' => [['Laboratory Technician', 'Day'], ['Laboratory Technician', 'Night']],
        'Microbiology' => [['Laboratory Technician', 'Office']],
        'Pathology' => [['Pathology Technician', 'Office']],
        'Blood Bank' => [['Blood Bank Technician', 'Long Day'], ['Blood Bank Technician', 'Long Night']],
    ],
    'Pharmacy' => [
        'Inpatient Pharmacy' => [['Pharmacist', 'Office'], ['Pharmacy Technician', 'Day'], ['Pharmacy Technician', 'Night']],
        'Outpatient Pharmacy' => [['Pharmacist', 'Office'], ['Pharmacy Technician', 'Office']],
    ],
    'Administration' => [
        'Human Resources' => [['HR Specialist', 'Office']],
        'Finance & Accounting' => [['Accountant', 'Office']],
        'Medical Records' => [['Medical Records Officer', 'Office']],
        'Quality Management' => [['Quality Officer', 'Office']],
        'Patient Relations' => [['Patient Relations Officer', 'Office']],
        'IT Department' => [['IT Support Specialist', 'Office'], ['IT Support Specialist', 'Evening']],
    ],
    'Support Services' => [
        'Kitchen & Catering' => [['Cook', 'Long Day'], ['Catering Assistant', 'Day']],
        'Laundry' => [['Laundry Worker', 'Day']],
        'Housekeeping' => [['Housekeeper', 'Day'], ['Housekeeper', 'Evening'], ['Housekeeper', 'Night']],
        'Patient Transport' => [['Porter', 'Day'], ['Porter', 'Evening'], ['Porter', 'Night']],
    ],
    'Facilities & Security' => [
        'Security' => [['Security Officer', 'Long Day'], ['Security Officer', 'Long Night']],
        'Technical Maintenance' => [['Maintenance Technician', 'Day'], ['Maintenance Technician', 'Evening']],
        'Biomedical Engineering' => [['Biomedical Engineer', 'Office']],
        'Motor Pool' => [['Driver', 'Long Day'], ['Driver', 'Long Night']],
    ],
];

$unitVolume = [
    'Adult Emergency' => 2.2, 'Patient Admissions' => 1.6, 'Central Laboratory' => 2.0, 'Security' => 1.8,
    'Housekeeping' => 3.0, 'Patient Transport' => 2.0, 'Kitchen & Catering' => 1.5, 'Internal Medicine Ward' => 1.4,
    'General Surgery Ward' => 1.3, 'Central Operating Theatre' => 2.5, 'Inpatient Pharmacy' => 1.4, 'X-Ray' => 1.5,
];

// ---------------------------------------------------------------------------------------------
// People
// ---------------------------------------------------------------------------------------------
$femaleNames = ['Ayşe', 'Elif', 'Zeynep', 'Merve', 'Emma', 'Olivia', 'Sofia', 'Hannah', 'Leyla', 'Deniz', 'Selin', 'Maria',
    'Laura', 'Nora', 'Ece', 'Fatma', 'Esra', 'Clara', 'Julia', 'Mia', 'Defne', 'Lina', 'Sara', 'Aylin', 'Irem', 'Nadia',
    'Chloe', 'Eva', 'Büşra', 'Gizem', 'Amira', 'Isabel', 'Derya', 'Melis', 'Yasmin', 'Grace'];
$maleNames = ['Mehmet', 'Ahmet', 'Mustafa', 'Emre', 'Can', 'Burak', 'James', 'Lucas', 'Noah', 'Daniel', 'Omar', 'Ali',
    'Kerem', 'Murat', 'David', 'Leo', 'Adam', 'Yusuf', 'Onur', 'Eren', 'Thomas', 'Samuel', 'Hakan', 'Ivan', 'Serkan',
    'Oliver', 'Mateo', 'Barış', 'Tolga', 'Karim', 'Felix', 'Volkan', 'Ethan', 'Ozan'];
$lastNames = ['Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Yıldız', 'Aydın', 'Öztürk', 'Arslan', 'Doğan', 'Smith',
    'Johnson', 'Brown', 'Garcia', 'Müller', 'Rossi', 'Novak', 'Petrov', 'Haddad', 'Kim', 'Silva', 'Martin', 'Fischer',
    'Koç', 'Kurt', 'Özdemir', 'Aksoy', 'Polat', 'Erdem', 'Güneş', 'Wilson', 'Taylor', 'Moreau', 'Costa', 'Nielsen',
    'Bulut', 'Korkmaz', 'Tan', 'Lopez', 'Schneider', 'Ivanova', 'Yalçın', 'Acar', 'Turan', 'Keller', 'Ramos'];

// Probability that a person in this title is female (others default to 0.5).
$femaleShare = [
    'Ward Nurse' => 0.82, 'Outpatient Nurse' => 0.8, 'Emergency Nurse' => 0.7, 'Triage Nurse' => 0.75, 'Scrub Nurse' => 0.75,
    'Nursing Aide' => 0.7, 'Clinic Secretary' => 0.75, 'Ward Secretary' => 0.75, 'Radiology Secretary' => 0.7,
    'Security Officer' => 0.12, 'Driver' => 0.08, 'Maintenance Technician' => 0.1, 'Porter' => 0.25, 'Biomedical Engineer' => 0.3,
];
$terminationReasons = ['Resignation' => 50, 'End of contract' => 18, 'Retirement' => 12, 'Transfer' => 12, 'Dismissal' => 8];
$employmentTypes = ['permanent' => 66, 'contract' => 24, 'temporary' => 7, 'intern' => 3];

// ---------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------
function q(?string $value): string
{
    return $value === null ? 'NULL' : "'" . strtr($value, ['\\' => '\\\\', "'" => "''"]) . "'";
}

function num(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
}

function rand_float(float $min, float $max): float
{
    return $min + mt_rand() / mt_getrandmax() * ($max - $min);
}

function pick(array $items)
{
    return $items[mt_rand(0, count($items) - 1)];
}

function weighted(array $weights): string
{
    $roll = mt_rand(1, array_sum($weights));
    foreach ($weights as $key => $weight) {
        $roll -= $weight;
        if ($roll <= 0) {
            return $key;
        }
    }
    return array_key_first($weights);
}

function random_date(string $from, string $to): string
{
    $a = strtotime($from);
    $b = strtotime($to);
    return date('Y-m-d', mt_rand(min($a, $b), max($a, $b)));
}

function insert(string $table, array $columns, array $rows, int $chunk = 200): string
{
    $sql = '';
    foreach (array_chunk($rows, $chunk) as $batch) {
        $sql .= "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES\n  "
            . implode(",\n  ", array_map(fn($r) => '(' . implode(', ', $r) . ')', $batch)) . ";\n";
    }
    return $sql . "\n";
}

// ---------------------------------------------------------------------------------------------
// Build rows
// ---------------------------------------------------------------------------------------------
$shiftRows = [];
$shiftIds = [];
$id = 0;
foreach ($shifts as $key => [$name, $start, $end, $days, $hours, $notes]) {
    $shiftIds[$key] = ++$id;
    $shiftRows[] = [$id, q($name), q("$start:00"), q("$end:00"), $days, num($hours), q($notes)];
}

$groupRows = $unitRows = $positionRows = $taskRows = $employeeRows = [];
$groupId = $unitId = $positionId = $taskId = $employeeId = 0;
$usedNames = [];

$newEmployee = function (string $title, ?int $positionId, string $hireDate, ?string $terminationDate)
    use (&$employeeId, &$employeeRows, &$usedNames, $femaleNames, $maleNames, $lastNames, $femaleShare, $terminationReasons, $employmentTypes) {
    $female = rand_float(0, 1) < ($femaleShare[$title] ?? 0.5);
    do {
        $first = pick($female ? $femaleNames : $maleNames);
        $last = pick($lastNames);
    } while (isset($usedNames["$first $last"]) && $usedNames["$first $last"] > 1);
    $usedNames["$first $last"] = ($usedNames["$first $last"] ?? 0) + 1;

    $birth = date('Y-m-d', strtotime($hireDate . ' -' . mt_rand(19, 45) . ' years -' . mt_rand(0, 364) . ' days'));
    $minBirth = date('Y-m-d', strtotime(REFERENCE_DATE . ' -64 years'));
    if ($birth < $minBirth) {
        $birth = random_date($minBirth, date('Y-m-d', strtotime($minBirth . ' +5 years')));
    }

    $type = weighted($employmentTypes);
    $employeeId++;
    $employeeRows[] = [
        $employeeId,
        q(sprintf('E%05d', 10000 + $employeeId)),
        q($first),
        q($last),
        q($female ? 'female' : 'male'),
        q($birth),
        $positionId ?? 'NULL',
        q($type),
        q($hireDate),
        q($terminationDate),
        q($terminationDate ? weighted($terminationReasons) : null),
    ];
};

$referenceTs = strtotime(REFERENCE_DATE);
foreach ($organization as $groupName => $units) {
    $groupRows[] = [++$groupId, q($groupName)];
    foreach ($units as $unitName => $positions) {
        $unitRows[] = [++$unitId, $groupId, q($unitName)];
        $unitFactor = ($unitVolume[$unitName] ?? 1.0) * rand_float(1.7, 2.7);

        foreach ($positions as [$title, $shiftKey]) {
            $positionRows[] = [++$positionId, $unitId, q($title), $shiftIds[$shiftKey]];
            $volume = $unitFactor * $shiftVolume[$shiftKey];

            $tasks = [];
            foreach ($library[$title] as [$name, $uom, $dMin, $dMax, $period, $fMin, $fMax]) {
                $scale = $fMin === $fMax ? 1.0 : $volume;
                $freqMin = max(1, (int) round($fMin * $scale));
                $freqMax = max($freqMin, (int) round($fMax * $scale));
                $task = [
                    'duration_min' => $dMin, 'duration_max' => $dMax,
                    'daily_min' => 0, 'daily_max' => 0, 'weekly_min' => 0, 'weekly_max' => 0, 'monthly_min' => 0, 'monthly_max' => 0,
                    'personal_allowance_pct' => mt_rand(5, 8), 'rest_allowance_pct' => mt_rand(4, 12),
                ];
                $prefix = ['d' => 'daily', 'w' => 'weekly', 'm' => 'monthly'][$period];
                $task["{$prefix}_min"] = $freqMin;
                $task["{$prefix}_max"] = $freqMax;
                $tasks[] = $task;

                $taskRows[] = [
                    ++$taskId, $positionId, q($name), q($uom),
                    num($dMin), num($dMax),
                    num($task['daily_min']), num($task['daily_max']),
                    num($task['weekly_min']), num($task['weekly_max']),
                    num($task['monthly_min']), num($task['monthly_max']),
                    num($task['personal_allowance_pct']), num($task['rest_allowance_pct']),
                ];
            }

            [, , , $days, $hours] = $shifts[$shiftKey];
            $required = Workload::position($tasks, ['active_days' => $days, 'effective_hours' => $hours], 0)['required_fte'];
            $roll = rand_float(0, 1);
            $factor = $roll < 0.5 ? rand_float(0.9, 1.1) : ($roll < 0.78 ? rand_float(0.55, 0.85) : ($roll < 0.92 ? rand_float(1.15, 1.5) : rand_float(0.85, 1.15)));
            $active = max(1, (int) round($required * $factor));

            for ($i = 0; $i < $active; $i++) {
                $hire = rand_float(0, 1) < 0.5
                    ? random_date('2020-10-01', REFERENCE_DATE)
                    : random_date('2004-01-01', '2020-09-30');
                $newEmployee($title, $positionId, $hire, null);
            }

            // Leavers over the last three years (~11% annual turnover).
            $leavers = (int) round($active * rand_float(0.15, 0.5));
            for ($i = 0; $i < $leavers; $i++) {
                $termination = random_date('2023-10-01', REFERENCE_DATE);
                $hire = random_date(date('Y-m-d', strtotime($termination . ' -12 years')), date('Y-m-d', strtotime($termination . ' -90 days')));
                $newEmployee($title, $positionId, $hire, $termination);
            }
        }
    }
}

// A few recent hires that have not been placed in a position yet.
for ($i = 0; $i < 5; $i++) {
    $newEmployee('Unassigned', null, random_date('2026-08-01', REFERENCE_DATE), null);
}

// ---------------------------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------------------------
echo "-- Fictional demo data generated by database/generate-seed.php (reference date " . REFERENCE_DATE . ").\n";
echo "-- Every name, unit and number in this file is made up.\n\n";
echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
echo insert('shifts', ['id', 'name', 'start_time', 'end_time', 'active_days', 'effective_hours', 'notes'], $shiftRows);
echo insert('unit_groups', ['id', 'name'], $groupRows);
echo insert('units', ['id', 'group_id', 'name'], $unitRows);
echo insert('positions', ['id', 'unit_id', 'title', 'shift_id'], $positionRows);
echo insert('tasks', ['id', 'position_id', 'name', 'unit_of_measure', 'duration_min', 'duration_max', 'daily_min', 'daily_max',
    'weekly_min', 'weekly_max', 'monthly_min', 'monthly_max', 'personal_allowance_pct', 'rest_allowance_pct'], $taskRows);
echo insert('employees', ['id', 'employee_no', 'first_name', 'last_name', 'gender', 'birth_date', 'position_id', 'employment_type',
    'hire_date', 'termination_date', 'termination_reason'], $employeeRows);
echo "SET FOREIGN_KEY_CHECKS = 1;\n";

fwrite(STDERR, sprintf(
    "Generated %d groups, %d units, %d positions, %d tasks, %d employees.\n",
    $groupId, $unitId, $positionId, $taskId, $employeeId
));
