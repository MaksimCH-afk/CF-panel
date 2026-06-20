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
$(document).ready(loadPerms);
</script>
<?php include 'footer.php'; ?>
