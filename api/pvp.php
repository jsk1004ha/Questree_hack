<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DATA_DIR', '../data');
$roomFile = DATA_DIR . '/rooms_v2.json';

// Ensure data directory exists
if(!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

function loadRooms() {
    global $roomFile;
    if(!file_exists($roomFile)) return [];
    $content = file_get_contents($roomFile);
    if($content === false) return [];
    return json_decode($content, true) ?: [];
}

function saveRooms($rooms) {
    global $roomFile;
    file_put_contents($roomFile, json_encode($rooms, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function generateCode() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for($i = 0; $i < 6; $i++) $code .= $chars[rand(0, strlen($chars)-1)];
    return $code;
}

function cleanupRooms(&$rooms) {
    $now = time();
    $dirty = false;
    foreach ($rooms as $code => &$room) {
        if(!isset($room['last_updated'])) $room['last_updated'] = $now;
        
        // DISABLED: Do NOT cleanup players automatically
        // This was causing players to disappear unexpectedly
        // Players will be removed only when they explicitly leave
        
        // Delete entire room only if stale for 10 minutes
        if($now - $room['last_updated'] > 600) {
            unset($rooms[$code]);
            $dirty = true;
        }
    }
    return $dirty;
}

$action = $_POST['action'] ?? '';
$rooms = loadRooms();
// cleanupRooms removed from global scope to reduce load
// if(cleanupRooms($rooms)) saveRooms($rooms);

switch($action) {
    case 'create':
        $needsSave = cleanupRooms($rooms); // Clean before create
        if($needsSave) saveRooms($rooms); // Save clean state (will be saved again with new room)
        
        $hostId = trim($_POST['host_id'] ?? '');
        $hostName = trim($_POST['host_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $mode = trim($_POST['mode'] ?? '20');
        $password = trim($_POST['password'] ?? '');
        $maxPlayers = intval($_POST['max_players'] ?? 2);
        if($maxPlayers < 2) $maxPlayers = 2;
        if($maxPlayers > 8) $maxPlayers = 8;
        
        if(!$hostId) { echo json_encode(['success'=>false, 'message'=>'No host ID']); exit; }
        
        $code = generateCode();
        while(isset($rooms[$code])) $code = generateCode();
        
        $rooms[$code] = [
            'host_id' => $hostId,
            'title' => $title ?: ($hostName.'의 방'),
            'mode' => $mode,
            'password' => $password,
            'max_players' => $maxPlayers,
            'players' => [
                ['id' => $hostId, 'name' => $hostName, 'finish_time' => null, 'ready' => true, 'progress' => 0]
            ],
            'status' => 'waiting',
            'seed' => null,
            'start_time' => null,
            'chat' => [],
            'created_at' => time()
        ];
        saveRooms($rooms);
        echo json_encode(['success'=>true, 'room_code'=>$code]);
        break;
        
    case 'list':
        $needsSave = cleanupRooms($rooms);
        if($needsSave) saveRooms($rooms);
        
        $allRooms = [];
        foreach($rooms as $code => $room) {
            if($room['status'] === 'waiting') {
                $allRooms[] = [
                    'code' => $code,
                    'title' => $room['title'] ?? ($room['players'][0]['name'].'의 방'),
                    'host_name' => $room['players'][0]['name'] ?? 'Unknown',
                    'mode' => $room['mode'],
                    'has_password' => !empty($room['password']),
                    'current_players' => count($room['players']),
                    'max_players' => $room['max_players']
                ];
            }
        }
        echo json_encode(['success'=>true, 'rooms'=>$allRooms]);
        break;
        
    case 'join':
        cleanupRooms($rooms); // Clean before join
        
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $playerId = trim($_POST['player_id'] ?? '');
        $playerName = trim($_POST['player_name'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false, 'message'=>'방을 찾을 수 없습니다']);
            exit;
        }
        $room = &$rooms[$code];
        
        if($room['status'] !== 'waiting') {
            echo json_encode(['success'=>false, 'message'=>'이미 시작된 방입니다']);
            exit;
        }
        if(!empty($room['password']) && $room['password'] !== $password) {
            echo json_encode(['success'=>false, 'message'=>'비밀번호가 틀렸습니다']);
            exit;
        }
        foreach($room['players'] as $p) {
            if($p['id'] === $playerId) {
                echo json_encode(['success'=>false, 'message'=>'이미 입장한 방입니다']);
                exit;
            }
        }
        if(count($room['players']) >= $room['max_players']) {
            echo json_encode(['success'=>false, 'message'=>'방이 가득 찼습니다']);
            exit;
        }
        
        $room['players'][] = ['id' => $playerId, 'name' => $playerName, 'finish_time' => null, 'ready' => false, 'progress' => 0];
        saveRooms($rooms);
        
        echo json_encode([
            'success' => true,
            'mode' => $room['mode'],
            'players' => array_map(fn($p)=>$p['name'], $room['players']),
            'max_players' => $room['max_players']
        ]);
        break;
        
    case 'ready':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $playerId = trim($_POST['player_id'] ?? '');
        
        $force = $_POST['force'] ?? null; // 'true' or 'false' string
        
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false, 'message'=>'방이 없습니다']);
            exit;
        }
        
        foreach($rooms[$code]['players'] as &$p) {
            if($p['id'] === $playerId) {
                if($force !== null) {
                    $p['ready'] = ($force === 'true');
                } else {
                    $p['ready'] = !($p['ready'] ?? false);
                }
                break;
            }
        }
        saveRooms($rooms);
        echo json_encode(['success'=>true]);
        break;
        
    case 'start':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $hostId = trim($_POST['host_id'] ?? '');
        
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false, 'message'=>'방이 없습니다']);
            exit;
        }
        if($rooms[$code]['host_id'] !== $hostId) {
            echo json_encode(['success'=>false, 'message'=>'호스트만 시작할 수 있습니다']);
            exit;
        }
        if(count($rooms[$code]['players']) < 2) {
            echo json_encode(['success'=>false, 'message'=>'최소 2명이 필요합니다']);
            exit;
        }
        
        // Host sends word list for synchronization
        $words = $_POST['words'] ?? '';
        
        $rooms[$code]['status'] = 'playing';
        $rooms[$code]['seed'] = rand(10000, 99999);
        $rooms[$code]['start_time'] = time() + 3;
        $rooms[$code]['words'] = $words; // Store word list from host
        saveRooms($rooms);
        
        echo json_encode(['success'=>true, 'seed'=>$rooms[$code]['seed'], 'start_time'=>$rooms[$code]['start_time']]);
        break;
        
    case 'reset':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $hostId = trim($_POST['host_id'] ?? '');
        
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false, 'message'=>'방이 없습니다']);
            exit;
        }
        if($rooms[$code]['host_id'] !== $hostId) {
            echo json_encode(['success'=>false, 'message'=>'호스트만 리셋할 수 있습니다']);
            exit;
        }
        
        // Reset room state
        $rooms[$code]['status'] = 'waiting';
        $rooms[$code]['start_time'] = 0;
        $rooms[$code]['seed'] = 0;
        $rooms[$code]['words'] = '';
        
        // Reset player states and REFRESH PINGS (prevent cleanup after long games)
        $now = time();
        foreach($rooms[$code]['players'] as &$p) {
            $p['progress'] = 0;
            $p['finish_time'] = null;
            $p['last_ping'] = $now; // Refresh ping for all players
            // Host should be ready by default, guests unready
            if ($p['id'] === $rooms[$code]['host_id']) {
                $p['ready'] = true;
            } else {
                $p['ready'] = false;
            }
        }
        unset($p); // CRITICAL: Clear reference to prevent data corruption
        
        saveRooms($rooms);
        echo json_encode(['success'=>true]);
        break;
        
    case 'status':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $playerId = trim($_POST['player_id'] ?? '');
        
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false, 'message'=>'방이 없습니다']);
            exit;
        }
        
        // DEBUG: Log player IDs to track duplication issue
        $playerIds = array_map(fn($p) => $p['id'], $rooms[$code]['players']);
        file_put_contents(DATA_DIR . '/debug_status.log', date('H:i:s') . " status: code=$code players=" . implode(',', $playerIds) . "\n", FILE_APPEND);
        
        // Update heartbeat
        $rooms[$code]['last_updated'] = time();
        if($playerId) {
            foreach($rooms[$code]['players'] as &$p) {
                if($p['id'] === $playerId) {
                    $p['last_ping'] = time();
                    break;
                }
            }
            unset($p); 
            // DO NOT SAVE in status polling to prevent race conditions
            // $now = time();
            // if(!isset($rooms[$code]['last_saved_at'])) $rooms[$code]['last_saved_at'] = 0;
            // $rooms[$code]['last_saved_at'] = $now;
            // saveRooms($rooms);
        }
        
        $room = $rooms[$code];
        echo json_encode([
            'success' => true,
            'status' => $room['status'],
            'mode' => $room['mode'],
            'players' => $room['players'],
            'max_players' => $room['max_players'],
            'seed' => $room['seed'],
            'start_time' => $room['start_time'],
            'host_id' => $room['host_id'],
            'words' => $room['words'] ?? '',
            'chat' => array_slice($room['chat'] ?? [], -30) // Last 30 messages
        ]);
        break;
        
    case 'chat':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        
        if(!isset($rooms[$code]) || !$name || !$msg) {
            echo json_encode(['success'=>false]);
            exit;
        }
        
        $rooms[$code]['chat'][] = ['name' => $name, 'msg' => $msg, 'time' => time()];
        // Keep only last 50 messages
        if(count($rooms[$code]['chat']) > 50) {
            $rooms[$code]['chat'] = array_slice($rooms[$code]['chat'], -50);
        }
        saveRooms($rooms);
        echo json_encode(['success'=>true]);
        break;
        
    case 'update_progress':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $playerId = trim($_POST['player_id'] ?? '');
        $progress = intval($_POST['progress'] ?? 0);
        
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false]);
            exit;
        }
        
        foreach($rooms[$code]['players'] as &$p) {
            if($p['id'] === $playerId) {
                $p['progress'] = $progress;
                break;
            }
        }
        unset($p); // CRITICAL: Clear reference
        saveRooms($rooms);
        echo json_encode(['success'=>true]);
        break;
        
    case 'finish':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $playerId = trim($_POST['player_id'] ?? '');
        $time = floatval($_POST['time'] ?? 0);
        
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false, 'message'=>'방이 없습니다']);
            exit;
        }
        
        foreach($rooms[$code]['players'] as &$p) {
            if($p['id'] === $playerId && $p['finish_time'] === null) {
                $p['finish_time'] = $time;
                break;
            }
        }
        unset($p); // CRITICAL: Clear reference
        
        // End game when n-1 players have finished (only 1 player left playing)
        $totalPlayers = count($rooms[$code]['players']);
        $finishedCount = 0;
        foreach($rooms[$code]['players'] as $p) {
            if($p['finish_time'] !== null) {
                $finishedCount++;
            }
        }
        
        // If n-1 or more players finished, game is over
        if($finishedCount >= $totalPlayers - 1) {
            $rooms[$code]['status'] = 'finished';
        }
        
        saveRooms($rooms);
        echo json_encode(['success'=>true]);
        break;
        
    case 'leave':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        $playerId = trim($_POST['player_id'] ?? '');
        
        if(isset($rooms[$code])) {
            if($rooms[$code]['host_id'] === $playerId) {
                // Host leaving - transfer host to next player or delete room
                $rooms[$code]['players'] = array_values(array_filter(
                    $rooms[$code]['players'],
                    fn($p) => $p['id'] !== $playerId
                ));
                
                if(count($rooms[$code]['players']) > 0) {
                    // Transfer host to first remaining player
                    $newHost = $rooms[$code]['players'][0];
                    $rooms[$code]['host_id'] = $newHost['id'];
                    $rooms[$code]['host_name'] = $newHost['name'];
                    // Mark new host as ready
                    foreach($rooms[$code]['players'] as &$p) {
                        if($p['id'] === $newHost['id']) {
                            $p['ready'] = true;
                        }
                    }
                    unset($p);
                } else {
                    // No players left - delete room
                    unset($rooms[$code]);
                }
            } else {
                // Guest leaving - remove from players
                $rooms[$code]['players'] = array_values(array_filter(
                    $rooms[$code]['players'],
                    fn($p) => $p['id'] !== $playerId
                ));
            }
            saveRooms($rooms);
        }
        
        echo json_encode(['success'=>true]);
        break;
    
    case 'lobby_chat':
        $name = trim($_POST['name'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        if(!$name || !$msg) {
            echo json_encode(['success'=>false]);
            exit;
        }
        
        $lobbyFile = DATA_DIR . '/lobby_chat.json';
        $lobbyChat = file_exists($lobbyFile) ? json_decode(file_get_contents($lobbyFile), true) : [];
        if(!is_array($lobbyChat)) $lobbyChat = [];
        
        $lobbyChat[] = ['name' => $name, 'msg' => $msg, 'time' => time()];
        if(count($lobbyChat) > 50) $lobbyChat = array_slice($lobbyChat, -50);
        
        file_put_contents($lobbyFile, json_encode($lobbyChat), LOCK_EX);
        echo json_encode(['success'=>true]);
        break;
        
    case 'lobby_chat_list':
        $lobbyFile = DATA_DIR . '/lobby_chat.json';
        $lobbyChat = file_exists($lobbyFile) ? json_decode(file_get_contents($lobbyFile), true) : [];
        if(!is_array($lobbyChat)) $lobbyChat = [];
        echo json_encode(['success'=>true, 'chat'=>array_slice($lobbyChat, -30)]);
        break;
        
    case 'record_result':
        // Record PVP result (win or loss) for a player
        $playerId = trim($_POST['player_id'] ?? '');
        $result = trim($_POST['result'] ?? ''); // 'win' or 'loss'
        
        if(!$playerId || !in_array($result, ['win', 'loss'])) {
            echo json_encode(['success'=>false, 'message'=>'Invalid parameters']);
            exit;
        }
        
        // Update user stats in users.json
        $userFile = DATA_DIR . '/users.json';
        if(!file_exists($userFile)) {
            echo json_encode(['success'=>false, 'message'=>'User file not found']);
            exit;
        }
        
        $fp = fopen($userFile, 'c+');
        if(flock($fp, LOCK_EX)) {
            $size = filesize($userFile);
            $content = $size > 0 ? fread($fp, $size) : '{}';
            $users = json_decode($content, true) ?? [];
            
            if(isset($users[$playerId])) {
                if(!isset($users[$playerId]['pvp_stats'])) {
                    $users[$playerId]['pvp_stats'] = ['wins' => 0, 'losses' => 0, 'streak' => 0, 'max_streak' => 0];
                }
                
                // Ensure streak fields exist
                if(!isset($users[$playerId]['pvp_stats']['streak'])) $users[$playerId]['pvp_stats']['streak'] = 0;
                if(!isset($users[$playerId]['pvp_stats']['max_streak'])) $users[$playerId]['pvp_stats']['max_streak'] = 0;
                
                if($result === 'win') {
                    $users[$playerId]['pvp_stats']['wins']++;
                    $users[$playerId]['pvp_stats']['streak']++;
                    // Update max streak
                    if($users[$playerId]['pvp_stats']['streak'] > $users[$playerId]['pvp_stats']['max_streak']) {
                        $users[$playerId]['pvp_stats']['max_streak'] = $users[$playerId]['pvp_stats']['streak'];
                    }
                } else {
                    $users[$playerId]['pvp_stats']['losses']++;
                    $users[$playerId]['pvp_stats']['streak'] = 0; // Reset streak on loss
                }
                
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($users, JSON_UNESCAPED_UNICODE));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        
        echo json_encode(['success'=>true]);
        break;
        
    case 'debug':
        $code = strtoupper(trim($_POST['room_code'] ?? ''));
        if(!isset($rooms[$code])) {
            echo json_encode(['success'=>false, 'message'=>'방이 없습니다']);
            exit;
        }
        echo json_encode(['success'=>true, 'room'=>$rooms[$code]]);
        break;
    
    case 'pvp_ranking':
        // Return PVP ranking sorted by wins
        $userFile = DATA_DIR . '/users.json';
        $users = file_exists($userFile) ? json_decode(file_get_contents($userFile), true) : [];
        
        $ranking = [];
        foreach($users as $id => $user) {
            $stats = $user['pvp_stats'] ?? null;
            if($stats && (($stats['wins'] ?? 0) > 0 || ($stats['losses'] ?? 0) > 0)) {
                $ranking[] = [
                    'name' => $user['name'] ?? $id,
                    'wins' => $stats['wins'] ?? 0,
                    'losses' => $stats['losses'] ?? 0,
                    'streak' => $stats['max_streak'] ?? 0
                ];
            }
        }
        
        // Sort by wins (desc), then by win rate (desc)
        usort($ranking, function($a, $b) {
            if($a['wins'] !== $b['wins']) return $b['wins'] - $a['wins'];
            $rateA = ($a['wins'] + $a['losses']) > 0 ? $a['wins'] / ($a['wins'] + $a['losses']) : 0;
            $rateB = ($b['wins'] + $b['losses']) > 0 ? $b['wins'] / ($b['wins'] + $b['losses']) : 0;
            return $rateB <=> $rateA;
        });
        
        // Limit to top 20
        $ranking = array_slice($ranking, 0, 20);
        
        echo json_encode(['success'=>true, 'ranking'=>$ranking]);
        break;
        
    default:
        echo json_encode(['success'=>false, 'message'=>'Invalid action']);
}
