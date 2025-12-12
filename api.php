 <?php
// Ativa a exibição de erros para depuração
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
require_once 'database.php';

// Verifica se a conexão com o banco de dados foi bem-sucedida
if (!$db) {
    http_response_code(503);
    // Garante que o header seja JSON mesmo em caso de erro fatal de conexão
    echo json_encode([
        'error' => 'Service Unavailable: Could not connect to the database.'
    ]);
    exit;
}

// A ação 'cadastrar_aluno_teste' é a única que usa autenticação de sessão.
// Vamos tratar dela primeiro, antes da verificação da chave de API.
// Isso evita o erro 401, pois a requisição do front-end não envia a chave de API.
if (isset($_POST['action']) && $_POST['action'] === 'enviar_carteirinha_por_whatsapp') {
    session_start();

    // Função para adicionar notificação à fila com bloqueio de arquivo
    function adicionarNotificacaoFila($notificacao) {
        $caminho_fila = __DIR__ . '/notifications_queue.json';
        $fila = file_exists($caminho_fila) ? json_decode(file_get_contents($caminho_fila), true) : [];
        $fila[] = $notificacao;
        file_put_contents($caminho_fila, json_encode($fila, JSON_PRETTY_PRINT), LOCK_EX);
    }

    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
        exit();
    }

    $aluno_id = $_POST['aluno_id'];
    $nome = $_POST['nome'];
    $telefone = $_POST['telefone'];
    $usuario_id = $_SESSION['usuario_id'];

    // Buscar credenciais do usuário logado
    $stmt_user = $db->prepare(
        "SELECT 
            (SELECT valor FROM configuracoes WHERE chave = 'botbot_appkey' AND usuario_id = :uid) as appkey,
            (SELECT valor FROM configuracoes WHERE chave = 'botbot_authkey' AND usuario_id = :uid) as authkey
        FROM usuarios WHERE id = :uid"
    );
    $stmt_user->bindValue(':uid', $usuario_id, SQLITE3_INTEGER);
    $user_creds = $stmt_user->execute()->fetchArray(SQLITE3_ASSOC);

    if (empty($user_creds['appkey']) || empty($user_creds['authkey'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Credenciais do Bot-Bot não configuradas.']);
        exit();
    }

    // Construir o link e a mensagem
    $link_carteirinha = 'https://samuraisoft.com.br/carteirinha.php?id=' . $aluno_id;
    $mensagem_whatsapp = "Olá {$nome}, segue o link para sua carteirinha digital. Você pode usá-la para validar sua entrada.\n\n{$link_carteirinha}\n\nBons treinos! 💪";
    
    $nova_notificacao = [
        'credentials' => ['appkey' => $user_creds['appkey'], 'authkey' => $user_creds['authkey']],
        'to' => $telefone, 'message' => $mensagem_whatsapp, 'type' => 'manual', 'aluno_id' => $aluno_id, 'send_at' => null
    ];

    // Adiciona a notificação à fila e retorna sucesso
    adicionarNotificacaoFila($nova_notificacao);

    echo json_encode(['success' => true, 'message' => 'Notificação enfileirada para envio.']);
    exit();
}
if (isset($_POST['action']) && $_POST['action'] === 'cadastrar_aluno_teste') {
    session_start();
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
        exit();
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

    $telefone = $_POST['telefone'] ?? null;
    if (!$telefone) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Número de telefone não fornecido.']);
        exit();
    }

    $usuario_id_logado = $_SESSION['usuario_id'];
    $nome = "Aluno Teste " . date('H:i:s');
    // Geração de CPF mais aleatória para evitar conflitos de chave única.
    $cpf_p1 = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    $cpf_p2 = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    $cpf_p3 = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    $cpf = "$cpf_p1.$cpf_p2.$cpf_p3-" . rand(10,99);
    $nascimento = '1990-01-01';
    $plano_id = 1; // Assumindo que um plano padrão com ID 1 exista
    $modalidades = 'Jiu Jitsu';
    $data_inicio = date('Y-m-d');
    $proximo_vencimento = date('Y-m-d', strtotime($data_inicio . ' +1 month'));
    $novo_id = gerarIdAleatorio();

    // --- Lógica para criar dados padrão se não existirem ---
    $db->exec('BEGIN'); // Inicia a transação aqui
    
    // 1. Garante que a modalidade 'Jiu Jitsu' exista
    $db->exec("INSERT OR IGNORE INTO modalidades (nome) VALUES ('Jiu Jitsu')");
    $modalidade_id = $db->querySingle("SELECT id FROM modalidades WHERE nome = 'Jiu Jitsu'");
    if (!$modalidade_id) {
        $db->exec('ROLLBACK');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro: Não foi possível encontrar ou criar a modalidade padrão.']);
        $db->close();
        exit();
    }

    // 2. Garante que um professor padrão exista para o usuário
    $stmt_prof = $db->prepare("INSERT OR IGNORE INTO professores (usuario_id, nome) VALUES (:uid, 'Professor Padrão')");
    $stmt_prof->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $stmt_prof->execute();
    $stmt_get_prof = $db->prepare("SELECT id FROM professores WHERE usuario_id = :uid AND nome = 'Professor Padrão'");
    $stmt_get_prof->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $professor_id = $stmt_get_prof->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
    if (!$professor_id) {
        $db->exec('ROLLBACK');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro: Não foi possível encontrar ou criar o professor padrão.']);
        $db->close();
        exit();
    }

    // 3. Garante que um plano padrão exista para o usuário
    $stmt_plano = $db->prepare("INSERT OR IGNORE INTO planos (usuario_id, nome, valor) VALUES (:uid, 'Plano Padrão', 130.00)");
    $stmt_plano->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $stmt_plano->execute();
    $stmt_get_plano = $db->prepare("SELECT id FROM planos WHERE usuario_id = :uid AND nome = 'Plano Padrão'");
    $stmt_get_plano->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $plano_id = $stmt_get_plano->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
    if (!$plano_id) {
        $db->exec('ROLLBACK');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro: Não foi possível encontrar ou criar o plano padrão.']);
        $db->close(); exit();
    }
    // Busca o valor do plano que acabamos de garantir que existe
    $stmt_get_valor_plano = $db->prepare("SELECT valor FROM planos WHERE id = :pid");
    $stmt_get_valor_plano->bindValue(':pid', $plano_id, SQLITE3_INTEGER);
    $valor_mensalidade = $stmt_get_valor_plano->execute()->fetchArray(SQLITE3_NUM)[0] ?? 130.00; // Fallback

    $modalidades = 'Jiu Jitsu';

    // Associa a modalidade ao plano, se ainda não estiver associado
    $stmt_pm = $db->prepare("INSERT OR IGNORE INTO planos_modalidades (plano_id, modalidade_id) VALUES (:pid, :mid)");
    $stmt_pm->bindValue(':pid', $plano_id, SQLITE3_INTEGER);
    $stmt_pm->bindValue(':mid', $modalidade_id, SQLITE3_INTEGER);
    $stmt_pm->execute();

    // 4. Garante que uma turma padrão exista para o usuário
    $stmt_turma = $db->prepare("INSERT OR IGNORE INTO turmas (usuario_id, professor_id, dias_semana, horario_inicio, horario_fim, tipo) 
               VALUES (:uid, :prof_id, 'Seg, Qua, Sex', '19:00', '20:00', 'Padrão')");
    $stmt_turma->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $stmt_turma->bindValue(':prof_id', $professor_id, SQLITE3_INTEGER);
    $stmt_turma->execute();
    $stmt_get_turma = $db->prepare("SELECT id FROM turmas WHERE usuario_id = :uid AND tipo = 'Padrão' AND professor_id = :prof_id");
    $stmt_get_turma->bindValue(':uid', $usuario_id_logado, SQLITE3_INTEGER);
    $stmt_get_turma->bindValue(':prof_id', $professor_id, SQLITE3_INTEGER);
    $turma_id = $stmt_get_turma->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
    if (!$turma_id) {
        $db->exec('ROLLBACK');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro: Não foi possível encontrar ou criar a turma padrão.']);
        $db->close();
        exit();
    }

    // Associa a modalidade à turma, se ainda não estiver associado
    $stmt_tm = $db->prepare("INSERT OR IGNORE INTO turmas_modalidades (turma_id, modalidade_id) VALUES (:tid, :mid)");
    $stmt_tm->bindValue(':tid', $turma_id, SQLITE3_INTEGER);
    $stmt_tm->bindValue(':mid', $modalidade_id, SQLITE3_INTEGER);
    $stmt_tm->execute();

    // --- Fim da lógica de dados padrão ---

    $stmt = $db->prepare("INSERT INTO alunos (id, usuario_id, nome_completo, cpf, data_nascimento, telefone, plano_id, turma_id, modalidades, data_inicio, valor_mensalidade, proximo_vencimento, status)
                         VALUES (:id, :usuario_id, :nome, :cpf, :nascimento, :telefone, :plano_id, :turma_id, :modalidades, :inicio, :valor, :vencimento, 'ativo')");

    // Bind dos valores para o aluno
    $stmt->bindValue(':id', $novo_id, SQLITE3_TEXT);
    $stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
    $stmt->bindValue(':nome', $nome, SQLITE3_TEXT);
    $stmt->bindValue(':cpf', $cpf, SQLITE3_TEXT);
    $stmt->bindValue(':nascimento', $nascimento, SQLITE3_TEXT);
    $stmt->bindValue(':telefone', $telefone, SQLITE3_TEXT);
    $stmt->bindValue(':plano_id', $plano_id, SQLITE3_INTEGER);
    $stmt->bindValue(':turma_id', $turma_id, SQLITE3_INTEGER);
    $stmt->bindValue(':modalidades', $modalidades, SQLITE3_TEXT);
    $stmt->bindValue(':inicio', $data_inicio, SQLITE3_TEXT);
    $stmt->bindValue(':valor', $valor_mensalidade, SQLITE3_FLOAT);
    $stmt->bindValue(':vencimento', $proximo_vencimento, SQLITE3_TEXT);

    $aluno_success = $stmt->execute();

    if ($aluno_success) {
        // Registrar o primeiro pagamento, assim como no cadastro normal
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

        $db->exec('COMMIT');
        echo json_encode(['success' => true, 'message' => 'Aluno de teste criado com sucesso!']);
    } else {
        $db->exec('ROLLBACK');
        http_response_code(500);
        $last_error = $db->lastErrorMsg();
        echo json_encode(['success' => false, 'message' => 'Erro ao criar aluno de teste. Detalhes: ' . $last_error]);
        $db->close();
        exit();
    }

    $db->close();
    exit(); // Termina o script aqui, pois a ação foi concluída.
}

// --- CONFIGURAÇÃO DE SEGURANÇA ---
// Esta chave deve ser a mesma no seu script Python. Mantenha-a em segredo.
define('API_SECRET_KEY', 'B3k7sPz9@wXv$rTqL!n2mCgFhJd5uA8i');

function formatarDataApi($data) {
    return $data ? date('d/m/Y', strtotime($data)) : 'N/A';
}

function formatarMoedaApi($valor) {
    return 'R$ ' . number_format($valor, 2, ",", ".");
}

// Verifica se o cabeçalho de autorização foi enviado.
// Esta lógica é mais robusta para diferentes configurações de servidor (Apache, Nginx, etc.).
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if (!$auth_header && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
}

if (!$auth_header || !preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization header missing or invalid.']);
    exit;
}

$token = $matches[1];
if ($token !== API_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API secret key.']);
    exit;
}

// --- LÓGICA DA API ---
$endpoint = $_GET['action'] ?? null;

if ($endpoint === 'get_notifications') {
    $notifications = [];
    $hoje = date('Y-m-d');
    $dias_semana_map = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
    $dia_hoje_str = $dias_semana_map[date('w')];

    // 1. Buscar credenciais de todos os usuários que têm Bot-Bot configurado
    $stmt_users = $db->query(
        "SELECT 
            u.id as usuario_id, u.nome_academia,
            (SELECT valor FROM configuracoes WHERE chave = 'botbot_appkey' AND usuario_id = u.id) as appkey,
            (SELECT valor FROM configuracoes WHERE chave = 'botbot_authkey' AND usuario_id = u.id) as authkey
        FROM usuarios u
        WHERE u.status = 'aprovado'"
    );

    while ($user = $stmt_users->fetchArray(SQLITE3_ASSOC)) {
        if (empty($user['appkey']) || empty($user['authkey'])) {
            continue; // Pula usuários sem credenciais
        }

        $credentials = [
            'appkey' => $user['appkey'],
            'authkey' => $user['authkey']
        ];
        $nome_academia = $user['nome_academia'] ?? 'Sua Academia';

        // 2. Buscar alunos com vencimento em 3 dias (que não receberam lembrete hoje)
        $tres_dias_futuro = date('Y-m-d', strtotime('+3 days'));
        $stmt_vencer = $db->prepare(
            "SELECT id, nome_completo, telefone, valor_mensalidade, proximo_vencimento 
            FROM alunos 
            WHERE usuario_id = :uid AND status = 'ativo' 
              AND proximo_vencimento BETWEEN :hoje AND :tres_dias
              AND (data_ultimo_lembrete IS NULL OR data_ultimo_lembrete < :hoje)"
        );
        $stmt_vencer->bindValue(':uid', $user['usuario_id'], SQLITE3_INTEGER);
        $stmt_vencer->bindValue(':hoje', $hoje, SQLITE3_TEXT);
        $stmt_vencer->bindValue(':tres_dias', $tres_dias_futuro, SQLITE3_TEXT);
        $res_vencer = $stmt_vencer->execute();

        while ($aluno = $res_vencer->fetchArray(SQLITE3_ASSOC)) {
            $mensagem = "Olá {$aluno['nome_completo']}, tudo bem?\n\nLembrete da {$nome_academia}: sua mensalidade no valor de " . formatarMoedaApi($aluno['valor_mensalidade']) . " vence em " . formatarDataApi($aluno['proximo_vencimento']) . ".\n\nEvite a inativação do seu plano realizando o pagamento. Bons treinos! 💪";
            $notifications[] = [
                'credentials' => $credentials,
                'to' => $aluno['telefone'],
                'message' => $mensagem,
                'type' => 'vencimento',
                'aluno_id' => $aluno['id'], // Para marcar como enviado
                'send_at' => date('Y-m-d') . ' 12:00:00' // Agendado para o meio-dia
            ];
        }

        // 3. Buscar alunos com aula hoje (que não receberam lembrete de aula hoje)
        $stmt_aula = $db->prepare(
            "SELECT a.id, a.nome_completo, a.telefone,
            t.horario_inicio, t.horario_fim FROM alunos a
            JOIN turmas t ON a.turma_id = t.id
            WHERE a.usuario_id = :uid AND a.status = 'ativo'
              AND t.dias_semana LIKE :dia_hoje
              AND (a.data_ultimo_lembrete_aula IS NULL OR a.data_ultimo_lembrete_aula < :hoje)"
        );
        $stmt_aula->bindValue(':uid', $user['usuario_id'], SQLITE3_INTEGER);
        $stmt_aula->bindValue(':dia_hoje', '%' . $dia_hoje_str . '%', SQLITE3_TEXT);
        $stmt_aula->bindValue(':hoje', $hoje, SQLITE3_TEXT);
        $res_aula = $stmt_aula->execute();

        while ($aluno = $res_aula->fetchArray(SQLITE3_ASSOC)) {
            // Calcula o horário de envio (1h antes da aula)
            $horario_envio = date('Y-m-d H:i:s', strtotime("{$hoje} {$aluno['horario_inicio']} -1 hour"));
            $mensagem = "Olá {$aluno['nome_completo']}, tudo certo?\n\nPassando para lembrar do nosso treino na {$nome_academia} hoje das {$aluno['horario_inicio']} às {$aluno['horario_fim']}! Sua presença é fundamental para a equipe e estamos contando com você para somar no tatame. Oss! 💪";
            $notifications[] = [
                'credentials' => $credentials,
                'to' => $aluno['telefone'],
                'message' => $mensagem,
                'type' => 'aula',
                'aluno_id' => $aluno['id'],
                'send_at' => $horario_envio
            ];
        }

        // 4. Buscar alunos recém-cadastrados que não receberam a mensagem de boas-vindas
        $stmt_bem_vindo = $db->prepare(
            "SELECT id, nome_completo, telefone 
            FROM alunos
            WHERE usuario_id = :uid AND data_bem_vindo_enviado IS NULL"
        );
        $stmt_bem_vindo->bindValue(':uid', $user['usuario_id'], SQLITE3_INTEGER);
        $res_bem_vindo = $stmt_bem_vindo->execute();

        while ($aluno = $res_bem_vindo->fetchArray(SQLITE3_ASSOC)) {
            $link_carteirinha = 'https://samuraisoft.com.br/carteirinha.php?id=' . $aluno['id'];
            $mensagem = "Olá {$aluno['nome_completo']}, seja bem-vindo(a) à {$nome_academia}! 🎉\n\nSeu cadastro foi realizado com sucesso. Aqui está o link para sua carteirinha digital:\n\n{$link_carteirinha}\n\nBons treinos!";
            $notifications[] = [
                'credentials' => $credentials,
                'to' => $aluno['telefone'],
                'message' => $mensagem,
                'type' => 'bem_vindo',
                'aluno_id' => $aluno['id'],
                'send_at' => null // Envio imediato
            ];
        }

    }

    // 5. Buscar notificações manuais da fila
    $caminho_fila = __DIR__ . '/notifications_queue.json';
    if (file_exists($caminho_fila)) {
        $fila_manual = json_decode(file_get_contents($caminho_fila), true);
        if (is_array($fila_manual) && !empty($fila_manual)) {
            // Adiciona as notificações da fila ao array principal
            $notifications = array_merge($notifications, $fila_manual);
            // Limpa o arquivo da fila para não reenviar
            file_put_contents($caminho_fila, json_encode([]), LOCK_EX);
        }
    }


    echo json_encode(['notifications' => $notifications ?: []]);
    // The script will exit and close the connection at the end.

} elseif ($endpoint === 'mark_as_sent') {
    $data = json_decode(file_get_contents('php://input'), true);
    $aluno_id = $data['aluno_id'] ?? null;
    $type = $data['type'] ?? null;

    if ($aluno_id && in_array($type, ['vencimento', 'aula', 'bem_vindo', 'manual'])) {
        $coluna_update = '';
        if ($type === 'vencimento') {
            $coluna_update = 'data_ultimo_lembrete';
        } elseif ($type === 'aula') {
            $coluna_update = 'data_ultimo_lembrete_aula';
        } elseif ($type === 'bem_vindo') {
            $coluna_update = 'data_bem_vindo_enviado';
        }

        // Se for 'manual', não faz nada no banco, apenas retorna sucesso.
        if ($type === 'manual') {
            echo json_encode(['success' => true, 'message' => "Notificação manual para aluno {$aluno_id} processada."]);
            exit();
        }

        $stmt = $db->prepare("UPDATE alunos SET {$coluna_update} = :hoje WHERE id = :id");
        $stmt->bindValue(':hoje', date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => "Aluno {$aluno_id} marcado como notificado."]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar o banco de dados.']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos para marcar como enviado.']);
    }
    // The script will exit and close the connection at the end.

} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint não encontrado.']);
}

$db->close();