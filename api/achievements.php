<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
error_reporting(0); // Suppress warnings that break JSON

$userFile = '../data/users.json';
$rankFile = '../data/rankings.json';

if (!file_exists('../data')) { mkdir('../data', 0777, true); }

// Load Users
$users = file_exists($userFile) ? json_decode(file_get_contents($userFile), true) : [];

$id = trim($_POST['id'] ?? '');
$mode = trim($_POST['mode'] ?? ''); // Easy, Normal, Hard, Extreme, or 10, 20...
$time = floatval($_POST['time'] ?? 9999);
$errorCount = intval($_POST['error_count'] ?? 0);

if (!$id || !isset($users[$id])) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Current Achievements
$myAch = $users[$id]['achievements'] ?? [];
$newUnlocked = [];

// --- VALIDATION LOGIC ---

// 1. Basic Clear (Mode Based)
$modeCode = '';
$modeName = ''; // 한글 이름
// Robust check using values (10, 20, 40, 80) or text fallback
if($mode == '10' || strpos($mode, '하남자') !== false) { $modeCode = 'easy'; $modeName = '하남자'; }
elseif($mode == '20' || strpos($mode, '중남자') !== false) { $modeCode = 'normal'; $modeName = '중남자'; }
elseif($mode == '40' || strpos($mode, '상남자') !== false) { $modeCode = 'hard'; $modeName = '상남자'; }
elseif($mode == '80' || strpos($mode, '씹상남자') !== false) { $modeCode = 'extreme'; $modeName = '씹상남자'; }

// Clear icons: easy/normal/hard = 🚩, extreme = 🤫
$clearIcon = ($modeCode === 'extreme') ? '🤫' : '🚩';
if($modeCode && !in_array("clear_{$modeCode}", $myAch)){
    $newUnlocked[] = ['id' => "clear_{$modeCode}", 'icon' => $clearIcon, 'name' => "{$modeName} 정복", 'desc' => "{$modeName} 난이도 클리어"];
    $myAch[] = "clear_{$modeCode}";
}

// 2. Speed Run (Difficulty Specific)
$speedId = "speed_{$modeCode}";
$isSpeed = false;
$limit = 0;

if($modeCode === 'easy' && $time <= 10) $limit = 10;
elseif($modeCode === 'normal' && $time <= 60) $limit = 60;
elseif($modeCode === 'hard' && $time <= 180) $limit = 180;
elseif($modeCode === 'extreme' && $time <= 600) $limit = 600;

// Speed icons: easy=👧, normal=👦, hard=😎, extreme=👑
$speedIcons = ['easy'=>'👧', 'normal'=>'👦', 'hard'=>'😎', 'extreme'=>'👑'];
$speedIcon = $speedIcons[$modeCode] ?? '⚡';
if($limit > 0 && !in_array($speedId, $myAch)){
    $newUnlocked[] = ['id' => $speedId, 'icon' => $speedIcon, 'name' => "{$modeName}의 왕", 'desc' => "{$limit}초 이내 클리어"];
    $myAch[] = $speedId;
}

// 3. Perfect Game (No Errors)
if($errorCount === 0 && !in_array('god_hand', $myAch)){
    $newUnlocked[] = ['id' => 'god_hand', 'icon' => '🎯', 'name' => '신의 손', 'desc' => '단 한 번의 실수도 없이 완벽하게 정렬했습니다.'];
    $myAch[] = 'god_hand';
}

// 4. Persistence (Took long but finished)
// Criteria: Easy > 30s, Normal > 120s, Hard > 300s, Extreme > 1200s
$slowLimit = 0;
if($modeCode === 'easy') $slowLimit = 60;
elseif($modeCode === 'normal') $slowLimit = 300;
elseif($modeCode === 'hard') $slowLimit = 500;
elseif($modeCode === 'extreme') $slowLimit = 1500;

if($time >= $slowLimit && !in_array('slow_steady', $myAch)){
    $newUnlocked[] = ['id' => 'slow_steady', 'icon' => '🔥', 'name' => '불굴의 의지', 'desc' => '오랜 시간이 걸렸지만 포기하지 않고 해냈습니다.'];
    $myAch[] = 'slow_steady';
}

