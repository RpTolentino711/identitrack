<?php
$code = file_get_contents(__DIR__ . '/../api/student/community_service_overview.php');

// Remove strict types
$code = str_replace("declare(strict_types=1);", "", $code);

// Fix absolute path for require_once
$code = str_replace(
  "require_once __DIR__ . '/../../database/database.php';",
  "require_once '" . addslashes(realpath(__DIR__ . '/../database/database.php')) . "';",
  $code
);

// Replace php://input read with mocked JSON
$code = str_replace(
  "\$raw = file_get_contents('php://input') ?: '';",
  "\$raw = '{\"student_id\":\"2023-183482\"}';",
  $code
);

// We also need to bypass require_student_api_auth
// Let's replace require_student_api_auth($studentId); with a no-op or mock
$code = str_replace(
  "require_student_api_auth(\$studentId);",
  "// bypassed auth",
  $code
);

// Evaluate the modified code
$_SERVER['REQUEST_METHOD'] = 'POST';
eval('?>' . $code);
echo "\n";
