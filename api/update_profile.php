<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");

$userFile = '../data/users.json';
$rankFile = '../data/rankings.json';

$id = trim($_POST['id'] ?? '');
$newName = trim($_POST['new_name'] ?? '');
$newAvatar = $_POST['avatar'] ?? '';

if(!$id){
    echo json_encode(['success'=>false, 'message'=>'Missing ID']);
    exit;
}

if(!file_exists($userFile)){
    echo json_encode(['success'=>false, 'message'=>'No users found']);
    exit;
}

$users = json_decode(file_get_contents($userFile), true);
if(!isset($users[$id])){
    echo json_encode(['success'=>false, 'message'=>'User not found']);
    exit;
}

// Handle Avatar Update
if($newAvatar){
    $users[$id]['avatar'] = $newAvatar;
    file_put_contents($userFile, json_encode($users, JSON_UNESCAPED_UNICODE), LOCK_EX);
    echo json_encode(['success'=>true, 'message'=>'Avatar updated']);
    exit;
}

// Handle Name Update
if(!$newName){
    echo json_encode(['success'=>false, 'message'=>'Missing new name']);
    exit;
}

$oldName = $users[$id]['name'];
$users[$id]['name'] = $newName;

if(file_put_contents($userFile, json_encode($users, JSON_UNESCAPED_UNICODE), LOCK_EX) === false){
    echo json_encode(['success'=>false, 'message'=>'Failed to write user data']);
    exit;
}

// Update Rankings (Name Replacement)
if(file_exists($rankFile)){
    $rankings = json_decode(file_get_contents($rankFile), true);
    $changed = false;
    
    foreach($rankings as $mode => &$list){
        foreach($list as &$row){
            if($row['name'] === $oldName){
                $row['name'] = $newName;
                $changed = true;
            }
        }
    }
    
    if($changed){
        file_put_contents($rankFile, json_encode($rankings, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

echo json_encode(['success'=>true, 'message'=>'Profile updated']);
?>
