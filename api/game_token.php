<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

session_start();

// Load secret key
$tokenSecret = "default_secret_key";
if (file_exists('secret.php')) { include 'secret.php'; }

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

// Generate signed token
function generateSignedToken($userId, $mode, $secret) {
    $data = $userId . '|' . $mode . '|' . time() . '|' . bin2hex(random_bytes(8));
    $signature = hash_hmac('sha256', $data, $secret);
    return $data . '.' . substr($signature, 0, 16); // token.signature
}

// Verify token signature
function verifyTokenSignature($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return false;
    $data = $parts[0];
    $sig = $parts[1];
    $expectedSig = substr(hash_hmac('sha256', $data, $secret), 0, 16);
    return hash_equals($expectedSig, $sig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = $_POST['user_id'] ?? '';
    $mode = $_POST['mode'] ?? '';
    
    if ($action === 'start') {
        // Generate new signed token
        if (!$userId || !$mode) {
            echo json_encode(['success' => false, 'error' => 'Missing user_id or mode']);
            exit;
        }
        
        $token = generateSignedToken($userId, $mode, $tokenSecret);
        
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
        
        // Verify token signature first (anti-forge)
        if (!verifyTokenSignature($token, $tokenSecret)) {
            echo json_encode(['success' => false, 'error' => 'Invalid token signature - forged token detected']);
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
        
        // Check actual elapsed time vs submitted time (anti-cheat)
        $actualElapsed = time() - $tokenData['created'];
        if ($time < ($actualElapsed - 2)) { // 2 second margin for network latency
            echo json_encode(['success' => false, 'error' => 'Time mismatch - cheating detected']);
            exit;
        }
        
        // Check minimum time based on mode
        $minTimes = ['10' => 3, '20' => 10, '40' => 20, '80' => 60];
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
