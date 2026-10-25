<?php
// Quick smoke test for document_requests table and model
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admin\Admin;
use App\Models\Admin\Teacher;
use App\Models\Admin\DocumentRequest;
use App\Models\Notification;

echo "=== Database Checks ===" . PHP_EOL;
echo "Admins total: " . Admin::count() . PHP_EOL;
echo "Teachers active: " . Teacher::where('status', 'active')->count() . PHP_EOL;
echo "document_requests table exists: " . (DocumentRequest::count() >= 0 ? 'YES' : 'NO') . PHP_EOL;
echo "DocumentRequest statuses: " . implode(', ', DocumentRequest::STATUSES) . PHP_EOL;
echo "DocumentRequest DOCUMENT_MAP count: " . count(DocumentRequest::DOCUMENT_MAP) . PHP_EOL;

echo PHP_EOL . "=== Notification Types ===" . PHP_EOL;
echo Notification::TYPE_DOCUMENT_REQUEST_SUBMITTED . PHP_EOL;
echo Notification::TYPE_DOCUMENT_REQUEST_ACKNOWLEDGED . PHP_EOL;
echo Notification::TYPE_DOCUMENT_REQUEST_PROCESSING . PHP_EOL;
echo Notification::TYPE_DOCUMENT_REQUEST_FULFILLED . PHP_EOL;
echo Notification::TYPE_DOCUMENT_REQUEST_UNAVAILABLE . PHP_EOL;

// Test code generation
echo PHP_EOL . "=== Code Generation ===" . PHP_EOL;
$code = DocumentRequest::generateRequestCode();
echo "Generated request code: " . $code . PHP_EOL;

// Check admin exists for token-based API tests
$admin = Admin::first();
if ($admin) {
    echo PHP_EOL . "=== Admin Token ===" . PHP_EOL;
    $token = $admin->createToken('test-token')->plainTextToken;
    echo "Admin token created (first 20 chars): " . substr($token, 0, 20) . "..." . PHP_EOL;
    echo "ADMIN_TOKEN=" . $token . PHP_EOL;
}

$teacher = Teacher::where('status', 'active')->first();
if ($teacher) {
    echo PHP_EOL . "=== Teacher Token ===" . PHP_EOL;
    $token = $teacher->createToken('test-token')->plainTextToken;
    echo "Teacher: " . $teacher->first_name . " " . $teacher->last_name . " (ID: " . $teacher->id . ")" . PHP_EOL;
    echo "TEACHER_TOKEN=" . $token . PHP_EOL;
    echo "TEACHER_DB_ID=" . $teacher->id . PHP_EOL;
} else {
    echo "No active teacher found." . PHP_EOL;
}

echo PHP_EOL . "=== All checks passed ===" . PHP_EOL;
