<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$userFile = '../data/users.json';

// Ensure data directory exists
if (!file_exists('../data')) { mkdir('../data', 0777, true); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = trim($_POST['id'] ?? '');

    // Helper to safely load users
    function loadUsers($path) {
        $fp = fopen($path, 'c+'); // Open for reading and writing, create if not exists
        if (!$fp) return [];
        
        if (flock($fp, LOCK_SH)) { // Shared lock for reading
            $size = filesize($path);
            $content = $size > 0 ? fread($fp, $size) : '{}';
            flock($fp, LOCK_UN);
            fclose($fp);
            return json_decode($content, true) ?? [];
        }
        fclose($fp);
        return [];
    }

    // Helper to safely save users
    function saveUsers($path, $data) {
        $fp = fopen($path, 'c+');
        if (flock($fp, LOCK_EX)) { // Exclusive lock for writing
            ftruncate($fp, 0); // Clear file
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    
    // Helper to update specific user (read-modify-write atomic)
    function updateUser($path, $userId, $callback) {
        $fp = fopen($path, 'c+');
        if (flock($fp, LOCK_EX)) {
            $size = filesize($path);
            $content = $size > 0 ? fread($fp, $size) : '{}';
            $users = json_decode($content, true) ?? [];
            
            // Apply callback
            $result = $callback($users);
            
            if ($result !== false) { // Only save if callback returns true-ish stuff
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($users, JSON_UNESCAPED_UNICODE));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            return $result; // Return whatever callback returned (e.g., user data)
        }
        fclose($fp);
        return false;
    }

    // Handle Actions
    if ($action === 'register') {
        $pw = trim($_POST['pw'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if (!$id || !$pw || !$name) {
            echo json_encode(['success' => false, 'message' => '모든 필드를 입력해주세요.']); exit;
        }

        $res = updateUser($userFile, $id, function(&$users) use ($id, $pw, $name) {
            if (isset($users[$id])) return ['error' => '이미 존재하는 아이디입니다.'];
            
            $sessionToken = bin2hex(random_bytes(16));
            $users[$id] = [
                'name' => $name,
                'pw' => password_hash($pw, PASSWORD_DEFAULT),
                'session_token' => $sessionToken,
                'joined_at' => date('Y-m-d H:i:s'),
                'pvp_stats' => ['wins' => 0, 'losses' => 0]
            ];
            return ['success' => true, 'session_token' => $sessionToken];
        });
        
        if (isset($res['error'])) echo json_encode(['success' => false, 'message' => $res['error']]);
        else echo json_encode($res);
        
    } elseif ($action === 'login') {
        $pw = trim($_POST['pw'] ?? '');
        if (!$id || !$pw) {
            echo json_encode(['success' => false, 'message' => '아이디와 비밀번호를 입력해주세요.']); exit;
        }

        $res = updateUser($userFile, $id, function(&$users) use ($id, $pw) {
            if (!isset($users[$id])) return ['error' => '존재하지 않는 아이디입니다.'];
            if (is_string($users[$id])) return ['error' => '구버전 계정입니다. 재가입해주세요.']; // Old format

            if (password_verify($pw, $users[$id]['pw'])) {
                // Check duplicate
                $forceLogin = isset($_POST['force']) && $_POST['force'] === 'true';
                $lastActivity = $users[$id]['last_activity'] ?? 0;
                if (!$forceLogin && (time() - $lastActivity) < 10) {
                     return ['duplicate' => true, 'message' => '이미 다른 기기에서 로그인 중입니다. 강제 로그인하시겠습니까?'];
                }

                $sessionToken = bin2hex(random_bytes(16));
                $users[$id]['session_token'] = $sessionToken;
                $users[$id]['last_activity'] = time();
                
                return [
                    'success' => true,
                    'name' => $users[$id]['name'],
                    'avatar' => $users[$id]['avatar'] ?? '',
                    'achievements' => $users[$id]['achievements'] ?? [],
                    'pvp_stats' => $users[$id]['pvp_stats'] ?? ['wins' => 0, 'losses' => 0],
                    'session_token' => $sessionToken
                ];
            } else {
                return ['error' => '비밀번호가 일치하지 않습니다.'];
            }
        });

        if (isset($res['error'])) echo json_encode(['success' => false, 'message' => $res['error']]);
        elseif (isset($res['duplicate'])) echo json_encode(['success' => false, 'duplicate' => true, 'message' => $res['message']]);
        else echo json_encode($res);

    } elseif ($action === 'validate_session') {
        $token = trim($_POST['session_token'] ?? '');
        
        // READ FIRST (No write lock)
        $users = loadUsers($userFile);
        $isValid = false;
        
        if (isset($users[$id]) && isset($users[$id]['session_token']) && $users[$id]['session_token'] === $token) {
            $isValid = true;
            
            // Optimization: Only update last_activity every 60 seconds to reduce locking
            $last = $users[$id]['last_activity'] ?? 0;
            if (time() - $last > 60) {
                // Update timestamp with write lock
                updateUser($userFile, $id, function(&$u) use ($id) {
                    if(isset($u[$id])) $u[$id]['last_activity'] = time();
                    return true;
                });
            }
        } else {
            $saved = $users[$id]['session_token'] ?? 'NONE';
            $dump = isset($users[$id]) ? 'UserExists' : 'UserNotFound';
            file_put_contents('../data/auth_debug.log', date('Y-m-d H:i:s')." [Fail] ID:$id / Input:$token / Saved:$saved / Status:$dump\n", FILE_APPEND);
        }
        
        echo json_encode(['valid' => $isValid]); 


    } else {
        echo json_encode(['error' => 'Invalid action']);
    }
}
?>
