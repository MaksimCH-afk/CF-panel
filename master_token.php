<?php
$pageTitle = 'Мастер-токен';
require_once 'header.php';
include 'sidebar.php';
?>
<div class="content">
    <div class="content-header">
        <h1><i class="fas fa-key me-2"></i>Мастер-токен — генератор API-токенов</h1>
        <p class="text-muted mb-0">Создаёт «дочерние» токены Cloudflare с нужным набором прав. Не нужно кликать пачку токенов вручную.</p>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><i class="fas fa-wand-magic-sparkles me-2"></i>Создать токен</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Мастер-токен <span class="text-muted small">(с правом «Create Additional Tokens»)</span></label>
                        <input type="password" id="masterToken" class="form-control" placeholder="cf...   — вставьте мастер-токен" autocomplete="off">
                        <div class="form-text">Используется только для запроса к Cloudflare и <strong>не сохраняется</strong> в панели.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Имя нового токена</label>
                        <input type="text" id="tokenName" class="form-control" placeholder="panel-token-… (если пусто — добавится дата)">
                    </div>

                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span>Права нового токена</span>
                        <span>
                            <button type="button" class="btn btn-link btn-sm p-0 me-2" onclick="toggleAllPerms(true)">все</button>
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllPerms(false)">ничего</button>
                        </span>
                    </label>
                    <div id="permsList" class="border rounded p-2 mb-3" style="max-height: 320px; overflow-y:auto;">
                        <div class="text-muted small">Загрузка прав…</div>
                    </div>

                    <button class="btn btn-primary w-100" id="createBtn" onclick="createToken()">
                        <i class="fas fa-key me-2"></i>Создать токен
                    </button>
                    <button class="btn btn-outline-secondary btn-sm w-100 mt-2" onclick="debugGroups()">
                        <i class="fas fa-magnifying-glass me-1"></i>Показать группы прав «redirect» (debug)
                    </button>
                    <div id="debugGroupsOut" class="small mt-2"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3" id="resultCard" style="display:none;">
                <div class="card-header text-success"><i class="fas fa-circle-check me-2"></i>Токен создан</div>
                <div class="card-body">
                    <div class="alert alert-warning small"><i class="fas fa-triangle-exclamation me-1"></i>Скопируйте токен сейчас — Cloudflare показывает значение только один раз.</div>
                    <label class="form-label small mb-1">Значение токена</label>
                    <div class="input-group mb-2">
                        <input type="text" id="newToken" class="form-control font-monospace" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyToken()"><i class="fas fa-copy"></i></button>
                    </div>
                    <div id="missingWarn" class="small text-warning"></div>
                    <a href="dashboard.php" class="btn btn-outline-primary btn-sm mt-2"><i class="fas fa-plus me-1"></i>Добавить аккаунт с этим токеном</a>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="fas fa-circle-info me-2"></i>Как это работает</div>
                <div class="card-body small text-muted">
                    <ol class="ps-3 mb-2">
                        <li>В Cloudflare создайте токен по шаблону <strong>«Create Additional Tokens»</strong> (право API Tokens → Edit) — это и есть мастер-токен.</li>
                        <li>Вставьте его сюда, отметьте нужные права, нажмите «Создать токен».</li>
                        <li>Панель сама подтянет ID групп прав и создаст токен на <strong>все зоны и аккаунты</strong>.</li>
                    </ol>
                    Zone-права применяются ко всем зонам, account-права (Workers Scripts, Account Analytics) — ко всем аккаунтам.
                </div>
            </div>
        </div>
    </div>

    <!-- Управление существующими токенами -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list me-2"></i>Существующие токены</span>
            <button class="btn btn-outline-secondary btn-sm" onclick="loadTokens()"><i class="fas fa-rotate me-1"></i>Загрузить список</button>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">Список токенов мастер-аккаунта. Можно удалить лишние/неполные и создать правильный сверху. Удаление необратимо.</p>
            <div id="tokensList"><div class="text-muted small">Введите мастер-токен и нажмите «Загрузить список».</div></div>
        </div>
    </div>
</div>

