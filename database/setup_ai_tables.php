<?php
/**
 * IdentiTrack AI Database Tables Setup Script
 * Creates handbook_rule and ai_analysis_log tables if missing.
 */
require_once __DIR__ . '/database.php';

try {
    // 1. Create handbook_rule table
    db_exec("
        CREATE TABLE IF NOT EXISTS handbook_rule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section VARCHAR(100) NOT NULL,
            rule_code VARCHAR(50) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            offense_type VARCHAR(100) NOT NULL,
            severity ENUM('MINOR', 'MAJOR', 'CRITICAL') DEFAULT 'MINOR',
            intervention_category INT DEFAULT 1,
            keywords TEXT DEFAULT NULL,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. Create ai_analysis_log table for audit logging & human-in-the-loop tracking
    db_exec("
        CREATE TABLE IF NOT EXISTS ai_analysis_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(64) NOT NULL UNIQUE,
            offense_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            model VARCHAR(100) NOT NULL,
            classification VARCHAR(100) NOT NULL,
            confidence FLOAT DEFAULT 0.0,
            handbook_rule_id INT DEFAULT NULL,
            recommendation TEXT DEFAULT NULL,
            human_decision ENUM('PENDING', 'ACCEPTED', 'REJECTED', 'MODIFIED') DEFAULT 'PENDING',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_request (request_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 3. Seed initial Student Handbook Rules if table is empty
    $ruleCount = db_one("SELECT COUNT(*) as cnt FROM handbook_rule");
    if ((int)($ruleCount['cnt'] ?? 0) === 0) {
        $seedRules = [
            [
                'section' => 'Section IV',
                'rule_code' => 'SEC4-UNIFORM',
                'title' => 'Uniform & Grooming Non-Compliance',
                'description' => 'Failure to wear the prescribed school uniform, unauthorized hair color/dye, or improper civilian attire on campus.',
                'offense_type' => 'Grooming / Dress Code',
                'severity' => 'MINOR',
                'intervention_category' => 1,
                'keywords' => 'uniform,dress code,hair dye,hair color,attire,grooming,civilian'
            ],
            [
                'section' => 'Section IV',
                'rule_code' => 'SEC4-ID-TAG',
                'title' => 'ID Card Non-Wearing / Lending',
                'description' => 'Failure to wear valid student ID inside campus premises or lending ID card to another individual.',
                'offense_type' => 'Identification Violation',
                'severity' => 'MINOR',
                'intervention_category' => 1,
                'keywords' => 'id,card,lending,wearing id,no id,identification'
            ],
            [
                'section' => 'Section V',
                'rule_code' => 'SEC5-CHEATING',
                'title' => 'Academic Dishonesty / Examination Cheating',
                'description' => 'Using unauthorized materials, mobile devices, cheat sheets, or communicating with others during examinations or major quizzes.',
                'offense_type' => 'Academic Integrity',
                'severity' => 'MAJOR',
                'intervention_category' => 2,
                'keywords' => 'cheat,cheating,phone exam,exam,quiz,dishonesty,leak,crib sheet'
            ],
            [
                'section' => 'Section V',
                'rule_code' => 'SEC5-ALCOHOL-DRUGS',
                'title' => 'Possession / Consumption of Drugs or Alcohol',
                'description' => 'Bringing, consuming, or distributing illegal drugs, alcoholic beverages, e-cigarettes, or tobacco inside university premises.',
                'offense_type' => 'Substance & Campus Safety',
                'severity' => 'CRITICAL',
                'intervention_category' => 3,
                'keywords' => 'alcohol,liquor,drugs,vape,vaping,smoke,smoking,substance,marijuana,beer'
            ],
            [
                'section' => 'Section V',
                'rule_code' => 'SEC5-PERJURY',
                'title' => 'Falsification / Lying During Administrative Investigation',
                'description' => 'Submitting false evidence, forged documents, or making deliberately deceptive statements during a UPCC hearing.',
                'offense_type' => 'Administrative Deceit',
                'severity' => 'MAJOR',
                'intervention_category' => 2,
                'keywords' => 'lie,lying,false statement,forgery,fake,deceit,untruth,perjury'
            ]
        ];

        foreach ($seedRules as $r) {
            db_exec("
                INSERT INTO handbook_rule (section, rule_code, title, description, offense_type, severity, intervention_category, keywords)
                VALUES (:sec, :code, :title, :desc, :type, :sev, :cat, :kw)
            ", [
                ':sec' => $r['section'],
                ':code' => $r['rule_code'],
                ':title' => $r['title'],
                ':desc' => $r['description'],
                ':type' => $r['offense_type'],
                ':sev' => $r['severity'],
                ':cat' => $r['intervention_category'],
                ':kw' => $r['keywords']
            ]);
        }
    }

    echo "✅ AI Tables (`handbook_rule`, `ai_analysis_log`) created and seeded successfully!\n";
} catch (\Throwable $e) {
    echo "❌ Error setting up AI tables: " . $e->getMessage() . "\n";
}
