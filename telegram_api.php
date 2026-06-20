<?php
/**
 * Telegram — настройки и отправка. Инфраструктура готова, но к событиям пока
 * НЕ подключена (триггеры добавим отдельно). Bot API: sendMessage напрямую,
 * без сторонних сервисов — только curl к api.telegram.org.
 */
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');
$userId = $_SESSION['user_id'] ?? 1;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function tgSetSetting($pdo, $k, $v) {
    $pdo->prepare("INSERT INTO app_settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$k, $v]);
}

try {
    switch ($action) {
        case 'get':
            $s = tgGetSettings($pdo);
            $masked = $s['bot_token'] ? (mb_substr($s['bot_token'], 0, 10) . '…' . mb_substr($s['bot_token'], -4)) : '';
            $alertKeys = ['offline', 'expiry', 'ns', 'ip', 'token', 'zone', 'queue'];
            $alerts = [];
            foreach ($alertKeys as $k) $alerts[$k] = appGetSetting($pdo, 'tg_alert_' . $k, '1') === '1';
            echo json_encode([
                'success' => true,
                'has_token' => !empty($s['bot_token']),
                'bot_token_masked' => $masked,
                'chat_id' => $s['chat_id'],
                'alerts' => $alerts,
                'interval_hours' => appGetSetting($pdo, 'tg_monitor_interval_hours', '12'),
                'batch' => appGetSetting($pdo, 'tg_monitor_batch', '8'),
                'last_digest' => appGetSetting($pdo, 'tg_last_digest', ''),
            ]);
            break;

        case 'save':
            $bot  = trim($_POST['bot_token'] ?? '');
            $chat = trim($_POST['chat_id'] ?? '');
            // Пустой bot_token при сохранении = оставить прежний (чтобы не затирать маской)
            if ($bot !== '') tgSetSetting($pdo, 'telegram_bot_token', $bot);
            tgSetSetting($pdo, 'telegram_chat_id', $chat);
            // Тумблеры категорий
            foreach (['offline', 'expiry', 'ns', 'ip', 'token', 'zone', 'queue'] as $k) {
                tgSetSetting($pdo, 'tg_alert_' . $k, (isset($_POST['alert_' . $k]) && $_POST['alert_' . $k] === '1') ? '1' : '0');
            }
            // Настройки мониторинга
            $ih = (float)($_POST['interval_hours'] ?? 12); if ($ih < 0.25) $ih = 0.25;
            $bt = (int)($_POST['batch'] ?? 8); if ($bt < 1) $bt = 1; if ($bt > 50) $bt = 50;
            tgSetSetting($pdo, 'tg_monitor_interval_hours', (string)$ih);
            tgSetSetting($pdo, 'tg_monitor_batch', (string)$bt);
            logAction($pdo, $userId, 'Telegram: настройки сохранены', "chat_id: {$chat}");
            echo json_encode(['success' => true]);
            break;

        case 'test':
            // Разрешаем протестировать введённые значения ещё до «Сохранить»
            $bot  = trim($_POST['bot_token'] ?? '');
            $chat = trim($_POST['chat_id'] ?? '');
            if ($bot !== '')  tgSetSetting($pdo, 'telegram_bot_token', $bot);
            if ($chat !== '') tgSetSetting($pdo, 'telegram_chat_id', $chat);
            $r = tgSendMessage($pdo, "✅ <b>CloudPanel</b>: тестовое сообщение.\nTelegram-оповещения подключены.");
            echo json_encode(['success' => $r['ok'], 'error' => $r['error'] ?? null]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
