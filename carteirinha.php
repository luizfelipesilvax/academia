<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar se o ID do aluno foi fornecido
if (!isset($_GET['id']) || !ctype_alnum($_GET['id'])) { // ctype_alnum verifica se é alfanumérico
    http_response_code(400);
    die("<h1>Erro: ID do aluno inválido.</h1>");
}

$aluno_id = $_GET['id'];

// Conectar ao banco de dados
$db_path = 'academia.db';
$db = new SQLite3($db_path);

// Buscar dados do aluno, plano e usuário proprietário
$stmt = $db->prepare("
    SELECT 
        a.*, 
        u.id as owner_id, 
        p.nome as plano_nome 
    FROM alunos a 
    JOIN usuarios u ON a.usuario_id = u.id 
    LEFT JOIN planos p ON a.plano_id = p.id 
    WHERE a.id = :id");
$stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
$resultado = $stmt->execute();
$aluno = $resultado->fetchArray(SQLITE3_ASSOC);

if (!$aluno) {
    http_response_code(404);
    $db->close();
    die("<h1>Erro: Aluno não encontrado.</h1>");
}

$usuario_proprietario_id = $aluno['owner_id'];

// Buscar turmas do aluno
$turmas_aluno = [];
$stmt_turmas = $db->prepare("SELECT t.tipo, t.dias_semana, t.horario_inicio FROM alunos_turmas at JOIN turmas t ON at.turma_id = t.id WHERE at.aluno_id = :aluno_id");
$stmt_turmas->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
$res_turmas = $stmt_turmas->execute();
while($turma_row = $res_turmas->fetchArray(SQLITE3_ASSOC)) { $turmas_aluno[] = $turma_row; }

// Buscar graduações do aluno
$graduacoes_aluno = [];
$stmt_grad = $db->prepare("SELECT m.nome as modalidade_nome, g.nome as graduacao_nome FROM alunos_graduacoes ag JOIN graduacoes g ON ag.graduacao_id = g.id JOIN modalidades m ON g.modalidade_id = m.id WHERE ag.aluno_id = :aluno_id");
$stmt_grad->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
$res_grad = $stmt_grad->execute();
while($grad_row = $res_grad->fetchArray(SQLITE3_ASSOC)) { $graduacoes_aluno[] = $grad_row; }


// --- Lógica de formatação (similar ao gerar_pdf_carteirinha.php) ---

// Busca as configurações específicas do dono da academia
$stmt_config = $db->prepare("SELECT valor FROM configuracoes WHERE chave = 'nome_academia' AND usuario_id = :usuario_id");
$stmt_config->bindValue(':usuario_id', $usuario_proprietario_id, SQLITE3_INTEGER);
$nome_academia = $stmt_config->execute()->fetchArray(SQLITE3_NUM)[0] ?? 'Academia';

// Buscar logo da academia
$logo_path = '';
$stmt_logo = $db->prepare("SELECT valor FROM configuracoes WHERE chave = 'logo_path' AND usuario_id = :usuario_id");
$stmt_logo->bindValue(':usuario_id', $usuario_proprietario_id, SQLITE3_INTEGER);
$logo_path_db = $stmt_logo->execute()->fetchArray(SQLITE3_NUM)[0] ?? '';
if ($logo_path_db && file_exists($logo_path_db)) {
    $logo_path = $logo_path_db;
}

// Formatação para exibição
$vencimento_display = $aluno['proximo_vencimento'] ? date('d/m/Y', strtotime($aluno['proximo_vencimento'])) : 'N/A';
$status_text = strtoupper($aluno['status']);
if ($aluno['status'] === 'bloqueado') {
    $status_color = '#ff5574'; // danger
    $status_bg_color = 'rgba(255, 85, 116, 0.15)';
} else {
$status_color = $aluno['status'] === 'ativo' ? '#00cc88' : '#ff5574';
$status_bg_color = $aluno['status'] === 'ativo' ? 'rgba(0, 204, 136, 0.15)' : 'rgba(255, 85, 116, 0.15)';
}

$db->close();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Carteirinha Digital - <?php echo htmlspecialchars($aluno['nome_completo']); ?></title>
    <style>
        * { box-sizing: border-box; } /* Global box-sizing reset */
        html { height: 100%; } /* Ensure full height for body centering */
        :root {
            --primary: #1a1a2e;
            --secondary: #16213e;
            --accent: #0f3460;
            --highlight: #e94560;
            --text: #f0f0f0;
            --text-secondary: #b0b0b0;
            --success: #00cc88;
            --danger: #ff5574;
            --card-border: #2a3a5c;
        }
        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
            background-color: var(--primary);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 1rem;
        }
        .carteirinha {
            max-width: 350px;
            width: 100%;
            border-radius: 16px;
            margin: 1rem auto;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--card-border);
            color: white;
            overflow: hidden;
        }
        .carteirinha-header {
            display: flex;
            justify-content: center; /* Centraliza a logo agora que o QR code foi removido */
            align-items: center;
            padding: 1rem 1.5rem;
            background-color: rgba(0,0,0,0.1);
        }
        .carteirinha-logo {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--text);
        }
        .carteirinha-logo img { max-height: 40px; filter: brightness(0) invert(1); }
        .carteirinha-body { padding: 1.5rem; }
        .carteirinha-nome { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .carteirinha-id { font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.5rem; }
        .carteirinha-info { display: flex; flex-direction: column; gap: 1rem; }
        .carteirinha-info-item { display: flex; justify-content: space-between; align-items: flex-start; }
        .carteirinha-info-item .label { font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; }
        .carteirinha-info-item .value { font-weight: 500; text-align: right; }
        .carteirinha-qr {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.1);
            padding: 5px;
            border-radius: 8px;
        }
        .carteirinha-qr img { border-radius: 4px; }
        .carteirinha-footer { padding: 0.75rem 1.5rem; }
        .carteirinha-footer .carteirinha-status {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            text-align: center;
            background-color: <?php echo $status_bg_color; ?>;
            color: <?php echo $status_color; ?>;
        }

        /* --- Estilos para o efeito de virar o cartão --- */
        .carteirinha-container {
            width: 100%;
            max-width: 350px;
            position: relative;
            perspective: 1000px;
            /* margin-bottom: 1rem; Removido para melhor centralização vertical */
        }
        .carteirinha-inner {
            position: relative;
            width: 100%; 
            height: 100%;
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }
        .carteirinha-container.is-flipped .carteirinha-inner {
            transform: rotateY(180deg);
        }
        .carteirinha-frente, .carteirinha-verso {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            -webkit-backface-visibility: hidden;
            backface-visibility: hidden;
        }
        .carteirinha-verso {
            transform: rotateY(180deg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--secondary), var(--accent));
            border-radius: 16px;
            border: 1px solid var(--card-border);
            padding: 1.5rem;
            gap: 1rem; /* Adicionado para espaçamento entre os itens */
        }
        .carteirinha-verso h3 {
            color: var(--text-secondary);
            /* margin-bottom: 1rem; Removido, usando gap no pai */
        }
                #qrcode-verso canvas,
        #qrcode-verso img {
            width: 100% !important; /* Override HTML width attribute */
            height: auto !important; /* Override HTML height attribute */
            max-width: 100%;
            display: block; /* Remove extra space below image */
        }

        @media (max-width: 400px) {
            body {
                padding: 0; /* Remover padding do body em mobile para maximizar espaço */
            }
            body {
                padding: 0; /* Remover padding do body em mobile para maximizar espaço */
            }
            .carteirinha-verso {
                padding: 1rem; /* Reduzir padding no mobile */
            }
            #qrcode-verso {
                padding: 10px; /* Reduzir padding do QR code no mobile */
            }
        }
        
    </style>
