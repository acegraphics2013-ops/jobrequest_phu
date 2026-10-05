<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Help Assistant Database Schema Setup & Comprehensive Seeding
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/config.php';

echo "Running Full Help Assistant Schema Migration & Seeding...\n";

// 1. Categories Table
$conn->query("
CREATE TABLE IF NOT EXISTS help_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(64) NOT NULL UNIQUE,
    title_en VARCHAR(150) NOT NULL,
    title_bisaya VARCHAR(150) NOT NULL,
    title_tagalog VARCHAR(150) NOT NULL,
    icon VARCHAR(64) DEFAULT 'bi-question-circle',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 2. FAQs Table
$conn->query("
CREATE TABLE IF NOT EXISTS help_faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_key VARCHAR(64) NOT NULL DEFAULT 'getting_started',
    question_en TEXT NOT NULL,
    question_bisaya TEXT NULL,
    question_tagalog TEXT NULL,
    answer_en TEXT NOT NULL,
    answer_bisaya TEXT NULL,
    answer_tagalog TEXT NULL,
    keywords TEXT NULL,
    aliases TEXT NULL,
    visibility ENUM('public', 'authenticated', 'admin_only') NOT NULL DEFAULT 'public',
    module_key VARCHAR(64) NOT NULL DEFAULT 'global',
    role_scope VARCHAR(64) NOT NULL DEFAULT 'all',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 3. Feedback Table
$conn->query("
CREATE TABLE IF NOT EXISTS help_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faq_id INT NULL,
    user_id INT NULL,
    session_id VARCHAR(128) NULL,
    helpful TINYINT(1) NOT NULL,
    query_text TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 4. Unmatched Queries Table
$conn->query("
CREATE TABLE IF NOT EXISTS help_unmatched_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query_text VARCHAR(255) NOT NULL UNIQUE,
    normalized_query TEXT NOT NULL,
    language VARCHAR(10) DEFAULT 'en',
    page_context VARCHAR(64) DEFAULT 'global',
    user_id INT NULL,
    count INT DEFAULT 1,
    status ENUM('pending', 'converted', 'ignored') DEFAULT 'pending',
    last_asked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 5. Query Logs Table
$conn->query("
CREATE TABLE IF NOT EXISTS help_query_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    query_text TEXT NOT NULL,
    matched_faq_id INT NULL,
    confidence FLOAT DEFAULT 0,
    language VARCHAR(10) DEFAULT 'en',
    page_context VARCHAR(64) DEFAULT 'global',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Seed Categories
$categories = [
    ['key_name' => 'getting_started', 'title_en' => 'Getting Started', 'title_bisaya' => 'Pagsugod', 'title_tagalog' => 'Panimula', 'icon' => 'bi-rocket-takeoff', 'sort_order' => 1],
    ['key_name' => 'submitting_requests', 'title_en' => 'Submitting Requests', 'title_bisaya' => 'Pagpasa ug Request', 'title_tagalog' => 'Pagsusumite ng Request', 'icon' => 'bi-file-earmark-plus', 'sort_order' => 2],
    ['key_name' => 'tracking', 'title_en' => 'Tracking & Status', 'title_bisaya' => 'Pagsubay sa Status', 'title_tagalog' => 'Pagsubaybay sa Status', 'icon' => 'bi-search', 'sort_order' => 3],
    ['key_name' => 'status_workflow', 'title_en' => 'Status Workflow', 'title_bisaya' => 'Proseso sa Status', 'title_tagalog' => 'Daloy ng Status', 'icon' => 'bi-diagram-3', 'sort_order' => 4],
    ['key_name' => 'revisions_attachments', 'title_en' => 'Revisions & Attachments', 'title_bisaya' => 'Pagbag-o ug Attachments', 'title_tagalog' => 'Mga Pagbabago at Attachment', 'icon' => 'bi-paperclip', 'sort_order' => 5],
    ['key_name' => 'kanban_board', 'title_en' => 'Kanban & Calendar', 'title_bisaya' => 'Kanban ug Kalendaryo', 'title_tagalog' => 'Kanban at Kalendaryo', 'icon' => 'bi-kanban', 'sort_order' => 6],
    ['key_name' => 'account_access', 'title_en' => 'Account & Login', 'title_bisaya' => 'Akaunt ug Pag-login', 'title_tagalog' => 'Account at Login', 'icon' => 'bi-person-badge', 'sort_order' => 7],
    ['key_name' => 'technical_support', 'title_en' => 'Technical Support', 'title_bisaya' => 'Teknikal nga Suporta', 'title_tagalog' => 'Teknikal na Suporta', 'icon' => 'bi-headset', 'sort_order' => 8],
];

$catStmt = $conn->prepare("
    INSERT INTO help_categories (key_name, title_en, title_bisaya, title_tagalog, icon, sort_order)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        title_en = VALUES(title_en),
        title_bisaya = VALUES(title_bisaya),
        title_tagalog = VALUES(title_tagalog),
        icon = VALUES(icon),
        sort_order = VALUES(sort_order);
");

foreach ($categories as $cat) {
    $catStmt->bind_param('sssssi', $cat['key_name'], $cat['title_en'], $cat['title_bisaya'], $cat['title_tagalog'], $cat['icon'], $cat['sort_order']);
    $catStmt->execute();
}
$catStmt->close();

// Comprehensive Seed FAQs
$faqsSeed = [
    // --- GETTING STARTED ---
    [
        'category_key' => 'getting_started',
        'question_en' => 'What is the Job Request System?',
        'question_bisaya' => 'Unsa man ang Job Request System?',
        'question_tagalog' => 'Ano ang Job Request System?',
        'answer_en' => 'The Job Request System is an online platform used by ASTMMC staff and departments to submit, review, approve, monitor, and manage requests for Health Promotion Unit (HPU) services, graphic designs, videos, photo coverage, and IEC materials.',
        'answer_bisaya' => 'Ang Job Request System usa ka online nga plataporma nga gigamit sa mga empleyado ug departamento sa ASTMMC aron magpasa, mag-review, mag-aprobar, ug magsubay sa mga request para sa Health Promotion Unit (HPU) graphics, bideo, photo coverage, ug IEC materials.',
        'answer_tagalog' => 'Ang Job Request System ay isang online platform na ginagamit ng mga kawani at departamento ng ASTMMC upang magsumite, mag-review, mag-aprubahan, at magsubaybay ng mga kahilingan para sa Health Promotion Unit (HPU) graphics, video, photo coverage, at IEC materials.',
        'keywords' => json_encode(['system', 'overview', 'what is', 'about', 'hpu', 'workbench', 'platform', 'purpose', 'astmmc']),
        'aliases' => json_encode(['unsa kini', 'ano ito', 'hpu info']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 1
    ],
    [
        'category_key' => 'getting_started',
        'question_en' => 'Who can submit job requests?',
        'question_bisaya' => 'Kinsa ang makapasa og job requests?',
        'question_tagalog' => 'Sino ang pwedeng mag-submit ng job requests?',
        'answer_en' => 'All authorized personnel, medical staff, section heads, and hospital department representatives registered in the ASTMMC system can submit job requests to HPU.',
        'answer_bisaya' => 'Tanan nga rehistradong empleyado, medical staff, ug representante sa departamento sa ASTMMC kay makapasa og job request sa HPU.',
        'answer_tagalog' => 'Lahat ng nakarehistrong kawani, medical staff, at kinatawan ng departamento sa ASTMMC ay pwedeng magsumite ng job request sa HPU.',
        'keywords' => json_encode(['who can', 'roles', 'authorized', 'staff', 'departments', 'users', 'access', 'kinsa', 'sino']),
        'aliases' => json_encode(['kinsa maka-request', 'sino pwedeng mag-request']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 2
    ],
    [
        'category_key' => 'getting_started',
        'question_en' => 'What services does the Health Promotion Unit (HPU) provide?',
        'question_bisaya' => 'Unsa nga mga serbisyo ang gihatag sa Health Promotion Unit (HPU)?',
        'question_tagalog' => 'Anong mga serbisyo ang ibinibigay ng Health Promotion Unit (HPU)?',
        'answer_en' => 'HPU provides Graphic Design (tarpaulins, posters, certificates, pubmats), Video Production (AVPs, teasers, instructional videos), Photo/Video Coverage for hospital events, IEC Materials (flyers, brochures), and Ward Health Education support.',
        'answer_bisaya' => 'Ang HPU naghatag og Graphic Design (tarpaulin, poster, certificate, pubmat), Video Production (AVP, teaser), Photo/Video Coverage sa mga okasyon, IEC Materials (flyers, brochure), ug Ward Health Education.',
        'answer_tagalog' => 'Ang HPU ay nagbibigay ng Graphic Design (tarpaulin, poster, certificate, pubmat), Video Production (AVP, teaser), Photo/Video Coverage para sa mga kaganapan, IEC Materials (flyers, brochure), at Ward Health Education.',
        'keywords' => json_encode(['services', 'hpu services', 'graphic design', 'video production', 'coverage', 'iec materials', 'tarpaulin', 'poster', 'avp', 'serbisyo']),
        'aliases' => json_encode(['unsa nga serbisyo', 'anong serbisyo', 'services offered']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 3
    ],

    // --- SUBMITTING REQUESTS ---
    [
        'category_key' => 'submitting_requests',
        'question_en' => 'How do I submit a job request?',
        'question_bisaya' => 'Unsaon nako pagpasa ug job request?',
        'question_tagalog' => 'Paano ako magsusumite ng job request?',
        'answer_en' => 'After logging in, go to "Job Requests" or "My Requests", click "+ New Request", select your request type (Graphic Design, Video, Coverage, IEC), fill in project details, event date, objective, attach sample files, and submit.',
        'answer_bisaya' => 'Pagkahuman og log in, adto sa "Job Requests" o "My Requests", i-click ang "+ New Request", pilia ang klase sa request, sulati ang project title, petsa, objective, ug mag-attach og sample files bago mag-submit.',
        'answer_tagalog' => 'Pagkatapos mag-login, pumunta sa "Job Requests" o "My Requests", i-click ang "+ New Request", piliin ang uri ng request, punan ang project title, petsa, layunin, mag-attach ng halimbawang file, at i-submit.',
        'keywords' => json_encode(['submit', 'create', 'new request', 'file', 'how to', 'make', 'send', 'unsaon', 'paano', 'pasa']),
        'aliases' => json_encode(['unsaon pag-file', 'magpasa og request', 'mag-file ng request', 'create job']),
        'visibility' => 'public',
        'module_key' => 'new_request',
        'role_scope' => 'all',
        'sort_order' => 4
    ],
    [
        'category_key' => 'submitting_requests',
        'question_en' => 'How far in advance should I submit an event coverage request?',
        'question_bisaya' => 'Pila ka adlaw sa dili pa ang okasyon kinahanglan mag-request og coverage?',
        'question_tagalog' => 'Ilang araw bago ang event dapat mag-submit ng coverage request?',
        'answer_en' => 'It is recommended to submit photo/video coverage requests at least 3 to 5 working days prior to the event date to ensure staff availability and equipment reservation.',
        'answer_bisaya' => 'Girekomendar nga magpasa og coverage request labing menos 3 hangtod 5 ka adlaw sa dili pa ang okasyon aron masiguro nga naay staff ug gamit.',
        'answer_tagalog' => 'Inirerekomendang magsumite ng coverage request kamakailan 3 hanggang 5 araw bago ang event upang matiyak na may nakalaang staff at kagamitan.',
        'keywords' => json_encode(['advance', 'days', 'event coverage', 'lead time', 'deadline', 'when to submit', 'pila ka adlaw', 'ilang araw']),
        'aliases' => json_encode(['advance request', 'how early', 'coverage lead time']),
        'visibility' => 'public',
        'module_key' => 'new_request',
        'role_scope' => 'all',
        'sort_order' => 5
    ],

    // --- TRACKING & STATUS ---
    [
        'category_key' => 'tracking',
        'question_en' => 'How can I track my request status?',
        'question_bisaya' => 'Asa ug unsaon nako pagtan-aw ang status sa akong request?',
        'question_tagalog' => 'Saan at paano ko makikita ang status ng aking request?',
        'answer_en' => 'Sign in to your account and open "My Requests". Each request displays real-time approval status badges (Pending Review, Under Review, Approved, In Development, For Revision, Completed). Click a request to view detailed remarks and progress.',
        'answer_bisaya' => 'I-log in ang imong account ug adto sa "My Requests". Makita nimo diha ang status sa matag request (Pending Review, Under Review, Approved, In Development, For Revision, Completed). I-click lang ang request para sa dugang mga detalye.',
        'answer_tagalog' => 'Mag-login sa iyong account at pumunta sa "My Requests". Makikita mo roon ang real-time status (Pending Review, Under Review, Approved, In Development, For Revision, Completed). I-click ang request upang makita ang mga detalye at remarks.',
        'keywords' => json_encode(['track', 'status', 'check', 'monitor', 'where is', 'progress', 'view', 'find', 'asa', 'saan', 'tanawon']),
        'aliases' => json_encode(['asa nako makita', 'saan makikita', 'status tracker', 'check progress']),
        'visibility' => 'public',
        'module_key' => 'my_requests',
        'role_scope' => 'all',
        'sort_order' => 6
    ],
    [
        'category_key' => 'tracking',
        'question_en' => 'How will I know when my request is completed?',
        'question_bisaya' => 'Unsaon nako pagkahibalo nga humana ang akong request?',
        'question_tagalog' => 'Paano ko malalaman kung tapos na ang aking request?',
        'answer_en' => 'When your request status changes to "Completed", you will receive an in-app notification and email. You can download the completed files directly from "My Requests".',
        'answer_bisaya' => 'Kung mag-Completed na ang status, makadawat ka og in-app notification ug email. Pwede na nimo ma-download ang naproseso nga files sa "My Requests".',
        'answer_tagalog' => 'Kapag naging "Completed" na ang status, makakatanggap ka ng in-app notification at email. Maaari mo nang i-download ang natapos na file sa "My Requests".',
        'keywords' => json_encode(['completed', 'finished', 'done', 'download', 'ready', 'notification', 'humana', 'tapos na']),
        'aliases' => json_encode(['unsaon pag-download', 'how to download finished file']),
        'visibility' => 'authenticated',
        'module_key' => 'my_requests',
        'role_scope' => 'all',
        'sort_order' => 7
    ],

    // --- STATUS WORKFLOW ---
    [
        'category_key' => 'status_workflow',
        'question_en' => 'What does Pending Review mean?',
        'question_bisaya' => 'Unsa ang pasabot sa Pending Review?',
        'question_tagalog' => 'Ano ang ibig sabihin ng Pending Review?',
        'answer_en' => '"Pending Review" means your request was successfully received by the system and is waiting for initial evaluation by the HPU Supervisor or Administrator.',
        'answer_bisaya' => 'Ang "Pending Review" nagpasabot nga nadawat na sa sistema ang imong request ug naghulat na kini sa evaluation sa HPU Supervisor o Administrator.',
        'answer_tagalog' => 'Ang "Pending Review" ay nangangahulugang matagumpay na natanggap ng sistema ang iyong request at naghihintay ng pagsusuri ng HPU Supervisor o Administrator.',
        'keywords' => json_encode(['pending review', 'pending', 'review', 'meaning', 'status', 'pasabot', 'sabihin']),
        'aliases' => json_encode(['unsa ang pending', 'ano ang pending', 'pending meaning']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 8
    ],
    [
        'category_key' => 'status_workflow',
        'question_en' => 'What does Under Review mean?',
        'question_bisaya' => 'Unsa ang pasabot sa Under Review?',
        'question_tagalog' => 'Ano ang ibig sabihin ng Under Review?',
        'answer_en' => '"Under Review" means the HPU Supervisor is currently assessing your request requirements, checking staff availability, and determining project feasibility.',
        'answer_bisaya' => 'Ang "Under Review" nagpasabot nga gitan-aw ug gisusi karon sa HPU Supervisor ang mga detalye ug kinahanglanon sa imong request.',
        'answer_tagalog' => 'Ang "Under Review" ay nangangahulugang kasalukuyang sinusuri ng HPU Supervisor ang mga detalye at pangangailangan ng iyong request.',
        'keywords' => json_encode(['under review', 'assessing', 'evaluation', 'status meaning']),
        'aliases' => json_encode(['under review meaning', 'unsa ang under review']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 9
    ],
    [
        'category_key' => 'status_workflow',
        'question_en' => 'What does In Development mean?',
        'question_bisaya' => 'Unsa ang pasabot sa In Development?',
        'question_tagalog' => 'Ano ang ibig sabihin ng In Development?',
        'answer_en' => '"In Development" means your request has been approved by the supervisor and assigned HPU layout artists or videographers are actively creating your requested output.',
        'answer_bisaya' => 'Ang "In Development" nagpasabot nga na-aprubahan na ang imong request ug gihimo na kini karon sa gi-assign nga HPU staff.',
        'answer_tagalog' => 'Ang "In Development" ay nangangahulugang na-aprubahan na ang iyong request at kasalukuyan nang ginagawa ng nakatalagang HPU staff.',
        'keywords' => json_encode(['in development', 'development', 'working', 'assigned', 'in progress', 'status']),
        'aliases' => json_encode(['unsa ang in development', 'ano ang in development', 'working status']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 10
    ],
    [
        'category_key' => 'status_workflow',
        'question_en' => 'What does Disapproved mean?',
        'question_bisaya' => 'Unsa ang pasabot sa Disapproved?',
        'question_tagalog' => 'Ano ang ibig sabihin ng Disapproved?',
        'answer_en' => '"Disapproved" means the request could not be processed due to schedule conflict, incomplete requirements, or non-compliance with institutional policies. Reason is provided in the remarks.',
        'answer_bisaya' => 'Ang "Disapproved" nagpasabot nga wala nadawat ang request tungod sa conflict sa schedule o kulang nga impormasyon. Makita ang rason sa remarks.',
        'answer_tagalog' => 'Ang "Disapproved" ay nangangahulugang hindi na-aprubahan ang request dahil sa conflict sa schedule o kulang na detalye. Makikita ang dahilan sa remarks.',
        'keywords' => json_encode(['disapproved', 'rejected', 'declined', 'status meaning', 'reason']),
        'aliases' => json_encode(['disapproved help', 'rason sa disapproved']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 11
    ],
    [
        'category_key' => 'status_workflow',
        'question_en' => 'Who approves job requests?',
        'question_bisaya' => 'Kinsa ang mag-aprobar sa mga job request?',
        'question_tagalog' => 'Sino ang nag-aapruba ng mga job request?',
        'answer_en' => 'Submitted job requests are evaluated and approved by designated HPU Supervisors, Unit Heads, or System Administrators according to hospital guidelines.',
        'answer_bisaya' => 'Ang mga gi-submit nga request kay susihon ug aprobaran sa HPU Supervisor, Unit Head, o Administrator.',
        'answer_tagalog' => 'Ang mga naisumiteng request ay sinusuri at inaaprubahan ng HPU Supervisor, Unit Head, o Administrator.',
        'keywords' => json_encode(['approves', 'approval', 'who approves', 'supervisor', 'admin', 'sign off', 'kinsa', 'sino']),
        'aliases' => json_encode(['kinsa mag-approve', 'sino mag-aapruba', 'approval process']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 12
    ],

    // --- REVISIONS & ATTACHMENTS ---
    [
        'category_key' => 'revisions_attachments',
        'question_en' => 'What should I do if my request is For Revision?',
        'question_bisaya' => 'Unsa akong buhaton kung For Revision ang akong request?',
        'question_tagalog' => 'Ano ang dapat kong gawin kung For Revision ang aking request?',
        'answer_en' => 'Open the request in "My Requests" and check the supervisor remarks. Click "Edit Request", update the missing details or upload requested reference files, then resubmit for re-evaluation.',
        'answer_bisaya' => 'Ablihi ang request sa "My Requests" ug tan-awa ang remarks sa supervisor. I-click ang "Edit Request", i-update ang kulang nga impormasyon o hulagway, ug i-resubmit kini.',
        'answer_tagalog' => 'Buksan ang request sa "My Requests" at basahin ang remarks ng supervisor. I-click ang "Edit Request", i-update ang kulang na impormasyon o files, at i-resubmit para sa muling pagsusuri.',
        'keywords' => json_encode(['for revision', 'revision', 'revise', 'change', 'correction', 'edit', 'resubmit', 'remarks', 'usab', 'bag-o']),
        'aliases' => json_encode(['unsaon pag resubmit', 'paano mag resubmit', 'for revision help']),
        'visibility' => 'public',
        'module_key' => 'my_requests',
        'role_scope' => 'all',
        'sort_order' => 13
    ],
    [
        'category_key' => 'revisions_attachments',
        'question_en' => 'Can I attach reference files or images?',
        'question_bisaya' => 'Pwede ba ko maka-attach og mga picture o dokumento?',
        'question_tagalog' => 'Maaari ba akong mag-attach ng mga larawan o dokumento?',
        'answer_en' => 'Yes! You can attach logos, draft text, guidelines, reference photos, and sample layout designs in JPG, PNG, GIF, WEBP, PDF, DOCX, or ZIP format up to 10 MB per file.',
        'answer_bisaya' => 'Oo! Makapadala ka og logos, draft text, sample pictures, ug uban pang file (JPG, PNG, PDF, DOCX, ZIP) hangtod 10 MB matag file.',
        'answer_tagalog' => 'Oo! Maaari kang mag-attach ng logo, draft text, sample pictures, at iba pang file (JPG, PNG, PDF, DOCX, ZIP) hanggang 10 MB bawat file.',
        'keywords' => json_encode(['attach', 'files', 'upload', 'photo', 'document', 'logo', 'reference', 'attachment', 'image', 'picture', 'pdf']),
        'aliases' => json_encode(['pwede mag-upload', 'pwede ba upload logo', 'file size limit']),
        'visibility' => 'public',
        'module_key' => 'new_request',
        'role_scope' => 'all',
        'sort_order' => 14
    ],

    // --- KANBAN & CALENDAR ---
    [
        'category_key' => 'kanban_board',
        'question_en' => 'What is the Kanban Board and how do I use it?',
        'question_bisaya' => 'Unsa ang Kanban Board ug unsaon paggamit niini?',
        'question_tagalog' => 'Ano ang Kanban Board at paano ito gamitin?',
        'answer_en' => 'The Kanban Board provides a visual card workflow organized into columns (Pending, Under Review, Approved, In Development, For Revision, Completed). Authorized HPU staff can drag & drop cards to update request progress.',
        'answer_bisaya' => 'Ang Kanban Board nagpakita sa mga job request forms sa visual nga kolum (Pending, Under Review, Approved, In Development, Completed). Ang HPU staff makahimo sa pag-drag & drop sa cards.',
        'answer_tagalog' => 'Ang Kanban Board ay nagpapakita ng mga job request sa visual na kolum (Pending, Under Review, Approved, In Development, Completed). Ang HPU staff ay maaaring mag-drag & drop ng mga card.',
        'keywords' => json_encode(['kanban', 'board', 'columns', 'drag and drop', 'cards', 'workflow', 'visual']),
        'aliases' => json_encode(['kanban help', 'unsa ang kanban', 'ano ang kanban']),
        'visibility' => 'authenticated',
        'module_key' => 'kanban',
        'role_scope' => 'all',
        'sort_order' => 15
    ],

    // --- ACCOUNT & LOGIN ---
    [
        'category_key' => 'account_access',
        'question_en' => 'What if I forgot my password or cannot log in?',
        'question_bisaya' => 'Unsaon kung nakalimot ko sa password o dili makalogin?',
        'question_tagalog' => 'Paano kung nakalimutan ko ang password o hindi makapag-login?',
        'answer_en' => 'Click "Need Account Help?" on the login page to send an official password reset or account recovery inquiry directly to the System Administrator.',
        'answer_bisaya' => 'I-click ang "Need Account Help?" sa login page aron magpadala og password reset request sa Administrator.',
        'answer_tagalog' => 'I-click ang "Need Account Help?" sa login page upang magpadala ng hiling sa Administrator para sa password reset.',
        'keywords' => json_encode(['forgot password', 'reset password', 'login help', 'cannot login', 'locked out', 'inactive', 'signin', 'nakalimtan', 'nakalimutan']),
        'aliases' => json_encode(['nakalimot sa password', 'forgot pass', 'account recovery']),
        'visibility' => 'public',
        'module_key' => 'login',
        'role_scope' => 'all',
        'sort_order' => 16
    ],
    [
        'category_key' => 'account_access',
        'question_en' => 'How do I request a new user account?',
        'question_bisaya' => 'Unsaon nako pag-request og bag-ong user account?',
        'question_tagalog' => 'Paano mag-request ng bagong user account?',
        'answer_en' => 'Click "Request Account" on the login page, fill out your full name, official email address, department, and desired role, then submit for administrator verification and approval.',
        'answer_bisaya' => 'I-click ang "Request Account" sa login page, sulati ang imong ngalan, email, ug departamento, dayon i-submit para sa pag-aprobar sa admin.',
        'answer_tagalog' => 'I-click ang "Request Account" sa login page, punan ang pangalan, email, at departamento, at i-submit para sa pag-aprubah ng admin.',
        'keywords' => json_encode(['request account', 'register', 'sign up', 'create account', 'new user', 'access']),
        'aliases' => json_encode(['mag-make og account', 'new user sign up']),
        'visibility' => 'public',
        'module_key' => 'login',
        'role_scope' => 'all',
        'sort_order' => 17
    ],

    // --- TECHNICAL SUPPORT ---
    [
        'category_key' => 'technical_support',
        'question_en' => 'What should I do if attachment upload fails?',
        'question_bisaya' => 'Unsa akong buhaton kung mag-error ang pag-upload sa file?',
        'question_tagalog' => 'Ano ang gagawin kung mag-error ang pag-upload ng file?',
        'answer_en' => 'Ensure your file size is under 10 MB and in an allowed format (JPG, PNG, GIF, WEBP, PDF, DOCX, ZIP). If it still fails, try compressing the file or clear your browser cache.',
        'answer_bisaya' => 'Siguroha nga ubos sa 10 MB ang file ug husto ang format (JPG, PNG, PDF, DOCX, ZIP). Kung mag-error gihapon, i-compress ang file o i-clear ang browser cache.',
        'answer_tagalog' => 'Tiyaking mas mababa sa 10 MB ang file at tama ang format (JPG, PNG, PDF, DOCX, ZIP). Kung mag-error pa rin, i-compress ang file o i-clear ang browser cache.',
        'keywords' => json_encode(['attachment upload failed', 'upload error', 'file size error', 'failed', 'cannot upload']),
        'aliases' => json_encode(['dili maka upload', 'hindi maka-upload']),
        'visibility' => 'public',
        'module_key' => 'global',
        'role_scope' => 'all',
        'sort_order' => 18
    ]
];

$faqStmt = $conn->prepare("
    INSERT INTO help_faqs 
    (category_key, question_en, question_bisaya, question_tagalog, answer_en, answer_bisaya, answer_tagalog, keywords, aliases, visibility, module_key, role_scope, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        question_bisaya = VALUES(question_bisaya),
        question_tagalog = VALUES(question_tagalog),
        answer_en = VALUES(answer_en),
        answer_bisaya = VALUES(answer_bisaya),
        answer_tagalog = VALUES(answer_tagalog),
        keywords = VALUES(keywords),
        aliases = VALUES(aliases);
");

foreach ($faqsSeed as $faq) {
    $faqStmt->bind_param(
        'ssssssssssssi',
        $faq['category_key'],
        $faq['question_en'],
        $faq['question_bisaya'],
        $faq['question_tagalog'],
        $faq['answer_en'],
        $faq['answer_bisaya'],
        $faq['answer_tagalog'],
        $faq['keywords'],
        $faq['aliases'],
        $faq['visibility'],
        $faq['module_key'],
        $faq['role_scope'],
        $faq['sort_order']
    );
    $faqStmt->execute();
}
$faqStmt->close();

echo "Comprehensive Multilingual FAQs seeded successfully.\n";
echo "Migration Complete.\n";
