<?php
/**
 * Prisca Pizza - API do Assistente Virtual IA (Prisca AI)
 * Cumpre rigorosamente todas as 7 Regras da IA:
 * 1. Atendimento educado, claro e cordial baseado no sistema.
 * 2. Pedidos, tamanhos, sabores e adicionais reais.
 * 3. Preços e promoções sem inventar valores ou cupons falsos.
 * 4. Entregas com taxa padrão (R$ 8,00) e prazos reais (40-50 min).
 * 5. Disponibilidade e verificação do horário (Ter a Dom 18h às 23h30).
 * 6. Segurança e privacidade (sem solicitar dados bancários/senhas).
 * 7. Confirmação e acompanhamento de status real do pedido (#PRISCA...) e encaminhamento para atendimento humano.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/bd.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'mensagem' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mensagem = trim($input['mensagem'] ?? '');

if (empty($mensagem)) {
    echo json_encode(['status' => 'error', 'mensagem' => 'Mensagem vazia']);
    exit;
}

$pdo = getDbConnection();
$msgLower = mb_strtolower($mensagem, 'UTF-8');

// -------------------------------------------------------------
// REGRA 7: ENCAMINHAMENTO PARA ATENDIMENTO HUMANO
// -------------------------------------------------------------
if (
    strpos($msgLower, 'humano') !== false || 
    strpos($msgLower, 'atendente') !== false || 
    strpos($msgLower, 'pessoa') !== false || 
    strpos($msgLower, 'suporte') !== false || 
    strpos($msgLower, 'falar com alguem') !== false || 
    strpos($msgLower, 'falar com alguém') !== false || 
    strpos($msgLower, 'sac') !== false
) {
    $resposta = "👨‍🍳 **Atendimento Humano — Prisca Pizza**\n\n"
        . "Com certeza! Nossa equipe de atendimento no caixa e recepção está à sua disposição:\n\n"
        . "• 📞 **Telefone Fixo:** " . APP_PHONE . "\n"
        . "• 📱 **WhatsApp Oficial:** " . APP_WHATSAPP . "\n"
        . "• 📍 **Balcão:** " . APP_ADDRESS . "\n\n"
        . "Você pode falar diretamente com nossa equipe no WhatsApp clicando no link abaixo:\n"
        . "👉 [Abrir Conversa no WhatsApp](https://wa.me/5511988887777)";

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// REGRA 7: ACOMPANHAMENTO E STATUS REAL DO PEDIDO (#PRISCA-...)
// -------------------------------------------------------------
if (preg_match('/(?:#?PRISCA-?)?(\d{4})/i', $mensagem, $matches)) {
    $num = $matches[1];
    $codigoBusca = '#PRISCA-' . $num;

    $stmt = $pdo->prepare("SELECT p.*, u.nome as cliente_nome FROM pedidos p JOIN usuarios u ON p.cliente_id = u.id WHERE p.codigo = :codigo OR p.id = :id LIMIT 1");
    $stmt->execute([':codigo' => $codigoBusca, ':id' => (int)$num]);
    $pedido = $stmt->fetch();

    if ($pedido) {
        // Regra 6: Privacidade - Se for cliente logado, só exibe seus próprios pedidos
        if (isset($_SESSION['usuario']) && $_SESSION['usuario']['perfil'] === ROLE_CLIENTE && $_SESSION['usuario']['id'] != $pedido['cliente_id']) {
            echo json_encode([
                'status' => 'success',
                'resposta' => "🔒 **Privacidade e Proteção:** Por motivos de segurança dos dados de clientes, não posso exibir detalhes de pedidos vinculados a outra conta. Você pode consultar seu histórico na aba **Meus Pedidos**!"
            ]);
            exit;
        }

        $tempoEst = $pedido['tempo_estimado_min'] ?? 45;
        $tipoEntrega = $pedido['tipo_entrega'] === 'retirada' ? 'Retirada no Balcão' : 'Entrega em Domicílio';
        $statusStr = $pedido['status'];

        $msgResp = "Encontrei o seu pedido **{$pedido['codigo']}**!\n\n";
        $msgResp .= "• **Status Atual:** {$statusStr}\n";
        $msgResp .= "• **Modalidade:** {$tipoEntrega}\n";
        $msgResp .= "• **Total:** " . formatMoney($pedido['total']) . "\n";
        $msgResp .= "• **Forma de Pagamento:** " . strtoupper($pedido['forma_pagamento']) . " ({$pedido['status_pagamento']})\n";
        $msgResp .= "• **Tempo Estimado:** {$tempoEst} minutos\n\n";

        if ($statusStr === 'Recebido') {
            $msgResp .= "Seu pedido já foi recebido pelo atendente de caixa e em instantes será encaminhado para a esteira de preparo da cozinha.";
        } elseif ($statusStr === 'Em preparo') {
            $msgResp .= "Nosso mestre pizzaiolo está montando seus produtos com fermentação natural e forno a lenha no ponto!";
        } elseif ($statusStr === 'Pronto') {
            $msgResp .= $pedido['tipo_entrega'] === 'retirada' 
                ? "Seu pedido está prontinho e quentinho aguardando sua retirada no balcão da pizzaria!" 
                : "Seu pedido está embalado na caixa térmica e o entregador já está a postos para assumir a entrega.";
        } elseif ($statusStr === 'Saiu para entrega') {
            $msgResp .= "O motoboy já está em rota com a sua pizza quentinha! Fique atento(a) ao interfone ou telefone.";
        } elseif ($statusStr === 'Entregue') {
            $msgResp .= "Este pedido já foi finalizado e entregue com sucesso! Bom apetite!";
        } elseif ($statusStr === 'Cancelado') {
            $msgResp .= "Este pedido foi cancelado. Motivo registrado: " . ($pedido['motivo_cancelamento'] ?: 'Cancelamento registrado pelo caixa.');
        }

        echo json_encode(['status' => 'success', 'resposta' => $msgResp]);
        exit;
    }
}

// -------------------------------------------------------------
// REGRA 3: PREÇOS E PROMOÇÕES (Nunca inventar cupons ou descontos)
// -------------------------------------------------------------
if (
    strpos($msgLower, 'promocao') !== false || 
    strpos($msgLower, 'promoção') !== false || 
    strpos($msgLower, 'cupom') !== false || 
    strpos($msgLower, 'desconto') !== false || 
    strpos($msgLower, 'oferta') !== false
) {
    $resposta = "🏷️ **Política de Preços e Promoções da Prisca Pizza:**\n\n"
        . "• Nós nunca inventamos cupons ou promoções falsas.\n"
        . "• Todos os nossos preços são reais, transparentes e cadastrados diretamente em nosso sistema com o melhor custo-benefício artesanal.\n"
        . "• Na modalidade **Retirada no Balcão**, você economiza a taxa de entrega (R$ 8,00 grátis) e seu pedido fica pronto em apenas " . ESTIMATED_PICKUP_TIME . "!\n"
        . "• Você pode conferir os valores exatos de qualquer item no nosso cardápio online a qualquer momento.";

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// REGRA 5: DISPONIBILIDADE E HORÁRIO DE FUNCIONAMENTO
// -------------------------------------------------------------
if (
    strpos($msgLower, 'horario') !== false || 
    strpos($msgLower, 'horário') !== false || 
    strpos($msgLower, 'aberto') !== false || 
    strpos($msgLower, 'funciona') !== false || 
    strpos($msgLower, 'fecha') !== false ||
    strpos($msgLower, 'funcionamento') !== false
) {
    $aberto = isStoreOpen();
    $statusTxt = $aberto 
        ? "🟢 Estamos **ABERTOS** agora mesmo e aceitando pedidos a todo vapor!" 
        : "🔴 No momento nossa cozinha está **fechada**.";
    
    $resposta = "{$statusTxt}\n\n"
        . "🕒 **Nosso Horário de Atendimento:**\n"
        . "• **Terça a Domingo:** das 18:00 às 23:30\n"
        . "• **Segunda-feira:** Fechado para descanso da equipe e manutenção do forno\n\n"
        . "Você pode montar seu pedido e personalizar qualquer sabor diretamente através do nosso cardápio online!";
    
    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// REGRA 4: ENTREGAS, TAXAS, PRAZOS E ÁREA DE COBERTURA
// -------------------------------------------------------------
if (
    strpos($msgLower, 'entrega') !== false || 
    strpos($msgLower, 'taxa') !== false || 
    strpos($msgLower, 'frete') !== false || 
    strpos($msgLower, 'tempo') !== false || 
    strpos($msgLower, 'demora') !== false ||
    strpos($msgLower, 'regiao') !== false ||
    strpos($msgLower, 'região') !== false ||
    strpos($msgLower, 'bairro') !== false
) {
    $taxa = formatMoney(DEFAULT_DELIVERY_FEE);
    $resposta = "🛵 **Informações de Entrega da Prisca Pizza:**\n\n"
        . "• **Taxa Fixa de Entrega:** {$taxa} para nossa área de cobertura atendida\n"
        . "• **Tempo Médio de Entrega:** " . ESTIMATED_DELIVERY_TIME . "\n"
        . "• **Área de Entrega:** Atendemos São Paulo capital (regiões central, Bela Vista, Jardins, Paulista, Consolação e bairros vizinhos)\n"
        . "• **Retirada no Balcão:** Taxa R$ 0,00 (Grátis!), pronto em " . ESTIMATED_PICKUP_TIME . "\n"
        . "• **Endereço da Pizzaria:** " . APP_ADDRESS . "\n\n"
        . "Todos os pedidos saem em caixas lacradas e mochilas térmicas seladas para manter o calor e a crocância!";

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// REGRA 6: FORMAS DE PAGAMENTO E SEGURANÇA DE DADOS
// -------------------------------------------------------------
if (
    strpos($msgLower, 'pagamento') !== false || 
    strpos($msgLower, 'cartao') !== false || 
    strpos($msgLower, 'cartão') !== false || 
    strpos($msgLower, 'pix') !== false || 
    strpos($msgLower, 'dinheiro') !== false || 
    strpos($msgLower, 'troco') !== false ||
    strpos($msgLower, 'segurança') !== false ||
    strpos($msgLower, 'seguranca') !== false
) {
    $resposta = "💳 **Formas de Pagamento e Segurança:**\n\n"
        . "Trabalhamos com simulação de pagamentos 100% segura e transparente:\n\n"
        . "1. **PIX Instantâneo:** Gera código padrão Copia e Cola e QR Code na tela para pagamento imediato.\n"
        . "2. **Cartão de Crédito / Débito:** Simulação de aprovação online sem armazenar dados sensíveis de seu cartão.\n"
        . "3. **Dinheiro:** Você paga no ato da entrega ou no balcão. Você pode indicar o valor para cálculo de troco na finalização do pedido!\n\n"
        . "🔒 **Aviso de Segurança:** Nunca solicitamos sua senha bancária, senhas de cartões ou dados sigilosos.";

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// REGRA 2: TAMANHOS, FATIAS E ADICIONAIS DE PIZZA
// -------------------------------------------------------------
if (
    strpos($msgLower, 'tamanho') !== false || 
    strpos($msgLower, 'fatia') !== false || 
    strpos($msgLower, 'pedaco') !== false || 
    strpos($msgLower, 'pedaço') !== false || 
    strpos($msgLower, 'meio a meio') !== false ||
    strpos($msgLower, 'borda') !== false ||
    strpos($msgLower, 'adicional') !== false ||
    strpos($msgLower, 'massa') !== false
) {
    $resposta = "🍕 **Tamanhos, Fatias e Customizações:**\n\n"
        . "• **Broto:** 4 fatias (25cm) - 1 sabor (individual)\n"
        . "• **Média:** 6 fatias (30cm) - até 2 sabores meio a meio (2 pessoas)\n"
        . "• **Grande:** 8 fatias (35cm) - até 2 sabores meio a meio (3 a 4 pessoas) — *Mais pedida!*\n"
        . "• **Família:** 12 fatias (40cm) - até 3 sabores (serve até 6 pessoas!)\n\n"
        . "🧀 **Bordas Recheadas Disponíveis:**\n"
        . "• Borda com Catupiry Original (+R$ 9,90)\n"
        . "• Borda com Cheddar Cremoso (+R$ 9,90)\n"
        . "• Borda Vulcão de Chocolate ao Leite (+R$ 12,90)\n\n"
        . "🌾 **Tipos de Massa:** Fermentação Natural Tradicional, Fina Italiana ou Massa Pan (+R$ 5,00).\n"
        . "🥓 **Adicionais Extras:** Bacon crocante (+R$ 6,50), Mussarela extra (+R$ 6,00), Cebola caramelizada (+R$ 4,00) e Alho dourado (+R$ 3,50).";

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// CONSULTA ESPECÍFICA DE INGREDIENTES OU PRODUTO POR NOME (Regra 1 e 5)
// -------------------------------------------------------------
$stmtAllProds = $pdo->query("SELECT id, nome, preco_base, descricao, disponivel, categoria_id FROM produtos WHERE 1=1");
$allProds = $stmtAllProds->fetchAll();

foreach ($allProds as $prod) {
    $prodNomeLower = mb_strtolower($prod['nome'], 'UTF-8');
    // Verifica se o usuário mencionou este produto especificamente
    // Por exemplo "margherita", "calabresa", "quatro queijos", "petit gateau", "coca"
    $palavrasChave = explode(' ', $prodNomeLower);
    $termoPrincipal = $palavrasChave[0];
    if (strlen($termoPrincipal) >= 4 && strpos($msgLower, $termoPrincipal) !== false) {
        $statusDisp = $prod['disponivel'] ? "✅ Disponível" : "❌ Temporariamente indisponível";
        
        $msgProd = "🍕 **{$prod['nome']}**\n\n"
            . "• **Preço Base:** " . formatMoney($prod['preco_base']) . "\n"
            . "• **Disponibilidade:** {$statusDisp}\n"
            . "• **Ingredientes & Descrição:**\n  _{$prod['descricao']}_\n\n";

        if ($prod['categoria_id'] == 1) {
            $msgProd .= "Você pode escolher o tamanho (Broto, Média, Grande ou Família), dividir meio a meio e adicionar borda de Catupiry ou Cheddar no cardápio!";
        }

        echo json_encode(['status' => 'success', 'resposta' => $msgProd]);
        exit;
    }
}

// -------------------------------------------------------------
// ESFIHAS (Regra 1 e 2)
// -------------------------------------------------------------
if (strpos($msgLower, 'esfiha') !== false || strpos($msgLower, 'esfirra') !== false) {
    $stmt = $pdo->query("SELECT nome, preco_base, descricao FROM produtos WHERE categoria_id = 2 AND disponivel = 1 ORDER BY preco_base ASC");
    $esfihas = $stmt->fetchAll();

    $resposta = "🥟 **Nossas Esfihas Abertas Artesanais:**\n\n";
    foreach ($esfihas as $esf) {
        $resposta .= "• **{$esf['nome']}** — " . formatMoney($esf['preco_base']) . "\n  _{$esf['descricao']}_\n";
    }
    $resposta .= "\nMassas assadas no forno à lenha com recheios frescos preparados na hora!";

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// BEBIDAS (Regra 1 e 2)
// -------------------------------------------------------------
if (strpos($msgLower, 'bebida') !== false || strpos($msgLower, 'refrigerante') !== false || strpos($msgLower, 'coca') !== false || strpos($msgLower, 'suco') !== false || strpos($msgLower, 'cerveja') !== false) {
    $stmt = $pdo->query("SELECT nome, preco_base FROM produtos WHERE categoria_id = 3 AND disponivel = 1 ORDER BY preco_base ASC");
    $bebidas = $stmt->fetchAll();

    $resposta = "🥤 **Bebidas Geladas Disponíveis:**\n\n";
    foreach ($bebidas as $b) {
        $resposta .= "• **{$b['nome']}** — " . formatMoney($b['preco_base']) . "\n";
    }

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// SOBREMESAS (Regra 1 e 2)
// -------------------------------------------------------------
if (strpos($msgLower, 'sobremesa') !== false || strpos($msgLower, 'doce') !== false || strpos($msgLower, 'churros') !== false || strpos($msgLower, 'tiramisu') !== false || strpos($msgLower, 'pudim') !== false) {
    $stmt = $pdo->query("SELECT nome, preco_base, descricao FROM produtos WHERE categoria_id = 4 AND disponivel = 1 ORDER BY preco_base ASC");
    $sobremesas = $stmt->fetchAll();

    $resposta = "🍰 **Sobremesas Especiais Prisca:**\n\n";
    foreach ($sobremesas as $s) {
        $resposta .= "• **{$s['nome']}** — " . formatMoney($s['preco_base']) . "\n  _{$s['descricao']}_\n";
    }

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// PIZZAS GERAIS / CARDÁPIO (Regra 1 e 2)
// -------------------------------------------------------------
if (strpos($msgLower, 'pizza') !== false || strpos($msgLower, 'sabor') !== false || strpos($msgLower, 'cardapio') !== false || strpos($msgLower, 'cardápio') !== false || strpos($msgLower, 'menu') !== false) {
    $stmt = $pdo->query("SELECT nome, preco_base, descricao FROM produtos WHERE categoria_id = 1 AND disponivel = 1 ORDER BY destaque DESC, preco_base ASC LIMIT 6");
    $pizzas = $stmt->fetchAll();

    $resposta = "🍕 **Nossos Sabores de Pizza Mais Pedidos:**\n\n";
    foreach ($pizzas as $p) {
        $resposta .= "• **{$p['nome']}** (a partir de " . formatMoney($p['preco_base']) . ")\n  _{$p['descricao']}_\n\n";
    }
    $resposta .= "Você pode conferir todos os sabores na página **Cardápio**, personalizar bordas recheadas de Catupiry ou Cheddar e escolher tamanho!";

    echo json_encode(['status' => 'success', 'resposta' => $resposta]);
    exit;
}

// -------------------------------------------------------------
// RESPOSTA PADRÃO CORDIAL (Regra 1: caso não saiba a resposta com precisão, orienta com cordialidade)
// -------------------------------------------------------------
$resposta = "Olá! Como assistente virtual da **Prisca Pizza**, estou aqui para te ajudar com base estrita no nosso sistema! 😊\n\n"
    . "Posso te ajudar com as seguintes informações:\n"
    . "• 🍕 **Cardápio & Ingredientes:** pergunte sobre qualquer sabor de pizza ou esfiha (ex: *'quais os ingredientes da margherita?'*)\n"
    . "• 📏 **Tamanhos e Fatias:** Broto, Média, Grande e Família\n"
    . "• 🛵 **Entregas e Taxa:** R$ 8,00 para entrega ou R$ 0,00 para retirada no balcão\n"
    . "• 🕒 **Horário:** Terça a Domingo das 18h às 23h30\n"
    . "• 💳 **Pagamentos:** Pix com QR Code, Cartões e Dinheiro com troco\n"
    . "• 🔍 **Status do Pedido:** Digite o código do seu pedido (ex: **#PRISCA-1001**)\n"
    . "• 👨‍🍳 **Atendimento Humano:** Digite *'falar com atendente'*\n\n"
    . "Como prefere que eu te ajude agora?";

echo json_encode(['status' => 'success', 'resposta' => $resposta]);
