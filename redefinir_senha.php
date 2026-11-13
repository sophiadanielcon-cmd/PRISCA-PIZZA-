<?php
/**
 * Prisca Pizza - Redefinição Segura de Senha
 * Atualiza o hash com bcrypt e invalida o token utilizado
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/bd.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = getDbConnection();
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$erro = null;
$sucesso = null;
$tokenValido = false;
$usuarioRecuperacao = null;

if (empty($token)) {
    $erro = 'Token de recuperação não informado ou inválido.';
} else {
    // Valida token e validade no banco
    $agora = date('Y-m-d H:i:s');
    $stmtCheck = $pdo->prepare("
        SELECT r.*, u.nome, u.email as usuario_email 
        FROM recuperacao_senha r
        JOIN usuarios u ON r.usuario_id = u.id
        WHERE r.token = :token AND r.usado = 0 AND r.expira_em >= :agora
        LIMIT 1
    ");
    $stmtCheck->execute([':token' => $token, ':agora' => $agora]);
    $recuperacao = $stmtCheck->fetch();

    if ($recuperacao) {
        $tokenValido = true;
        $usuarioRecuperacao = $recuperacao;
    } else {
        $erro = 'Este link de recuperação expirou ou já foi utilizado. Por favor, solicite uma nova redefinição.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValido) {
    $novaSenha = $_POST['nova_senha'] ?? '';
    $confirmaSenha = $_POST['confirma_senha'] ?? '';

    if (strlen($novaSenha) < 6) {
        $erro = 'A nova senha deve ter no mínimo 6 caracteres.';
    } elseif ($novaSenha !== $confirmaSenha) {
        $erro = 'A confirmação de senha não confere.';
    } else {
        try {
            // Hashing seguro com bcrypt (Regra de Negócio CORE)
            $novoHash = password_hash($novaSenha, PASSWORD_BCRYPT);

            // Atualiza senha do usuário e reseta bloqueios por brute force
            $stmtUpdate = $pdo->prepare("
                UPDATE usuarios 
                SET senha = :senha, tentativas_login = 0, bloqueado_ate = NULL 
                WHERE id = :uid
            ");
            $stmtUpdate->execute([
                ':senha' => $novoHash,
                ':uid' => $usuarioRecuperacao['usuario_id']
            ]);

            // Invalida o token de recuperação (uso único)
            $stmtUse = $pdo->prepare("UPDATE recuperacao_senha SET usado = 1 WHERE id = :id");
            $stmtUse->execute([':id' => $usuarioRecuperacao['id']]);

            // Registra auditoria (sem expor senhas)
            registrarAuditoria(
                null,
                'senha_redefinida',
                null,
                null,
                "Senha redefinida com sucesso para o usuário {$usuarioRecuperacao['nome']} ({$usuarioRecuperacao['usuario_email']}) via token seguro."
            );

            setToast('success', 'Senha Redefinida!', 'Sua nova senha foi gravada com sucesso. Faça login agora!');
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        } catch (Exception $e) {
            $erro = 'Erro interno ao redefinir a senha: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Criar Nova Senha - ' . APP_NAME;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<main class="max-w-md mx-auto px-4 sm:px-6 py-12 flex-1 w-full flex flex-col justify-center">

    <div class="text-center mb-8">
        <div class="w-14 h-14 rounded-2xl bg-rose-600 text-white flex items-center justify-center text-2xl mx-auto mb-3 shadow-lg shadow-rose-600/30">
            <i class="fa-solid fa-lock"></i>
        </div>
        <h1 class="text-2xl font-extrabold text-slate-900 font-serif">Redefinir sua Senha</h1>
        <p class="text-xs text-slate-500 mt-1">Digite sua nova senha de acesso à Prisca Pizza</p>
    </div>

    <?php if ($erro && !$tokenValido): ?>
        <div class="p-6 rounded-3xl bg-rose-50 border border-rose-200 text-rose-800 text-xs space-y-4 text-center">
            <div class="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center text-xl mx-auto">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <h4 class="font-bold text-sm text-rose-900">Link Expirado ou Inválido</h4>
                <p class="mt-1 leading-relaxed"><?= e($erro) ?></p>
            </div>
            <a href="<?= BASE_URL ?>/recuperar_senha.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs uppercase tracking-wider transition-colors shadow">
                <i class="fa-solid fa-arrows-rotate"></i> Solicitar Novo Link
            </a>
        </div>
    <?php else: ?>

        <?php if ($erro): ?>
            <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-start gap-3">
                <i class="fa-solid fa-triangle-exclamation text-base mt-0.5 shrink-0"></i>
                <span><?= e($erro) ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-200/80">
            <div class="mb-4 pb-3 border-b border-slate-100 text-xs text-slate-600">
                Redefinindo senha para: <strong class="text-slate-900"><?= e($usuarioRecuperacao['nome']) ?></strong> (<?= e($usuarioRecuperacao['usuario_email']) ?>)
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Nova Senha <span class="text-rose-600">*</span>
                    </label>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="password" name="nova_senha" required placeholder="Mínimo 6 caracteres" class="w-full pl-9 pr-3.5 py-2.5 text-xs rounded-xl border border-slate-200 focus:ring-2 focus:ring-rose-500 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Confirmar Nova Senha <span class="text-rose-600">*</span>
                    </label>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="password" name="confirma_senha" required placeholder="Repita a nova senha" class="w-full pl-9 pr-3.5 py-2.5 text-xs rounded-xl border border-slate-200 focus:ring-2 focus:ring-rose-500 outline-none">
                    </div>
                </div>

                <button type="submit" class="w-full py-3.5 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs uppercase tracking-wider shadow-lg shadow-rose-600/30 transition-all">
                    Gravar Nova Senha
                </button>
            </form>
        </div>

    <?php endif; ?>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
