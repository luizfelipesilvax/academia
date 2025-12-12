<?php
session_start();
file_put_contents(__DIR__ . '/debug_registration.log', "Testing log file creation.\n", FILE_APPEND);

// Função para verificar permissão
function tem_permissao($modulo, $permissoes) {
    // O admin principal (ID 1) sempre tem permissão para tudo.
    if ($_SESSION['usuario_id'] == 1) {
        return true;
    }
    // O dashboard é um caso especial, todos os usuários logados e aprovados têm acesso.
    if ($modulo === 'dashboard') {
        return true;
    }
    return in_array($modulo, $permissoes);
}


// Incluir os arquivos necessários
require_once 'QRCodeGenerator.php';
require_once 'database.php'; // Ponto central de conexão com o DB

// Lógica de navegação
$pagina_atual = isset($_GET['p']) ? sanitizeInput($_GET['p']) : 'dashboard';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
} else {
    $check_user_stmt = $db->prepare("SELECT id FROM usuarios WHERE id = :id");
    $check_user_stmt->bindValue(':id', $_SESSION['usuario_id'], SQLITE3_INTEGER);
    if (!$check_user_stmt->execute()->fetchArray()) {
        // Se o usuário da sessão não existe mais (ex: DB foi apagado), força o logout.
        header("Location: logout.php");
        exit();
    }
}
$usuario_id_logado = $_SESSION['usuario_id'];

// Verifica e adiciona a coluna 'tipo' se não existir, para compatibilidade com versões antigas do DB.
$colunas_usuarios = $db->query("PRAGMA table_info(usuarios)");
$tipo_column_exists = false;
while ($coluna = $colunas_usuarios->fetchArray(SQLITE3_ASSOC)) {
    if ($coluna['name'] === 'tipo') { $tipo_column_exists = true; break; }
}
if (!$tipo_column_exists) { $db->exec("ALTER TABLE usuarios ADD COLUMN tipo TEXT DEFAULT 'professor'"); }
$usuario_tipo = $db->querySingle("SELECT tipo FROM usuarios WHERE id = $usuario_id_logado") ?? 'professor';

// Adiciona a coluna 'criado_por_id' para rastrear quem criou o usuário
$colunas_usuarios = $db->query("PRAGMA table_info(usuarios)");
$criado_por_exists = false; while ($coluna = $colunas_usuarios->fetchArray(SQLITE3_ASSOC)) { if ($coluna['name'] === 'criado_por_id') { $criado_por_exists = true; break; } } if (!$criado_por_exists) { $db->exec("ALTER TABLE usuarios ADD COLUMN criado_por_id INTEGER"); }

// Adiciona a coluna 'dia_vencimento' na tabela de alunos se não existir
$colunas_alunos = $db->query("PRAGMA table_info(alunos)");
$dia_vencimento_exists = false; while ($coluna = $colunas_alunos->fetchArray(SQLITE3_ASSOC)) { if ($coluna['name'] === 'dia_vencimento') { $dia_vencimento_exists = true; break; } } if (!$dia_vencimento_exists) { $db->exec("ALTER TABLE alunos ADD COLUMN dia_vencimento INTEGER DEFAULT 10"); }

$professor_id_associado = null;
if ($usuario_tipo === 'professor') {
    $professor_id_associado = $db->querySingle("SELECT professor_id FROM usuarios WHERE id = $usuario_id_logado");
}

// Adiciona a coluna 'cpf' na tabela de professores se não existir
$colunas_prof_cpf = $db->query("PRAGMA table_info(professores)");
$cpf_prof_exists = false; 
while ($coluna = $colunas_prof_cpf->fetchArray(SQLITE3_ASSOC)) { 
    if ($coluna['name'] === 'cpf') { 
        $cpf_prof_exists = true; 
        break; 
    } 
} 
if (!$cpf_prof_exists) { 
    $db->exec("ALTER TABLE professores ADD COLUMN cpf TEXT"); 
}

// Adiciona a coluna 'status' na tabela de professores se não existir
$colunas_prof = $db->query("PRAGMA table_info(professores)");
$status_prof_exists = false; while ($coluna = $colunas_prof->fetchArray(SQLITE3_ASSOC)) { if ($coluna['name'] === 'status') { $status_prof_exists = true; break; } } if (!$status_prof_exists) { $db->exec("ALTER TABLE professores ADD COLUMN status TEXT DEFAULT 'ativo'"); }


// Apenas o admin principal (ID 1) pode aprovar novos usuários.
if ($pagina_atual == 'aprovacoes' && $usuario_id_logado != 1) {
    $pagina_atual = 'dashboard'; // Redireciona para o dashboard se não for o admin principal
}

// Carregar permissões do usuário logado
$permissoes_usuario = [];
if ($usuario_id_logado != 1) { // Admin principal não precisa disso
    $stmt_perm = $db->prepare("SELECT modulo FROM usuario_permissoes WHERE usuario_id = :uid");
    $stmt_perm->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $res_perm = $stmt_perm->execute();
    while($row = $res_perm->fetchArray(SQLITE3_ASSOC)) {
        $permissoes_usuario[] = $row['modulo'];
    }
}

// Inserir dados padrão para o usuário, se ainda não existirem, usando INSERT OR IGNORE.
// Isso é mais eficiente e seguro do que verificar primeiro.
$db->exec('BEGIN');

$configuracoes_padrao = [
    ['valor_muay_thai', '120.00'],
    ['valor_jiu_jitsu', '130.00'],
    ['valor_total_pass', '200.00'],
    ['nome_academia', $_SESSION['nome_academia'] ?? 'Sua Academia'],
    ['endereco_academia', 'Seu Endereço'],
    ['logo_path', '']
];
$stmt_config = $db->prepare("INSERT OR IGNORE INTO configuracoes (usuario_id, chave, valor) VALUES (:uid, :chave, :valor)");
$stmt_config->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
foreach ($configuracoes_padrao as $config) {
    $stmt_config->bindValue(':chave', $config[0], SQLITE3_TEXT);
    $stmt_config->bindValue(':valor', $config[1], SQLITE3_TEXT);
    $stmt_config->execute();
}

$db->exec('COMMIT');

// Funções do sistema
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function formatarData($data) {
    return date('d/m/Y', strtotime($data));
}

