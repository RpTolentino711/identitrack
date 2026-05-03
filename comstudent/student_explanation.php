<?php
require_once __DIR__ . '/../database/database.php';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $caseId = (int)($_POST['case_id'] ?? 0);
    $text = trim((string)($_POST['explanation_text'] ?? ''));

    if ($studentId === '' || $caseId <= 0) {
        $error = "Student ID and Case ID are required.";
    } else {
        // Simple mock of the API call for testing
        $_POST['student_id'] = $studentId;
        $_POST['case_id'] = $caseId;
        $_POST['explanation_text'] = $text;
        
        require_once __DIR__ . '/../api/student/submit_explanation.php';
        // The API script will exit, so we won't reach here if it succeeds.
    }
}

// If we are here, it's either GET or an error happened before require
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Explanation | IDENTITRACK</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0d1f5c; --blue: #1a3fa0; --accent: #2563eb; --sky: #e8eeff;
            --white: #ffffff; --muted: #6b7a9e; --error: #dc2626; --success: #16a34a;
            --radius: 16px; --shadow: 0 24px 70px rgba(13,31,92,.28);
        }
        body {
            font-family: 'DM Sans', sans-serif; background: #f0f2f5; color: var(--navy);
            display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;
        }
        .card {
            background: #fff; padding: 32px; border-radius: 24px; box-shadow: var(--shadow);
            width: 100%; max-width: 500px;
        }
        h1 { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 24px; margin-bottom: 8px; }
        p { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
        .field { margin-bottom: 20px; }
        label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
        input, textarea {
            width: 100%; padding: 12px 16px; border-radius: 12px; border: 1.5px solid #dde2ef;
            font-size: 15px; outline: none; transition: 0.2s;
        }
        input:focus, textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .btn {
            width: 100%; padding: 14px; border-radius: 12px; border: none;
            background: linear-gradient(135deg, var(--navy), var(--accent));
            color: #fff; font-family: 'Syne', sans-serif; font-weight: 800; font-size: 16px; cursor: pointer;
        }
        .alert { padding: 12px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
        .alert-error { background: #fef2f2; color: var(--error); border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: var(--success); border: 1px solid #bbf7d0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Submit Explanation</h1>
        <p>Provide your statement and supporting documents for your pending UPCC case.</p>
        
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        
        <form action="../api/student/submit_explanation.php" method="POST" enctype="multipart/form-data">
            <div class="field">
                <label>Student ID</label>
                <input type="text" name="student_id" placeholder="e.g. 2024-00001" required>
            </div>
            <div class="field">
                <label>Case ID</label>
                <input type="number" name="case_id" placeholder="e.g. 1" required>
            </div>
            <div class="field">
                <label>Statement (Text)</label>
                <textarea name="explanation_text" placeholder="Write your explanation here..." rows="4"></textarea>
            </div>
            <div class="field">
                <label>Supporting Image (JPG/PNG)</label>
                <input type="file" name="explanation_image" accept="image/*">
            </div>
            <div class="field">
                <label>Supporting Document (PDF)</label>
                <input type="file" name="explanation_pdf" accept="application/pdf">
            </div>
            <button type="submit" class="btn">Submit Explanation</button>
        </form>
    </div>
</body>
</html>