// 5. Ranker (Check Top 5)
// Need to load rankings to check real rank
if(!in_array('ranker', $myAch)){
    $rankings = file_exists($rankFile) ? json_decode(file_get_contents($rankFile), true) : [];
    $list = $rankings[$mode] ?? [];
    $myRank = -1;
    foreach($list as $idx => $row){
        if($row['name'] === $users[$id]['name'] && abs($row['time'] - $time) < 0.01){
            $myRank = $idx;
            break;
        }
    }
    if($myRank !== -1){
        // Top 5 Achievement
        if($myRank < 5 && !in_array('ranker', $myAch)){
            $newUnlocked[] = ['id' => 'ranker', 'icon' => '🏆', 'name' => '명예의 전당', 'desc' => 'Top 5 랭킹에 이름을 올렸습니다!'];
            $myAch[] = 'ranker';
        }
        // Top 1 Achievement
        if($myRank === 0 && !in_array('goat', $myAch)){
            $newUnlocked[] = ['id' => 'goat', 'icon' => '🐐', 'name' => 'GOAT', 'desc' => '역대 1위! Greatest of All Time.'];
            $myAch[] = 'goat';
        }
    }
}

// 6. Lucky 7 (Time ends in .77)
$decimal = $time - floor($time);
// check if decimal is roughly .77 (floating point safety)
if(abs(round($decimal, 2) - 0.77) < 0.001 && !in_array('lucky_seven', $myAch)){
    $newUnlocked[] = ['id' => 'lucky_seven', 'icon' => '🍀', 'name' => '럭키세븐', 'desc' => '기록의 소수점이 정확히 .77입니다!'];
    $myAch[] = 'lucky_seven';
}

// 7. Veteran 10 (10 Games Cleared)
// Increment play_count
$users[$id]['play_count'] = ($users[$id]['play_count'] ?? 0) + 1;
$playCount = $users[$id]['play_count'];

if($playCount >= 10 && !in_array('veteran_10', $myAch)){
    $newUnlocked[] = ['id' => 'veteran_10', 'icon' => '⚔️', 'name' => '전장의 지배자', 'desc' => '게임을 10회 클리어했습니다.'];
    $myAch[] = 'veteran_10';
}

// 8. Real Man (10 Achievements Unlocked)
// Check current count + new unlocks
$totalUnlockedCount = count($users[$id]['achievements'] ?? []) + count($newUnlocked);
if($totalUnlockedCount >= 10 && !in_array('real_man', $myAch)){
    // Prevent double add if it's already in newUnlocked (unlikely but safe)
    $already = false;
    foreach($newUnlocked as $n){ if($n['id'] === 'real_man') $already = true; }
    
    if(!$already){
        $newUnlocked[] = ['id' => 'real_man', 'icon' => '☠️', 'name' => '남자중의 남자', 'desc' => '업적 10개 달성'];
        $myAch[] = 'real_man';
    }
}

// 9. Secret ??? Achievement (All 14 regular achievements unlocked)
$allAchievements = [
    'clear_easy', 'clear_normal', 'clear_hard', 'clear_extreme',
    'speed_easy', 'speed_normal', 'speed_hard', 'speed_extreme',
    'god_hand', 'slow_steady', 'ranker', 'goat', 'lucky_seven', 'veteran_10', 'real_man'
];
$hasAll = true;
foreach($allAchievements as $achId){
    if(!in_array($achId, $myAch)) { $hasAll = false; break; }
}
if($hasAll && !in_array('secret_master', $myAch)){
    $newUnlocked[] = ['id' => 'secret_master', 'icon' => '❓', 'name' => '???', 'desc' => '???'];
    $myAch[] = 'secret_master';
}

// Save Updates (Always save play_count increment)
$users[$id]['achievements'] = $myAch;
file_put_contents($userFile, json_encode($users, JSON_UNESCAPED_UNICODE), LOCK_EX);

// --- GLOBAL STATS CALCULATION ---
$totalUsers = count($users);
$achCounts = [];
foreach($users as $u){
    $uAch = $u['achievements'] ?? [];
    foreach($uAch as $a){
        if(!isset($achCounts[$a])) $achCounts[$a] = 0;
        $achCounts[$a]++;
    }
}

$stats = [];
foreach($achCounts as $k => $cnt){
    $per = ($totalUsers > 0) ? round(($cnt / $totalUsers) * 100, 1) : 0;
    $stats[$k] = $per;
}

echo json_encode([
    'success' => true, 
    'new_achievements' => $newUnlocked, 
    'all_achievements' => $myAch,
    'global_stats' => $stats,
    'total_users' => $totalUsers
]);

