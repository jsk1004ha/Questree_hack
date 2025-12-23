<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

session_start();

$tokensFile = '../data/game_tokens.json';

// Ensure data directory exists
if (!file_exists('../data')) { mkdir('../data', 0777, true); }

function loadTokens() {
    global $tokensFile;
    if (file_exists($tokensFile)) {
        return json_decode(file_get_contents($tokensFile), true) ?: [];
    }
    return [];
}

function saveTokens($tokens) {
    global $tokensFile;
    // Clean expired tokens (older than 30 minutes)
    $now = time();
    $tokens = array_filter($tokens, function($t) use ($now) {
        return ($now - $t['created']) < 1800;
    });
    file_put_contents($tokensFile, json_encode($tokens), LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = $_POST['user_id'] ?? '';
    $mode = $_POST['mode'] ?? '';
    
    if ($action === 'start') {
        // Generate new token
        if (!$userId || !$mode) {
            echo json_encode(['success' => false, 'error' => 'Missing user_id or mode']);
            exit;
        }
        
        $token = $userId . '_' . time() . '_' . bin2hex(random_bytes(8));
        
        $tokens = loadTokens();
        $tokens[$token] = [
            'user_id' => $userId,
            'mode' => $mode,
            'created' => time(),
            'used' => false
        ];
        saveTokens($tokens);
        
        echo json_encode(['success' => true, 'token' => $token]);
    }
    elseif ($action === 'validate') {
        // Validate and consume token
        $token = $_POST['token'] ?? '';
        $time = floatval($_POST['time'] ?? 0);
        
        if (!$token) {
            echo json_encode(['success' => false, 'error' => 'No token']);
            exit;
        }
        
        $tokens = loadTokens();
        
        if (!isset($tokens[$token])) {
            echo json_encode(['success' => false, 'error' => 'Invalid token']);
            exit;
        }
        
        $tokenData = $tokens[$token];
        
        if ($tokenData['used']) {
            echo json_encode(['success' => false, 'error' => 'Token already used']);
            exit;
        }
        
        // Check minimum time based on mode
        $minTimes = ['10' => 3, '20' => 10, '40' => 30, '80' => 60];
        $modeKey = $tokenData['mode'];
        if (isset($minTimes[$modeKey]) && $time < $minTimes[$modeKey]) {
            echo json_encode(['success' => false, 'error' => 'Time too fast']);
            exit;
        }
        
        // Mark as used
        $tokens[$token]['used'] = true;
        saveTokens($tokens);
        
        echo json_encode([
            'success' => true, 
            'user_id' => $tokenData['user_id'],
            'mode' => $tokenData['mode']
        ]);
    }
    else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}
?>
