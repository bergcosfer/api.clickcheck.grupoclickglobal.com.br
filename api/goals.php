<?php
/**
 * Clickcheck - API de Metas
 * Versão atualizada com hierarquia recursiva de gerentes
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function validateToken($token) {
    if (!$token) return null;
    $decoded = base64_decode($token);
    $parts = explode('.', $decoded);
    if (count($parts) !== 2) return null;
    $payload = json_decode($parts[0], true);
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    return $payload;
}

function getCurrentUser() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $payload = validateToken($token);
    if (!$payload) return null;
    
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$payload['user_id']]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

function requireManager() {
    $user = getCurrentUser();
    if (!$user || !in_array($user['admin_level'], ['admin_principal', 'admin_secundario'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas gerentes podem gerenciar metas']);
        exit;
    }
    return $user;
}

function requireAuth() {
    $user = getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
    return $user;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? null;

    switch ($method) {
        case 'GET':
            if ($action === 'progress') {
                getProgress();
            } elseif ($action === 'team') {
                getTeamMembers();
            } else {
                listGoals();
            }
            break;
        case 'POST':
            createGoal();
            break;
        case 'PUT':
            updateGoal($id);
            break;
        case 'DELETE':
            deleteGoal($id);
            break;
        default:
            echo json_encode(['error' => 'Método não suportado']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
}

function listGoals() {
    requireAuth();
    $db = getDB();
    
    $month = $_GET['month'] ?? date('Y-m');
    $userId = $_GET['user_id'] ?? null;
    
    $sql = "SELECT g.*, u.email as user_email, u.full_name as user_name, u.nickname, u.profile_picture,
                   p.name as package_name, p.type as package_type
            FROM user_goals g
            JOIN users u ON g.user_id = u.id
            JOIN validation_packages p ON g.package_id = p.id
            WHERE g.month = ?";
    $params = [$month];
    
    if ($userId) {
        $sql .= " AND g.user_id = ?";
        $params[] = $userId;
    }
    
    $sql .= " ORDER BY u.full_name, p.name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
}

function getTeamMembers() {
    requireAuth();
    $db = getDB();
    
    $managerId = $_GET['manager_id'] ?? null;
    if (!$managerId) {
        echo json_encode([]);
        return;
    }
    
    $stmt = $db->prepare("
        SELECT id, email, full_name, nickname, profile_picture, profile
        FROM users 
        WHERE manager_id = ?
        ORDER BY full_name
    ");
    $stmt->execute([$managerId]);
    echo json_encode($stmt->fetchAll());
}

function getProgress() {
    requireAuth();
    $db = getDB();
    
    $month = $_GET['month'] ?? date('Y-m');
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
    
    // Verificar se a coluna manager_id existe
    $hasManagerColumn = false;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'manager_id'");
        $hasManagerColumn = $stmt->rowCount() > 0;
    } catch (Exception $e) {
        $hasManagerColumn = false;
    }
    
    // Buscar gerentes e construir hierarquia recursiva
    $managerInfo = [];
    $teamByManager = [];
    $memberIdsByManager = [];
    $allManagerIds = [];
    $userManagerMap = [];
    $usersById = [];
    
    if ($hasManagerColumn) {
        try {
            // Buscar todos os usuários com seus manager_id
            $stmt = $db->query("SELECT id, email, full_name, nickname, profile_picture, profile, admin_level, manager_id FROM users");
            $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Construir mapa de usuários e relações
            foreach ($allUsers as $user) {
                $usersById[$user['id']] = $user;
                if ($user['manager_id']) {
                    $userManagerMap[$user['id']] = $user['manager_id'];
                    $allManagerIds[$user['manager_id']] = true;
                }
            }
            
            // Função recursiva para obter todos os subordinados (diretos e indiretos)
            $getAllSubordinates = function($managerId, $depth = 0) use (&$getAllSubordinates, $userManagerMap, $usersById) {
                if ($depth > 10) return []; // Prevenir loops infinitos
                $subordinates = [];
                foreach ($userManagerMap as $userId => $mgrId) {
                    if ($mgrId == $managerId) {
                        $subordinates[] = $userId;
                        // Recursivamente adicionar subordinados deste subordinado
                        $subordinates = array_merge($subordinates, $getAllSubordinates($userId, $depth + 1));
                    }
                }
                return $subordinates;
            };
            
            // Construir info dos gerentes e seus subordinados (recursivos)
            foreach (array_keys($allManagerIds) as $managerId) {
                if (isset($usersById[$managerId])) {
                    $mgr = $usersById[$managerId];
                    $managerInfo[$managerId] = [
                        'id' => $mgr['id'],
                        'email' => $mgr['email'],
                        'full_name' => $mgr['full_name'],
                        'nickname' => $mgr['nickname'],
                        'profile_picture' => $mgr['profile_picture'],
                        'profile' => $mgr['profile'],
                        'admin_level' => $mgr['admin_level'],
                    ];
                    
                    // Obter TODOS os subordinados (recursivamente)
                    $allSubIds = $getAllSubordinates($managerId);
                    $memberIdsByManager[$managerId] = $allSubIds;
                    
                    $teamByManager[$managerId] = [];
                    foreach ($allSubIds as $subId) {
                        if (isset($usersById[$subId])) {
                            $teamByManager[$managerId][] = $usersById[$subId]['email'];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Se der erro, continua sem gerentes
        }
    }
    
    // Buscar metas do mês
    $managerIdColumn = $hasManagerColumn ? ", u.manager_id" : "";
    $stmt = $db->prepare("
        SELECT g.*, u.id as user_id, u.email as user_email, u.full_name as user_name, 
               u.nickname, u.profile_picture, u.admin_level, u.profile $managerIdColumn,
               p.id as pkg_id, p.name as package_name, p.type as package_type
        FROM user_goals g
        JOIN users u ON g.user_id = u.id
        JOIN validation_packages p ON g.package_id = p.id
        WHERE g.month = ?
        ORDER BY u.full_name, p.name
    ");
    $stmt->execute([$month]);
    $goals = $stmt->fetchAll();
    
    // Buscar contagem de links aprovados
    $stmt = $db->prepare("
        SELECT r.requested_by as user_email, r.package_id,
               SUM(COALESCE(r.approved_links_count, 0)) as approved_count,
               SUM(COALESCE(JSON_LENGTH(r.content_urls), 0)) as total_submitted
        FROM validation_requests r
        WHERE r.created_at BETWEEN ? AND ?
          AND r.status IN ('aprovado', 'aprovado_parcial')
        GROUP BY r.requested_by, r.package_id
    ");
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    $counts = $stmt->fetchAll();
    
    $countMap = [];
    foreach ($counts as $c) {
        $key = $c['user_email'] . '_' . $c['package_id'];
        $countMap[$key] = [
            'approved' => (int)$c['approved_count'],
            'submitted' => (int)$c['total_submitted']
        ];
    }
    
    // Agrupar por usuário
    $userProgress = [];
    foreach ($goals as $goal) {
        $userId = $goal['user_id'];
        $key = $goal['user_email'] . '_' . $goal['pkg_id'];
        $counts = $countMap[$key] ?? ['approved' => 0, 'submitted' => 0];
        
        if (!isset($userProgress[$userId])) {
            $userProgress[$userId] = [
                'user_id' => $userId,
                'user_email' => $goal['user_email'],
                'user_name' => $goal['user_name'],
                'nickname' => $goal['nickname'],
                'profile_picture' => $goal['profile_picture'],
                'admin_level' => $goal['admin_level'],
                'profile' => $goal['profile'],
                'manager_id' => $hasManagerColumn ? ($goal['manager_id'] ?? null) : null,
                'is_manager' => false,
                'team_members' => [],
                'goals' => [],
                'total_target' => 0,
                'total_achieved' => 0,
            ];
        }
        
        $achieved = $counts['approved'];
        $target = (int)$goal['target_count'];
        if ($target == 0 && $achieved > 0) {
            $target = $achieved;
        }
        $percentage = $target > 0 ? round(($achieved / $target) * 100) : 0;
        
        $userProgress[$userId]['goals'][] = [
            'goal_id' => $goal['id'],
            'package_id' => $goal['pkg_id'],
            'package_name' => $goal['package_name'],
            'package_type' => $goal['package_type'],
            'target' => $target,
            'achieved' => $achieved,
            'percentage' => $percentage,
        ];
        
        $userProgress[$userId]['total_target'] += $target;
        $userProgress[$userId]['total_achieved'] += $achieved;
    }
    
    // Buscar informações de TODOS os membros das equipes (mesmo sem metas)
    $allMemberIds = [];
    foreach ($memberIdsByManager as $mgrId => $ids) {
        $allMemberIds = array_merge($allMemberIds, $ids);
    }
    $allMemberIds = array_unique($allMemberIds);
    
    $memberInfoMap = [];
    if (!empty($allMemberIds)) {
        $placeholders = implode(',', array_fill(0, count($allMemberIds), '?'));
        $stmt = $db->prepare("SELECT id, email, full_name, nickname, profile_picture FROM users WHERE id IN ($placeholders)");
        $stmt->execute(array_values($allMemberIds));
        foreach ($stmt->fetchAll() as $m) {
            $memberInfoMap[$m['id']] = $m;
        }
    }
    
    // Criar entradas para gerentes com metas próprias + equipe combinada
    foreach ($teamByManager as $managerId => $memberEmails) {
        $teamTarget = 0;
        $teamAchieved = 0;
        $teamGoals = [];
        $memberNames = [];
        
        foreach ($memberIdsByManager[$managerId] as $memberId) {
            $memberInfo = $memberInfoMap[$memberId] ?? null;
            if (!$memberInfo) continue;
            
            $memberNames[] = $memberInfo['nickname'] ?: $memberInfo['full_name'];
            
            if (isset($userProgress[$memberId])) {
                $member = $userProgress[$memberId];
                $teamTarget += $member['total_target'];
                $teamAchieved += $member['total_achieved'];
                
                foreach ($member['goals'] as $goal) {
                    $pkgId = $goal['package_id'];
                    if (!isset($teamGoals[$pkgId])) {
                        $teamGoals[$pkgId] = [
                            'package_id' => $pkgId,
                            'package_name' => $goal['package_name'],
                            'package_type' => $goal['package_type'],
                            'target' => 0,
                            'achieved' => 0,
                        ];
                    }
                    $teamGoals[$pkgId]['target'] += $goal['target'];
                    $teamGoals[$pkgId]['achieved'] += $goal['achieved'];
                }
            }
        }
        
        foreach ($teamGoals as &$tg) {
            if ($tg['target'] == 0 && $tg['achieved'] > 0) {
                $tg['target'] = $tg['achieved'];
            }
            $tg['percentage'] = $tg['target'] > 0 ? round(($tg['achieved'] / $tg['target']) * 100) : 0;
        }
        
        if (isset($managerInfo[$managerId]) && count($memberNames) > 0) {
            $info = $managerInfo[$managerId];
            
            // Criar detalhes de cada membro para exibir no card do gerente
            $teamProgress = [];
            foreach ($memberIdsByManager[$managerId] as $memberId) {
                $memberInfo = $memberInfoMap[$memberId] ?? null;
                if (!$memberInfo) continue;
                
                if (isset($userProgress[$memberId])) {
                    $member = $userProgress[$memberId];
                    $memberTarget = $member['total_target'];
                    $memberAchieved = $member['total_achieved'];
                    if ($memberTarget == 0 && $memberAchieved > 0) {
                        $memberTarget = $memberAchieved;
                    }
                    $pct = $memberTarget > 0 
                        ? round(($memberAchieved / $memberTarget) * 100) 
                        : 0;
                    $teamProgress[] = [
                        'user_id' => $memberId,
                        'name' => $member['nickname'] ?: $member['user_name'],
                        'target' => $member['total_target'],
                        'achieved' => $memberAchieved,
                        'percentage' => $pct,
                    ];
                } else {
                    $teamProgress[] = [
                        'user_id' => $memberId,
                        'name' => $memberInfo['nickname'] ?: $memberInfo['full_name'],
                        'target' => 0,
                        'achieved' => 0,
                        'percentage' => 0,
                    ];
                }
            }
            
            // Incluir metas pessoais do gerente (se tiver)
            $managerOwnGoals = [];
            $managerOwnTarget = 0;
            $managerOwnAchieved = 0;
            if (isset($userProgress[$managerId])) {
                $managerOwnGoals = $userProgress[$managerId]['goals'];
                $managerOwnTarget = $userProgress[$managerId]['total_target'];
                $managerOwnAchieved = $userProgress[$managerId]['total_achieved'];
            }
            
            $userProgress['manager_' . $managerId] = [
                'user_id' => $managerId,
                'user_email' => $info['email'],
                'user_name' => $info['full_name'],
                'nickname' => $info['nickname'],
                'profile_picture' => $info['profile_picture'],
                'admin_level' => $info['admin_level'],
                'profile' => $info['profile'],
                'is_manager' => true,
                'team_members' => $memberNames,
                'team_progress' => $teamProgress,
                // Combinar metas pessoais + metas da equipe
                'goals' => array_values($teamGoals),
                'own_goals' => $managerOwnGoals,
                'total_target' => $teamTarget + $managerOwnTarget,
                'total_achieved' => $teamAchieved + $managerOwnAchieved,
            ];
            
            // Remover o card individual do gerente se ele também é um membro com metas
            if (isset($userProgress[$managerId])) {
                unset($userProgress[$managerId]);
            }
        }
    }
    
    // Calcular percentual total
    foreach ($userProgress as &$up) {
        if ($up['total_target'] == 0 && $up['total_achieved'] > 0) {
            $up['total_target'] = $up['total_achieved'];
        }
        $up['total_percentage'] = $up['total_target'] > 0 
            ? round(($up['total_achieved'] / $up['total_target']) * 100) 
            : 0;
    }
    
    // Ordenar por percentual
    usort($userProgress, function($a, $b) {
        return $b['total_percentage'] - $a['total_percentage'];
    });
    
    echo json_encode(array_values($userProgress));
}

function createGoal() {
    $manager = requireManager();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['user_id']) || empty($data['package_id']) || !isset($data['target_count'])) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id, package_id e target_count são obrigatórios']);
        return;
    }
    
    $month = $data['month'] ?? date('Y-m');
    $db = getDB();
    
    $stmt = $db->prepare("
        INSERT INTO user_goals (user_id, package_id, target_count, month, created_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE target_count = VALUES(target_count), created_by = VALUES(created_by)
    ");
    $stmt->execute([
        $data['user_id'],
        $data['package_id'],
        $data['target_count'],
        $month,
        $manager['id']
    ]);
    
    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
}

function updateGoal($id) {
    requireManager();
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID é obrigatório']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['target_count'])) {
        http_response_code(400);
        echo json_encode(['error' => 'target_count é obrigatório']);
        return;
    }
    
    $db = getDB();
    $stmt = $db->prepare("UPDATE user_goals SET target_count = ? WHERE id = ?");
    $stmt->execute([$data['target_count'], $id]);
    
    echo json_encode(['success' => true]);
}

function deleteGoal($id) {
    requireManager();
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID é obrigatório']);
        return;
    }
    
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM user_goals WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
}