<!-- jQuery нужен для AJAX (как в Security Manager); footer.php грузит только Bootstrap -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
let PRESET = [];
function loadPerms() {
    $.get('master_token_api.php', { action: 'list_permissions' }, function(r) {
        if (!r.success) { $('#permsList').html('<div class="text-danger small">Ошибка загрузки</div>'); return; }
        PRESET = r.preset;
        let html = '';
        r.preset.forEach(function(p) {
            const lvl = p.level === 'account' ? '<span class="badge bg-secondary ms-1">account</span>' : '<span class="badge bg-light text-dark ms-1">zone</span>';
            html += `<div class="form-check">
                <input class="form-check-input perm-cb" type="checkbox" value="${p.key}" id="perm_${p.key}" checked>
                <label class="form-check-label" for="perm_${p.key}">${p.label} ${lvl}</label>
            </div>`;
        });
        $('#permsList').html(html);
    }, 'json');
}
function toggleAllPerms(on) { $('.perm-cb').prop('checked', on); }
function debugGroups() {
    const master = $('#masterToken').val().trim();
    if (!master) { showToast('Вставьте мастер-токен сверху', 'warning'); return; }
    $('#debugGroupsOut').html('<i class="fas fa-spinner fa-spin"></i> Загрузка…');
    $.post('master_token_api.php', { action: 'list_groups', master_token: master }, function(r) {
        if (!r.success) { $('#debugGroupsOut').html('<span class="text-danger">' + (r.error || 'ошибка') + '</span>'); return; }
        if (!r.matched.length) { $('#debugGroupsOut').html('<span class="text-muted">Групп с redirect/transform/url не найдено (всего групп: ' + r.total + ')</span>'); return; }
        let html = '<div class="border rounded p-2 bg-light"><b>Найдены группы (всего ' + r.total + '):</b><ul class="mb-0 ps-3">';
        r.matched.forEach(function(g){ html += '<li><code>' + $('<div>').text(g.name).html() + '</code> <span class="text-muted">[' + (g.scopes||[]).join(', ') + ']</span></li>'; });
        html += '</ul></div>';
        $('#debugGroupsOut').html(html);
    }, 'json').fail(function(){ $('#debugGroupsOut').html('<span class="text-danger">ошибка соединения</span>'); });
}
function copyToken() {
    const el = document.getElementById('newToken');
    el.select(); document.execCommand('copy');
    showToast('Токен скопирован', 'success');
}
function createToken() {
    const master = $('#masterToken').val().trim();
    if (!master) { showToast('Вставьте мастер-токен', 'warning'); return; }
    const perms = $('.perm-cb:checked').map(function(){ return this.value; }).get();
    if (!perms.length) { showToast('Выберите хотя бы одно право', 'warning'); return; }
    const $btn = $('#createBtn');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Создаём…');
    $.ajax({
        url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 40000,
        data: { action: 'create', master_token: master, name: $('#tokenName').val().trim(), perms: perms }
    }).done(function(r) {
        if (r.success) {
            $('#newToken').val(r.token || '');
            $('#missingWarn').html(r.missing && r.missing.length ? ('Не найдены группы: ' + r.missing.join(', ')) : '');
            $('#resultCard').show();
            showToast('Токен создан', 'success');
        } else {
            showToast('Ошибка: ' + (r.error || 'unknown'), 'error');
        }
    }).fail(function(x, st){
        showToast(st === 'timeout' ? 'Таймаут запроса к Cloudflare' : 'Ошибка соединения', 'error');
    }).always(function(){
        $btn.prop('disabled', false).html('<i class="fas fa-key me-2"></i>Создать токен');
    });
}
function loadTokens() {
    const master = $('#masterToken').val().trim();
    if (!master) { showToast('Сначала вставьте мастер-токен сверху', 'warning'); return; }
    $('#tokensList').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Загрузка…</div>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 30000,
        data: { action: 'list_tokens', master_token: master } })
    .done(function(r) {
        if (!r.success) { $('#tokensList').html('<div class="text-danger small">' + (r.error || 'Ошибка') + '</div>'); return; }
        if (!r.tokens.length) { $('#tokensList').html('<div class="text-muted small">Токенов нет.</div>'); return; }
        let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Имя</th><th>Права</th><th>Статус</th><th></th></tr></thead><tbody>';
        r.tokens.forEach(function(t) {
            const permsTitle = (t.perms || []).join(', ').replace(/"/g, '&quot;');
            const badge = t.status === 'active' ? 'success' : 'secondary';
            html += `<tr>
                <td class="font-monospace small">${$('<div>').text(t.name).html()}</td>
                <td><span class="badge bg-light text-dark" title="${permsTitle}">${t.count} прав</span></td>
                <td><span class="badge bg-${badge}">${t.status}</span></td>
                <td class="text-end"><button class="btn btn-outline-danger btn-sm" onclick="deleteToken('${t.id}', this)"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        $('#tokensList').html(html);
    })
    .fail(function(x, st){ $('#tokensList').html('<div class="text-danger small">' + (st === 'timeout' ? 'Таймаут' : 'Ошибка соединения') + '</div>'); });
}
function deleteToken(id, btn) {
    if (!confirm('Удалить этот токен безвозвратно? Все интеграции на нём перестанут работать.')) return;
    const master = $('#masterToken').val().trim();
    $(btn).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 20000,
        data: { action: 'delete_token', master_token: master, token_id: id } })
    .done(function(r) {
        if (r.success) { showToast('Токен удалён', 'success'); loadTokens(); }
        else { showToast('Ошибка: ' + (r.error || 'unknown'), 'error'); $(btn).prop('disabled', false).html('<i class="fas fa-trash"></i>'); }
    })
    .fail(function(){ showToast('Ошибка соединения', 'error'); $(btn).prop('disabled', false).html('<i class="fas fa-trash"></i>'); });
}
$(document).ready(loadPerms);
</script>
<?php include 'footer.php'; ?>