function formatarMoeda($valor) {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function calcularValorMensalidade($plano, $modalidades) {
    // Esta função se torna obsoleta, pois o valor virá diretamente do plano selecionado.
    // Mantida por segurança, mas a lógica principal mudará.
    return 0; // O valor será pego do DB.
}

function gerarIdAleatorio($length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * Redimensiona uma imagem para uma altura máxima, mantendo a proporção.
 * Suporta JPEG, PNG e GIF.
 *
 * @param string $caminho_origem Caminho do arquivo de imagem original.
 * @param string $caminho_destino Caminho para salvar a nova imagem.
 * @param int $altura_maxima A altura máxima desejada para a imagem.
 * @return bool Retorna true em caso de sucesso, false em caso de falha.
 */
function redimensionarImagem($caminho_origem, $caminho_destino, $altura_maxima = 200) {
    list($largura_orig, $altura_orig, $tipo_img) = getimagesize($caminho_origem);

    if (!$largura_orig || !$altura_orig) {
        return false;
    }

    // Calcular novas dimensões
    $ratio = $largura_orig / $altura_orig;
    $nova_altura = $altura_maxima;
    $nova_largura = $nova_altura * $ratio;

    // Criar a nova imagem
    $nova_imagem = imagecreatetruecolor($nova_largura, $nova_altura);

    // Carrega a imagem original
    $imagem_origem = imagecreatefromstring(file_get_contents($caminho_origem));

    // --- Tratamento de Transparência ---
    if ($tipo_img == IMAGETYPE_PNG) {
        // Preserva a transparência original de PNGs
        imagealphablending($nova_imagem, false);
        imagesavealpha($nova_imagem, true);
        $cor_transparente = imagecolorallocatealpha($nova_imagem, 255, 255, 255, 127);
        imagefilledrectangle($nova_imagem, 0, 0, $nova_largura, $nova_altura, $cor_transparente);
        imagealphablending($nova_imagem, true);
    } else {
        // Para JPEGs/GIFs, verifica se o fundo é preto para torná-lo transparente
        $cor_fundo_index = imagecolorat($imagem_origem, 0, 0);
        $cor_fundo_rgb = imagecolorsforindex($imagem_origem, $cor_fundo_index);

        // Se o fundo for preto (ou quase preto), define o preto como transparente
        if ($cor_fundo_rgb['red'] < 20 && $cor_fundo_rgb['green'] < 20 && $cor_fundo_rgb['blue'] < 20) {
            $cor_preta = imagecolorallocate($nova_imagem, 0, 0, 0);
            imagecolortransparent($nova_imagem, $cor_preta);
            imagefill($nova_imagem, 0, 0, $cor_preta);
        }
    }
    
    // Redimensionar
    imagecopyresampled($nova_imagem, $imagem_origem, 0, 0, 0, 0, $nova_largura, $nova_altura, $largura_orig, $altura_orig);

    // Salvar a imagem redimensionada (sempre como PNG para manter a qualidade e transparência)
    return imagepng($nova_imagem, $caminho_destino);
}
// Lógica para lidar com o envio da carteirinha via AJAX (pelo botão no modal)
if (isset($_POST['action']) && $_POST['action'] == 'enviar_carteirinha_por_whatsapp') {
    header('Content-Type: application/json');
    
    $aluno_id = $_POST['aluno_id'];
    $telefone = $_POST['telefone'];
    $nome = $_POST['nome'];
    
    // Construir o link para a carteirinha de verificação
    $link_carteirinha = 'https://samuraisoft.com.br/carteirinha.php?id=' . $aluno_id;
    
    // Nova mensagem com o link
    $mensagem_whatsapp = "Olá {$nome}, segue o link para sua carteirinha digital. Você pode usá-la para validar sua entrada.\n\n{$link_carteirinha}\n\nBons treinos! 💪";
    $envio_status = ['success' => true, 'message' => 'A solicitação de envio foi registrada e será processada pelo bot.'];

    echo json_encode($envio_status);
    exit(); // Termina o script para não renderizar o HTML
}

// Lógica para lidar com o envio de lembrete de vencimento via AJAX
if (isset($_POST['action']) && $_POST['action'] == 'enviar_lembrete_vencimento') {
    header('Content-Type: application/json');

    $aluno_id = $_POST['aluno_id'];
    $aluno = $db->querySingle("SELECT nome_completo, telefone, valor_mensalidade, proximo_vencimento FROM alunos WHERE id = '$aluno_id' AND usuario_id = $usuario_id_logado", true);

    if ($aluno) {
        $mensagem_lembrete = "Olá {$aluno['nome_completo']}, tudo bem?\n\nLembrete: sua mensalidade no valor de " . formatarMoeda($aluno['valor_mensalidade']) . " vence em " . formatarData($aluno['proximo_vencimento']) . ".\n\nEvite a inativação do seu plano realizando o pagamento. Bons treinos! 💪";
        echo json_encode(['success' => true, 'message' => 'A solicitação de envio foi registrada e será processada pelo bot.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Aluno não encontrado.']);
    }

    exit();
}


// Processamento do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // Este if agora envolve todo o processamento
    if (isset($_POST['cadastrar_aluno'])) { // Início do bloco movido
        $nome = sanitizeInput($_POST['nome_completo']);
        $cpf = sanitizeInput($_POST['cpf']);
        $nascimento = sanitizeInput($_POST['data_nascimento']);
        $telefone = sanitizeInput($_POST['telefone']);
        $turmas_ids = $_POST['turma_id'] ?? []; // Agora é um array
        $plano_id = intval($_POST['plano_id'] ?? 0);
        $professor = sanitizeInput($_POST['professor'] ?? '');
        $dia_vencimento = intval($_POST['dia_vencimento']);
        $data_inicio = sanitizeInput($_POST['data_inicio'] ?? date('Y-m-d'));

        // Processar 'dias_semana' como um array
        $dias_semana_array = $_POST['dias_semana'] ?? [];
        $dias_semana_sanitizados = array_map('sanitizeInput', $dias_semana_array);
        $dias_semana = implode(', ', $dias_semana_sanitizados);
        // O campo hidden 'dias_semana_hidden' não é mais necessário com este tratamento.

        $horario_inicio = sanitizeInput($_POST['horario_inicio'] ?? ''); 
        $horario_fim = sanitizeInput($_POST['horario_fim'] ?? ''); 

        // Busca os detalhes do plano selecionado
        $stmt_plano = $db->prepare("SELECT p.valor, GROUP_CONCAT(m.nome) as modalidades_nomes FROM planos p JOIN planos_modalidades pm ON p.id = pm.plano_id JOIN modalidades m ON pm.modalidade_id = m.id WHERE p.id = :id AND p.usuario_id = :uid GROUP BY p.id");
        $stmt_plano->bindValue(':id', $plano_id, SQLITE3_INTEGER);
        $stmt_plano->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $plano_info = $stmt_plano->execute()->fetchArray(SQLITE3_ASSOC);

        $valor_mensalidade = $plano_info['valor'] ?? 0;
        $modalidades = $plano_info['modalidades_nomes'] ?? '';
        
        // Verificar se o CPF já existe para este usuário
        $stmt_check_cpf = $db->prepare("SELECT COUNT(*) as count FROM alunos WHERE cpf = :cpf AND usuario_id = :usuario_id");
        $stmt_check_cpf->bindValue(':cpf', $cpf, SQLITE3_TEXT);
        $stmt_check_cpf->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $cpf_exists = $stmt_check_cpf->execute()->fetchArray(SQLITE3_ASSOC)['count'] > 0;

        if ($cpf_exists) {
            $_SESSION['msg'] = "Erro: Já existe um aluno cadastrado com este CPF.";
            // Não continua a execução do cadastro
        } else {
        // Calcula o próximo vencimento com base no dia escolhido
        $data_base_vencimento = new DateTime($data_inicio);
        $data_base_vencimento->modify('+1 month'); // Vai para o próximo mês
        $data_base_vencimento->setDate($data_base_vencimento->format('Y'), $data_base_vencimento->format('m'), $dia_vencimento);
        $proximo_vencimento = $data_base_vencimento->format('Y-m-d');
        $novo_id = gerarIdAleatorio(); 
        $stmt = $db->prepare("INSERT INTO alunos (id, usuario_id, nome_completo, cpf, data_nascimento, telefone, plano_id, modalidades, professor, dia_vencimento, data_inicio, dias_semana, horario_inicio, horario_fim, valor_mensalidade, proximo_vencimento, status) 
                             VALUES (:id, :usuario_id, :nome, :cpf, :nascimento, :telefone, :plano_id, :modalidades, :professor, :dia_venc, :inicio, :dias, :h_inicio, :h_fim, :valor, :vencimento, 'ativo')");
        $stmt->bindValue(':id', $novo_id, SQLITE3_TEXT);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt->bindValue(':nome', $nome, SQLITE3_TEXT);
        $stmt->bindValue(':cpf', $cpf, SQLITE3_TEXT);
        $stmt->bindValue(':nascimento', $nascimento, SQLITE3_TEXT);
        $stmt->bindValue(':telefone', $telefone, SQLITE3_TEXT);
        $stmt->bindValue(':plano_id', $plano_id, SQLITE3_INTEGER);
        $stmt->bindValue(':modalidades', $modalidades, SQLITE3_TEXT);
        $stmt->bindValue(':professor', $professor, SQLITE3_TEXT);
        $stmt->bindValue(':dia_venc', $dia_vencimento, SQLITE3_INTEGER);
        $stmt->bindValue(':inicio', $data_inicio, SQLITE3_TEXT);
        $stmt->bindValue(':dias', $dias_semana, SQLITE3_TEXT);
        $stmt->bindValue(':h_inicio', $horario_inicio, SQLITE3_TEXT);
        $stmt->bindValue(':h_fim', $horario_fim, SQLITE3_TEXT);
        $stmt->bindValue(':valor', $valor_mensalidade, SQLITE3_FLOAT);
        $stmt->bindValue(':vencimento', $proximo_vencimento, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            // Associar turmas selecionadas
            if (!empty($turmas_ids)) {
                $stmt_turma = $db->prepare("INSERT INTO alunos_turmas (aluno_id, turma_id) VALUES (:aluno_id, :turma_id)");
                $stmt_turma->bindValue(':aluno_id', $novo_id, SQLITE3_TEXT);
                foreach ($turmas_ids as $turma_id) {
                    if (!empty($turma_id)) {
                        $stmt_turma->bindValue(':turma_id', intval($turma_id), SQLITE3_INTEGER);
                        $stmt_turma->execute();
                    }
                }
            }
            // Associar graduações selecionadas
            if (isset($_POST['graduacoes']) && is_array($_POST['graduacoes'])) {
                $stmt_grad = $db->prepare("INSERT INTO alunos_graduacoes (aluno_id, graduacao_id) VALUES (:aluno_id, :grad_id)");
                $stmt_grad->bindValue(':aluno_id', $novo_id, SQLITE3_TEXT);
                foreach ($_POST['graduacoes'] as $grad_id) {
                    if (!empty($grad_id)) {
                        $stmt_grad->bindValue(':grad_id', intval($grad_id), SQLITE3_INTEGER);
                        $stmt_grad->execute();
                    }
                }
            }
            // Registrar o primeiro pagamento
            $mes_referencia = date('Y-m-01');
            $stmt_pagamento = $db->prepare("INSERT INTO pagamentos (aluno_id, usuario_id, nome_aluno, mes_referencia, data_pagamento, valor, status) 
                                           VALUES (:aluno_id, :usuario_id, :nome_aluno, :mes_ref, :data_pag, :valor, 'pago')");
            $stmt_pagamento->bindValue(':aluno_id', $novo_id, SQLITE3_TEXT);
            $stmt_pagamento->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
            $stmt_pagamento->bindValue(':nome_aluno', $nome, SQLITE3_TEXT);
            $stmt_pagamento->bindValue(':mes_ref', $mes_referencia, SQLITE3_TEXT);
            $stmt_pagamento->bindValue(':data_pag', date('Y-m-d'), SQLITE3_TEXT);
            $stmt_pagamento->bindValue(':valor', $valor_mensalidade, SQLITE3_FLOAT);
            $stmt_pagamento->execute();
            
            // Construir o link para a carteirinha e enviar na mensagem de boas-vindas
            $link_carteirinha = 'https://samuraisoft.com.br/carteirinha.php?id=' . $novo_id;
            $_SESSION['msg'] = "Aluno cadastrado com sucesso! A mensagem de boas-vindas será enviada em breve.";
            
        } else {
            $_SESSION['msg'] = "Erro ao cadastrar aluno. CPF pode já existir.";
        }
        }
    }
    
    if (isset($_POST['remover_aluno'])) {
        $aluno_id = sanitizeInput($_POST['aluno_id_remover']);

        // Iniciar uma transação para garantir a integridade
        $db->exec('BEGIN');

        // 1. Remover pagamentos associados
        $stmt_pag = $db->prepare("DELETE FROM pagamentos WHERE aluno_id = :aluno_id AND usuario_id = :usuario_id");
        $stmt_pag->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
        $stmt_pag->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $pag_success = $stmt_pag->execute();

        // 2. Remover o aluno
        $stmt_aluno = $db->prepare("DELETE FROM alunos WHERE id = :id AND usuario_id = :usuario_id");
        $stmt_aluno->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt_aluno->bindValue(':id', $aluno_id, SQLITE3_TEXT);
        $aluno_success = $stmt_aluno->execute();

        $db->exec('COMMIT');
        $_SESSION['msg'] = "Aluno removido com sucesso!";
    }

    if (isset($_POST['registrar_pagamento'])) {
        $aluno_id = $_POST['aluno_id'];
        $aluno_nome = $_POST['aluno_nome']; // Pegar o nome do aluno do formulário
        $mes_referencia = $_POST['mes_referencia'];
        $valor = $_POST['valor'];
        
        $stmt = $db->prepare("INSERT INTO pagamentos (aluno_id, usuario_id, nome_aluno, mes_referencia, data_pagamento, valor, status) 
                             VALUES (:aluno_id, :usuario_id, :nome_aluno, :mes_ref, :data_pag, :valor, 'pago')");
        $stmt->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
        $stmt->bindValue(':nome_aluno', $aluno_nome, SQLITE3_TEXT);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt->bindValue(':mes_ref', $mes_referencia, SQLITE3_TEXT);
        $stmt->bindValue(':data_pag', date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':valor', $valor, SQLITE3_FLOAT);
        
        if ($stmt->execute()) {
            // Atualizar status do aluno e datas de pagamento
            $dia_vencimento_aluno = $db->querySingle("SELECT dia_vencimento FROM alunos WHERE id = '{$aluno_id}'") ?? 10;
            $data_base_vencimento = new DateTime($mes_referencia);
            $data_base_vencimento->modify('+1 month');
            $proximo_vencimento = $data_base_vencimento->format('Y-m-') . str_pad($dia_vencimento_aluno, 2, '0', STR_PAD_LEFT);
            $stmt_update = $db->prepare("UPDATE alunos SET status='ativo', ultimo_pagamento = :ultimo_pag, proximo_vencimento = :prox_venc WHERE id = :id AND usuario_id = :usuario_id");
            $stmt_update->bindValue(':ultimo_pag', date('Y-m-d'), SQLITE3_TEXT);
            $stmt_update->bindValue(':prox_venc', $proximo_vencimento, SQLITE3_TEXT);
            $stmt_update->bindValue(':id', $aluno_id, SQLITE3_TEXT);
            $stmt_update->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

            if ($stmt_update->execute()) {
                $_SESSION['msg'] = "Pagamento registrado com sucesso!";
            }
        } else {
            $_SESSION['msg'] = "Erro ao registrar pagamento.";
        }
    }

    if (isset($_POST['salvar_configuracoes'])) {
        $botbot_appkey = sanitizeInput($_POST['botbot_appkey']);
        $botbot_authkey = sanitizeInput($_POST['botbot_authkey']);

        // Usar INSERT OR REPLACE para inserir ou atualizar as chaves
        $stmt_api = $db->prepare("INSERT OR REPLACE INTO configuracoes (usuario_id, chave, valor) VALUES (:usuario_id, 'botbot_appkey', :valor)");
        $stmt_api->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt_api->bindValue(':valor', $botbot_appkey, SQLITE3_TEXT);
        $stmt_api->execute();

        $stmt_auth = $db->prepare("INSERT OR REPLACE INTO configuracoes (usuario_id, chave, valor) VALUES (:usuario_id, 'botbot_authkey', :valor)");
        $stmt_auth->bindValue(':valor', $botbot_authkey, SQLITE3_TEXT);
        $stmt_auth->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        
        if ($stmt_auth->execute()) {
            $_SESSION['msg'] = "Configurações do Bot-Bot salvas com sucesso!";
        } else {
            $_SESSION['msg'] = "Erro ao salvar as configurações.";
        }
    }

    if (isset($_POST['salvar_configuracoes_avancadas'])) {
        $valor_mt = floatval($_POST['valor_muay_thai']);
        $valor_jj = floatval($_POST['valor_jiu_jitsu']);
        $valor_tp = floatval($_POST['valor_total_pass']);

        $db->exec("INSERT OR REPLACE INTO configuracoes (usuario_id, chave, valor) VALUES ($usuario_id_logado, 'valor_muay_thai', '$valor_mt')");
        $db->exec("INSERT OR REPLACE INTO configuracoes (usuario_id, chave, valor) VALUES ($usuario_id_logado, 'valor_jiu_jitsu', '$valor_jj')");
        $db->exec("INSERT OR REPLACE INTO configuracoes (usuario_id, chave, valor) VALUES ($usuario_id_logado, 'valor_total_pass', '$valor_tp')");

        $_SESSION['msg'] = "Valores dos planos atualizados com sucesso!";
        header("Location: ?p=avancados");
        exit();
    }

    if (isset($_POST['aprovar_usuario'])) {
        $usuario_id_aprovar = intval($_POST['usuario_id_aprovar']);
        $db->exec('BEGIN');
        
        // 1. Atualiza o status do usuário para 'aprovado'
        $stmt_update = $db->prepare("UPDATE usuarios SET status = 'aprovado' WHERE id = :id");
        $stmt_update->bindValue(':id', $usuario_id_aprovar, SQLITE3_INTEGER);
        $success = $stmt_update->execute();

        if ($success) {
            // 2. Define todos os módulos que o usuário aprovado receberá
            $modulos_padrao = [
                'alunos', 'pagamentos', 'turmas', 'presenca', 'planos', 'usuarios',
                'modalidades', 'professores', 'despesas', 'despesas_futuras', 'configuracoes'
            ];

            // 3. Insere as permissões para o usuário
            $stmt_perm = $db->prepare("INSERT INTO usuario_permissoes (usuario_id, modulo) VALUES (:uid, :modulo)");
            $stmt_perm->bindValue(':uid', $usuario_id_aprovar, SQLITE3_INTEGER);
            foreach ($modulos_padrao as $modulo) {
                $stmt_perm->bindValue(':modulo', $modulo, SQLITE3_TEXT);
                $stmt_perm->execute();
            }
            $db->exec('COMMIT');
            $_SESSION['msg'] = "Usuário aprovado com sucesso e permissões concedidas!";
        } else {
            $db->exec('ROLLBACK');
            $_SESSION['msg'] = "Erro ao aprovar o usuário.";
        }
    }

    if (isset($_POST['rejeitar_usuario'])) {
        $usuario_id_rejeitar = intval($_POST['usuario_id_rejeitar']);
        $stmt = $db->prepare("UPDATE usuarios SET status = 'rejeitado' WHERE id = :id");
        $stmt->bindValue(':id', $usuario_id_rejeitar, SQLITE3_INTEGER);
        $stmt->execute();
        $_SESSION['msg'] = "Usuário rejeitado.";
    }

    if (isset($_POST['reconsiderar_usuario'])) {
        $usuario_id_reconsiderar = intval($_POST['usuario_id_reconsiderar']);
        // Altera o status de 'rejeitado' de volta para 'pendente'
        $stmt = $db->prepare("UPDATE usuarios SET status = 'pendente' WHERE id = :id AND status = 'rejeitado'");
        $stmt->bindValue(':id', $usuario_id_reconsiderar, SQLITE3_INTEGER);
        $stmt->execute();
        $_SESSION['msg'] = "Usuário movido de volta para pendentes.";
    }

    if (isset($_POST['adicionar_professor'])) {
        $nome_professor = sanitizeInput($_POST['nome_professor']);
        $cpf_professor = sanitizeInput($_POST['cpf_professor']);

        // 1. Verificar se o CPF já existe para este usuário
        $stmt_check = $db->prepare("SELECT COUNT(*) as count FROM professores WHERE cpf = :cpf AND usuario_id = :uid");
        $stmt_check->bindValue(':cpf', $cpf_professor, SQLITE3_TEXT);
        $stmt_check->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $cpf_exists = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['count'] > 0;

        if ($cpf_exists) {
            $_SESSION['msg'] = "Erro: Já existe um professor cadastrado com este CPF.";
        } else {
            if (!empty($nome_professor) && !empty($cpf_professor)) {
                $stmt = $db->prepare("INSERT INTO professores (usuario_id, nome, cpf) VALUES (:usuario_id, :nome, :cpf)");
                $stmt->bindValue(':nome', $nome_professor, SQLITE3_TEXT);
                $stmt->bindValue(':cpf', $cpf_professor, SQLITE3_TEXT);
                $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
                if ($stmt->execute()) {
                    $_SESSION['msg'] = "Professor '{$nome_professor}' adicionado com sucesso!";
                } else {
                    $_SESSION['msg'] = "Erro: Professor '{$nome_professor}' já existe.";
                }
            } else {
                $_SESSION['msg'] = "Erro: Nome e CPF do professor são obrigatórios.";
            }
        }
        header("Location: ?p=professores");
        exit();
    }

    if (isset($_POST['remover_professor'])) {
        $id_professor = intval($_POST['id_professor']);
        $stmt = $db->prepare("DELETE FROM professores WHERE id = :id AND usuario_id = :usuario_id");
        $stmt->bindValue(':id', $id_professor, SQLITE3_INTEGER);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Professor removido com sucesso!";
        }
        header("Location: ?p=professores");
        exit();
    }

    if (isset($_POST['adicionar_plano'])) {
        $nome_plano = sanitizeInput($_POST['nome_plano']);
        $valor_plano = floatval($_POST['valor_plano']);
        $modalidades_ids = $_POST['modalidades_plano'] ?? [];

        $db->exec('BEGIN');
        $stmt = $db->prepare("INSERT INTO planos (usuario_id, nome, valor) VALUES (:uid, :nome, :valor)");
        $stmt->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt->bindValue(':nome', $nome_plano, SQLITE3_TEXT);
        $stmt->bindValue(':valor', $valor_plano, SQLITE3_FLOAT);
        $success = $stmt->execute();
        if ($success) {
            $novo_plano_id = $db->lastInsertRowID();
            $stmt_assoc = $db->prepare("INSERT INTO planos_modalidades (plano_id, modalidade_id) VALUES (:plano_id, :modalidade_id)");
            $stmt_assoc->bindValue(':plano_id', $novo_plano_id, SQLITE3_INTEGER);
            foreach ($modalidades_ids as $mod_id) {
                $stmt_assoc->bindValue(':modalidade_id', intval($mod_id), SQLITE3_INTEGER);
                $stmt_assoc->execute();
            }
            $db->exec('COMMIT');
            $_SESSION['msg'] = "Plano '{$nome_plano}' adicionado com sucesso!";
        } else {
            $db->exec('ROLLBACK');
            $_SESSION['msg'] = "Erro: Já existe um plano com o nome '{$nome_plano}'.";
        }
        header("Location: ?p=planos");
        exit();
    }

    if (isset($_POST['adicionar_turma'])) {
        $modalidades_ids = $_POST['modalidades_turma'] ?? [];
        $professor_id = intval($_POST['professor_id']);
        $dias_semana = implode(', ', $_POST['dias_semana_turma'] ?? []);
        $horario_inicio = sanitizeInput($_POST['horario_inicio_turma']);
        $horario_fim = sanitizeInput($_POST['horario_fim_turma']);
        $tipo = sanitizeInput($_POST['tipo_turma']);

        $db->exec('BEGIN');
        $stmt_turma = $db->prepare("INSERT INTO turmas (usuario_id, professor_id, dias_semana, horario_inicio, horario_fim, tipo) VALUES (:uid, :professor, :dias, :h_inicio, :h_fim, :tipo)");
        $stmt_turma->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt_turma->bindValue(':professor', $professor_id, SQLITE3_INTEGER);
        $stmt_turma->bindValue(':dias', $dias_semana, SQLITE3_TEXT);
        $stmt_turma->bindValue(':h_inicio', $horario_inicio, SQLITE3_TEXT);
        $stmt_turma->bindValue(':h_fim', $horario_fim, SQLITE3_TEXT);
        $stmt_turma->bindValue(':tipo', $tipo, SQLITE3_TEXT);

        if ($stmt_turma->execute()) {
            $nova_turma_id = $db->lastInsertRowID();
            $stmt_assoc = $db->prepare("INSERT INTO turmas_modalidades (turma_id, modalidade_id) VALUES (:turma_id, :modalidade_id)");
            $stmt_assoc->bindValue(':turma_id', $nova_turma_id, SQLITE3_INTEGER);
            foreach ($modalidades_ids as $mod_id) {
                $stmt_assoc->bindValue(':modalidade_id', intval($mod_id), SQLITE3_INTEGER);
                $stmt_assoc->execute();
            }
            $db->exec('COMMIT');
            $_SESSION['msg'] = "Turma adicionada com sucesso!";
        } else {
            $db->exec('ROLLBACK');
            $_SESSION['msg'] = "Erro ao adicionar a turma.";
        }
        header("Location: ?p=turmas");
        exit();
    }

    if (isset($_POST['remover_turma'])) {
        $id_turma = intval($_POST['id_turma']);
        // Adicionar verificação se a turma está em uso antes de remover
        $stmt = $db->prepare("DELETE FROM turmas WHERE id = :id AND usuario_id = :uid");
        $stmt->bindValue(':id', $id_turma, SQLITE3_INTEGER);
        $stmt->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Turma removida com sucesso!";
        }
        header("Location: ?p=turmas");
        exit();
    }

    if (isset($_POST['remover_plano'])) {
        $id_plano = intval($_POST['id_plano']);

        // 1. Verificar se o plano está sendo usado por algum aluno
        $stmt_check = $db->prepare("SELECT COUNT(*) as count FROM alunos WHERE plano_id = :plano_id AND usuario_id = :uid");
        $stmt_check->bindValue(':plano_id', $id_plano, SQLITE3_INTEGER);
        $stmt_check->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $count = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['count'];

        if ($count > 0) {
            // 2. Se estiver em uso, exibe uma mensagem de erro
            $_SESSION['msg'] = "Erro: Este plano não pode ser removido pois está associado a {$count} aluno(s).";
            header("Location: ?p=planos");
            exit();
        } else {
            // 3. Se não estiver em uso, remove o plano
            $stmt = $db->prepare("DELETE FROM planos WHERE id = :id AND usuario_id = :uid");
            $stmt->bindValue(':id', $id_plano, SQLITE3_INTEGER);
            $stmt->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
            header("Location: ?p=planos");
            if ($stmt->execute()) { 
                $_SESSION['msg'] = "Plano removido com sucesso!"; 
            }
            exit();
        }
    }

    if (isset($_POST['adicionar_modalidade'])) {
        $nome_modalidade = sanitizeInput($_POST['nome_modalidade']);
        $stmt = $db->prepare("INSERT INTO modalidades (nome) VALUES (:nome)");
        $stmt->bindValue(':nome', $nome_modalidade, SQLITE3_TEXT);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Modalidade '{$nome_modalidade}' adicionada com sucesso!";
        } else {
            $_SESSION['msg'] = "Erro: Modalidade já existe.";
        }
        header("Location: ?p=avancados");
        exit();
    }

    if (isset($_POST['remover_modalidade'])) {
        $id_modalidade = intval($_POST['id_modalidade']);
        // Esta funcionalidade é apenas para o admin principal (ID 1).
        // Ele gerencia modalidades globais. Usuários secundários não devem remover modalidades.
        if ($usuario_id_logado == 1) {
            // Adicionar verificação se a modalidade está em uso por algum plano de QUALQUER usuário.
            $stmt_check = $db->prepare("SELECT COUNT(*) FROM planos_modalidades WHERE modalidade_id = :mid");
            $stmt_check->bindValue(':mid', $id_modalidade, SQLITE3_INTEGER);
            $count = $stmt_check->execute()->fetchArray(SQLITE3_NUM)[0];

            if ($count > 0) {
                $_SESSION['msg'] = "Erro: Esta modalidade não pode ser removida pois está em uso por {$count} plano(s).";
            } else {
                $stmt = $db->prepare("DELETE FROM modalidades WHERE id = :id");
                $stmt->bindValue(':id', $id_modalidade, SQLITE3_INTEGER);
                if ($stmt->execute()) {
                    $_SESSION['msg'] = "Modalidade removida com sucesso!";
                }
            }
        } else {
            $_SESSION['msg'] = "Apenas o administrador principal pode remover modalidades.";
        }
        header("Location: ?p=modalidades");
        exit();
    }

    if (isset($_POST['adicionar_graduacao'])) {
        $modalidade_id = intval($_POST['modalidade_id_grad']);
        $nome_graduacao = sanitizeInput($_POST['nome_graduacao']);
        $ordem_graduacao = intval($_POST['ordem_graduacao']);
        // Graduações são globais, gerenciadas pelo admin principal
        $stmt = $db->prepare("INSERT INTO graduacoes (modalidade_id, nome, ordem) VALUES (:mid, :nome, :ordem)");
        $stmt->bindValue(':mid', $modalidade_id, SQLITE3_INTEGER);
        $stmt->bindValue(':nome', $nome_graduacao, SQLITE3_TEXT);
        $stmt->bindValue(':ordem', $ordem_graduacao, SQLITE3_INTEGER);
        $stmt->execute();

        // Redireciona de volta para a mesma tela, com um parâmetro para reabrir o modal
        $_SESSION['msg'] = "Graduação adicionada!";
        header("Location: ?p=modalidades&reopen_grad_modal=" . $modalidade_id);
        exit();
    }

    if (isset($_POST['remover_graduacao'])) {
        $graduacao_id = intval($_POST['graduacao_id_remover']);
        $stmt = $db->prepare("DELETE FROM graduacoes WHERE id = :id");
        $stmt->bindValue(':id', $graduacao_id, SQLITE3_INTEGER);
        $stmt->execute();
        $_SESSION['msg'] = "Graduação removida com sucesso!";
        header("Location: ?p=modalidades");
        exit();
    }

    if (isset($_POST['editar_graduacao'])) {
        $graduacao_id = intval($_POST['graduacao_id_edit']);
        $nome_graduacao = sanitizeInput($_POST['nome_graduacao_edit']);
        $ordem_graduacao = intval($_POST['ordem_graduacao_edit']);

        $stmt = $db->prepare("UPDATE graduacoes SET nome = :nome, ordem = :ordem WHERE id = :id");
        $stmt->bindValue(':nome', $nome_graduacao, SQLITE3_TEXT);
        $stmt->bindValue(':ordem', $ordem_graduacao, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $graduacao_id, SQLITE3_INTEGER);
        $stmt->execute();
        $_SESSION['msg'] = "Graduação atualizada com sucesso!";
        header("Location: ?p=modalidades");
        exit();
    }

    if (isset($_POST['salvar_logo']) && isset($_FILES['logo_academia'])) {
        $arquivo = $_FILES['logo_academia'];
        if ($arquivo['error'] === UPLOAD_ERR_OK && in_array($arquivo['type'], ['image/png', 'image/jpeg', 'image/gif'])) {
            // Criar um diretório específico para o usuário para evitar conflitos de nome de arquivo.
            $upload_dir = 'uploads/user_' . $usuario_id_logado . '/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Gerar um nome de arquivo único para a logo do usuário.
            $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
            // Salvaremos sempre como PNG para manter a consistência após o redimensionamento.
            $nome_arquivo = 'logo_' . $usuario_id_logado . '.png';
            $caminho_arquivo = $upload_dir . $nome_arquivo;

            // Redimensiona a imagem antes de salvar permanentemente
            if (redimensionarImagem($arquivo['tmp_name'], $caminho_arquivo, 200)) {
                $stmt_logo = $db->prepare("INSERT OR REPLACE INTO configuracoes (usuario_id, chave, valor) VALUES (:uid, 'logo_path', :path)");
                $stmt_logo->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
                $stmt_logo->bindValue(':path', $caminho_arquivo, SQLITE3_TEXT);
                $stmt_logo->execute();
                $_SESSION['msg'] = "Logo atualizada com sucesso!";
            } else {
                $_SESSION['msg'] = "Erro ao processar e redimensionar a imagem da logo.";
            }
        }
    }

    if (isset($_POST['remover_logo'])) {
        // 1. Pega o caminho da logo atual no banco de dados para poder apagar o arquivo.
        $stmt_get_logo = $db->prepare("SELECT valor FROM configuracoes WHERE usuario_id = :uid AND chave = 'logo_path'");
        $stmt_get_logo->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $logo_path_remover = $stmt_get_logo->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;

        // 2. Apaga o arquivo físico do servidor, se ele existir.
        if ($logo_path_remover && file_exists($logo_path_remover)) {
            unlink($logo_path_remover);
        }

        // 3. Remove a entrada 'logo_path' do banco de dados.
        $stmt_delete_logo = $db->prepare("DELETE FROM configuracoes WHERE usuario_id = :uid AND chave = 'logo_path'");
        $stmt_delete_logo->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        
        if ($stmt_delete_logo->execute()) {
            $_SESSION['msg'] = "Logo removida com sucesso!";
        }
        header("Location: ?p=configuracoes"); // Redireciona para a página de configurações
        exit();
    }

    if (isset($_POST['cadastrar_despesa'])) {
        $descricao = sanitizeInput($_POST['descricao']);
        $valor = floatval($_POST['valor_despesa']);

        $stmt = $db->prepare("INSERT INTO despesas (usuario_id, descricao, valor, mes_referencia, data_pagamento, status) 
                             VALUES (:usuario_id, :descricao, :valor, :mes_ref, :pagamento, 'pago')");
        $stmt->bindValue(':descricao', $descricao, SQLITE3_TEXT);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt->bindValue(':valor', $valor, SQLITE3_FLOAT);
        $stmt->bindValue(':mes_ref', date('Y-m-01'), SQLITE3_TEXT);
        $stmt->bindValue(':pagamento', date('Y-m-d'), SQLITE3_TEXT);

        if ($stmt->execute()) {
            $_SESSION['msg'] = "Despesa '{$descricao}' cadastrada com sucesso!";
        }
    }

    if (isset($_POST['cadastrar_despesa_futura'])) {
        $descricao = sanitizeInput($_POST['descricao_futura']);
        $valor = floatval($_POST['valor_despesa_futura']);
        $data_vencimento = sanitizeInput($_POST['data_vencimento_futura']);
        $mes_referencia = date('Y-m-01', strtotime($data_vencimento));

        $stmt = $db->prepare("INSERT INTO despesas (usuario_id, descricao, valor, mes_referencia, data_vencimento, status) 
                             VALUES (:usuario_id, :descricao, :valor, :mes_ref, :vencimento, 'pendente')");
        $stmt->bindValue(':descricao', $descricao, SQLITE3_TEXT);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt->bindValue(':valor', $valor, SQLITE3_FLOAT);
        $stmt->bindValue(':mes_ref', $mes_referencia, SQLITE3_TEXT);
        $stmt->bindValue(':vencimento', $data_vencimento, SQLITE3_TEXT);

        if ($stmt->execute()) {
            $_SESSION['msg'] = "Despesa futura '{$descricao}' cadastrada com sucesso!";
        }
    }

    if (isset($_POST['remover_despesa'])) {
        $despesa_id = intval($_POST['despesa_id']);
        $stmt = $db->prepare("DELETE FROM despesas WHERE id = :id AND usuario_id = :usuario_id");
        $stmt->bindValue(':id', $despesa_id, SQLITE3_INTEGER);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

        if ($stmt->execute()) {
            $_SESSION['msg'] = "Despesa removida com sucesso!";
        } else {
            $_SESSION['msg'] = "Erro ao remover a despesa.";
        }
    }

    if (isset($_POST['marcar_pago_despesa_futura'])) {
        $despesa_id = intval($_POST['despesa_id']);
        
        // Atualiza o status para 'pago' e define a data de pagamento
        $stmt = $db->prepare("UPDATE despesas SET status = 'pago', data_pagamento = :data_pagamento WHERE id = :id AND usuario_id = :usuario_id");
        $stmt->bindValue(':data_pagamento', date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':id', $despesa_id, SQLITE3_INTEGER);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

        if ($stmt->execute()) {
            $_SESSION['msg'] = "Despesa marcada como paga com sucesso!";
        } else {
            $_SESSION['msg'] = "Erro ao marcar a despesa como paga.";
        }
        header("Location: ?p=despesas_futuras");
        exit();
    }

    if (isset($_POST['cadastrar_usuario_professor'])) {
        if ($usuario_id_logado == 1) { // Apenas o admin principal (ID 1) pode fazer isso
            $email = sanitizeInput($_POST['email_professor']);
            $nome_academia = sanitizeInput($_POST['nome_academia_professor']);
            $senha = $_POST['senha_professor'];
            $senha_confirm = $_POST['senha_professor_confirm'];
            $professor_id = intval($_POST['professor_id_usuario']); // Agora é um campo obrigatório
            $permissoes = $_POST['permissoes'] ?? [];

            // Verificar se o e-mail já existe
            $stmt_check = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
            $stmt_check->bindValue(':email', $email, SQLITE3_TEXT);
            if ($stmt_check->execute()->fetchArray()) {
                $_SESSION['msg'] = "Erro: O e-mail '{$email}' já está em uso.";
            } elseif ($senha !== $senha_confirm) {
                $_SESSION['msg'] = "Erro: As senhas não coincidem.";
            } else {
                $db->exec('BEGIN');
                $hash_senha = password_hash($senha, PASSWORD_DEFAULT);
                $stmt_user = $db->prepare("INSERT INTO usuarios (email, password_hash, nome_academia, status, tipo, professor_id, criado_por_id) VALUES (:email, :hash, :nome, 'aprovado', 'professor', :prof_id, :criado_por)");
                $stmt_user->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt_user->bindValue(':hash', $hash_senha, SQLITE3_TEXT);
                $stmt_user->bindValue(':nome', $nome_academia, SQLITE3_TEXT);
                $stmt_user->bindValue(':prof_id', $professor_id, SQLITE3_INTEGER);
                $stmt_user->bindValue(':criado_por', $usuario_id_logado, SQLITE3_INTEGER);

                if ($stmt_user->execute()) {
                    $log_file = __DIR__ . '/debug_registration.log';
                    file_put_contents($log_file, "User '{$email}' registered successfully.\n", FILE_APPEND);
                    $novo_usuario_id = $db->lastInsertRowID();
                    $stmt_perm = $db->prepare("INSERT INTO usuario_permissoes (usuario_id, modulo) VALUES (:uid, :modulo)");
                    $stmt_perm->bindValue(':uid', $novo_usuario_id, SQLITE3_INTEGER);
                    foreach ($permissoes as $modulo) {
                        $stmt_perm->bindValue(':modulo', sanitizeInput($modulo), SQLITE3_TEXT);
                        $stmt_perm->execute();
                    }
                    $db->exec('COMMIT');
                    $_SESSION['msg'] = "Usuário professor cadastrado com sucesso!";
                } else {
                    $log_file = __DIR__ . '/debug_registration.log';
                    file_put_contents($log_file, "Error registering user '{$email}'. SQLite Error: " . $db->lastErrorMsg() . "\n", FILE_APPEND);
                    $db->exec('ROLLBACK'); $_SESSION['msg'] = "Erro ao cadastrar o usuário.";
                }
            }
        }
    }

    if (isset($_POST['remover_usuario'])) {
        if ($usuario_id_logado == 1) {
            $usuario_id_remover = intval($_POST['usuario_id_remover']);
            if ($usuario_id_remover == 1) {
                $_SESSION['msg'] = "Erro: O administrador principal não pode ser removido.";
            } else {
                $db->exec('BEGIN');
                $stmt_perm = $db->prepare("DELETE FROM usuario_permissoes WHERE usuario_id = :id");
                $stmt_perm->bindValue(':id', $usuario_id_remover, SQLITE3_INTEGER);
                $stmt_user = $db->prepare("DELETE FROM usuarios WHERE id = :id");
                $stmt_user->bindValue(':id', $usuario_id_remover, SQLITE3_INTEGER);
                if ($stmt_perm->execute() && $stmt_user->execute()) { $db->exec('COMMIT'); $_SESSION['msg'] = "Usuário removido com sucesso!"; } 
                else { $db->exec('ROLLBACK'); $_SESSION['msg'] = "Erro ao remover o usuário."; }
            }
        }
    }

    if (isset($_POST['alterar_status_usuario'])) {
        if ($usuario_id_logado == 1) {
            $usuario_id_status = intval($_POST['usuario_id_status']);
            $status_atual = sanitizeInput($_POST['status_atual']);
            $novo_status = ($status_atual == 'aprovado') ? 'bloqueado' : 'aprovado';

            $stmt = $db->prepare("UPDATE usuarios SET status = :status WHERE id = :id");
            $stmt->bindValue(':status', $novo_status, SQLITE3_TEXT);
            $stmt->bindValue(':id', $usuario_id_status, SQLITE3_INTEGER);
            if ($stmt->execute()) { $_SESSION['msg'] = "Status do usuário alterado com sucesso!"; } 
            else { $_SESSION['msg'] = "Erro ao alterar o status do usuário."; }
        }
    }

    if (isset($_POST['editar_usuario_professor'])) {
        if ($usuario_id_logado == 1) {
            $usuario_id_edit = intval($_POST['usuario_id_edit']);
            $email = sanitizeInput($_POST['email_professor_edit']);
            $professor_id = intval($_POST['professor_id_usuario_edit']);
            $permissoes = $_POST['permissoes_edit'] ?? [];

            $db->exec('BEGIN');
            $stmt_user = $db->prepare("UPDATE usuarios SET email = :email, professor_id = :prof_id WHERE id = :id");
            $stmt_user->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt_user->bindValue(':prof_id', $professor_id, SQLITE3_INTEGER);
            $stmt_user->bindValue(':id', $usuario_id_edit, SQLITE3_INTEGER);

            if ($stmt_user->execute()) {
                // 1. Remover permissões antigas
                $db->exec("DELETE FROM usuario_permissoes WHERE usuario_id = $usuario_id_edit");
                // 2. Inserir novas permissões
                $stmt_perm = $db->prepare("INSERT INTO usuario_permissoes (usuario_id, modulo) VALUES (:uid, :modulo)");
                $stmt_perm->bindValue(':uid', $usuario_id_edit, SQLITE3_INTEGER);
                foreach ($permissoes as $modulo) {
                    $stmt_perm->bindValue(':modulo', sanitizeInput($modulo), SQLITE3_TEXT);
                    $stmt_perm->execute();
                }
                $db->exec('COMMIT');
                $_SESSION['msg'] = "Usuário atualizado com sucesso!";
            } else {
                $db->exec('ROLLBACK');
                $_SESSION['msg'] = "Erro ao atualizar o usuário. O e-mail pode já estar em uso.";
            }
        }
    }

    if (isset($_POST['alterar_senha_usuario'])) {
        if ($usuario_id_logado == 1) {
            $usuario_id_senha = intval($_POST['usuario_id_senha']);
            $nova_senha = $_POST['nova_senha'];
            $nova_senha_confirm = $_POST['nova_senha_confirm'];

            if ($nova_senha !== $nova_senha_confirm) {
                $_SESSION['msg'] = "Erro: As novas senhas não coincidem.";
            } else {
                $hash_senha = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE usuarios SET password_hash = :hash WHERE id = :id");
                $stmt->bindValue(':hash', $hash_senha, SQLITE3_TEXT);
                $stmt->bindValue(':id', $usuario_id_senha, SQLITE3_INTEGER);
                if ($stmt->execute()) { $_SESSION['msg'] = "Senha do usuário alterada com sucesso!"; } else { $_SESSION['msg'] = "Erro ao alterar a senha."; }
            }
        }
    }

    if (isset($_POST['editar_mensalidade_aluno'])) {
        $aluno_id = sanitizeInput($_POST['aluno_id_edit']);
        $novo_valor = floatval($_POST['valor_mensalidade_edit']);

        $stmt = $db->prepare("UPDATE alunos SET valor_mensalidade = :valor WHERE id = :id AND usuario_id = :usuario_id");
        $stmt->bindValue(':valor', $novo_valor, SQLITE3_FLOAT);
        $stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

        if ($stmt->execute()) {
            // Atualiza o valor do pagamento já existente para o mês corrente, se houver.
            $mes_atual_ref = date('Y-m-01');
            $stmt_update_pagamento = $db->prepare("
                UPDATE pagamentos SET valor = :novo_valor 
                WHERE aluno_id = :aluno_id AND mes_referencia = :mes_ref AND usuario_id = :usuario_id
            ");
            $stmt_update_pagamento->bindValue(':novo_valor', $novo_valor, SQLITE3_FLOAT);
            $stmt_update_pagamento->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
            $stmt_update_pagamento->bindValue(':mes_ref', $mes_atual_ref, SQLITE3_TEXT);
            $stmt_update_pagamento->execute();

            $_SESSION['msg'] = "Valor da mensalidade atualizado com sucesso!";
        } else {
            $_SESSION['msg'] = "Erro ao atualizar o valor da mensalidade.";
        }
        // Redireciona para a mesma página para recarregar os dados e evitar reenvio do formulário
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }

    if (isset($_POST['teste_vencimento_aluno'])) {
        $aluno_id_teste = sanitizeInput($_POST['aluno_id_teste']);
        $tres_dias_futuro = date('Y-m-d', strtotime('+3 days'));

        $stmt = $db->prepare("UPDATE alunos SET proximo_vencimento = :vencimento WHERE id = :id AND usuario_id = :usuario_id");
        $stmt->bindValue(':vencimento', $tres_dias_futuro, SQLITE3_TEXT);
        $stmt->bindValue(':id', $aluno_id_teste, SQLITE3_TEXT);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

        if ($stmt->execute()) {
            $_SESSION['msg'] = "Vencimento do aluno alterado para teste!";
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
} // Fim do bloco movido

if (isset($_POST['alterar_status_aluno'])) {
    $aluno_id = sanitizeInput($_POST['aluno_id_status']);
    $status_atual = sanitizeInput($_POST['status_atual']);

    // Alterna entre 'ativo' e 'bloqueado'. Se estiver 'inativo', vai para 'bloqueado'.
    $novo_status = ($status_atual === 'ativo') ? 'bloqueado' : 'ativo';

    $stmt = $db->prepare("UPDATE alunos SET status = :status WHERE id = :id AND usuario_id = :uid");
    $stmt->bindValue(':status', $novo_status, SQLITE3_TEXT);
    $stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
    $stmt->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    if ($stmt->execute()) { $_SESSION['msg'] = "Status do aluno alterado com sucesso!"; } else { $_SESSION['msg'] = "Erro ao alterar o status do aluno."; }
}

if (isset($_POST['salvar_presenca'])) {
    $presencas = $_POST['presenca'] ?? [];
    date_default_timezone_set('America/Sao_Paulo'); // Garante o fuso horário correto
    $data_hoje = date('Y-m-d'); // Pega a data atual com o fuso horário definido

    $db->exec('BEGIN');
    // Usamos INSERT OR REPLACE para simplificar: insere se não existir, atualiza se já existir para o mesmo aluno e dia.
    $stmt = $db->prepare("INSERT OR REPLACE INTO presencas (aluno_id, usuario_id, data_presenca, status) VALUES (:aluno_id, :usuario_id, :data_presenca, :status)");
    $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
    $stmt->bindValue(':data_presenca', $data_hoje, SQLITE3_TEXT);

    foreach ($presencas as $aluno_id => $status) {
        $stmt->bindValue(':aluno_id', sanitizeInput($aluno_id), SQLITE3_TEXT);
        $stmt->bindValue(':status', sanitizeInput($status), SQLITE3_TEXT);
        $stmt->execute();
    }
    $db->exec('COMMIT');
    $_SESSION['msg'] = "Lista de presença salva com sucesso!";
    header("Location: ?p=presenca");
    exit();
}

if (isset($_POST['editar_aluno'])) {
    $aluno_id_edit = sanitizeInput($_POST['aluno_id_edit_form']);
    $nome = sanitizeInput($_POST['nome_completo_edit']);
    $plano_id = intval($_POST['plano_id_edit']);

    // Busca os detalhes do plano selecionado para atualizar modalidades e valor
    $stmt_plano = $db->prepare("SELECT p.valor, GROUP_CONCAT(m.nome) as modalidades_nomes FROM planos p JOIN planos_modalidades pm ON p.id = pm.plano_id JOIN modalidades m ON pm.modalidade_id = m.id WHERE p.id = :id AND p.usuario_id = :uid GROUP BY p.id");
    $stmt_plano->bindValue(':id', $plano_id, SQLITE3_INTEGER);
    $stmt_plano->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $plano_info = $stmt_plano->execute()->fetchArray(SQLITE3_ASSOC);
    
    $modalidades = $plano_info['modalidades_nomes'] ?? '';
    $novo_valor_mensalidade = $plano_info['valor'] ?? 0;

    $db->exec('BEGIN');
    $stmt = $db->prepare("UPDATE alunos SET nome_completo = :nome, plano_id = :plano_id, modalidades = :modalidades, valor_mensalidade = :valor WHERE id = :id AND usuario_id = :usuario_id");
    $stmt->bindValue(':nome', $nome, SQLITE3_TEXT);
    $stmt->bindValue(':plano_id', $plano_id, SQLITE3_INTEGER);
    $stmt->bindValue(':modalidades', $modalidades, SQLITE3_TEXT);
    $stmt->bindValue(':valor', $novo_valor_mensalidade, SQLITE3_FLOAT);
    $stmt->bindValue(':id', $aluno_id_edit, SQLITE3_TEXT);
    $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

    if ($stmt->execute()) {
        // Atualizar graduações
        // 1. Remover as antigas
        $db->exec("DELETE FROM alunos_graduacoes WHERE aluno_id = '{$aluno_id_edit}'");
        // 2. Inserir as novas
        if (isset($_POST['graduacoes_edit']) && is_array($_POST['graduacoes_edit'])) {
            $stmt_grad = $db->prepare("INSERT INTO alunos_graduacoes (aluno_id, graduacao_id) VALUES (:aluno_id, :grad_id)");
            $stmt_grad->bindValue(':aluno_id', $aluno_id_edit, SQLITE3_TEXT);
            foreach ($_POST['graduacoes_edit'] as $grad_id) {
                if (!empty($grad_id)) {
                    $stmt_grad->bindValue(':grad_id', intval($grad_id), SQLITE3_INTEGER);
                    $stmt_grad->execute();
                }
            }
        }
        $db->exec('COMMIT');
        $_SESSION['msg'] = "Dados do aluno atualizados com sucesso!";
    } else {
        $db->exec('ROLLBACK');
        $_SESSION['msg'] = "Erro ao atualizar os dados do aluno.";
    }
}

    if (isset($_POST['editar_aluno_pessoal'])) {
        $aluno_id = sanitizeInput($_POST['aluno_id_edit_pessoal']);
        $cpf = sanitizeInput($_POST['cpf_edit']);
        $nascimento = sanitizeInput($_POST['data_nascimento_edit']);
        $telefone = sanitizeInput($_POST['telefone_edit']);

        $stmt = $db->prepare("UPDATE alunos SET cpf = :cpf, data_nascimento = :nascimento, telefone = :telefone WHERE id = :id AND usuario_id = :usuario_id");
        $stmt->bindValue(':cpf', $cpf, SQLITE3_TEXT);
        $stmt->bindValue(':nascimento', $nascimento, SQLITE3_TEXT);
        $stmt->bindValue(':telefone', $telefone, SQLITE3_TEXT);
        $stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
        $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
        if ($stmt->execute()) { $_SESSION['msg'] = "Dados pessoais do aluno atualizados com sucesso!"; } else { $_SESSION['msg'] = "Erro ao atualizar os dados do aluno."; }
    }
if (isset($_POST['renovar_plano_aluno'])) {
    $aluno_id = sanitizeInput($_POST['aluno_id_renovar']);
    $novo_plano_id = intval($_POST['plano_id_renovar']);
    $nova_turma_id = intval($_POST['turma_id_renovar']);

    // 1. Buscar informações do novo plano (valor, modalidades)
    $stmt_plano = $db->prepare("SELECT p.valor, GROUP_CONCAT(m.nome) as modalidades_nomes FROM planos p JOIN planos_modalidades pm ON p.id = pm.plano_id JOIN modalidades m ON pm.modalidade_id = m.id WHERE p.id = :id AND p.usuario_id = :uid GROUP BY p.id");
    $stmt_plano->bindValue(':id', $novo_plano_id, SQLITE3_INTEGER);
    $stmt_plano->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $plano_info = $stmt_plano->execute()->fetchArray(SQLITE3_ASSOC);

    if ($plano_info) {
        $novo_valor = $plano_info['valor'];
        $novas_modalidades = $plano_info['modalidades_nomes'];
        $novo_vencimento = date('Y-m-d', strtotime('+1 month'));

        $db->exec('BEGIN');

        // 2. Atualizar os dados do aluno
        $stmt_update = $db->prepare("UPDATE alunos SET plano_id = :plano_id, turma_id = :turma_id, modalidades = :modalidades, valor_mensalidade = :valor, ultimo_pagamento = :ultimo_pag, proximo_vencimento = :prox_venc, status = 'ativo' WHERE id = :id AND usuario_id = :uid");
        $stmt_update->bindValue(':plano_id', $novo_plano_id, SQLITE3_INTEGER);
        $stmt_update->bindValue(':turma_id', $nova_turma_id, SQLITE3_INTEGER);
        $stmt_update->bindValue(':modalidades', $novas_modalidades, SQLITE3_TEXT);
        $stmt_update->bindValue(':valor', $novo_valor, SQLITE3_FLOAT);
        $stmt_update->bindValue(':ultimo_pag', date('Y-m-d'), SQLITE3_TEXT);
        $stmt_update->bindValue(':prox_venc', $novo_vencimento, SQLITE3_TEXT);
        $stmt_update->bindValue(':id', $aluno_id, SQLITE3_TEXT);
        $stmt_update->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt_update->execute();

        // 3. Registrar o pagamento da renovação
        $aluno_nome = $db->querySingle("SELECT nome_completo FROM alunos WHERE id = '{$aluno_id}'");
        $stmt_pag = $db->prepare("INSERT INTO pagamentos (aluno_id, usuario_id, nome_aluno, mes_referencia, data_pagamento, valor, status) VALUES (:aluno_id, :uid, :nome, :mes_ref, :data_pag, :valor, 'pago')");
        $stmt_pag->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
        $stmt_pag->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
        $stmt_pag->bindValue(':nome', $aluno_nome, SQLITE3_TEXT);
        $stmt_pag->bindValue(':mes_ref', date('Y-m-01'), SQLITE3_TEXT);
        $stmt_pag->bindValue(':data_pag', date('Y-m-d'), SQLITE3_TEXT);
        $stmt_pag->bindValue(':valor', $novo_valor, SQLITE3_FLOAT);
        $stmt_pag->execute();

        $db->exec('COMMIT');
        $_SESSION['msg'] = "Plano do aluno renovado com sucesso!";
    } else {
        $_SESSION['msg'] = "Erro: Plano selecionado para renovação não encontrado.";
    }
    header("Location: ?p=pagamentos");
    exit();
}

// Verificar status de pagamento dos alunos
$hoje = date('Y-m-d'); 
$stmt_vencidos = $db->prepare("UPDATE alunos SET status='inativo' WHERE status='ativo' AND proximo_vencimento < :hoje AND usuario_id = :usuario_id"); // Não mexe em alunos 'bloqueado'
$stmt_vencidos->bindValue(':hoje', $hoje, SQLITE3_TEXT);
$stmt_vencidos->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$stmt_vencidos->execute();

// Consultar estatísticas para o dashboard
$total_alunos = $db->querySingle("SELECT COUNT(*) FROM alunos WHERE usuario_id = $usuario_id_logado");
$total_ativos = $db->querySingle("SELECT COUNT(*) FROM alunos WHERE status='ativo' AND usuario_id = $usuario_id_logado");
$total_inativos = $db->querySingle("SELECT COUNT(*) FROM alunos WHERE status='inativo' AND usuario_id = $usuario_id_logado");

// Calcular receita do mês
$mes_atual = date('Y-m');
$query_receita = "SELECT SUM(p.valor) FROM pagamentos p";
if ($usuario_tipo === 'professor' && $professor_id_associado) {
    // Se for professor, junta com a tabela de alunos para filtrar pelo professor_id
    $query_receita .= " JOIN alunos a ON p.aluno_id = a.id WHERE a.professor_id = :professor_id AND p.status='pago' AND strftime('%Y-%m', p.data_pagamento) = :mes_atual AND p.usuario_id = :usuario_id";
} else {
    // Se for admin, a consulta original
    $query_receita .= " WHERE p.status='pago' AND strftime('%Y-%m', p.data_pagamento) = :mes_atual AND p.usuario_id = :usuario_id";
}

$stmt_receita = $db->prepare($query_receita);
$stmt_receita->bindValue(':mes_atual', $mes_atual, SQLITE3_TEXT);
$stmt_receita->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
if ($usuario_tipo === 'professor' && $professor_id_associado) {
    $stmt_receita->bindValue(':professor_id', $professor_id_associado, SQLITE3_INTEGER);
}
$resultado_receita = $stmt_receita->execute();
$receita_mes = 0;
if ($resultado_receita) {
    $valor = $resultado_receita->fetchArray(SQLITE3_NUM);
    $receita_mes = $valor[0] ?? 0;
}

// Calcular despesas pagas do mês
$query_despesas = "SELECT SUM(valor) FROM despesas WHERE usuario_id = :usuario_id AND ( (status = 'pago' AND strftime('%Y-%m', mes_referencia) = :mes_atual) OR (status = 'pendente' AND strftime('%Y-%m', data_vencimento) = :mes_atual) )";

// A lógica de despesas por professor não está clara. Assumindo que despesas são por usuário (academia) e não por professor individual.
// Se precisar filtrar despesas por professor, a estrutura do banco precisaria ser alterada para associar despesas a professores.
// Por enquanto, professores verão as despesas totais da academia a que pertencem.

$stmt_despesas_mes = $db->prepare($query_despesas);
$stmt_despesas_mes->bindValue(':mes_atual', $mes_atual, SQLITE3_TEXT);
$stmt_despesas_mes->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

// Se a lógica fosse filtrar por professor, seria algo assim, mas requer mudança no schema:
// if ($usuario_tipo === 'professor' && $professor_id_associado) {
//     $query_despesas = "SELECT SUM(valor) FROM despesas WHERE professor_id = :professor_id AND ...";
//     $stmt_despesas_mes = $db->prepare($query_despesas);
//     $stmt_despesas_mes->bindValue(':professor_id', $professor_id_associado, SQLITE3_INTEGER);
//     // ... outros binds
// } else {
//     // consulta de admin
// }

$resultado_despesas = $stmt_despesas_mes->execute();
$despesas_mes = 0;
if ($resultado_despesas) {
    $valor_despesa = $resultado_despesas->fetchArray(SQLITE3_NUM);
    $despesas_mes = $valor_despesa[0] ?? 0;
}

// Consultar despesas pagas e futuras
$despesas_pagas = [];
$despesas_futuras = [];
$stmt_despesas_lista = $db->prepare("SELECT * FROM despesas WHERE usuario_id = :usuario_id ORDER BY data_vencimento DESC, mes_referencia DESC");
$stmt_despesas_lista->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$resultado_despesas_lista = $stmt_despesas_lista->execute();
if ($resultado_despesas_lista) {
    while ($row = $resultado_despesas_lista->fetchArray(SQLITE3_ASSOC)) {
        if ($row['status'] == 'pago') {
            $despesas_pagas[] = $row;
        } else {
            // Considera 'pendente' ou qualquer outro status como futuro/a pagar
            $despesas_futuras[] = $row;
        }
    }
}

$balanco_mes = $receita_mes - $despesas_mes;

// Calcular total de despesas futuras (pendentes)
$stmt_despesas_futuras_mes = $db->prepare("
    SELECT SUM(valor) FROM despesas
    WHERE status='pendente' AND usuario_id = :usuario_id AND strftime('%Y-%m', data_vencimento) = :mes_atual
");
$stmt_despesas_futuras_mes->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$stmt_despesas_futuras_mes->bindValue(':mes_atual', $mes_atual, SQLITE3_TEXT);
$resultado_despesas_futuras = $stmt_despesas_futuras_mes->execute();
$total_despesas_futuras = $resultado_despesas_futuras->fetchArray(SQLITE3_NUM)[0] ?? 0;


// Alunos próximos a vencer (3 dias)
$tres_dias_futuro = date('Y-m-d', strtotime('+3 days'));
$alunos_proximo_vencer = [];

// 1. Primeiro, selecionamos TODOS os alunos que estão no período de vencimento para exibi-los na dashboard.
$stmt_vencer = $db->prepare("
    SELECT * FROM alunos 
    WHERE status='ativo' 
      AND proximo_vencimento BETWEEN :hoje AND :tres_dias 
      AND usuario_id = :usuario_id 
    ORDER BY proximo_vencimento
");
$stmt_vencer->bindValue(':hoje', $hoje, SQLITE3_TEXT);
$stmt_vencer->bindValue(':tres_dias', $tres_dias_futuro, SQLITE3_TEXT);
$stmt_vencer->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

$resultado_vencer = $stmt_vencer->execute();
if ($resultado_vencer) {
    while ($row = $resultado_vencer->fetchArray(SQLITE3_ASSOC)) {
        // Adiciona o aluno à lista para exibição
        $alunos_proximo_vencer[] = $row;
    }
}

// Consultar alunos para a tela de pagamentos
$pagamentos_vencidos = [];
$pagamentos_pagos = [];
$hoje = date('Y-m-d');
$tres_dias_futuro = date('Y-m-d', strtotime('+3 days'));

$stmt_pagamentos = $db->prepare("
    SELECT a.*, p.nome as plano_nome 
    FROM alunos a 
    LEFT JOIN planos p ON a.plano_id = p.id 
    WHERE a.usuario_id = :usuario_id 
    ORDER BY a.proximo_vencimento ASC
");
$stmt_pagamentos->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$res_pagamentos = $stmt_pagamentos->execute();
while($aluno = $res_pagamentos->fetchArray(SQLITE3_ASSOC)) {
    if ($aluno['status'] === 'inativo') {
        $pagamentos_vencidos[] = $aluno;
    } elseif ($aluno['status'] === 'ativo' && $aluno['proximo_vencimento'] > $tres_dias_futuro) {
        $pagamentos_pagos[] = $aluno;
    }
}

// Consultar alunos
$alunos = [];
$stmt_alunos = $db->prepare("
    SELECT 
        a.*, 
        p.nome as plano_nome,
        prof.nome as professor_nome
    FROM alunos a 
    LEFT JOIN planos p ON a.plano_id = p.id 
    LEFT JOIN alunos_turmas at ON a.id = at.aluno_id
    LEFT JOIN turmas t ON at.turma_id = t.id
    LEFT JOIN professores prof ON t.professor_id = prof.id
    WHERE a.usuario_id = :usuario_id GROUP BY a.id ORDER BY a.nome_completo");
$stmt_alunos->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$resultado_alunos = $stmt_alunos->execute();
if ($resultado_alunos) {
    while ($row = $resultado_alunos->fetchArray(SQLITE3_ASSOC)) {
        // Buscar turmas para cada aluno
        $turmas_aluno = [];
        $stmt_turmas_aluno = $db->prepare("SELECT t.tipo, t.dias_semana, t.horario_inicio FROM alunos_turmas at JOIN turmas t ON at.turma_id = t.id WHERE at.aluno_id = :aluno_id");
        $stmt_turmas_aluno->bindValue(':aluno_id', $row['id'], SQLITE3_TEXT);
        $res_turmas_aluno = $stmt_turmas_aluno->execute();
        while($turma_row = $res_turmas_aluno->fetchArray(SQLITE3_ASSOC)) { $turmas_aluno[] = $turma_row; }
        // Buscar graduações para cada aluno
        $graduacoes_aluno = [];
        $stmt_grad = $db->prepare("SELECT m.nome as modalidade_nome, g.nome as graduacao_nome FROM alunos_graduacoes ag JOIN graduacoes g ON ag.graduacao_id = g.id JOIN modalidades m ON g.modalidade_id = m.id WHERE ag.aluno_id = :aluno_id");
        $stmt_grad->bindValue(':aluno_id', $row['id'], SQLITE3_TEXT);
        $res_grad = $stmt_grad->execute();
        while($grad_row = $res_grad->fetchArray(SQLITE3_ASSOC)) { $graduacoes_aluno[] = $grad_row; }
        $alunos[] = $row;
        $alunos[count($alunos)-1]['graduacoes'] = $graduacoes_aluno;
        $alunos[count($alunos)-1]['turmas'] = $turmas_aluno;
    }
}

// Consultar turmas para o formulário
$turmas = [];
$stmt_turmas = $db->prepare("
    SELECT 
        t.id, t.dias_semana, t.horario_inicio, t.horario_fim, t.tipo,        
        p.nome as professor_nome,
        GROUP_CONCAT(m.nome) as modalidades_nomes,
        GROUP_CONCAT(m.id) as modalidades_ids
    FROM turmas t
    JOIN professores p ON t.professor_id = p.id
    LEFT JOIN turmas_modalidades tm ON t.id = tm.turma_id
    LEFT JOIN modalidades m ON tm.modalidade_id = m.id
    WHERE t.usuario_id = :usuario_id 
    GROUP BY t.id
    ORDER BY m.nome, t.tipo, t.dias_semana
");
$stmt_turmas->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$resultado_turmas = $stmt_turmas->execute();
if ($resultado_turmas) {
    while ($row = $resultado_turmas->fetchArray(SQLITE3_ASSOC)) {
        $turmas[] = $row;
    }
}



// Consultar professores para o formulário
$professores = [];
$stmt_professores = $db->prepare("SELECT id, nome, cpf, status FROM professores WHERE usuario_id = :usuario_id ORDER BY nome");
$stmt_professores->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$resultado_professores = $stmt_professores->execute();
if ($resultado_professores) {
    while ($row = $resultado_professores->fetchArray(SQLITE3_ASSOC)) {
        $professores[] = $row;
    }
}

// Consultar planos para os formulários
$planos = [];
$stmt_planos = $db->prepare("
    SELECT 
        p.*, 
        GROUP_CONCAT(m.nome) as modalidades_nomes
    FROM planos p
    LEFT JOIN planos_modalidades pm ON p.id = pm.plano_id
    LEFT JOIN modalidades m ON pm.modalidade_id = m.id
    WHERE p.usuario_id = :usuario_id 
    GROUP BY p.id
    ORDER BY p.nome
");
$stmt_planos->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$resultado_planos = $stmt_planos->execute();
while ($row = $resultado_planos->fetchArray(SQLITE3_ASSOC)) {
    $planos[] = $row;
}

// Consultar modalidades para os formulários
$modalidades_disponiveis = [];
$stmt_modalidades = $db->prepare("SELECT * FROM modalidades ORDER BY nome");
$resultado_modalidades = $stmt_modalidades->execute();
if ($resultado_modalidades) {
    while ($row = $resultado_modalidades->fetchArray(SQLITE3_ASSOC)) {
        $modalidades_disponiveis[] = $row;
    }
}

// Consultar graduações para os formulários
$graduacoes_disponiveis = [];
$stmt_graduacoes = $db->prepare("SELECT * FROM graduacoes ORDER BY modalidade_id, ordem");
$resultado_graduacoes = $stmt_graduacoes->execute();
if ($resultado_graduacoes) {
    while ($row = $resultado_graduacoes->fetchArray(SQLITE3_ASSOC)) {
        $graduacoes_disponiveis[$row['modalidade_id']][] = $row;
    }
}

// Consultar todos os usuários (para admin)
$usuarios_cadastrados = [];
// A permissão 'usuarios' agora controla o acesso a esta lista.
if (tem_permissao('usuarios', $permissoes_usuario)) {
    $query_usuarios = "SELECT u.*, p.nome as professor_nome FROM usuarios u LEFT JOIN professores p ON u.professor_id = p.id";
    // Se não for o admin principal, filtra para ver apenas os usuários que ele criou.
    if ($usuario_id_logado != 1) {
        $query_usuarios .= " WHERE u.criado_por_id = :criador_id";
    }
    $query_usuarios .= " ORDER BY u.nome_academia, u.email";

    $stmt_usuarios = $db->prepare($query_usuarios);
    if ($usuario_id_logado != 1) {
        $stmt_usuarios->bindValue(':criador_id', $usuario_id_logado, SQLITE3_INTEGER);
    }
    $res_usuarios = $stmt_usuarios->execute();
    while ($row = $res_usuarios->fetchArray(SQLITE3_ASSOC)) {
        $usuarios_cadastrados[] = $row;
    }
}

// Consultar alunos para a lista de presença do dia
$alunos_do_dia = [];
$dias_semana_map = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
$dia_hoje = $dias_semana_map[date('w')];
$stmt_presenca = $db->prepare("
    SELECT a.id, a.nome_completo 
    FROM alunos a
    JOIN turmas t ON a.turma_id = t.id
    WHERE a.status = 'ativo' AND a.usuario_id = :uid AND t.dias_semana LIKE :dia_hoje
");
$stmt_presenca->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
$stmt_presenca->bindValue(':dia_hoje', '%' . $dia_hoje . '%', SQLITE3_TEXT);
$res_presenca = $stmt_presenca->execute();
while($row = $res_presenca->fetchArray(SQLITE3_ASSOC)) {
    $alunos_do_dia[] = $row;
}

// Consultar usuários pendentes (apenas para o admin principal, id=1)
$usuarios_para_analise = [];
if ($usuario_id_logado == 1) {
    $stmt_pendentes = $db->prepare("SELECT id, email, nome_academia, status FROM usuarios WHERE status = 'pendente' OR status = 'rejeitado'");
    $resultado_pendentes = $stmt_pendentes->execute();
    if ($resultado_pendentes) {
        while ($row = $resultado_pendentes->fetchArray(SQLITE3_ASSOC)) {
            $usuarios_para_analise[] = $row;
        }
    }
}

// Consultar valores dos planos
$valor_muay_thai = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'valor_muay_thai' AND usuario_id = $usuario_id_logado") ?: '120.00';
$valor_jiu_jitsu = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'valor_jiu_jitsu' AND usuario_id = $usuario_id_logado") ?: '130.00';
$valor_total_pass = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'valor_total_pass' AND usuario_id = $usuario_id_logado") ?: '200.00';

// Consultar logo
$logo_path = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'logo_path' AND usuario_id = $usuario_id_logado") ?: '';
$nome_academia = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'nome_academia' AND usuario_id = $usuario_id_logado") ?: 'Sua Academia';

// Consultar configurações para preencher o formulário
$botbot_appkey = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'botbot_appkey' AND usuario_id = $usuario_id_logado") ?: '';
$botbot_authkey = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'botbot_authkey' AND usuario_id = $usuario_id_logado") ?: '';


// Redirecionamento para evitar reenvio do formulário
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestão</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        // Os dados dos alunos são injetados aqui para garantir que estejam disponíveis globalmente
        // antes que qualquer outra função seja definida ou chamada.
        window.alunos = <?php echo json_encode($alunos); ?>;

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function filtrarAlunos() {
            // Declara as variáveis
            let input, filter, table, tr, td, i, txtValue;
            input = document.getElementById("search-aluno");
            filter = input.value.toUpperCase();
            table = document.getElementById("tabela-alunos");
            tr = table.getElementsByTagName("tr");

            // Percorre todas as linhas da tabela e esconde as que não correspondem à pesquisa
            for (i = 0; i < tr.length; i++) {
                td = tr[i].getElementsByTagName("td")[0]; // A coluna 0 é a do "Nome"
                if (td) {
                    txtValue = td.textContent || td.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        function openGraduacaoModal(modalidadeId, modalidadeNome) {
            document.getElementById('modalidade_id_grad').value = modalidadeId;
            document.getElementById('modalidade_id_grad_edit').value = modalidadeId;
            document.getElementById('graduacao-modal-title').innerText = `Gerenciar Graduações - ${modalidadeNome}`;
            
            const listaContainer = document.getElementById('lista-graduacoes-existentes');
            listaContainer.innerHTML = '<p>Carregando...</p>'; // Feedback para o usuário

            fetch(`get_graduacoes.php?modalidade_id=${modalidadeId}`) // A API get_graduacoes já foi ajustada para não precisar de usuario_id
                .then(response => response.json())
                .then(graduacoes => {
                    listaContainer.innerHTML = ''; // Limpa o container
                    if (graduacoes.length === 0) {
                        listaContainer.innerHTML = '<p>Nenhuma graduação cadastrada para esta modalidade.</p>';
                    } else {
                        const table = document.createElement('table');
                        table.innerHTML = '<thead><tr><th>Ordem</th><th>Nome</th><th>Ações</th></tr></thead>';
                        const tbody = document.createElement('tbody');
                        graduacoes.forEach(grad => {
                            tbody.innerHTML += `
                                <tr>
                                    <td>${grad.ordem}</td>
                                    <td>${grad.nome.replace(/'/g, "\\'")}</td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-sm" onclick="openEditGraduacaoModal(${grad.id}, '${grad.nome.replace(/'/g, "\\'")}', ${grad.ordem})"><i class="fas fa-edit"></i></button>
                                            <form method="POST" onsubmit="return confirm('Deseja remover esta graduação?');" style="display:inline;"><input type="hidden" name="graduacao_id_remover" value="${grad.id}"><button type="submit" name="remover_graduacao" class="btn btn-sm" style="background:var(--danger);"><i class="fas fa-trash"></i></button></form>
                                        </div>
                                    </td>
                                </tr>`;
                        });
                        table.appendChild(tbody);
                        listaContainer.appendChild(table);
                    }
                    openModal('modal-graduacoes');
                });
        }

        function openEditUsuarioModal(usuario) {
            document.getElementById('usuario_id_edit').value = usuario.id;
            document.getElementById('email_professor_edit').value = usuario.email;
            document.getElementById('professor_id_usuario_edit').value = usuario.professor_id || '';

            // Limpa permissões antigas
            document.querySelectorAll('#permissoes-container-edit input[type="checkbox"]').forEach(cb => cb.checked = false);

            // Carrega e marca as permissões atuais do usuário
            fetch(`get_permissoes_usuario.php?id=${usuario.id}`)
                .then(response => response.json())
                .then(permissoes => {
                    if (permissoes && permissoes.length > 0) {
                        permissoes.forEach(modulo => {
                            const checkbox = document.getElementById(`perm_edit_${modulo}`);
                            if (checkbox) checkbox.checked = true;
                        });
                    }
                    openModal('modal-editar-usuario');
                });
        }

        function openAlterarSenhaModal(usuarioId, usuarioEmail) {
            document.getElementById('usuario_id_senha').value = usuarioId;
            document.getElementById('usuario_email_senha').textContent = usuarioEmail;
            document.getElementById('nova_senha').value = '';
            document.getElementById('nova_senha_confirm').value = '';
            closeModal('modal-editar-usuario'); // Fecha o modal de edição se estiver aberto
            openModal('modal-alterar-senha');
        }


        function openFrequenciaModal(alunoId, alunoNome) {
            document.getElementById('frequencia-modal-title').innerText = `Frequência - ${alunoNome}`;
            const calendarBody = document.getElementById('frequencia-calendar-body');
            calendarBody.innerHTML = '<tr><td colspan="7">Carregando...</td></tr>';
            openModal('modal-frequencia');

            fetch(`get_frequencia_aluno.php?aluno_id=${alunoId}`)
                .then(response => response.json())
                .then(data => {
                    renderCalendar(data.registros, data.dias_treino, data.data_inicio);
                });
        }

        function renderCalendar(frequencia, diasTreinoStr, dataInicioAluno) {
            const calendarBody = document.getElementById('frequencia-calendar-body');
            calendarBody.innerHTML = '';
            const hoje = new Date();
            const mes = hoje.getMonth();
            const ano = hoje.getFullYear();

            const primeiroDia = new Date(ano, mes, 1).getDay();
            const diasNoMes = new Date(ano, mes + 1, 0).getDate();
            const diasSemanaMapa = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
            // Converte a string 'Seg, Qua, Sex' em um array ['Seg', 'Qua', 'Sex']
            const diasDeTreino = diasTreinoStr ? diasTreinoStr.split(',').map(d => d.trim()) : [];
            const dataInicio = dataInicioAluno ? new Date(dataInicioAluno + 'T00:00:00') : null;

            let data = 1;
            for (let i = 0; i < 6; i++) {
                let row = document.createElement('tr');
                for (let j = 0; j < 7; j++) {
                    if (i === 0 && j < primeiroDia) {
                        let cell = document.createElement('td');
                        row.appendChild(cell);
                    } else if (data > diasNoMes) {
                        break;
                    } else {
                        let cell = document.createElement('td');
                        cell.innerText = data;
                        const dataFormatada = `${ano}-${String(mes + 1).padStart(2, '0')}-${String(data).padStart(2, '0')}`;
                        const dataAtualCalendario = new Date(ano, mes, data);
                        const diaDaSemanaAtual = diasSemanaMapa[dataAtualCalendario.getDay()];
                        
                        const registro = frequencia.find(f => f.data_presenca === dataFormatada);
                        if (registro) {
                            // Se existe um registro ('presente' ou 'falta'), aplica a classe
                            cell.classList.add(registro.status === 'presente' ? 'presenca-presente' : 'presenca-falta');
                            cell.title = registro.status.charAt(0).toUpperCase() + registro.status.slice(1);
                        } else if (diasDeTreino.includes(diaDaSemanaAtual) && dataAtualCalendario < hoje && (!dataInicio || dataAtualCalendario >= dataInicio)) {
                            // Se não há registro, mas era um dia de treino e a data já passou, marca como falta.
                            cell.classList.add('presenca-falta');
                            cell.title = 'Falta (não registrado)';
                        } else {
                            // Se não era dia de treino ou o dia ainda não passou, a célula fica em branco.
                            cell.title = diaDaSemanaAtual;
                        }

                        if (data === hoje.getDate()) {
                            cell.classList.add('hoje');
                        }
                        row.appendChild(cell);
                        data++;
                    }
                }
                calendarBody.appendChild(row);
            }
        }

        function gerarCarteirinha(alunoId) {
            if (!window.alunos) {
                console.error("A lista de alunos (window.alunos) ainda não foi carregada.");
                alert("Erro: Os dados dos alunos ainda não estão prontos. Tente novamente em um instante.");
                return;
            }
            const aluno = window.alunos.find(a => a.id === alunoId);
            
            if (!aluno) {
                alert('Aluno não encontrado!');
                return;
            }

            const linkCarteirinha = `https://samuraisoft.com.br/carteirinha.php?id=${aluno.id}`;
            const statusClass = aluno.status === 'ativo' ? 'status-ativo' : 'status-inativo';
            const statusText = aluno.status.charAt(0).toUpperCase() + aluno.status.slice(1);

            // Formata as graduações
            let graduacoesHTML = '';
            if (aluno.graduacoes && aluno.graduacoes.length > 0) {
                graduacoesHTML = '<div class="carteirinha-info-item"><span class="label"><i class="fas fa-medal"></i> Graduação</span><span class="value">';
                graduacoesHTML += aluno.graduacoes.map(g => `${g.modalidade_nome}: ${g.graduacao_nome}`).join('<br>');
                graduacoesHTML += '</span></div>';
            }

            // Formata as turmas
            let turmasHTML = '';
            if (aluno.turmas && aluno.turmas.length > 0) {
                turmasHTML = '<div class="carteirinha-info-item"><span class="label"><i class="fas fa-clock"></i> Turmas</span><span class="value">';
                turmasHTML += aluno.turmas.map(t => `${t.tipo} (${t.dias_semana} ${t.horario_inicio})`).join('<br>');
                turmasHTML += '</span></div>';
            }

            const carteirinhaHTML = `
                <div class="carteirinha">
                    <div class="carteirinha-header">
                        <div class="carteirinha-logo">
                            <?php if ($logo_path && file_exists($logo_path)): ?>
                                <img src="<?php echo $logo_path; ?>" alt="Logo">
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($nome_academia); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="carteirinha-qr" id="qrcode-container"></div>
                    </div>
                    <div class="carteirinha-body">
                        <div class="carteirinha-nome">${aluno.nome_completo}</div>
                        <div class="carteirinha-id">ID: ${aluno.id}</div>
                        <div class="carteirinha-info">
                            <div class="carteirinha-info-item"><span class="label"><i class="fas fa-tags"></i> Plano</span><span class="value">${aluno.plano_nome || 'N/A'}</span></div>
                            <div class="carteirinha-info-item"><span class="label"><i class="fas fa-calendar-times"></i> Vencimento</span><span class="value">${new Date(aluno.proximo_vencimento + 'T00:00:00').toLocaleDateString('pt-BR')}</span></div>
                            ${graduacoesHTML}
                            ${turmasHTML}
                        </div>
                    </div>
                    <div class="carteirinha-footer">
                        <div class="carteirinha-status ${statusClass}">${statusText}</div>
                    </div>
                    <br>
                    <button type="button" class="btn" onclick='enviarCarteirinhaPorWhatsApp(${JSON.stringify(aluno)})'><i class="fab fa-whatsapp"></i> Enviar por WhatsApp</button>
                </div>
            `;

            document.getElementById('carteirinha-container').innerHTML = carteirinhaHTML;
            new QRCode(document.getElementById('qrcode-container'), { 
                text: linkCarteirinha, 
                width: 90, 
                height: 90, 
                colorDark: "#16213e", // Cor escura para os módulos (corrigido)
                colorLight: "#ffffff"  // Cor clara para o fundo (corrigido)
            });
            openModal('modal-carteirinha');
        }
        
        function registrarPagamento(alunoId, alunoNome, valorMensalidade) {
            const aluno = window.alunos.find(a => a.id === alunoId);
            if (!aluno) {
                alert('Erro: Aluno não encontrado para registrar o pagamento.');
                return;
            }

            document.getElementById('aluno_nome_pagamento_display').value = alunoNome;
            document.getElementById('aluno_id_pagamento').value = alunoId;
            document.getElementById('aluno_nome_pagamento').value = alunoNome;
            const valor = parseFloat(valorMensalidade) || 0;
            document.getElementById('valor_pagamento').value = valor.toFixed(2);
            document.getElementById('mes_referencia').value = '<?php echo date('Y-m'); ?>';
            openModal('modal-pagamento');
        }

        function editarMensalidade(alunoId, alunoNome, valorAtual) {
            document.getElementById('aluno_id_edit').value = alunoId;
            document.getElementById('aluno_nome_edit').textContent = alunoNome;
            document.getElementById('valor_mensalidade_edit').value = valorAtual.toFixed(2);
            openModal('modal-edit-mensalidade');
        }

        function editarAluno(alunoId) {
            // Usar os dados já carregados em window.alunos para evitar uma nova requisição
            const aluno = window.alunos.find(a => a.id === alunoId);
            if (!aluno) {
                alert('Erro: Aluno não encontrado.');
                return;
            }

            // A função get_aluno.php ainda é útil para pegar as turmas e graduações associadas
            // que não estão na lista principal de alunos.
            fetch(`get_aluno.php?id=${alunoId}&include_associations=true`)
                .then(response => response.json())
                .then(alunoDetails => {
                    if (!alunoDetails) {
                        alert('Erro: Aluno não encontrado.');
                        return;
                    }

                    // Preenche os campos do formulário de edição
                    document.getElementById('aluno_id_edit_form').value = aluno.id;
                    document.getElementById('nome_completo_edit').value = aluno.nome_completo;
                    document.getElementById('plano_id_edit').value = aluno.plano_id || ''; // Aciona o 'change'

                    // Chama a função para ajustar a visibilidade dos campos com base no plano e pré-selecionar as graduações e turmas
                    toggleModalidadesEdit(alunoDetails.graduacoes, alunoDetails.turmas);

                    openModal('modal-editar-aluno');
                })
                .catch(error => {
                    console.error('Erro ao buscar dados do aluno:', error);
                    alert('Ocorreu um erro ao carregar os dados para edição.');
                });
        }
        
        function editarAlunoPessoal(alunoId) {
            const aluno = window.alunos.find(a => a.id === alunoId);
            if (!aluno) { alert('Erro: Aluno não encontrado.'); return; }
            document.getElementById('aluno_id_edit_pessoal').value = aluno.id;
            document.getElementById('cpf_edit').value = aluno.cpf;
            document.getElementById('data_nascimento_edit').value = aluno.data_nascimento;
            document.getElementById('telefone_edit').value = aluno.telefone;
            openModal('modal-editar-aluno-pessoal');
        }

        function toggleModalidadesEdit(graduacoesAtuais = [], turmasAtuais = []) {
            const planoId = document.getElementById('plano_id_edit').value;
            const container = document.getElementById('graduacoes-container-edit');
            container.innerHTML = ''; // Limpa o container

            if (!planoId) return;

            const selectedOption = document.querySelector(`#plano_id_edit option[value="${planoId}"]`);
            const modalidadesIds = selectedOption.dataset.modalidadesIds ? selectedOption.dataset.modalidadesIds.split(',') : [];
            
            modalidadesIds.forEach(modId => {
                const selectHTML = document.querySelector(`.graduacao-select-template[data-modalidade-id="${modId}"]`);
                if (selectHTML) {
                    const clone = selectHTML.cloneNode(true);
                    clone.style.display = 'block';
                    const select = clone.querySelector('select');
                    select.name = 'graduacoes_edit[]';

                    // Verifica se há uma graduação atual para esta modalidade e a seleciona
                    const graduacaoAtual = graduacoesAtuais.find(gradId => select.querySelector(`option[value="${gradId}"]`));
                    if (graduacaoAtual) select.value = graduacaoAtual;

                    container.appendChild(clone);
                }
            });

            // Pré-seleciona as turmas do aluno
            const turmasContainer = document.getElementById('turmas-checkbox-container-edit');
            filtrarTurmasPorPlano('edit', turmasAtuais); // Chama o filtro para o modal de edição
            turmasAtuais.forEach(turmaId => {
                const checkbox = turmasContainer.querySelector(`input[value="${turmaId}"]`);
                if (checkbox) checkbox.checked = true;
            });
        }
        // --- LÓGICA DO FORMULÁRIO DE CADASTRO MULTI-ETAPAS ---
        function irParaEtapa2() {
            // Validação simples para garantir que o plano foi selecionado
            const planoId = document.getElementById('plano_id').value;
            if (!planoId) {
                alert('Por favor, selecione um plano antes de continuar.');
                document.getElementById('plano_id').focus();
                return;
            }
            
            document.getElementById('cadastro-etapa-1').style.display = 'none';
            document.getElementById('cadastro-etapa-2').style.display = 'block';
            toggleModalidades(); // Adiciona esta linha para carregar as graduações
            filtrarTurmasPorPlano();
        }

        function voltarParaEtapa1() {
            document.getElementById('cadastro-etapa-2').style.display = 'none'; 
            document.getElementById('cadastro-etapa-1').style.display = 'block';
        }

        function toggleModalidades() {
            const planoIdEl = document.getElementById('plano_id');
            if (!planoIdEl) return;

            const planoId = planoIdEl.value;
            const container = document.getElementById('graduacoes-container');
            if (!container) return;
            
            container.innerHTML = ''; // Limpa o container

            if (!planoId) return;

            const selectedOption = document.querySelector(`#plano_id option[value="${planoId}"]`);
            if (!selectedOption) return;

            const modalidadesIds = selectedOption.dataset.modalidadesIds ? selectedOption.dataset.modalidadesIds.split(',') : [];
            
            modalidadesIds.forEach(modId => {
                const selectHTML = document.querySelector(`.graduacao-select-template[data-modalidade-id="${modId}"]`);
                if (selectHTML) {
                    const clone = selectHTML.cloneNode(true);
                    clone.style.display = 'block';
                    container.appendChild(clone);
                }
            });
        }

        function filtrarTurmasPorPlano(context = 'cadastro', turmasPreSelecionadas = []) {
            const planoId = document.getElementById(`plano_id${context === 'edit' ? '_edit' : ''}`).value;
            const turmasContainer = document.getElementById(`turmas-checkbox-container${context === 'edit' ? '-edit' : ''}`);
            turmasContainer.innerHTML = '<p style="color: var(--text-secondary);">Carregando turmas...</p>';

            if (!planoId) {
                turmasContainer.innerHTML = '<p style="color: var(--text-secondary);">Selecione um plano na etapa anterior para ver as turmas.</p>';
                return; // Se nenhum plano for selecionado, não faz nada.
            }

            const selectedOption = document.querySelector(`#plano_id${context === 'edit' ? '_edit' : ''} option[value="${planoId}"]`);
            const modalidadesIds = selectedOption.dataset.modalidadesIds ? selectedOption.dataset.modalidadesIds.split(',') : [];
            let turmasEncontradas = 0;
            document.querySelectorAll('#turmas-disponiveis-data option').forEach(option => {
                const turmaModalidades = option.dataset.modalidadesIds ? option.dataset.modalidadesIds.split(',') : [];
                // Adiciona a turma se alguma de suas modalidades estiver presente nas modalidades do plano
                if (turmaModalidades.some(turmaModId => modalidadesIds.includes(turmaModId))) {
                    if (turmasEncontradas === 0) {
                        turmasContainer.innerHTML = ''; // Limpa a mensagem "Carregando..."
                    }
                    const turmaId = option.value;
                    const turmaData = JSON.parse(option.dataset.turmaData);

                    // Filtra as modalidades da turma para mostrar apenas as que estão no plano do aluno
                    const modalidadesRelevantes = turmaData.modalidades_nomes.filter(nome => 
                        modalidadesIds.includes(turmaData.modalidades_ids[turmaData.modalidades_nomes.indexOf(nome)])
                    );

                    // Monta o novo label
                    const turmaLabel = `${turmaData.tipo} - ${modalidadesRelevantes.join(', ')} (${turmaData.dias_semana} ${turmaData.horario_inicio}-${turmaData.horario_fim})`;

                    const isChecked = turmasPreSelecionadas.includes(parseInt(turmaId)) ? 'checked' : '';
                    const checkboxHTML = `
                        <div class="checkbox-item">
                            <input type="checkbox" id="turma_check_${context}_${turmaId}" name="turma_id[]" value="${turmaId}" ${isChecked}>
                            <label for="turma_check_${context}_${turmaId}">${turmaLabel}</label>
                        </div>
                    `;
                    turmasContainer.insertAdjacentHTML('beforeend', checkboxHTML);
                    turmasEncontradas++;
                }
            });

            if (turmasEncontradas === 0) {
                turmasContainer.innerHTML = '<p style="color: var(--text-secondary);">Nenhuma turma encontrada para as modalidades deste plano.</p>';
            }
        }
        
        // Controla a exibição das seções
        function showSection(sectionId) {
            // Esconde o botão flutuante por padrão
            const fab = document.getElementById('despesas-fab');
            if (fab) fab.style.display = 'none';

            const mainContent = document.getElementById('main-content-area');
            if (!mainContent) return;
            mainContent.querySelectorAll('.content-section').forEach(section => {
                section.style.display = 'none';
            });
            const activeSection = document.getElementById(sectionId);
            if (activeSection) {
                activeSection.style.display = 'block';
            }

            // Mostra o botão flutuante de despesas na tela de despesas em mobile
            if (window.innerWidth <= 992 && sectionId === 'despesas-section') {
                if (fab) fab.style.display = 'block';
            }
        }

        

        function enviarCarteirinhaPorWhatsApp(aluno) {
            const formData = new FormData();
            formData.append('action', 'enviar_carteirinha_por_whatsapp');
            formData.append('aluno_id', aluno.id);
            formData.append('telefone', aluno.telefone);
            formData.append('nome', aluno.nome_completo);

            fetch('api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Adiciona uma notificação visual sobre o envio do WhatsApp
                const isSuccess = data.success === true;

                const alertContainer = document.querySelector('.container');
                const statusClass = isSuccess ? 'alert-success' : 'alert-danger';
                const iconClass = isSuccess ? 'fa-check-circle' : 'fa-exclamation-circle';
                const message = isSuccess ? 'Carteirinha enfileirada para envio via WhatsApp!' : `Falha ao enviar WhatsApp: ${data.message || 'Erro desconhecido.'}`;
                
                alertContainer.insertAdjacentHTML('afterbegin', `<div class="alert ${statusClass}"><i class="fas ${iconClass}"></i> ${message}</div>`);
            });
        }

        function enviarLembreteVencimento(alunoId, telefone, nome, valor, vencimento) {
            // Monta a mensagem
            const valorNumerico = parseFloat(valor) || 0;
            const mensagem = `Olá ${nome}, tudo bem?\n\nLembrete: sua mensalidade no valor de R$ ${valorNumerico.toFixed(2).replace('.', ',')} vence em ${new Date(vencimento + 'T00:00:00').toLocaleDateString('pt-BR')}.\n\nEvite a inativação do seu plano realizando o pagamento. Bons treinos! 💪`;
            
            // Abre o link do WhatsApp em uma nova aba para envio manual
            const telefoneLimpo = telefone.replace(/\D/g, '');
            const linkWhatsApp = `https://wa.me/55${telefoneLimpo}?text=${encodeURIComponent(mensagem)}`;
            window.open(linkWhatsApp, '_blank');
        }

        function openPaymentTab(evt, tabName) {
            // Esconde todos os conteúdos das abas
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("payment-tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }

            // Remove a classe "active" de todos os botões
            tablinks = document.getElementsByClassName("payment-tab-button");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
        }

        function openRenovarModal(alunoId, alunoNome, planoIdAtual) {
            document.getElementById('aluno_id_renovar').value = alunoId;
            document.getElementById('aluno_nome_renovar').textContent = alunoNome;
            document.getElementById('plano_id_renovar').value = planoIdAtual;
            // Dispara o evento change para carregar as turmas do plano atual
            document.getElementById('plano_id_renovar').dispatchEvent(new Event('change'));
            openModal('modal-renovar');
        }

        function filtrarTurmasRenovacao() {
            const planoId = document.getElementById('plano_id_renovar').value;
            const turmaSelect = document.getElementById('turma_id_renovar');
            turmaSelect.innerHTML = '<option value="">Carregando...</option>';

            if (!planoId) {
                turmaSelect.innerHTML = '<option value="">Selecione um plano</option>';
                return;
            }

            const selectedOption = document.querySelector(`#plano_id_renovar option[value="${planoId}"]`);
            const modalidadesIds = selectedOption.dataset.modalidadesIds ? selectedOption.dataset.modalidadesIds.split(',') : [];
            turmaSelect.innerHTML = '<option value="">Selecione uma turma</option>';
            document.querySelectorAll('#turmas-disponiveis-data option').forEach(option => { const turmaModalidades = option.dataset.modalidadesIds.split(','); if (turmaModalidades.some(turmaModId => modalidadesIds.includes(turmaModId))) { if (!turmaSelect.querySelector(`option[value="${option.value}"]`)) turmaSelect.add(option.cloneNode(true)); } });
        }

        document.addEventListener('DOMContentLoaded', function() {
        function initializePage() {
            const currentPage = new URLSearchParams(window.location.search).get('p') || 'dashboard';
            if (document.getElementById(`${currentPage}-section`)) {
                showSection(`${currentPage}-section`);
            } else {
                showSection('dashboard-section'); // Fallback
            }

            // Ativar o item de menu correto
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
                if (item.href && item.href.includes(`p=${currentPage}`)) {
                    item.classList.add('active');
                }
            });
        }
            initializePage();

            // Adiciona o listener para o seletor de plano
            const planoIdEl = document.getElementById('plano_id');
            if(planoIdEl) {
                toggleModalidades();
                planoIdEl.addEventListener('change', () => filtrarTurmasPorPlano('cadastro'));
            }

            // Adiciona os listeners para os checkboxes de modalidade
            const checkMT = document.getElementById('modalidade_muay_thai');
            if(checkMT) checkMT.addEventListener('change', function() {
                toggleModalidades();
            });
            const checkJJ = document.getElementById('modalidade_jiu_jitsu');
            if(checkJJ) checkJJ.addEventListener('change', toggleModalidades);

            // Listeners para o modal de edição
            const planoIdEditEl = document.getElementById('plano_id_edit');
            if(planoIdEditEl) planoIdEditEl.addEventListener('change', () => toggleModalidadesEdit([], [])); // Chama sem argumentos no 'change'
            const checkMTEdit = document.getElementById('modalidade_muay_thai_edit');
            if(checkMTEdit) checkMTEdit.addEventListener('change', toggleModalidadesEdit);
            const checkJJEdit = document.getElementById('modalidade_jiu_jitsu_edit');
            if(checkJJEdit) checkJJEdit.addEventListener('change', toggleModalidadesEdit);

            // Mascar para CPF
            const cpfEl = document.getElementById('cpf');
            if(cpfEl) cpfEl.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 11) value = value.slice(0, 11);
                
                if (value.length > 9) {
                    value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                } else if (value.length > 6) {
                    value = value.replace(/(\d{3})(\d{3})(\d+)/, '$1.$2.$3');
                } else if (value.length > 3) {
                    value = value.replace(/(\d{3})(\d+)/, '$1.$2');
                }
                
                e.target.value = value;
            });
            
            // Máscara para telefone
            const telEl = document.getElementById('telefone');
            if(telEl) telEl.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 11) value = value.slice(0, 11);
                
                if (value.length > 10) {
                    value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
                } else if (value.length > 6) {
                    value = value.replace(/(\d{2})(\d{4})(\d+)/, '($1) $2-$3');
                } else if (value.length > 2) {
                    value = value.replace(/(\d{2})(\d+)/, '($1) $2');
                }
                
                e.target.value = value;
            });

            // Lógica de navegação com JS para atualizar a URL e o conteúdo
            document.querySelectorAll('.sidebar a.nav-item, .mobile-nav a.nav-item').forEach(link => {
                if (link.href.includes('?p=')) { // Aplicar apenas a links de navegação de página
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        const url = new URL(this.href);
                        const page = url.searchParams.get('p') || 'dashboard';
                        
                        // Atualiza a URL sem recarregar a página
                        if (window.location.search !== url.search) {
                            history.pushState({page: page}, '', this.href);
                        }
                        
                        // Mostra a seção correta
                        showSection(`${page}-section`);

                        // Atualiza a classe 'active'
                        document.querySelectorAll('.nav-item').forEach(item => {
                            item.classList.remove('active');
                        });
                        
                        document.querySelectorAll(`.nav-item[href*="p=${page}"]`).forEach(activeLink => {
                            activeLink.classList.add('active');
                        });

                        if (page === 'alunos') {
                            const mobileBtn = document.querySelector('.card-actions-mobile .btn');
                            if(mobileBtn) mobileBtn.style.display = 'inline-block';
                        }
                    });
                }
            });

            // Abre a primeira aba de pagamentos por padrão
            if (document.getElementById("default-payment-tab")) {
                document.getElementById("default-payment-tab").click();
            }

            const testeAlunoBtn = document.getElementById('teste-aluno-btn');
            if(testeAlunoBtn) {
                testeAlunoBtn.addEventListener('click', function() {
                    const telefone = prompt("Por favor, informe o número de telefone para o aluno de teste:", "11999999999");
                    if (telefone) {
                        const formData = new FormData();
                        formData.append('action', 'cadastrar_aluno_teste');
                        formData.append('telefone', telefone);

                        fetch('api.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if(data.success) {
                                alert(data.message);
                                window.location.reload();
                            } else {
                                const errorMessage = 'Erro: ' + (data.message || 'Ocorreu um erro desconhecido.');
                                console.error('Falha ao criar aluno de teste:', data);
                                alert(errorMessage);
                            }
                        })
                        .catch(error => {
                            console.error('Erro na requisição para criar aluno de teste:', error);
                            alert('Ocorreu um erro de comunicação ao tentar criar o aluno de teste. Verifique o console para mais detalhes.');
                        });
                    }
                });
            }

            // Verifica se a URL tem o parâmetro para reabrir o modal de graduações
            const urlParams = new URLSearchParams(window.location.search);
            const reopenModalId = urlParams.get('reopen_grad_modal');
            if (reopenModalId) {
                const modalidadeNome = document.querySelector(`.graduacao-select-template[data-modalidade-id="${reopenModalId}"] label`).innerText.replace('Graduação (', '').replace(')', '');
                openGraduacaoModal(reopenModalId, modalidadeNome);
            }

        });

        // Fechar modal ao clicar fora dele
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        };
    </script>
    <style>
        :root {
            --primary: #1a1a2e;
            --secondary: #16213e;
            --accent: #0f3460;
            --highlight: #e94560;
            --text: #f0f0f0;
            --text-secondary: #b0b0b0;
            --success: #00cc88;
            --warning: #ffaa00;
            --danger: #ff5574;
            --card-bg: #1e2a47;
            --card-border: #2a3a5c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: var(--primary);
            color: var(--text);
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        header {
            background-color: var(--secondary);
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--accent);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo i {
            font-size: 2rem;
            color: var(--highlight);
        }
        
        .logo h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(45deg, var(--highlight), #ff7a00);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--accent), var(--highlight));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .sidebar {
            background-color: var(--secondary);
            border-radius: 12px;
            padding: 1.5rem 1rem;
            height: fit-content;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--card-border);
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .nav-item:hover, .nav-item.active {
            background-color: var(--accent);
        }
        
        .sidebar .nav-item i {
            width: 20px;
            text-align: center;
        }
        
        .content {
            display: grid;
            gap: 1.5rem;
        }
        
        .dashboard-grid {
            display: grid; /* Five columns for the new card */
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--card-border);
        }
        
        .stat-card {
            display: flex;
            flex-direction: column;
        }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(45deg, var(--highlight), #ff7a00);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-card h3 {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .stat-card .value {
            font-size: 2.2rem;
            font-weight: 700;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--card-border);
        }
        
        .card-header h2 {
            font-size: 1.4rem;
        }
        
        .btn {
            background: linear-gradient(45deg, var(--accent), var(--highlight));
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.9rem;
        }
        
        .table-responsive {
            /* overflow-x removido daqui para não cortar o dropdown */
        }
        
        table {
            width: 100%;
            overflow-x: auto; /* Adicionado aqui para manter a rolagem da tabela */
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--card-border);
        }
        
        th {
            color: var(--text-secondary);
            font-weight: 600;
        }
        
        tr:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-ativo {
            background-color: rgba(0, 204, 136, 0.15);
            color: var(--success);
        }
        
        .status-inativo {
            background-color: rgba(255, 85, 116, 0.15);
            color: var(--danger);
        }
        
        .status-pendente {
            background-color: rgba(255, 170, 0, 0.15);
            color: var(--warning);
        }
        
        .status-pago {
            background-color: rgba(0, 204, 136, 0.15);
            color: var(--success);
        }

        .status-pendente {
            background-color: rgba(255, 170, 0, 0.15);
            color: var(--warning);
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        input, select {
            width: 100%;
            padding: 0.8rem 1rem;
            border-radius: 6px;
            border: 1px solid var(--card-border);
            background-color: var(--secondary);
            color: var(--text);
            font-size: 1rem;
        }
        
        .checkbox-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap; /* Permite que os itens quebrem a linha */
            margin-top: 0.5rem;
        }
        
        .checkbox-item {
            flex-grow: 1;
        }

        .checkbox-item input[type="checkbox"] {
            display: none;
        }

        .checkbox-item label {
            background-color: var(--secondary);
            color: var(--text);
            padding: 0.8rem 1.2rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid var(--card-border);
            display: block;
            text-align: center;
            width: 100%;
        }

        .checkbox-item input[type="checkbox"]:checked + label {
            background: linear-gradient(45deg, var(--accent), var(--highlight));
            color: white;
            border-color: var(--highlight);
            font-weight: 600;
        }

        .checkbox-item label:hover {
            opacity: 0.9;
        }
        
        .checkbox-group-vertical {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.5rem;
            border: 1px solid var(--card-border);
            border-radius: 6px;
            padding: 1rem;
            background-color: var(--secondary);
        }

        .radio-group { display: flex; gap: 1rem; }
        .radio-group input[type="radio"] { display: none; }
        .radio-group label {
            padding: 0.5rem 1rem;
            border: 1px solid var(--card-border);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .radio-group input[type="radio"]:checked + label {
            font-weight: bold;
        }
        .radio-group input[value="presente"]:checked + label {
            background-color: var(--success);
            border-color: var(--success);
            color: var(--primary);
        }
        .radio-group input[value="falta"]:checked + label {
            background-color: var(--danger);
            border-color: var(--danger);
            color: var(--primary);
        }

        #frequencia-calendar { width: 100%; }
        #frequencia-calendar th { text-align: center; padding: 0.5rem; }
        #frequencia-calendar td {
            text-align: center;
            padding: 1rem 0.5rem;
            border: 1px solid var(--card-border);
            transition: background-color 0.2s;
        }
        #frequencia-calendar td.hoje {
            font-weight: bold;
            background-color: var(--accent);
            outline: 1px solid var(--text-secondary);
        }
        .presenca-presente {
            background-color: rgba(0, 204, 136, 0.5);
        }
        .presenca-falta {
            background-color: rgba(255, 85, 116, 0.5);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap; /* Alterado para permitir quebra de linha */
            justify-content: flex-end;
        }

        /* Dropdown Styles */
        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: var(--accent);
            min-width: 200px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 10;
            border-radius: 8px;
            padding: 0.5rem 0;
            border: 1px solid var(--card-border);
        }

        .dropdown-menu a, .dropdown-menu button {
            color: var(--text);
            padding: 0.75rem 1rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .dropdown-menu i.fa-fw {
            width: 1.28571429em;
            text-align: center;
        }

        .dropdown-menu a:hover, .dropdown-menu button:hover {
            background-color: var(--secondary);
        }

        /* Regra original para exibir o dropdown, agora vai funcionar */
        .dropdown:hover .dropdown-menu, .dropdown:focus-within .dropdown-menu {
            display: block;
        }
        
        .dropdown .dropdown-toggle {
            padding: 0.4rem 0.6rem; /* Smaller padding for icon only button */
        }

        .carteirinha {
            max-width: 350px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--secondary), var(--accent));
            margin: 1rem auto;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--card-border);
            color: white;
            overflow: hidden;
        }
        
        .carteirinha-header {
            display: flex;
            justify-content: space-between;
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

        .carteirinha-body {
            padding: 1.5rem;
        }
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
        /* Garante que a imagem do QR code se ajuste ao container */
        .carteirinha-qr img, .carteirinha-qr canvas {
            width: 100% !important;
            height: 100% !important;
        }
        
        .carteirinha-footer {
            padding: 0.75rem 1.5rem;
        }
        .carteirinha-footer .carteirinha-status {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            text-align: center;
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-success {
            background-color: rgba(0, 204, 136, 0.15);
            color: var(--success);
            border: 1px solid rgba(0, 204, 136, 0.3);
        }
        
        .alert-danger {
            background-color: rgba(255, 85, 116, 0.15);
            color: var(--danger);
            border: 1px solid rgba(255, 85, 116, 0.3);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 1.5rem;
        }

        .fab-container {
            display: none; /* Escondido por padrão */
            position: fixed;
            bottom: 80px; /* Acima da barra de navegação mobile */
            right: 20px;
            z-index: 998;
        }

        .fab-container .btn {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            font-size: 1.5rem;
        }

        /* Estilos para a navegação mobile */
        .mobile-nav {
            display: none; /* Escondido por padrão */
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: var(--secondary);
            box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
            z-index: 999;
            border-top: 1px solid var(--card-border);
            justify-content: space-around;
            padding: 0.5rem 0;
        }

        /* Estilos para transformar tabela em cards no mobile */
        @media (max-width: 768px) {
            .table-responsive table, 
            .table-responsive thead, 
            .table-responsive tbody, 
            .table-responsive th, 
            .table-responsive td, 
            .table-responsive tr {
                display: block;
            }

            .table-responsive thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }

            .table-responsive tr {
                border: 1px solid var(--card-border);
                border-radius: 8px;
                margin-bottom: 1rem;
                padding: 1rem;
            }

            .table-responsive td {
                border: none;
                border-bottom: 1px solid var(--card-border);
                position: relative;
                padding-left: 50%;
                text-align: right;
            }

            .table-responsive td:before {
                position: absolute;
                top: 50%;
                left: 1rem;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                transform: translateY(-50%);
                text-align: left;
                font-weight: bold;
                color: var(--text-secondary);
            }

            /* Adicionando os labels para cada coluna dinamicamente */
            /* Para a tabela de Alunos */
            #alunos-section td:nth-of-type(1):before { content: "Nome"; } #alunos-section td:nth-of-type(2):before { content: "Plano"; } #alunos-section td:nth-of-type(3):before { content: "Modalidades"; } #alunos-section td:nth-of-type(4):before { content: "Professor"; } #alunos-section td:nth-of-type(5):before { content: "Graduação"; } #alunos-section td:nth-of-type(6):before { content: "Status"; } #alunos-section td:nth-of-type(7):before { content: "Vencimento"; } #alunos-section td:nth-of-type(8):before { content: "Ações"; }
            /* Para a tabela do Dashboard */
            #dashboard-section td:nth-of-type(1):before { content: "Nome"; } #dashboard-section td:nth-of-type(2):before { content: "Plano"; } #dashboard-section td:nth-of-type(3):before { content: "Vencimento"; } #dashboard-section td:nth-of-type(4):before { content: "Valor"; } #dashboard-section td:nth-of-type(5):before { content: "Ações"; }
            /* Para a tabela de Presença */
            #presenca-section td:nth-of-type(1):before { content: "Aluno"; } #presenca-section td:nth-of-type(2):before { content: "Status"; }
            /* Para a tabela de Planos */
            #planos-section td:nth-of-type(1):before { content: "Nome"; } #planos-section td:nth-of-type(2):before { content: "Valor"; } #planos-section td:nth-of-type(3):before { content: "Ações"; }
            /* Para a tabela de Pagamentos */
            .payment-tab-content td:nth-of-type(1):before { content: "Nome"; } .payment-tab-content td:nth-of-type(2):before { content: "Plano"; } .payment-tab-content td:nth-of-type(3):before { content: "Vencimento"; } .payment-tab-content td:nth-of-type(4):before { content: "Valor"; } .payment-tab-content td:nth-of-type(5):before { content: "Ações"; }
            /* Para a tabela de Turmas */
            #turmas-section td:nth-of-type(1):before { content: "Modalidade"; } #turmas-section td:nth-of-type(2):before { content: "Professor"; } #turmas-section td:nth-of-type(3):before { content: "Horários"; } #turmas-section td:nth-of-type(4):before { content: "Tipo"; } #turmas-section td:nth-of-type(5):before { content: "Ações"; }
            /* Para a tabela de Despesas */
            #despesas-section td:nth-of-type(1):before { content: "Descrição"; } #despesas-section td:nth-of-type(2):before { content: "Valor"; } #despesas-section td:nth-of-type(3):before { content: "Ações"; }
            /* Para a tabela de Modalidades */
            #avancados-section .table-responsive:nth-of-type(1) td:nth-of-type(1):before { content: "Nome"; }
            #avancados-section .table-responsive:nth-of-type(1) td:nth-of-type(2):before { content: "Ações"; }
            /* Para a tabela de Professores */
            #avancados-section .table-responsive:nth-of-type(2) td:nth-of-type(1):before { content: "Nome"; }
            #avancados-section .table-responsive:nth-of-type(2) td:nth-of-type(2):before { content: "Ações"; }
            /* Para a tabela de Aprovações */
            #aprovacoes-section td:nth-of-type(1):before { content: "Academia"; } #aprovacoes-section td:nth-of-type(2):before { content: "E-mail"; } #aprovacoes-section td:nth-of-type(3):before { content: "Ações"; }

            .table-responsive td:last-child {
                padding-bottom: 0.5rem;
            }
            
            .action-buttons { 
                display: flex; 
                flex-direction: column; 
                align-items: flex-end; 
                gap: 0.5rem; 
                width: 100%;
            }
            #presenca-section .radio-group {
                justify-content: flex-end;
                width: 100%;
            }
            .action-buttons .btn { width: 100%; text-align: center; }
            .table-responsive td:last-child { border-bottom: none; }
        }
        
        /* Estilos para os botões de ação na tabela */
        @media (max-width: 992px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .mobile-nav {
                display: flex; /* Mostra no mobile */
            }

            .container {
                padding-bottom: 80px; /* Espaço para não sobrepor o nav mobile */
            }

            .logo h1 {
                font-size: 1.4rem;
            }

            .user-info > div > div {
                display: none; /* Esconde o nome "Admin" */
            }

            .card-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
            .card-header > .btn {
                display: none; /* Esconde o botão do header no mobile */
            }
            .card-actions-mobile {
                display: block !important; /* Mostra o botão fora do header */
            }

        }

        @media (max-width: 768px) {
            .action-buttons { flex-direction: column; }
            .action-buttons .btn { width: 100%; }
        }

        /* Estilos para as abas de pagamento */
        .payment-tabs {
            display: flex;
            border-bottom: 2px solid var(--card-border);
            margin-bottom: 1.5rem;
        }
        .payment-tab-button {
            background: none;
            border: none;
            color: var(--text-secondary);
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px; /* Alinha a borda inferior com a borda do container */
        }
        .payment-tab-button:hover {
            color: var(--text);
        }
        .payment-tab-button.active {
            color: var(--highlight);
            border-bottom-color: var(--highlight);
        }
        @media (max-width: 480px) {
            .dashboard-grid {
                grid-template-columns: 1fr; /* Uma coluna em telas muito pequenas */
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">
            <?php if ($logo_path && file_exists($logo_path)): ?>
                <img src="<?php echo $logo_path; ?>" alt="Logo da Academia" style="height: 80px; max-width: 300px;">
            <?php else: ?>
                <i class="fas fa-fist-raised"></i>
                <h1><?php echo htmlspecialchars($nome_academia); ?></h1>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['nome_academia'], 0, 2)); ?></div>
            <div>
                <?php if ($logo_path && file_exists($logo_path)): ?>
                    <div style="display: none;"><?php echo htmlspecialchars($nome_academia); ?></div>
                <?php else: ?>
                    <div><?php echo htmlspecialchars($_SESSION['nome_academia']); ?></div>
                <?php endif; ?>
                <small>Administrador</small>
            </div>
        </div>
    </header>
    
    <!-- Navegação para Mobile -->
    <div class="mobile-nav">
        <a href="?p=dashboard" class="nav-item <?php echo $pagina_atual == 'dashboard' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit; flex-direction: column; padding: 0.5rem; gap: 0.2rem; flex-grow: 1; justify-content: center;">
            <i class="fas fa-home"></i>
            <span style="font-size: 0.7rem;">Dashboard</span>
        </a>
        <button onclick="openModal('modal-menu-mobile')" class="nav-item" style="flex-direction: column; padding: 0.5rem; gap: 0.2rem; background: none; border: none; color: var(--text); flex-grow: 1; justify-content: center;">
            <i class="fas fa-bars"></i>
            <span style="font-size: 0.7rem;">Menu</span>
        </button>
    </div>

    <div class="container">
        <?php if (isset($_SESSION['msg'])): ?>
            <div class="alert <?php echo strpos($_SESSION['msg'], 'sucesso') !== false ? 'alert-success' : 'alert-danger'; ?>">
                <i class="<?php echo strpos($_SESSION['msg'], 'sucesso') !== false ? 'fas fa-check-circle' : 'fas fa-exclamation-circle'; ?>"></i>
                <?php echo $_SESSION['msg']; unset($_SESSION['msg']); ?>
                <button type="button" class="modal-close" style="margin-left: auto; padding: 0 0.5rem;" onclick="this.parentElement.style.display='none';">&times;</button>
            </div>
        <?php endif; ?>
        
        <div class="main-content">
            <div class="sidebar">
                <a href="?p=dashboard" class="nav-item <?php echo $pagina_atual == 'dashboard' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <?php if(tem_permissao('alunos', $permissoes_usuario)): ?>
                <a href="?p=alunos" class="nav-item <?php echo $pagina_atual == 'alunos' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-users"></i>
                    <span>Alunos</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('pagamentos', $permissoes_usuario)): ?>
                <a href="?p=pagamentos" class="nav-item <?php echo $pagina_atual == 'pagamentos' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-dollar-sign"></i>
                    <span>Pagamentos</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('turmas', $permissoes_usuario)): ?>
                <a href="?p=turmas" class="nav-item <?php echo $pagina_atual == 'turmas' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Turmas</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('presenca', $permissoes_usuario)): ?>
                <a href="?p=presenca" class="nav-item <?php echo $pagina_atual == 'presenca' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-user-check"></i>
                    <span>Presença</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('planos', $permissoes_usuario)): ?>
                <a href="?p=planos" class="nav-item <?php echo $pagina_atual == 'planos' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-tags"></i>
                    <span>Planos</span>
                </a>
                <?php endif; ?>
                <?php if($usuario_id_logado == 1): // Apenas admin principal vê ?>
                <a href="?p=modalidades" class="nav-item <?php echo $pagina_atual == 'modalidades' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-medal"></i>
                    <span>Modalidades</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('professores', $permissoes_usuario)): ?>
                <a href="?p=professores" class="nav-item <?php echo $pagina_atual == 'professores' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-user-tie"></i>
                    <span>Professores</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('despesas', $permissoes_usuario)): ?>
                <a href="?p=despesas" class="nav-item <?php echo $pagina_atual == 'despesas' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-receipt"></i>
                    <span>Despesas</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('despesas_futuras', $permissoes_usuario)): ?>
                <a href="?p=despesas_futuras" class="nav-item <?php echo $pagina_atual == 'despesas_futuras' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Despesas Futuras</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('configuracoes', $permissoes_usuario)): ?>
                <a href="?p=configuracoes" class="nav-item <?php echo $pagina_atual == 'configuracoes' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-cog"></i>
                    <span>Configurações</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('usuarios', $permissoes_usuario)): // Agora verifica a permissão ?>
                <a href="?p=usuarios" class="nav-item <?php echo $pagina_atual == 'usuarios' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;"><i class="fas fa-users-cog"></i><span>Usuários</span></a>
                <?php endif; ?>
                <?php if ($usuario_id_logado == 1): // Apenas admin principal vê ?>
                <a href="?p=aprovacoes" class="nav-item <?php echo $pagina_atual == 'aprovacoes' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;"><i class="fas fa-user-check"></i><span>Aprovações</span></a>
                <?php endif; ?>

                <a href="logout.php" class="nav-item" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sair</span>
                </a>
            </div>
            
            <div class="content" id="main-content-area">
                <div id="dashboard-section" class="card content-section" style="<?php echo ($pagina_atual == 'dashboard' && tem_permissao('dashboard', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="dashboard-grid" style="margin-bottom: 1.5rem;">
                        <div class="card stat-card"><i class="fas fa-users"></i><h3>TOTAL DE ALUNOS</h3><div class="value"><?php echo $total_alunos; ?></div></div>
                        <div class="card stat-card"><i class="fas fa-check-circle"></i><h3>ALUNOS ATIVOS</h3><div class="value"><?php echo $total_ativos; ?></div></div>
                        <div class="card stat-card"><i class="fas fa-exclamation-circle"></i><h3>ALUNOS INATIVOS</h3><div class="value"><?php echo $total_inativos; ?></div></div>
                        <div class="card stat-card" style="background-color: rgba(0, 204, 136, 0.1);">
                            <i class="fas fa-chart-line" style="color: var(--success);"></i>
                            <h3>RECEITA DO MÊS</h3>
                            <div class="value" style="color: var(--success);"><?php echo formatarMoeda($receita_mes); ?></div>
                        </div>
                        <div class="card stat-card" style="background-color: rgba(255, 85, 116, 0.1);">
                            <i class="fas fa-receipt" style="color: var(--danger);"></i>
                            <h3>DESPESAS DO MÊS</h3>
                            <div class="value" style="color: var(--danger);"><?php echo formatarMoeda($despesas_mes); ?></div>
                        </div>
                        <div class="card stat-card"><i class="fas fa-balance-scale"></i><h3>BALANÇO DO MÊS</h3><div class="value" style="color: <?php echo $balanco_mes >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;"><?php echo formatarMoeda($balanco_mes); ?></div></div>
                        <div class="card stat-card" style="background-color: rgba(255, 170, 0, 0.1);">
                            <i class="fas fa-calendar-alt" style="color: var(--warning);"></i>
                            <h3>DESPESAS FUTURAS DO MÊS</h3>
                            <div class="value" style="color: var(--warning);"><?php echo formatarMoeda($total_despesas_futuras); ?></div>
                        </div>
                    </div>

                    <div class="card-header">
                        <h2>Alunos Próximos do Vencimento (3 dias)</h2>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Plano</th>
                                    <th>Vencimento</th>
                                    <th>Valor</th>
                                    <th>Ações</th>
                                </tr>
                            </thead> 
                            <tbody>
                                <?php if (count($alunos_proximo_vencer) > 0): ?>
                                    <?php foreach ($alunos_proximo_vencer as $aluno): ?>
                                    <tr>
                                        <td><?php echo $aluno['nome_completo']; ?></td>
                                        <td><?php echo htmlspecialchars($aluno['plano_nome'] ?? 'N/A'); ?></td>
                                        <td><?php echo formatarData($aluno['proximo_vencimento']); ?></td>
                                        <td><?php echo formatarMoeda($aluno['valor_mensalidade']); ?></td>
                                        <td>
                                            <button class="btn btn-sm" onclick="registrarPagamento('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>)">
                                                <i class="fas fa-money-bill-wave"></i> Pagamento
                                            </button>
                                            <button class="btn btn-sm" style="background: #25D366;" onclick="enviarLembreteVencimento('<?php echo $aluno['id']; ?>', '<?php echo $aluno['telefone']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>, '<?php echo $aluno['proximo_vencimento']; ?>')">
                                                <i class="fab fa-whatsapp"></i> Lembrar
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center;">Nenhum aluno próximo do vencimento</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
                
                <div id="alunos-section" class="card content-section" style="<?php echo ($pagina_atual == 'alunos' && tem_permissao('alunos', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Todos os Alunos</h2>
                        <div class="card-actions">
                            <button class="btn" onclick="openModal('modal-cadastro')">
                                <i class="fas fa-plus"></i> Novo Aluno
                            </button>
                            <!-- <button class="btn" id="teste-aluno-btn" style="background: var(--warning);"><i class="fas fa-flask"></i> Aluno de Teste</button>-->
                        </div>
                    </div>
                    <div class="form-group" style="padding: 0 1.5rem; margin-bottom: 1rem;">
                        <input type="text" id="search-aluno" onkeyup="filtrarAlunos()" placeholder="Pesquisar por nome do aluno..." style="background-color: var(--primary);">
                    </div>
                    <div class="card-actions-mobile" style="padding: 0 1.5rem 1.5rem; text-align: right; display: none;">
                        <button class="btn" onclick="openModal('modal-cadastro')">
                            <i class="fas fa-plus"></i> Novo Aluno
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table id="tabela-alunos">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Plano</th>
                                    <th>Modalidades</th>
                                    <th>Professor</th>
                                    <th>Faixa</th>
                                    <th>Status</th>
                                    <th>Vencimento</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos as $aluno): ?>
                                <tr>
                                    <td><?php echo $aluno['nome_completo']; ?></td>
                                    <td><?php echo htmlspecialchars($aluno['plano_nome'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        // A coluna 'modalidades' já vem formatada do banco de dados.
                                        echo htmlspecialchars($aluno['modalidades']);
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($aluno['professor_nome'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                            $graduacoes_formatadas = [];
                                            foreach($aluno['graduacoes'] as $grad) {
                                                $graduacoes_formatadas[] = htmlspecialchars($grad['modalidade_nome'] . ': ' . $grad['graduacao_nome']);
                                            }
                                            echo implode('<br>', $graduacoes_formatadas);
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $aluno['status'] == 'ativo' ? 'status-ativo' : ($aluno['status'] == 'bloqueado' ? 'status-danger' : 'status-inativo'); ?>">
                                            <?php echo ucfirst($aluno['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $aluno['proximo_vencimento'] ? formatarData($aluno['proximo_vencimento']) : 'N/A'; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm" onclick="gerarCarteirinha('<?php echo $aluno['id']; ?>')" title="Gerar Carteirinha">
                                                <i class="fas fa-id-card"></i>
                                            </button>
                                            <button class="btn btn-sm" onclick="registrarPagamento('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>)" title="Registrar Pagamento">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </button>
                                            
                                            <div class="dropdown">
                                                <button class="btn btn-sm dropdown-toggle" style="background: var(--secondary);" title="Mais Opções">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <a href="#" onclick="editarAluno('<?php echo $aluno['id']; ?>'); return false;"><i class="fas fa-edit fa-fw"></i> Editar Plano/Turma</a>
                                                    <a href="#" onclick="editarAlunoPessoal('<?php echo $aluno['id']; ?>'); return false;"><i class="fas fa-user-edit fa-fw"></i> Editar Dados</a>
                                                    <a href="#" onclick="editarMensalidade('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>); return false;"><i class="fas fa-dollar-sign fa-fw"></i> Alterar Valor</a>
                                                    <a href="#" onclick="openFrequenciaModal('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>'); return false;"><i class="fas fa-calendar-alt fa-fw"></i> Ver Frequência</a>
                                                    
                                                    <form method="POST" onsubmit="return confirm('Deseja alterar o status deste aluno?');" style="margin: 0;">
                                                        <input type="hidden" name="aluno_id_status" value="<?php echo $aluno['id']; ?>">
                                                        <input type="hidden" name="status_atual" value="<?php echo $aluno['status']; ?>">
                                                        <button type="submit" name="alterar_status_aluno">
                                                            <i class="fas fa-ban fa-fw"></i> <?php echo $aluno['status'] === 'bloqueado' ? 'Desbloquear' : 'Bloquear'; ?>
                                                        </button>
                                                    </form>
                                                    
                                                    <form method="POST" onsubmit="return confirm('ATENÇÃO: Esta ação removerá o aluno e todo o seu histórico de pagamentos. Deseja continuar?');" style="margin: 0;">
                                                        <input type="hidden" name="aluno_id_remover" value="<?php echo $aluno['id']; ?>">
                                                        <button type="submit" name="remover_aluno" style="color: var(--danger);">
                                                            <i class="fas fa-user-slash fa-fw"></i> Remover
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Seção Pagamentos -->
                <div id="pagamentos-section" class="card content-section" style="<?php echo ($pagina_atual == 'pagamentos' && tem_permissao('pagamentos', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Controle de Pagamentos</h2>
                    </div>
                    <div class="payment-tabs">
                        <button class="payment-tab-button" onclick="openPaymentTab(event, 'tab-vencer')" id="default-payment-tab">A Vencer (<?php echo count($alunos_proximo_vencer); ?>)</button>
                        <button class="payment-tab-button" onclick="openPaymentTab(event, 'tab-vencidos')">Vencidos (<?php echo count($pagamentos_vencidos); ?>)</button>
                        <button class="payment-tab-button" onclick="openPaymentTab(event, 'tab-pagos')">Pagos (<?php echo count($pagamentos_pagos); ?>)</button>
                    </div>

                    <!-- Aba A Vencer -->
                    <div id="tab-vencer" class="payment-tab-content">
                        <div class="table-responsive">
                            <table>
                                <thead><tr><th>Nome</th><th>Plano</th><th>Vencimento</th><th>Valor</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php if (empty($alunos_proximo_vencer)): ?>
                                        <tr><td colspan="5" style="text-align: center;">Nenhum aluno com vencimento nos próximos 3 dias.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($alunos_proximo_vencer as $aluno): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($aluno['nome_completo']); ?></td>
                                            <td><?php echo htmlspecialchars($aluno['plano_nome'] ?? 'N/A'); ?></td>
                                            <td><?php echo formatarData($aluno['proximo_vencimento']); ?></td>
                                            <td><?php echo formatarMoeda($aluno['valor_mensalidade']); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm" onclick="registrarPagamento('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>)"><i class="fas fa-money-bill-wave"></i> Pagar</button>
                                                    <button class="btn btn-sm" style="background: var(--warning);" onclick="openRenovarModal('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', '<?php echo $aluno['plano_id']; ?>')"><i class="fas fa-sync-alt"></i> Renovar</button>
                                                    <button class="btn btn-sm" style="background: #25D366;" onclick="enviarLembreteVencimento('<?php echo $aluno['id']; ?>', '<?php echo $aluno['telefone']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>, '<?php echo $aluno['proximo_vencimento']; ?>')"><i class="fab fa-whatsapp"></i> Lembrar</button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Aba Vencidos -->
                    <div id="tab-vencidos" class="payment-tab-content">
                        <div class="table-responsive">
                            <table>
                                <thead><tr><th>Nome</th><th>Plano</th><th>Vencimento</th><th>Valor</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php if (empty($pagamentos_vencidos)): ?>
                                        <tr><td colspan="5" style="text-align: center;">Nenhum aluno com pagamento vencido.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($pagamentos_vencidos as $aluno): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($aluno['nome_completo']); ?></td>
                                            <td><?php echo htmlspecialchars($aluno['plano_nome'] ?? 'N/A'); ?></td>
                                            <td><?php echo formatarData($aluno['proximo_vencimento']); ?></td>
                                            <td><?php echo formatarMoeda($aluno['valor_mensalidade']); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm" onclick="registrarPagamento('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>)"><i class="fas fa-money-bill-wave"></i> Pagar</button>
                                                    <button class="btn btn-sm" style="background: var(--warning);" onclick="openRenovarModal('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', '<?php echo $aluno['plano_id']; ?>')"><i class="fas fa-sync-alt"></i> Renovar</button>
                                                    <button class="btn btn-sm" style="background: #25D366;" onclick="enviarLembreteVencimento('<?php echo $aluno['id']; ?>', '<?php echo $aluno['telefone']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', <?php echo $aluno['valor_mensalidade']; ?>, '<?php echo $aluno['proximo_vencimento']; ?>')"><i class="fab fa-whatsapp"></i> Lembrar</button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Aba Pagos -->
                    <div id="tab-pagos" class="payment-tab-content">
                        <div class="table-responsive">
                            <table>
                                <thead><tr><th>Nome</th><th>Plano</th><th>Próximo Vencimento</th><th>Valor</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php if (empty($pagamentos_pagos)): ?>
                                        <tr><td colspan="5" style="text-align: center;">Nenhum aluno com pagamento em dia.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($pagamentos_pagos as $aluno): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($aluno['nome_completo']); ?></td>
                                            <td><?php echo htmlspecialchars($aluno['plano_nome'] ?? 'N/A'); ?></td>
                                            <td><?php echo formatarData($aluno['proximo_vencimento']); ?></td>
                                            <td><?php echo formatarMoeda($aluno['valor_mensalidade']); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm" onclick="gerarCarteirinha('<?php echo $aluno['id']; ?>')"><i class="fas fa-id-card"></i> Ver</button>
                                                    <button class="btn btn-sm" style="background: var(--warning);" onclick="openRenovarModal('<?php echo $aluno['id']; ?>', '<?php echo htmlspecialchars($aluno['nome_completo'], ENT_QUOTES); ?>', '<?php echo $aluno['plano_id']; ?>')"><i class="fas fa-sync-alt"></i> Renovar</button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Seção Turmas -->
                <div id="turmas-section" class="card content-section" style="<?php echo ($pagina_atual == 'turmas' && tem_permissao('turmas', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Gerenciar Turmas</h2>
                        <button class="btn" onclick="openModal('modal-nova-turma')"><i class="fas fa-plus"></i> Nova Turma</button>
                    </div>
                    <div class="card-actions-mobile" style="padding: 0 1.5rem 1.5rem; text-align: right; display: none;">
                        <button class="btn" onclick="openModal('modal-nova-turma')"><i class="fas fa-plus"></i> Nova Turma</button>
                    </div>
                    <div class="table-responsive">
                        <table id="turmas-table">
                            <thead><tr><th>Modalidades</th><th>Professor</th><th>Horários</th><th>Tipo</th><th>Ações</th></tr></thead>
                            <tbody>
                                <?php if (empty($turmas)): ?>
                                    <tr><td colspan="5" style="text-align: center;">Nenhuma turma cadastrada.</td></tr>
                                <?php else: ?>
                                    <?php foreach($turmas as $turma): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(str_replace(',', ', ', $turma['modalidades_nomes'])); ?></td>
                                        <td><?php echo htmlspecialchars($turma['professor_nome']); ?></td>
                                        <td><?php echo htmlspecialchars($turma['dias_semana'] . ' ' . $turma['horario_inicio'] . '-' . $turma['horario_fim']); ?></td>
                                        <td><?php echo htmlspecialchars($turma['tipo']); ?></td>
                                        <td><form method="POST" onsubmit="return confirm('Deseja remover esta turma?');"><input type="hidden" name="id_turma" value="<?php echo $turma['id']; ?>"><button type="submit" name="remover_turma" class="btn btn-sm" style="background: var(--danger);"><i class="fas fa-trash"></i></button></form></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Seção Presença -->
                <div id="presenca-section" class="card content-section" style="<?php echo ($pagina_atual == 'presenca' && tem_permissao('presenca', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Lista de Presença - <?php echo date('d/m/Y'); ?> (<?php echo $dia_hoje; ?>)</h2>
                        <div class="form-group" style="margin-bottom: 0; min-width: 250px;">
                            <label for="filtro_turma_presenca">Filtrar por Turma</label>
                            <select id="filtro_turma_presenca" onchange="filtrarAlunosPresenca()">
                                <option value="todas">Todas as turmas do dia</option>
                                <?php foreach($turmas as $turma): if(strpos($turma['dias_semana'], $dia_hoje) !== false): ?>
                                    <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['modalidades_nomes'] . ' - ' . $turma['tipo'] . ' (' . $turma['horario_inicio'] . ')'); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <div class="table-responsive">
                                <table>
                                    <thead id="presenca-table-head"><tr><th>Aluno</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php if (empty($alunos_do_dia)): ?>
                                            <tr><td colspan="2" style="text-align: center;">Nenhum aluno com treino hoje.</td></tr>
                                        <?php else: ?>
                                            <?php foreach($alunos_do_dia as $aluno): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($aluno['nome_completo']); ?></td>
                                                <td data-turma-id="<?php echo $aluno['turma_id']; ?>">
                                                    <div class="radio-group">                                                        
                                                        <input type="radio" id="presenca_p_<?php echo $aluno['id']; ?>" name="presenca[<?php echo $aluno['id']; ?>]" value="presente"><label for="presenca_p_<?php echo $aluno['id']; ?>">Presente</label>
                                                        <input type="radio" id="presenca_f_<?php echo $aluno['id']; ?>" name="presenca[<?php echo $aluno['id']; ?>]" value="falta"><label for="presenca_f_<?php echo $aluno['id']; ?>">Falta</label>
                                                    </div>                                                    
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" name="salvar_presenca" class="btn" style="margin-top: 1.5rem;"><i class="fas fa-save"></i> Salvar Lista</button>
                        </form>
                    </div>
                </div>

                <!-- Seção Planos -->
                <div id="planos-section" class="card content-section" style="<?php echo ($pagina_atual == 'planos' && tem_permissao('planos', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Gerenciar Planos</h2>
                    </div>
                    <div class="modal-body">
                        <form method="POST" style="border: 1px solid var(--card-border); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                            <div class="form-group"><label>Nome do Plano</label><input type="text" name="nome_plano" required></div>
                            <div class="form-group"><label>Valor (R$)</label><input type="number" step="0.01" name="valor_plano" required></div>
                            <div class="form-group">
                                <label>Modalidades Inclusas (selecione uma ou mais)</label>
                                <div class="checkbox-group-vertical">
                                    <?php if (empty($modalidades_disponiveis)): ?>
                                        <p style="color: var(--text-secondary);">Nenhuma modalidade cadastrada. Adicione uma na aba 'Modalidades'.</p>
                                    <?php else: ?>
                                        <?php foreach($modalidades_disponiveis as $mod): ?>
                                            <div class="checkbox-item"><input type="checkbox" id="plano_mod_<?php echo $mod['id']; ?>" name="modalidades_plano[]" value="<?php echo $mod['id']; ?>"><label for="plano_mod_<?php echo $mod['id']; ?>"><?php echo htmlspecialchars($mod['nome']); ?></label></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="submit" name="adicionar_plano" class="btn"><i class="fas fa-plus"></i> Adicionar Plano</button>
                        </form>
                        <h4>Planos Existentes</h4>
                        <div class="table-responsive"><table><thead><tr><th>Nome</th><th>Valor</th><th>Modalidades</th><th>Ações</th></tr></thead><tbody><?php foreach($planos as $pl): ?><tr><td><?php echo htmlspecialchars($pl['nome']); ?></td><td><?php echo formatarMoeda($pl['valor']); ?></td><td><?php echo htmlspecialchars($pl['modalidades_nomes'] ?? 'N/A'); ?></td><td><form method="POST" onsubmit="return confirm('Deseja remover este plano?');"><input type="hidden" name="id_plano" value="<?php echo $pl['id']; ?>"><button type="submit" name="remover_plano" class="btn btn-sm" style="background: var(--danger);"><i class="fas fa-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div>
                    </div>
                </div>

                <!-- Seção Despesas -->
                <div id="despesas-section" class="card content-section" style="<?php echo ($pagina_atual == 'despesas' && tem_permissao('despesas', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Lançamento de Despesas</h2>
                        <button class="btn" onclick="openModal('modal-despesa')">
                            <i class="fas fa-plus"></i> Nova Despesa
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($despesas_pagas) > 0): ?>
                                    <?php foreach ($despesas_pagas as $despesa): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($despesa['descricao']); ?></td>
                                        <td style="color: var(--success);"><?php echo formatarMoeda($despesa['valor']); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja remover esta despesa?');">
                                                <input type="hidden" name="despesa_id" value="<?php echo $despesa['id']; ?>">
                                                <button type="submit" name="remover_despesa" class="btn btn-sm" style="background: var(--danger);">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center;">Nenhuma despesa registrada.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Botão Flutuante para Adicionar Despesa (Mobile) -->
                <div id="despesas-fab" class="fab-container" style="display: none;">
                    <button class="btn" onclick="openModal('modal-despesa')">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>

                <!-- Seção Despesas Futuras -->
                <div id="despesas_futuras-section" class="card content-section" style="<?php echo ($pagina_atual == 'despesas_futuras' && tem_permissao('despesas_futuras', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Despesas Futuras</h2>
                        <button class="btn" onclick="openModal('modal-despesa-futura')">
                            <i class="fas fa-plus"></i> Nova Despesa Futura
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Vencimento</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($despesas_futuras) > 0): ?>
                                    <?php foreach ($despesas_futuras as $despesa): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($despesa['descricao']); ?></td>
                                        <td style="color: var(--warning);"><?php echo formatarMoeda($despesa['valor']); ?></td>
                                        <td><?php echo formatarData($despesa['data_vencimento']); ?></td>
                                        <td>
                                            <span class="status-badge status-pendente">
                                                Pendente
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" onsubmit="return confirm('Marcar esta despesa como paga?');" style="display:inline;">
                                                    <input type="hidden" name="despesa_id" value="<?php echo $despesa['id']; ?>">
                                                    <button type="submit" name="marcar_pago_despesa_futura" class="btn btn-sm" style="background:var(--success);"><i class="fas fa-check"></i> Pagar</button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Deseja remover esta despesa?');" style="display:inline;">
                                                    <input type="hidden" name="despesa_id" value="<?php echo $despesa['id']; ?>"><button type="submit" name="remover_despesa" class="btn btn-sm" style="background:var(--danger);"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align: center;">Nenhuma despesa futura registrada.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Seção de Configurações (Exemplo) -->
                <div id="configuracoes-section" class="card content-section" style="<?php echo ($pagina_atual == 'configuracoes' && tem_permissao('configuracoes', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Configurações</h2>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <h3>Integração com Bot-Bot (WhatsApp)</h3>
                            <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Insira suas credenciais da API do Bot-Bot para habilitar o envio automático de mensagens.</p>

                            <div class="form-group">
                                <label for="botbot_appkey">Chave do App (App Key)</label>
                                <input type="text" id="botbot_appkey" name="botbot_appkey" value="<?php echo $botbot_appkey; ?>" placeholder="Sua Chave do App do botbot.chat">
                            </div>

                            <div class="form-group">
                                <label for="botbot_authkey">Chave de Autenticação (Auth Key)</label>
                                <input type="text" id="botbot_authkey" name="botbot_authkey" value="<?php echo $botbot_authkey; ?>" placeholder="Sua Chave de Autenticação do botbot.chat">
                            </div>
                            <button type="submit" name="salvar_configuracoes" class="btn">
                                <i class="fas fa-save"></i> Salvar Configurações
                            </button>
                        </form>
                        <hr style="border-color: var(--card-border); margin: 2rem 0;">
                        <form method="POST" enctype="multipart/form-data">
                            <h3>Logo da Academia</h3>
                            <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Faça o upload de um arquivo de imagem (PNG, JPG) para ser a logo da sua academia.</p>
                            <?php if ($logo_path && file_exists($logo_path)): ?>
                                <div class="form-group">
                                    <label>Logo Atual</label>
                                    <img src="<?php echo $logo_path; ?>" alt="Logo Atual" style="max-height: 60px; background: #fff; padding: 5px; border-radius: 8px;">
                                </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="logo_academia">Selecionar nova logo (PNG, JPG)</label>
                                <input type="file" id="logo_academia" name="logo_academia" accept="image/png, image/jpeg">
                            </div>
                            <button type="submit" name="salvar_logo" class="btn">
                                <i class="fas fa-upload"></i> Enviar Logo
                            </button>
                            <?php if ($logo_path && file_exists($logo_path)): ?>
                                <button type="submit" name="remover_logo" class="btn" style="background: var(--danger);" onclick="return confirm('Tem certeza que deseja remover a logo atual?');"><i class="fas fa-trash"></i> Remover Logo</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Seção de Modalidades -->
                <div id="modalidades-section" class="card content-section" style="<?php echo ($pagina_atual == 'modalidades' && $usuario_id_logado == 1) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Modalidades e Graduações</h2>
                    </div>
                    <div class="modal-body">
                        <?php if ($usuario_id_logado == 1): // Apenas admin principal pode gerenciar modalidades ?>
                        <form method="POST" style="display: flex; gap: 1rem; align-items: flex-end; margin-bottom: 1.5rem;"><div class="form-group" style="flex-grow: 1; margin-bottom: 0;"><label for="nome_modalidade">Nova Modalidade</label><input type="text" name="nome_modalidade" required></div><button type="submit" name="adicionar_modalidade" class="btn"><i class="fas fa-plus"></i> Adicionar</button></form>
                        <div class="table-responsive"><table><thead><tr><th>Nome</th><th>Ações</th></tr></thead><tbody><?php foreach($modalidades_disponiveis as $mod): ?><tr><td><?php echo htmlspecialchars($mod['nome']); ?></td><td><div class="action-buttons"><button class="btn btn-sm" onclick="openGraduacaoModal(<?php echo $mod['id']; ?>, '<?php echo htmlspecialchars(addslashes($mod['nome'])); ?>')"><i class="fas fa-medal"></i> Graduações</button><form method="POST" action="?p=modalidades" onsubmit="return confirm('Deseja remover esta modalidade?');"><input type="hidden" name="id_modalidade" value="<?php echo $mod['id']; ?>"><button type="submit" name="remover_modalidade" class="btn btn-sm" style="background: var(--danger);"><i class="fas fa-trash"></i></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
                        <?php else: ?>
                            <p>Você não tem permissão para acessar esta área.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Seção de Professores -->
                <div id="professores-section" class="card content-section" style="<?php echo ($pagina_atual == 'professores' && tem_permissao('professores', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Cadastro de Professores</h2>
                    </div>
                    <div class="modal-body">
                        <!-- Formulário para adicionar professor -->
                        <form method="POST" style="border: 1px solid var(--card-border); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;"><div class="form-group"><label for="nome_professor">Nome do Professor</label><input type="text" name="nome_professor" required></div><div class="form-group"><label for="cpf_professor">CPF do Professor</label><input type="text" name="cpf_professor" required></div><button type="submit" name="adicionar_professor" class="btn"><i class="fas fa-plus"></i> Adicionar</button></form>
                        <!-- Tabela de professores -->
                        <div class="table-responsive"><table><thead><tr><th>Nome</th><th>CPF</th><th>Ações</th></tr></thead><tbody><?php foreach($professores as $prof): ?><tr><td><?php echo htmlspecialchars($prof['nome']); ?></td><td><?php echo htmlspecialchars($prof['cpf'] ?? 'N/A'); ?></td><td><form method="POST" onsubmit="return confirm('Deseja remover este professor?');"><input type="hidden" name="id_professor" value="<?php echo $prof['id']; ?>"><button type="submit" name="remover_professor" class="btn btn-sm" style="background: var(--danger);"><i class="fas fa-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div>
                    </div>
                </div>

                <!-- Seção de Aprovações (Apenas para Admin ID 1) -->
                <?php if ($usuario_id_logado == 1): ?>
                <div id="aprovacoes-section" class="card content-section" style="<?php echo ($pagina_atual == 'aprovacoes' && tem_permissao('aprovacoes', $permissoes_usuario)) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h2>Aprovações de Novos Usuários</h2>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome da Academia</th>
                                    <th>E-mail</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($usuarios_para_analise) > 0): ?>
                                    <?php foreach ($usuarios_para_analise as $usuario_analise): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($usuario_analise['nome_academia']); ?></td>
                                        <td><?php echo htmlspecialchars($usuario_analise['email']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($usuario_analise['status'] === 'pendente'): ?>
                                                    <form method="POST" style="display: inline;"><input type="hidden" name="usuario_id_aprovar" value="<?php echo $usuario_analise['id']; ?>"><button type="submit" name="aprovar_usuario" class="btn btn-sm" style="background: var(--success);"><i class="fas fa-check"></i> Aprovar</button></form>
                                                    <form method="POST" style="display: inline;"><input type="hidden" name="usuario_id_rejeitar" value="<?php echo $usuario_analise['id']; ?>"><button type="submit" name="rejeitar_usuario" class="btn btn-sm" style="background: var(--danger);"><i class="fas fa-times"></i> Rejeitar</button></form>
                                                <?php elseif ($usuario_analise['status'] === 'rejeitado'): ?>
                                                    <span class="status-badge status-inativo">Rejeitado</span>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="usuario_id_reconsiderar" value="<?php echo $usuario_analise['id']; ?>">
                                                        <button type="submit" name="reconsiderar_usuario" class="btn btn-sm" style="background: var(--warning);"><i class="fas fa-undo"></i> Reconsiderar</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" style="text-align: center;">Nenhum usuário para análise.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Seção de Usuários (Verifica permissão) -->
                <?php if (tem_permissao('usuarios', $permissoes_usuario)): ?>
                    <div id="usuarios-section" class="card content-section" style="<?php echo ($pagina_atual == 'usuarios') ? 'display: block;' : 'display: none;'; ?>">
                        <div class="card-header">
                            <h2>Gerenciamento de Usuários</h2>
                        </div>
                        <div class="modal-body">
                            <form method="POST" style="border: 1px solid var(--card-border); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                <h4>Cadastrar Novo Usuário (Professor)</h4>
                                <div class="form-group"><label>E-mail</label><input type="email" name="email_professor" required></div>
                                <div class="form-group"><label>Nome (para exibição)</label><input type="text" name="nome_academia_professor" required></div>
                                <div class="form-group">
                                    <label for="professor_id_usuario">Associar a um Professor</label>
                                    <select id="professor_id_usuario" name="professor_id_usuario" required><option value="">Selecione um professor...</option><?php foreach($professores as $prof): ?><option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-group"><label>Senha</label><input type="password" name="senha_professor" required></div>
                                <div class="form-group"><label>Confirmar Senha</label><input type="password" name="senha_professor_confirm" required></div>
                                <div class="form-group">
                                    <label>Permissões de Acesso</label>
                                    <div class="checkbox-group-vertical" id="permissoes-container-cadastro">
                                        <?php 
                                        $modulos_disponiveis = [
                                            'alunos' => 'Alunos', 'pagamentos' => 'Pagamentos', 'turmas' => 'Turmas',
                                            'presenca' => 'Presença', 'planos' => 'Planos',
                                            'professores' => 'Professores', 'despesas' => 'Despesas', 'despesas_futuras' => 'Despesas Futuras', 'configuracoes' => 'Configurações'
                                        ];
                                        foreach($modulos_disponiveis as $key => $label):
                                        ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" id="perm_<?php echo $key; ?>" name="permissoes[]" value="<?php echo $key; ?>">
                                            <label for="perm_<?php echo $key; ?>"><?php echo $label; ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <button type="submit" name="cadastrar_usuario_professor" class="btn"><i class="fas fa-plus"></i> Cadastrar Usuário</button>
                            </form>
                            <hr style="border-color: var(--card-border); margin: 2rem 0;">
                            <h4>Usuários Cadastrados</h4>
                            <div class="table-responsive">
                                <table>
                                    <thead><tr><th>E-mail</th><th>Academia</th><th>Professor Assoc.</th><th>Status</th><th>Ações</th></tr></thead>
                                    <tbody>
                                        <?php foreach($usuarios_cadastrados as $u): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td><?php echo htmlspecialchars($u['nome_academia']); ?></td>
                                            <td><?php echo htmlspecialchars($u['professor_nome'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $u['status'] == 'aprovado' ? 'status-ativo' : 'status-inativo'; ?>">
                                                    <?php echo ucfirst($u['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm" onclick='openEditUsuarioModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8"); ?>)'><i class="fas fa-edit"></i> Editar</button>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm dropdown-toggle" style="background: var(--secondary);"><i class="fas fa-ellipsis-v"></i></button>
                                                        <div class="dropdown-menu">
                                                            <form method="POST" onsubmit="return confirm('Deseja alterar o status deste usuário?');" class="dropdown-form">
                                                                <input type="hidden" name="usuario_id_status" value="<?php echo $u['id']; ?>"><input type="hidden" name="status_atual" value="<?php echo $u['status']; ?>"><button type="submit" name="alterar_status_usuario"><i class="fas fa-ban"></i> <?php echo $u['status'] == 'aprovado' ? 'Bloquear' : 'Desbloquear'; ?></button>
                                                            </form>
                                                            <div class="dropdown-divider"></div>
                                                            <form method="POST" onsubmit="return confirm('ATENÇÃO: Esta ação é irreversível e removerá o usuário permanentemente. Deseja continuar?');" class="dropdown-form">
                                                                <input type="hidden" name="usuario_id_remover" value="<?php echo $u['id']; ?>"><button type="submit" name="remover_usuario" class="danger-action"><i class="fas fa-trash"></i> Remover</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    
    <!-- Modal de Cadastro -->
    <div id="modal-cadastro" class="modal">
        <div class="modal-content">
            <form method="POST" id="form-cadastro-aluno" onsubmit="return true;">
                <!-- ETAPA 1: DADOS PESSOAIS -->
                <div id="cadastro-etapa-1">
                    <div class="modal-header">
                        <h2>Cadastrar Aluno (Etapa 1/2)</h2>
                        <button type="button" class="modal-close" onclick="closeModal('modal-cadastro')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="nome_completo">Nome Completo</label>
                            <input type="text" id="nome_completo" name="nome_completo" required>
                        </div>
                        <div class="form-group">
                            <label for="cpf">CPF</label>
                            <input type="text" id="cpf" name="cpf" required placeholder="000.000.000-00">
                        </div>
                        <div class="form-group">
                            <label for="data_nascimento">Data de Nascimento</label>
                            <input type="date" id="data_nascimento" name="data_nascimento" required>
                        </div>
                        <div class="form-group">
                            <label for="telefone">Telefone</label>
                            <input type="text" id="telefone" name="telefone" required placeholder="(00) 00000-0000">
                        </div>
                        <div class="form-group">
                            <label for="plano_id">Plano</label>
                            <select id="plano_id" name="plano_id" required>
                                <option value="">Selecione o plano</option>
                                <?php foreach ($planos as $plano):
                                    $mods_ids = $db->query("SELECT modalidade_id FROM planos_modalidades WHERE plano_id = {$plano['id']}"); $ids_array = []; while($id = $mods_ids->fetchArray(SQLITE3_NUM)) { $ids_array[] = $id[0]; } ?>
                                    <option value="<?php echo $plano['id']; ?>" data-modalidades-ids="<?php echo implode(',', $ids_array); ?>"><?php echo htmlspecialchars($plano['nome']); ?> - <?php echo formatarMoeda($plano['valor']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="dia_vencimento">Dia do Vencimento</label>
                            <select id="dia_vencimento" name="dia_vencimento" required>
                                <?php for ($i = 1; $i <= 31; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($i == 10) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="data_inicio">Data de Início</label>
                            <input type="date" id="data_inicio" name="data_inicio" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button type="button" class="btn" onclick="irParaEtapa2()">Próximo <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>
                <!-- ETAPA 2: FAIXAS E TURMAS -->
                <div id="cadastro-etapa-2" style="display: none;">
                    <div class="modal-header">
                        <h2>Turmas e Graduações (Etapa 2/2)</h2>
                        <button type="button" class="modal-close" onclick="closeModal('modal-cadastro')">&times;</button>
                    </div>
                    <div class="modal-body">
                         <div class="form-group">
                            <label>Turmas Disponíveis (selecione ao menos uma)</label>
                            <div id="turmas-checkbox-container" class="checkbox-group-vertical" required>
                                <p style="color: var(--text-secondary);">Selecione um plano na etapa anterior para ver as turmas.</p>
                            </div>
                        </div>

                        <!-- Container para os selects de graduação dinâmicos -->
                        <div id="graduacoes-container">
                            <!-- Os selects de graduação serão inseridos aqui pelo JS -->
                        </div>

                        <button type="button" class="btn" style="background: var(--text-secondary);" onclick="voltarParaEtapa1()"><i class="fas fa-arrow-left"></i> Voltar</button>
                        <button type="submit" name="cadastrar_aluno" class="btn">Finalizar Cadastro</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de Despesa -->
    <div id="modal-despesa" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Lançar Nova Despesa</h2>
                <button class="modal-close" onclick="closeModal('modal-despesa')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label for="descricao">Descrição da Despesa</label>
                        <input type="text" id="descricao" name="descricao" required placeholder="Ex: Aluguel, Conta de Luz">
                    </div>
                    <div class="form-group">
                        <label for="valor_despesa">Valor</label>
                        <input type="number" step="0.01" id="valor_despesa" name="valor_despesa" required placeholder="0.00">
                    </div>
                    <button type="submit" name="cadastrar_despesa" class="btn">Salvar Despesa</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Despesa Futura -->
    <div id="modal-despesa-futura" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Lançar Despesa Futura</h2>
                <button class="modal-close" onclick="closeModal('modal-despesa-futura')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label for="descricao_futura">Descrição da Despesa</label>
                        <input type="text" id="descricao_futura" name="descricao_futura" required placeholder="Ex: Cartão de Crédito, Salário Funcionário">
                    </div>
                    <div class="form-group">
                        <label for="valor_despesa_futura">Valor</label>
                        <input type="number" step="0.01" id="valor_despesa_futura" name="valor_despesa_futura" required placeholder="0.00">
                    </div>
                    <div class="form-group"><label for="data_vencimento_futura">Data de Vencimento</label><input type="date" id="data_vencimento_futura" name="data_vencimento_futura" required></div>
                    <button type="submit" name="cadastrar_despesa_futura" class="btn">Salvar Despesa Futura</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Pagamento -->
    <div id="modal-pagamento" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Registrar Pagamento</h2>
                <button class="modal-close" onclick="closeModal('modal-pagamento')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="form-pagamento">
                    <input type="hidden" name="aluno_id" id="aluno_id_pagamento">
                    <div class="form-group"> <input type="hidden" name="aluno_nome" id="aluno_nome_pagamento">
                        <label for="aluno_nome">Aluno</label>
                        <input type="text" id="aluno_nome_pagamento_display" readonly>
                    </div>
                    <div class="form-group">
                        <label for="valor">Valor</label>
                        <input type="number" step="0.01" id="valor_pagamento" name="valor" required>
                    </div>
                    <div class="form-group">
                        <label for="mes_referencia">Mês de Referência</label>
                        <input type="month" id="mes_referencia" name="mes_referencia" required value="<?php echo date('Y-m'); ?>">
                    </div>
                    <button type="submit" name="registrar_pagamento" class="btn">Registrar Pagamento</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Carteirinha -->
    <div id="modal-carteirinha" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Carteirinha do Aluno</h2>
                <button class="modal-close" onclick="closeModal('modal-carteirinha')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="carteirinha-container"></div>
            </div>
        </div>
    </div>

    <!-- Modal de Edição de Mensalidade -->
    <div id="modal-edit-mensalidade" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Editar Valor da Mensalidade</h2>
                <button class="modal-close" onclick="closeModal('modal-edit-mensalidade')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="aluno_id_edit" id="aluno_id_edit">
                    <div class="form-group">
                        <label>Aluno</label>
                        <p id="aluno_nome_edit" style="font-size: 1.1rem; font-weight: bold;"></p>
                    </div>
                    <div class="form-group">
                        <label for="valor_mensalidade_edit">Novo Valor da Mensalidade</label>
                        <input type="number" step="0.01" id="valor_mensalidade_edit" name="valor_mensalidade_edit" required placeholder="0.00">
                    </div>
                    <button type="submit" name="editar_mensalidade_aluno" class="btn">
                        <i class="fas fa-save"></i> Salvar Alteração
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Edição de Aluno (Dados Pessoais) -->
    <div id="modal-editar-aluno-pessoal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Editar Dados Pessoais do Aluno</h2>
                <button class="modal-close" onclick="closeModal('modal-editar-aluno-pessoal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="aluno_id_edit_pessoal" id="aluno_id_edit_pessoal">
                    <div class="form-group">
                        <label for="cpf_edit">CPF</label>
                        <input type="text" id="cpf_edit" name="cpf_edit" required>
                    </div>
                    <div class="form-group">
                        <label for="data_nascimento_edit">Data de Nascimento</label>
                        <input type="date" id="data_nascimento_edit" name="data_nascimento_edit" required>
                    </div><div class="form-group">
                        <label for="telefone_edit">Telefone</label>
                        <input type="text" id="telefone_edit" name="telefone_edit" required>
                    </div>
                    <button type="submit" name="editar_aluno_pessoal" class="btn">Salvar Alterações</button>
                </form>
            </div>
        </div>
    </div>
    <!-- Modal de Edição de Aluno -->
    <div id="modal-editar-aluno" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Editar Dados do Aluno</h2>
                <button class="modal-close" onclick="closeModal('modal-editar-aluno')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" onsubmit="return confirm('Tem certeza que deseja salvar as alterações?');">
                    <input type="hidden" name="aluno_id_edit_form" id="aluno_id_edit_form">
                    
                    <div class="form-group">
                        <label for="nome_completo_edit">Nome Completo</label>
                        <input type="text" id="nome_completo_edit" name="nome_completo_edit" required>
                    </div>

                    <div class="form-group">
                        <label for="plano_id_edit">Plano</label>
                        <select id="plano_id_edit" name="plano_id_edit" required>
                            <option value="">Selecione o plano</option>
                            <?php foreach ($planos as $plano): 
                                $mods_ids = $db->query("SELECT modalidade_id FROM planos_modalidades WHERE plano_id = {$plano['id']}"); $ids_array = []; while($id = $mods_ids->fetchArray(SQLITE3_NUM)) { $ids_array[] = $id[0]; } ?>
                                <option value="<?php echo $plano['id']; ?>" data-modalidades-ids="<?php echo implode(',', $ids_array); ?>"><?php echo htmlspecialchars($plano['nome']); ?> - <?php echo formatarMoeda($plano['valor']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Container para os selects de graduação dinâmicos -->
                    <div id="graduacoes-container-edit">
                        <!-- Os selects de graduação serão inseridos aqui pelo JS -->
                    </div>
                    
                    <div class="form-group">
                        <label>Turmas</label>
                        <div id="turmas-checkbox-container-edit" class="checkbox-group-vertical">
                        </div>
                    </div>

                    <button type="submit" name="editar_aluno" class="btn">Salvar Alterações</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Renovação de Plano -->
    <div id="modal-renovar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Renovar / Trocar Plano</h2>
                <button class="modal-close" onclick="closeModal('modal-renovar')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" onsubmit="return confirm('Isso irá registrar um novo pagamento e atualizar o plano e turma do aluno. Confirma a renovação?');">
                    <input type="hidden" name="aluno_id_renovar" id="aluno_id_renovar">
                    <div class="form-group">
                        <label>Aluno</label>
                        <p id="aluno_nome_renovar" style="font-size: 1.1rem; font-weight: bold;"></p>
                    </div>
                    <div class="form-group">
                        <label for="plano_id_renovar">Novo Plano</label>
                        <select id="plano_id_renovar" name="plano_id_renovar" required onchange="filtrarTurmasRenovacao()">
                            <option value="">Selecione o plano</option>
                            <?php foreach ($planos as $plano):
                                $mods_ids = $db->query("SELECT modalidade_id FROM planos_modalidades WHERE plano_id = {$plano['id']}"); $ids_array = []; while($id = $mods_ids->fetchArray(SQLITE3_NUM)) { $ids_array[] = $id[0]; } ?>
                                    <option value="<?php echo $plano['id']; ?>" data-modalidades-ids="<?php echo implode(',', $ids_array); ?>"><?php echo htmlspecialchars($plano['nome']); ?> - <?php echo formatarMoeda($plano['valor']); ?></option>
                                <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="turma_id_renovar">Nova Turma</label>
                        <select id="turma_id_renovar" name="turma_id_renovar" required>
                            <option value="">Selecione um plano primeiro</option>
                        </select>
                    </div>
                    <button type="submit" name="renovar_plano_aluno" class="btn">
                        <i class="fas fa-check"></i> Confirmar Renovação
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Nova Turma -->
    <div id="modal-nova-turma" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Cadastrar Nova Turma</h2>
                <button class="modal-close" onclick="closeModal('modal-nova-turma')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label>Modalidades (selecione uma ou mais)</label>
                        <div class="checkbox-group-vertical">
                            <?php if (empty($modalidades_disponiveis)): ?>
                                <p style="color: var(--text-secondary);">Nenhuma modalidade cadastrada. Adicione uma na aba 'Avançados'.</p>
                            <?php else: ?>
                                <?php foreach($modalidades_disponiveis as $mod): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="turma_mod_<?php echo $mod['id']; ?>" name="modalidades_turma[]" value="<?php echo $mod['id']; ?>">
                                        <label for="turma_mod_<?php echo $mod['id']; ?>"><?php echo htmlspecialchars($mod['nome']); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group"><label>Professor</label><select name="professor_id" required><option value="">Selecione...</option><?php foreach($professores as $prof): ?><option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group">
                        <label>Dias da Semana</label>
                        <div class="checkbox-group"><?php $dias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom']; ?><?php foreach($dias as $dia): ?><div class="checkbox-item" style="flex: 1;"><input type="checkbox" id="dia_turma_<?php echo strtolower($dia); ?>" name="dias_semana_turma[]" value="<?php echo $dia; ?>"><label for="dia_turma_<?php echo strtolower($dia); ?>"><?php echo $dia; ?></label></div><?php endforeach; ?></div>
                    </div>
                    <div style="display: flex; gap: 1rem;"><div class="form-group" style="flex: 1;"><label>Horário Início</label><input type="time" name="horario_inicio_turma" required></div><div class="form-group" style="flex: 1;"><label>Horário Fim</label><input type="time" name="horario_fim_turma" required></div></div>
                    <div class="form-group"><label>Tipo (Ex: Adulto, Infantil)</label><input type="text" name="tipo_turma" required placeholder="Adulto"></div>
                    <button type="submit" name="adicionar_turma" class="btn"><i class="fas fa-plus"></i> Adicionar Turma</button>
                </form>
            </div>
        </div>
    </div>


    <!-- Modal de Frequência -->
    <div id="modal-frequencia" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 id="frequencia-modal-title">Frequência do Aluno</h2>
                <button class="modal-close" onclick="closeModal('modal-frequencia')">&times;</button>
            </div>
            <div class="modal-body">
                <table id="frequencia-calendar">
                    <thead>
                        <tr><th>Dom</th><th>Seg</th><th>Ter</th><th>Qua</th><th>Qui</th><th>Sex</th><th>Sáb</th></tr>
                    </thead>
                    <tbody id="frequencia-calendar-body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal de Edição de Graduação -->
    <div id="modal-edit-graduacao" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h2>Editar Graduação</h2>
                <button class="modal-close" onclick="closeModal('modal-edit-graduacao')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="graduacao_id_edit" id="graduacao_id_edit">
                    <input type="hidden" name="modalidade_id_grad_edit" id="modalidade_id_grad_edit">
                    <div class="form-group">
                        <label for="nome_graduacao_edit">Nome da Graduação</label>
                        <input type="text" id="nome_graduacao_edit" name="nome_graduacao_edit" required>
                    </div>
                    <div class="form-group">
                        <label for="ordem_graduacao_edit">Ordem</label>
                        <input type="number" id="ordem_graduacao_edit" name="ordem_graduacao_edit" required>
                    </div>
                    <button type="submit" name="editar_graduacao" class="btn">Salvar Alterações</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditGraduacaoModal(id, nome, ordem) {
            document.getElementById('graduacao_id_edit').value = id;
            document.getElementById('nome_graduacao_edit').value = nome;
            document.getElementById('ordem_graduacao_edit').value = ordem;
            openModal('modal-edit-graduacao');
        }
    </script>

    <!-- Modal de Graduações -->
    <div id="modal-graduacoes" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="graduacao-modal-title">Gerenciar Graduações</h2>
                <button class="modal-close" onclick="closeModal('modal-graduacoes')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="lista-graduacoes-existentes" class="table-responsive" style="margin-bottom: 2rem;">
                    <!-- A lista de graduações será carregada aqui via JS -->
                </div>
                <hr style="border-color: var(--card-border); margin: 2rem 0;">
                <h4>Adicionar Nova Graduação</h4>
                <form method="POST">
                    <input type="hidden" name="modalidade_id_grad" id="modalidade_id_grad">
                    <input type="hidden" name="reopen_modal" value="true">
                    <div class="form-group">
                        <label for="nome_graduacao">Nome da Graduação (Faixa/Corda)</label>
                        <input type="text" name="nome_graduacao" required>
                    </div>
                    <div class="form-group">
                        <label for="ordem_graduacao">Ordem (menor para maior, ex: 1, 2, 3...)</label>
                        <input type="number" name="ordem_graduacao" required value="1">
                    </div>
                    <button type="submit" name="adicionar_graduacao" class="btn">Adicionar Graduação</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Edição de Usuário -->
    <div id="modal-editar-usuario" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Editar Usuário</h2>
                <button class="modal-close" onclick="closeModal('modal-editar-usuario')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="usuario_id_edit" id="usuario_id_edit">
                    <div class="form-group"><label>E-mail</label><input type="email" name="email_professor_edit" id="email_professor_edit" required></div>
                    <div class="form-group">
                        <label>Associar a um Professor</label>
                        <select name="professor_id_usuario_edit" id="professor_id_usuario_edit" required><option value="">Selecione...</option><?php foreach($professores as $prof): ?><option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="form-group">
                        <label>Permissões de Acesso</label>
                        <div class="checkbox-group-vertical" id="permissoes-container-edit">
                            <?php foreach($modulos_disponiveis as $key => $label): ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="perm_edit_<?php echo $key; ?>" name="permissoes_edit[]" value="<?php echo $key; ?>">
                                <label for="perm_edit_<?php echo $key; ?>"><?php echo $label; ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" name="editar_usuario_professor" class="btn"><i class="fas fa-save"></i> Salvar Alterações</button>
                    <button type="button" class="btn" style="background: var(--warning);" onclick="openAlterarSenhaModal(document.getElementById('usuario_id_edit').value, document.getElementById('email_professor_edit').value)"><i class="fas fa-key"></i> Alterar Senha</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Alteração de Senha -->
    <div id="modal-alterar-senha" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h2>Alterar Senha</h2>
                <button class="modal-close" onclick="closeModal('modal-alterar-senha')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="usuario_id_senha" id="usuario_id_senha">
                    <div class="form-group">
                        <label>Usuário</label>
                        <p id="usuario_email_senha" style="font-weight: bold;"></p>
                    </div>
                    <div class="form-group">
                        <label for="nova_senha">Nova Senha</label>
                        <input type="password" name="nova_senha" id="nova_senha" required>
                    </div>
                    <div class="form-group">
                        <label for="nova_senha_confirm">Confirmar Nova Senha</label>
                        <input type="password" name="nova_senha_confirm" id="nova_senha_confirm" required>
                    </div>
                    <button type="submit" name="alterar_senha_usuario" class="btn"><i class="fas fa-save"></i> Salvar Nova Senha</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Templates para os selects de graduação (usados pelo JS) -->
    <div style="display: none;">
        <?php foreach($modalidades_disponiveis as $mod): ?>
            <div class="form-group graduacao-select-template" data-modalidade-id="<?php echo $mod['id']; ?>"><label>Graduação (<?php echo htmlspecialchars($mod['nome']); ?>)</label><select name="graduacoes[]"><option value="">Selecione...</option><?php if(isset($graduacoes_disponiveis[$mod['id']])): foreach($graduacoes_disponiveis[$mod['id']] as $grad): ?><option value="<?php echo $grad['id']; ?>"><?php echo htmlspecialchars($grad['nome']); ?></option><?php endforeach; endif; ?></select></div>
        <?php endforeach; ?>
    </div>

    <!-- Dados das turmas para o Javascript -->
    <div style="display: none;">
        <select id="turmas-disponiveis-data">
            <?php
            // A query foi movida para cima para ser usada tanto aqui quanto na seção de turmas
            $stmt_all_turmas = $db->prepare("
                SELECT 
                    t.id, t.dias_semana, t.horario_inicio, t.horario_fim, t.tipo,        
                    p.nome as professor_nome,
                    GROUP_CONCAT(m.nome) as modalidades_nomes_str,
                    GROUP_CONCAT(m.id) as modalidades_ids_str
                FROM turmas t
                JOIN professores p ON t.professor_id = p.id
                LEFT JOIN turmas_modalidades tm ON t.id = tm.turma_id
                LEFT JOIN modalidades m ON tm.modalidade_id = m.id
                WHERE t.usuario_id = :usuario_id 
                GROUP BY t.id
                ORDER BY t.tipo, t.horario_inicio
            ");
            $stmt_all_turmas->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
            $res_all_turmas = $stmt_all_turmas->execute();

            while($turma = $res_all_turmas->fetchArray(SQLITE3_ASSOC)): 
                // Prepara os dados para o dataset do JS
                $turma_data_json = htmlspecialchars(json_encode([
                    'id' => $turma['id'],
                    'tipo' => $turma['tipo'],
                    'dias_semana' => $turma['dias_semana'],
                    'horario_inicio' => $turma['horario_inicio'],
                    'horario_fim' => $turma['horario_fim'],
                    'modalidades_ids' => explode(',', $turma['modalidades_ids_str']),
                    'modalidades_nomes' => explode(',', $turma['modalidades_nomes_str']),
                ]), ENT_QUOTES, 'UTF-8');
            ?>
                <option 
                    value="<?php echo $turma['id']; ?>" 
                    data-modalidades-ids="<?php echo $turma['modalidades_ids_str']; ?>"
                    data-turma-data='<?php echo $turma_data_json; ?>'>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <!-- Modal de Menu Mobile -->
    <div id="modal-menu-mobile" class="modal">
        <div class="modal-content" style="max-width: 350px; margin: auto;">
            <div class="modal-header">
                <h2>Menu</h2>
                <button class="modal-close" onclick="closeModal('modal-menu-mobile')">&times;</button>
            </div>
            <div class="modal-body" style="padding: 0.5rem;">
                <a href="?p=alunos" class="nav-item <?php echo $pagina_atual == 'alunos' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-users"></i>
                    <span>Alunos</span>
                </a>
                <a href="?p=pagamentos" class="nav-item <?php echo $pagina_atual == 'pagamentos' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-dollar-sign"></i>
                    <span>Pagamentos</span>
                </a>
                <?php if(tem_permissao('turmas', $permissoes_usuario)): ?>
                <a href="?p=turmas" class="nav-item <?php echo $pagina_atual == 'turmas' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Turmas</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('presenca', $permissoes_usuario)): ?>
                <a href="?p=presenca" class="nav-item <?php echo $pagina_atual == 'presenca' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-user-check"></i>
                    <span>Presença</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('planos', $permissoes_usuario)): ?>
                <a href="?p=planos" class="nav-item <?php echo $pagina_atual == 'planos' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-tags"></i>
                    <span>Planos</span>
                </a>
                <?php endif; ?>
                <?php if($usuario_id_logado == 1): // Apenas admin principal vê ?>
                <a href="?p=modalidades" class="nav-item <?php echo $pagina_atual == 'modalidades' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-medal"></i>
                    <span>Modalidades</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('professores', $permissoes_usuario)): ?>
                <a href="?p=professores" class="nav-item <?php echo $pagina_atual == 'professores' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-user-tie"></i>
                    <span>Professores</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('despesas', $permissoes_usuario)): ?>
                <a href="?p=despesas" class="nav-item <?php echo $pagina_atual == 'despesas' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-receipt"></i>
                    <span>Despesas</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('despesas_futuras', $permissoes_usuario)): ?>
                <a href="?p=despesas_futuras" class="nav-item <?php echo $pagina_atual == 'despesas_futuras' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Despesas Futuras</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('configuracoes', $permissoes_usuario)): ?>
                <a href="?p=configuracoes" class="nav-item <?php echo $pagina_atual == 'configuracoes' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-cog"></i>
                    <span>Configurações</span>
                </a>
                <?php endif; ?>
                <?php if(tem_permissao('usuarios', $permissoes_usuario)): ?>
                <a href="?p=usuarios" class="nav-item <?php echo $pagina_atual == 'usuarios' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;"><i class="fas fa-users-cog"></i><span>Usuários</span></a>
                <?php endif; ?>
                <?php if ($usuario_id_logado == 1): ?>
                <a href="?p=aprovacoes" class="nav-item <?php echo $pagina_atual == 'aprovacoes' ? 'active' : ''; ?>" style="text-decoration: none; color: inherit;"><i class="fas fa-user-check"></i><span>Aprovações</span></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
</body>
</html>