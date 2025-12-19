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
    $pw = trim($_POST['pw'] ?? '');
    $name = trim($_POST['name'] ?? ''); // Name is optional for login, required for register

    if (!$id || !$pw) {
        echo json_encode(['success' => false, 'message' => '아이디와 비밀번호를 입력해주세요.']);
        exit;
    }

    $users = [];
    if (file_exists($userFile)) {
        $users = json_decode(file_get_contents($userFile), true);
    }
    if (!is_array($users)) $users = [];

    if ($action === 'register') {
        if (!$name) {
            echo json_encode(['success' => false, 'message' => '이름(닉네임)을 입력해주세요.']);
            exit;
        }
        // Check if ID exists
        if (isset($users[$id])) {
            echo json_encode(['success' => false, 'message' => '이미 존재하는 아이디입니다.']);
        } else {
            // Hash the password
            $hashedPw = password_hash($pw, PASSWORD_DEFAULT);
            $users[$id] = [
                'name' => $name,
                'pw' => $hashedPw,
                'joined_at' => date('Y-m-d H:i:s')
            ];
            file_put_contents($userFile, json_encode($users, JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo json_encode(['success' => true]);
        }
    } elseif ($action === 'login') {
        if (isset($users[$id])) {
            // Check Password
            $storedHash = $users[$id]['pw'] ?? '';
            // Backward compatibility check (if old users exist as string) - can be removed if fresh start
            if (is_string($users[$id])) { 
                 echo json_encode(['success' => false, 'message' => '구버전 계정입니다. 재가입해주세요.']);
                 exit;
            }

            if (password_verify($pw, $storedHash)) {
                echo json_encode([
                    'success' => true, 
                    'name' => $users[$id]['name'],
                    'avatar' => $users[$id]['avatar'] ?? '',
                    'achievements' => $users[$id]['achievements'] ?? []
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => '비밀번호가 일치하지 않습니다.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '존재하지 않는 아이디입니다.']);
        }
    } else {
        echo json_encode(['error' => 'Invalid action']);
    }
}
?>
