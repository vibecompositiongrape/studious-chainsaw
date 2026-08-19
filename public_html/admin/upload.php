<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';
lsb_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['pptx'])) {
    lsb_verify_csrf();
    $errors = [];
    $success = [];
    foreach ($_FILES['pptx']['error'] as $i => $err) {
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) { 
            $errors[] = "Error with file #".($i+1); 
            continue; 
        }
        $tmp = $_FILES['pptx']['tmp_name'][$i];
        $name = basename($_FILES['pptx']['name'][$i]);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        
        // Accept pptx, ppt, pdf, and txt files
        if (!in_array($ext, ['pptx','ppt','pdf','txt'])) { 
            $errors[] = "$name - unsupported file type (.$ext)"; 
            continue; 
        }
        
        $dest = UPLOAD_DIR.'/'.$name;
        if (move_uploaded_file($tmp, $dest)) {
            $success[] = $name;
        } else {
            $errors[] = "Failed to move $name";
        }
    }
    
    $message = '';
    if (!empty($success)) {
        $message = "Uploaded: " . implode(", ", $success) . ". ";
    }
    if (!empty($errors)) {
        $message .= "Issues: " . implode("; ", $errors);
    }
    $_SESSION['flash'] = $message ?: "Upload completed.";
    header('Location: ./index.php'); 
    exit;
}
http_response_code(405); 
echo "Method not allowed";