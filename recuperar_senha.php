<?php
/**
 * Prisca Pizza - Recuperação de Senha Segura
 * Regra: "O processo de recuperação de senha deve utilizar um mecanismo seguro de redefinição, sem revelar a senha antiga."
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/bd.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$mensagemSucesso = null;
$resetLink = null;
$erro = null;

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/cardapio.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Por favor, informe um endereço de e-mail válido.';
    } else {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Gera token criptográfico seguro de 64 caracteres hexadecimais
            $token = bin2hex(random_bytes(32));
            $expiraEm = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

            // Invalida tokens anteriores não utilizados do usuário
            $stmtInvalidate = $pdo->prepare("UPDATE recuperacao_senha SET usado = 1 WHERE usuario_id = :uid AND usado = 0");
            $stmtInvalidate->execute([':uid' => $user['id']]);

            // Salva novo token
            $stmtInsert = $pdo->prepare("INSERT INTO recuperacao_senha (usuario_id, email, token, expira_em, usado) VALUES (:uid, :email, :token, :expira, 0)");
            $stmtInsert->execute([
                ':uid' => $user['id'],
                ':email' => $user['email'],
                ':token' => $token,
                ':expira' => $expiraEm
            ]);

            // Registra na auditoria (sem expor senhas)
            registrarAuditoria(
                null,
                'recuperacao_solicitada',
                null,
                null,
                "Solicitação de redefinição de senha para o e-mail: {$email}. Token gerado com validade de 1 hora."
            );

            $resetLink = BASE_URL . '/redefinir_senha.php?token=' . urlencode($token);
            $mensagemSucesso = "Um link seguro de redefinição de senha foi gerado com validade de 1 hora.";
        } else {
            // Resposta genérica para evitar enumeração de contas, mas registra em log
            registrarAuditoria(
                null,
                'recuperacao_tentativa_invalida',
                null,
                null,
                "Tentativa de recuperação para e-mail não cadastrado: {$email}"
            );
            $mensagemSucesso = "Se este e-mail estiver cadastrado em nossa base, as instruções para redefinição foram enviadas.";
        }
    }
}

$pageTitle = 'Recuperar Senha - ' . APP_NAME;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<main class="max-w-md mx-auto px-4 sm:px-6 py-12 flex-1 w-full flex flex-col justify-center">

    <div class="text-center mb-8">
        <div class="w-14 h-14 rounded-2xl bg-slate-900 text-white flex items-center justify-center text-2xl mx-auto mb-3 shadow-lg shadow-slate-900/20">
            <i class="fa-solid fa-key text-rose-500"></i>
        </div>
        <h1 class="text-2xl font-extrabold text-slate-900 font-serif">Recuperação de Senha</h1>
        <p class="text-xs text-slate-500 mt-1">Redefina sua senha de forma 100% segura sem expor dados confidenciais</p>
    </div>

    <?php if ($erro): ?>
        <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-start gap-3">
            <i class="fa-solid fa-triangle-exclamation text-base mt-0.5 shrink-0"></i>
            <span><?= e($erro) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($mensagemSucesso): ?>
        <div class="mb-6 p-5 rounded-3xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs space-y-3">
            <div class="flex items-center gap-2 font-bold text-sm text-emerald-800">
                <i class="fa-solid fa-circle-check text-emerald-600"></i> Solicitação Processada!
            </div>
            <p><?= e($mensagemSucesso) ?></p>

            <?php if ($resetLink): ?>
                <div class="pt-2 border-t border-emerald-200/70 space-y-2">
                    <span class="block font-bold text-[11px] uppercase tracking-wider text-emerald-800">Simulação de Envio de E-mail (Ambiente Local):</span>
                    <div class="p-3 bg-white rounded-xl border border-emerald-200 break-all font-mono text-[11px] text-slate-700">
                        <?= e($resetLink) ?>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2 pt-1">
                        <a href="<?= e($resetLink) ?>" class="flex-1 py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-center text-xs uppercase tracking-wider transition-colors shadow">
                            <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Abrir Link de Redefinição
                        </a>
                        <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($resetLink) ?>'); showToast('success', 'Copiado!', 'Link de redefinição copiado.')" class="py-2.5 px-3 rounded-xl border border-emerald-300 text-emerald-800 hover:bg-emerald-100 font-bold text-xs uppercase transition-colors">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-200/80">
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Informe seu E-mail Cadastrado
                </label>
                <div class="relative">
                    <i class="fa-regular fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input type="email" name="email" required placeholder="seu@email.com" value="<?= e($_POST['email'] ?? '') ?>" class="w-full pl-9 pr-3.5 py-2.5 text-xs rounded-xl border border-slate-200 focus:ring-2 focus:ring-rose-500 outline-none">
                </div>
                <p class="text-[11px] text-slate-400 mt-1">Você receberá um token único criptografado com validade temporária.</p>
            </div>

            <button type="submit" class="w-full py-3.5 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs uppercase tracking-wider shadow-lg shadow-rose-600/30 transition-all">
                Enviar Instruções de Redefinição
            </button>

            <div class="text-center pt-2">
                <a href="<?= BASE_URL ?>/login.php" class="text-xs font-semibold text-slate-500 hover:text-rose-600 transition-colors flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i> Voltar para a tela de login
                </a>
            </div>
        </form>
    </div>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
