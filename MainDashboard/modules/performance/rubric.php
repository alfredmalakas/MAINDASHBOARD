<?php
// Standard 1-5 employee performance evaluation rubric.
// 1 = Needs Significant Improvement, 5 = Exceptional
$performanceRubric = [
    'A. JOB KNOWLEDGE AND COMPETENCE' => [
        'Demonstrates adequate knowledge of assigned duties and responsibilities.',
        'Applies appropriate knowledge and skills when performing tasks.',
        'Understands organizational policies, procedures, and standards.',
        'Keeps updated with relevant knowledge, tools, and technologies.',
        'Solves work-related problems effectively and independently.'
    ],
    'B. QUALITY AND PRODUCTIVITY' => [
        'Produces accurate and high-quality work.',
        'Completes assigned tasks within established deadlines.',
        'Maintains consistent productivity and work performance.',
        'Pays attention to details and minimizes errors.',
        'Uses time, equipment, and organizational resources efficiently.'
    ],
    'C. INITIATIVE AND PROBLEM-SOLVING' => [
        'Takes initiative in completing assigned responsibilities.',
        'Identifies problems and recommends appropriate solutions.',
        'Can work independently with minimal supervision.',
        'Shows willingness to learn new skills and responsibilities.',
        'Adapts effectively to changes in procedures or work requirements.'
    ],
    'D. ATTENDANCE AND PUNCTUALITY' => [
        'Reports to work on time and follows assigned schedules.',
        'Maintains regular and dependable attendance.',
        'Properly informs the supervisor regarding absences or tardiness.',
        'Observes approved leave and attendance procedures.'
    ],
    'E. COMMUNICATION SKILLS' => [
        'Communicates information clearly and professionally.',
        'Listens attentively and responds appropriately.',
        'Maintains professional written communication.',
        'Communicates effectively with supervisors, coworkers, and clients.'
    ],
    'F. TEAMWORK AND INTERPERSONAL RELATIONS' => [
        'Works cooperatively with team members.',
        'Maintains positive and respectful relationships with coworkers.',
        'Demonstrates patience and professionalism when dealing with others.',
        'Supports colleagues when assistance is needed.',
        'Accepts constructive feedback positively.'
    ],
    'G. PROFESSIONALISM AND WORK ATTITUDE' => [
        'Demonstrates a positive attitude toward work.',
        'Observes organizational rules, policies, and procedures.',
        'Demonstrates honesty, integrity, and accountability.',
        'Maintains appropriate professional appearance and behavior.',
        'Shows commitment and responsibility toward assigned duties.'
    ],
    'H. CUSTOMER SERVICE AND RESPONSIVENESS' => [
        'Responds promptly to requests and concerns.',
        'Provides courteous and professional service.',
        'Understands and responds to customer/client needs.',
        'Handles complaints and difficult situations professionally.'
    ],
    'I. LEADERSHIP AND ACCOUNTABILITY' => [
        'Takes responsibility for assigned tasks and decisions.',
        'Demonstrates leadership when appropriate.',
        'Provides guidance and support to team members when needed.',
        'Meets commitments and takes ownership of results.'
    ],
];

function performanceRatingFromScore($score) {
    if ($score >= 4.50) return 'Excellent';
    if ($score >= 3.75) return 'Very Good';
    if ($score >= 3.00) return 'Good';
    if ($score >= 2.00) return 'Fair';
    return 'Needs Improvement';
}
