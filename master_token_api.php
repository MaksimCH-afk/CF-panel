<?php
/**
 * Мастер-токен: создаёт «дочерние» API-токены Cloudflare с нужным набором прав,
 * чтобы не кликать пачку токенов вручную в интерфейсе CF.
 * Мастер-токен (с правом «Create Additional Tokens» / API Tokens Write) вводится
 * пользователем в рантайме и НЕ сохраняется.
 */
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');
$userId = $_SESSION['user_id'] ?? 1;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Предустановленный набор прав (UI-метка → имя группы в CF → уровень). */
function masterTokenPreset() {
    return [
        ['key' => 'zone',             'label' => 'Zone (Edit)',                 'cf' => 'Zone Write',                 'level' => 'zone'],
        ['key' => 'dns',              'label' => 'DNS (Edit)',                  'cf' => 'DNS Write',                  'level' => 'zone'],
        ['key' => 'zone_settings',    'label' => 'Zone Settings (Edit)',        'cf' => 'Zone Settings Write',        'level' => 'zone'],
        ['key' => 'ssl',              'label' => 'SSL and Certificates (Edit)', 'cf' => 'SSL and Certificates Write', 'level' => 'zone'],
        ['key' => 'cache_purge',      'label' => 'Cache Purge',                 'cf' => 'Cache Purge',                'level' => 'zone'],
        ['key' => 'firewall',         'label' => 'Firewall Services (Edit)',    'cf' => 'Firewall Services Write',    'level' => 'zone'],
        ['key' => 'page_rules',       'label' => 'Page Rules (Edit)',           'cf' => 'Page Rules Write',           'level' => 'zone'],
        ['key' => 'workers_routes',   'label' => 'Workers Routes (Edit)',       'cf' => 'Workers Routes Write',       'level' => 'zone'],
        ['key' => 'workers_scripts',  'label' => 'Workers Scripts (Edit)',      'cf' => 'Workers Scripts Write',      'level' => 'account'],
        ['key' => 'account_analytics','label' => 'Account Analytics (Read)',     'cf' => 'Account Analytics Read',     'level' => 'account'],
        ['key' => 'zone_waf',         'label' => 'Zone WAF (Edit)',             'cf' => 'Zone WAF Write',             'level' => 'zone'],
        ['key' => 'analytics',        'label' => 'Analytics (Read)',            'cf' => 'Analytics Read',             'level' => 'zone'],
        // У Single Redirect имя группы в CF отличается от UI-метки. Несколько кандидатов
        // + fuzzy-поиск (имя должно содержать оба слова: 'redirect' и 'write').
        // ВАЖНО: НЕ матчим 'Transform Rules Write' — она не грантится на ресурс «все зоны»
        // и роняет создание всего токена.
        ['key' => 'single_redirect',  'label' => 'Single Redirect (Edit)',
         'cf' => ['Single Redirect Write', 'Dynamic URL Redirect Write', 'Dynamic Redirects Write', 'Dynamic Redirect Write'],
         'match' => ['redirect', 'write'], 'level' => 'zone'],
    ];
}

/** Находит id группы права в списке CF: сперва по точным кандидатам, затем fuzzy по ключевым словам. */
function matchPermissionGroupId($preset, $byName, $allGroups) {
    foreach ((array)($preset['cf'] ?? []) as $cand) {
        $k = mb_strtolower($cand);
        if (isset($byName[$k])) return $byName[$k];
    }
    if (!empty($preset['match'])) {
        foreach ($allGroups as $g) {
            $n = mb_strtolower($g['name']);
            $ok = true;
            foreach ($preset['match'] as $kw) {
                if (mb_strpos($n, mb_strtolower($kw)) === false) { $ok = false; break; }
            }
            if ($ok) return $g['id'];
        }
    }
    return null;
}

/** Прямой вызов CF API мастер-токеном (отдельно от cloudflareApiRequest — там логика аккаунтов панели). */
function cfMasterApi($token, $method, $path, $body = null) {
    $ch = curl_init("https://api.cloudflare.com/client/v4/$path");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    curl_close($ch);
    $r = json_decode($raw, true);
    return is_array($r) ? $r : ['success' => false, 'errors' => [['message' => 'нет ответа от Cloudflare']]];
}
function cfErr($r) { return $r['errors'][0]['message'] ?? 'неизвестная ошибка'; }