</head>
<body>
    <div class="carteirinha-container" id="carteirinha-container-main" onclick="this.classList.toggle('is-flipped')">
        <div class="carteirinha-inner" id="carteirinha-inner">
            <!-- Frente da Carteirinha -->
            <div class="carteirinha-frente">
                <div class='carteirinha' style="background: linear-gradient(135deg, var(--secondary), var(--accent)); margin: 0;">
                    <div class='carteirinha-header'>
                        <div class='carteirinha-logo'>
                            <?php if ($logo_path): ?>
                                <img src="<?php echo $logo_path; ?>" alt="Logo">
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($nome_academia); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class='carteirinha-body'>
                        <div class='carteirinha-nome'><?php echo htmlspecialchars($aluno['nome_completo']); ?></div>
                        <div class='carteirinha-id'>ID: <?php echo htmlspecialchars($aluno['id']); ?></div>
                        <div class='carteirinha-info'>
                            <div class='carteirinha-info-item'><span class='label'><i class='fas fa-tags'></i> Plano</span><span class='value'><?php echo htmlspecialchars($aluno['plano_nome'] ?? 'N/A'); ?></span></div>
                            <div class='carteirinha-info-item'><span class='label'><i class='fas fa-calendar-times'></i> Vencimento</span><span class='value'><?php echo $vencimento_display; ?></span></div>
                            <?php if (!empty($graduacoes_aluno)): ?>
                                <div class='carteirinha-info-item'><span class='label'><i class='fas fa-medal'></i> Graduação</span><span class='value'><?php echo implode('<br>', array_map(fn($g) => htmlspecialchars($g['modalidade_nome'] . ': ' . $g['graduacao_nome']), $graduacoes_aluno)); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($turmas_aluno)): ?>
                                <div class='carteirinha-info-item'><span class='label'><i class='fas fa-clock'></i> Turmas</span><span class='value'><?php echo implode('<br>', array_map(fn($t) => htmlspecialchars($t['tipo'] . ' (' . $t['dias_semana'] . ' ' . $t['horario_inicio'] . ')'), $turmas_aluno)); ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class='carteirinha-footer'>
                        <div class='carteirinha-status'><?php echo $status_text; ?></div>
                    </div>
                </div>
            </div>
            <!-- Verso da Carteirinha -->
            <div class="carteirinha-verso">
                <h2><?php echo htmlspecialchars($aluno['nome_completo']); ?></h2>
                <h3><?php echo htmlspecialchars($aluno['id']); ?></h3>
                <div id="qrcode-verso" style="background-color: white; padding: 15px; border-radius: 12px;"></div>
            </div>
        </div>
    </div>
    <script>
        const linkCarteirinha = `<?php echo 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\\\') . '/verificar_carteirinha.php?id=' . $aluno['id']; ?>`;
        // QR Code do verso (grande e legível)
        new QRCode(document.getElementById('qrcode-verso'), { text: linkCarteirinha, width: 200, height: 200, colorDark: "#16213e", colorLight: "#ffffff" });

        // Ajusta a altura do container-inner para a altura da frente
        document.addEventListener('DOMContentLoaded', function() {
            const frente = document.querySelector('.carteirinha-frente .carteirinha');
            const verso = document.querySelector('.carteirinha-verso');
            const inner = document.getElementById('carteirinha-inner');

            if (frente && verso && inner) {
                // Temporariamente remove estilos de posicionamento do verso para medir sua altura natural
                verso.style.position = 'static';
                verso.style.transform = 'none';
                verso.style.visibility = 'hidden'; // Mantém oculto
                verso.style.display = 'block'; // Garante que esteja no fluxo do layout

                const frenteHeight = frente.offsetHeight;
                const versoHeight = verso.offsetHeight;

                // Restaura os estilos originais do verso
                verso.style.position = 'absolute';
                verso.style.transform = 'rotateY(180deg)';
                verso.style.visibility = ''; // Deixa a classe controlar a visibilidade
                verso.style.display = ''; // Restaura o display padrão

                inner.style.height = Math.max(frenteHeight, versoHeight) + 'px';
            }
        });
    </script>
</body>
</html>