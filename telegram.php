<?php
$pageTitle = 'Telegram';
require_once 'header.php';
include 'sidebar.php';
?>
<div class="content">
    <div class="content-header">
        <h1><i class="fab fa-telegram me-2"></i>Telegram-оповещения</h1>
        <p class="text-muted mb-0">Бот шлёт уведомления напрямую через Telegram Bot API (без сторонних сервисов).</p>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-gear me-2"></i>Настройки</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Bot Token</label>
                        <input type="password" id="botToken" class="form-control" placeholder="123456:ABC-DEF… (оставьте пустым, чтобы не менять)" autocomplete="off">
                        <div class="form-text" id="tokenState"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Chat ID</label>
                        <input type="text" id="chatId" class="form-control" placeholder="напр. 123456789 или -1001234567890 (канал/группа)">
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" onclick="saveTg()"><i class="fas fa-save me-1"></i>Сохранить</button>
                        <button class="btn btn-outline-secondary" onclick="testTg()"><i class="fas fa-paper-plane me-1"></i>Отправить тест</button>
                    </div>
                    <div class="alert alert-secondary small mt-3 mb-0">
                        <strong>Триггеры пока не подключены</strong> — это только инфраструктура (бот + тест). Куда вешать оповещения, обсудим отдельно.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-circle-info me-2"></i>Как получить</div>
                <div class="card-body small">
                    <p class="mb-2"><strong>Bot Token:</strong> напишите <code>@BotFather</code> → <code>/newbot</code> → получите токен вида <code>123456:ABC…</code>.</p>
                    <p class="mb-2"><strong>Chat ID:</strong> напишите своему боту любое сообщение, затем откройте<br><code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> — там в <code>chat.id</code> будет ваш ID. Для канала/группы добавьте бота админом, ID начинается с <code>-100…</code>.</p>
                    <p class="mb-0 text-muted">Токен хранится в БД панели (gitignored), сообщения шлёт сам сервер через <code>api.telegram.org</code>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function loadTg() {
    $.get('telegram_api.php', { action: 'get' }, function(r) {
        if (!r.success) return;
        $('#tokenState').text(r.has_token ? ('Токен сохранён: ' + r.bot_token_masked) : 'Токен не задан');
        $('#chatId').val(r.chat_id || '');
    }, 'json');
}
function saveTg() {
    $.post('telegram_api.php', { action: 'save', bot_token: $('#botToken').val().trim(), chat_id: $('#chatId').val().trim() }, function(r) {
        if (r.success) { showToast('Сохранено', 'success'); $('#botToken').val(''); loadTg(); }
        else showToast('Ошибка: ' + (r.error || ''), 'error');
    }, 'json');
}
function testTg() {
    $.post('telegram_api.php', { action: 'test', bot_token: $('#botToken').val().trim(), chat_id: $('#chatId').val().trim() }, function(r) {
        if (r.success) showToast('Тестовое сообщение отправлено', 'success');
        else showToast('Не отправлено: ' + (r.error || ''), 'error');
    }, 'json');
}
$(document).ready(loadTg);
</script>
<?php include 'footer.php'; ?>