/** Возвращает значение мастер-токена: из сохранённого (master_id) или введённого (master_token). */
function resolveMasterToken($pdo) {
    $mid = trim($_POST['master_id'] ?? '');
    if ($mid !== '' && ctype_digit($mid)) {
        $st = $pdo->prepare("SELECT token FROM master_tokens WHERE id = ?");
        $st->execute([$mid]);
        $t = $st->fetchColumn();
        if ($t) return $t;
    }
    return trim($_POST['master_token'] ?? '');
}

try {
    switch ($action) {
        case 'list_permissions':
            echo json_encode(['success' => true, 'preset' => masterTokenPreset()]);
            break;

        case 'create':
            $master   = resolveMasterToken($pdo);
            $name     = trim($_POST['name'] ?? '');
            $selected = $_POST['perms'] ?? [];
            if (!is_array($selected)) $selected = [];
            if ($master === '')   throw new Exception('Укажите мастер-токен');
            if (empty($selected)) throw new Exception('Выберите хотя бы одно право');
            if ($name === '')     $name = 'panel-token-' . date('Ymd-His');

            // 1) Проверяем мастер-токен и получаем список групп прав (name -> id)
            $pg = cfMasterApi($master, 'GET', 'user/tokens/permission_groups');
            if (empty($pg['success'])) {
                throw new Exception('Мастер-токен недействителен или у него нет права «Create Additional Tokens»: ' . cfErr($pg));
            }
            $byName = [];
            foreach ($pg['result'] as $g) $byName[mb_strtolower($g['name'])] = $g['id'];

            $byKey = [];
            foreach (masterTokenPreset() as $p) $byKey[$p['key']] = $p;

            $zoneGroups = [];
            $accountGroups = [];
            $missing = [];
            foreach ($selected as $key) {
                if (!isset($byKey[$key])) continue;
                $p  = $byKey[$key];
                $id = matchPermissionGroupId($p, $byName, $pg['result']);
                if (!$id) { $missing[] = $p['label']; continue; }
                if ($p['level'] === 'account') $accountGroups[] = ['id' => $id];
                else                            $zoneGroups[]    = ['id' => $id];
            }
            if (empty($zoneGroups) && empty($accountGroups)) {
                throw new Exception('Не удалось сопоставить выбранные права с группами Cloudflare: ' . implode(', ', $missing));
            }

            // 2) Политики: zone-права на ВСЕ зоны, account-права на ВСЕ аккаунты
            $policies = [];
            if ($zoneGroups) {
                $policies[] = ['effect' => 'allow', 'resources' => ['com.cloudflare.api.account.zone.*' => '*'], 'permission_groups' => $zoneGroups];
            }
            if ($accountGroups) {
                $policies[] = ['effect' => 'allow', 'resources' => ['com.cloudflare.api.account.*' => '*'], 'permission_groups' => $accountGroups];
            }

            // 3) Создаём токен
            $res = cfMasterApi($master, 'POST', 'user/tokens', ['name' => $name, 'policies' => $policies]);
            if (empty($res['success'])) {
                logAction($pdo, $userId, 'Master Token: ошибка создания', cfErr($res) . " | права: " . implode(',', $selected));
                throw new Exception('Cloudflare отклонил создание токена: ' . cfErr($res));
            }

            logAction($pdo, $userId, 'Master Token: создан токен', "Имя: {$name}, прав: " . count($selected) . ($missing ? "; не найдены: " . implode(', ', $missing) : ''));

            // Если создавали из СОХРАНЁННОГО мастера — подтянем домены новым child-токеном
            // (у него есть Zone Read) и привяжем подсказку к мастеру. Сам мастер зоны не видит.
            $mid = trim($_POST['master_id'] ?? '');
            $newToken = $res['result']['value'] ?? null;
            if ($mid !== '' && ctype_digit($mid) && $newToken) {
                $z = cfMasterApi($newToken, 'GET', 'zones?per_page=50');
                if (!empty($z['success'])) {
                    $names = array_map(function ($zone) { return $zone['name']; }, $z['result']);
                    $total = $z['result_info']['total_count'] ?? count($names);
                    $hint = $total . ' доменов: ' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? '…' : '');
                    $pdo->prepare("UPDATE master_tokens SET domains_hint = ? WHERE id = ?")->execute([$hint, $mid]);
                }
            }

            echo json_encode([
                'success' => true,
                'token'   => $newToken,
                'id'      => $res['result']['id'] ?? null,
                'name'    => $name,
                'missing' => $missing,
            ]);
            break;

        case 'add_master':
            $tok   = trim($_POST['master_token'] ?? '');
            $label = trim($_POST['label'] ?? '');
            if ($tok === '') throw new Exception('Вставьте мастер-токен');
            // Проверяем валидность токена
            $v = cfMasterApi($tok, 'GET', 'user/tokens/verify');
            if (empty($v['success'])) throw new Exception('Токен недействителен: ' . cfErr($v));
            // Пытаемся узнать email аккаунта (если у токена есть доступ — иначе пусто)
            $email = '';
            $u = cfMasterApi($tok, 'GET', 'user');
            if (!empty($u['success'])) $email = $u['result']['email'] ?? '';
            if ($label === '') $label = $email ?: ('master-' . date('Ymd-His'));
            $pdo->prepare("INSERT INTO master_tokens (label, token, account_email) VALUES (?, ?, ?)")->execute([$label, $tok, $email]);
            logAction($pdo, $userId, 'Master Token: сохранён мастер', "label: {$label}");
            echo json_encode(['success' => true]);
            break;

        case 'list_masters':
            $rows = $pdo->query("SELECT id, label, account_email, domains_hint, token FROM master_tokens ORDER BY id DESC")->fetchAll();
            $out = array_map(function ($r) {
                return [
                    'id' => $r['id'],
                    'label' => $r['label'],
                    'email' => $r['account_email'],
                    'domains_hint' => $r['domains_hint'],
                    'masked' => mb_substr($r['token'], 0, 10) . '…' . mb_substr($r['token'], -4),
                ];
            }, $rows);
            echo json_encode(['success' => true, 'masters' => $out]);
            break;

        case 'delete_master':
            $mid = trim($_POST['id'] ?? '');
            if ($mid === '' || !ctype_digit($mid)) throw new Exception('Не указан id');
            $pdo->prepare("DELETE FROM master_tokens WHERE id = ?")->execute([$mid]);
            echo json_encode(['success' => true]);
            break;

        case 'list_groups':
            // DEBUG: показать группы прав, относящиеся к редиректам/трансформам —
            // чтобы найти точное имя группы Single Redirect в этом аккаунте.
            $master = resolveMasterToken($pdo);
            if ($master === '') throw new Exception('Укажите мастер-токен');
            $pg = cfMasterApi($master, 'GET', 'user/tokens/permission_groups');
            if (empty($pg['success'])) throw new Exception('Не удалось получить группы: ' . cfErr($pg));
            $kw = ['redirect', 'transform', 'url', 'single', 'rule'];
            $found = [];
            foreach ($pg['result'] as $g) {
                $n = mb_strtolower($g['name']);
                foreach ($kw as $k) {
                    if (mb_strpos($n, $k) !== false) {
                        $found[] = ['name' => $g['name'], 'scopes' => $g['scopes'] ?? []];
                        break;
                    }
                }
            }
            echo json_encode(['success' => true, 'total' => count($pg['result']), 'matched' => $found]);
            break;

        case 'list_tokens':
            $master = resolveMasterToken($pdo);
            if ($master === '') throw new Exception('Укажите мастер-токен');
            $res = cfMasterApi($master, 'GET', 'user/tokens?per_page=50');
            if (empty($res['success'])) throw new Exception('Не удалось получить список токенов: ' . cfErr($res));
            $tokens = [];
            foreach ($res['result'] as $t) {
                // Собираем имена групп прав по всем политикам токена
                $perms = [];
                foreach ($t['policies'] ?? [] as $pol) {
                    foreach ($pol['permission_groups'] ?? [] as $g) {
                        if (!empty($g['name'])) $perms[$g['name']] = true;
                    }
                }
                $tokens[] = [
                    'id'      => $t['id'],
                    'name'    => $t['name'] ?? '(без имени)',
                    'status'  => $t['status'] ?? '',
                    'perms'   => array_keys($perms),
                    'count'   => count($perms),
                ];
            }
            echo json_encode(['success' => true, 'tokens' => $tokens]);
            break;

        case 'delete_token':
            $master = resolveMasterToken($pdo);
            $tokenId = trim($_POST['token_id'] ?? '');
            if ($master === '')  throw new Exception('Укажите мастер-токен');
            if ($tokenId === '') throw new Exception('Не указан id токена');
            $res = cfMasterApi($master, 'DELETE', 'user/tokens/' . rawurlencode($tokenId));
            if (empty($res['success'])) throw new Exception('Не удалось удалить токен: ' . cfErr($res));
            logAction($pdo, $userId, 'Master Token: удалён токен', "id: {$tokenId}");
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
