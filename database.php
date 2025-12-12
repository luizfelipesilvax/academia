<?php
/**
 * Ponto central para a conexão e inicialização do banco de dados.
 * Este arquivo garante que a estrutura do banco de dados (tabelas) seja criada
 * de forma consistente em toda a aplicação.
 */

function getDbConnection() {
    $db_path = __DIR__ . '/academia.db';
    $db = new SQLite3($db_path);

    // Ativa o suporte a chaves estrangeiras, importante para a integridade dos dados.
    $db->exec('PRAGMA foreign_keys = ON;');

    // Executa a criação de todas as tabelas, se elas não existirem.
    // Esta é a "fonte da verdade" para a estrutura do banco de dados.
    $db->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            nome_academia TEXT,
            status TEXT DEFAULT 'pendente', -- 'pendente', 'aprovado', 'rejeitado'
            tipo TEXT DEFAULT 'professor', -- 'admin', 'professor'
            professor_id INTEGER, 
            FOREIGN KEY(professor_id) REFERENCES professores(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS alunos (
            id TEXT PRIMARY KEY,
            usuario_id INTEGER NOT NULL,
            nome_completo TEXT NOT NULL,
            cpf TEXT NOT NULL,
            data_nascimento TEXT NOT NULL,
            telefone TEXT NOT NULL,
            plano_id INTEGER,
            modalidades TEXT NOT NULL,
            professor TEXT, -- Esta coluna pode ser removida no futuro, pois a turma já tem professor.
            dia_vencimento INTEGER DEFAULT 10, -- Novo campo para o dia do vencimento
            data_inicio TEXT NOT NULL,
            dias_semana TEXT,
            horario_inicio TEXT,
            horario_fim TEXT,
            valor_mensalidade REAL NOT NULL,
            ultimo_pagamento TEXT,
            proximo_vencimento TEXT,
            data_ultimo_lembrete TEXT, -- Para lembretes de vencimento
            data_ultimo_lembrete_aula TEXT, -- Para lembretes de aula
            data_bem_vindo_enviado TEXT, -- Para a mensagem de boas-vindas
            status TEXT DEFAULT 'inativo', -- 'ativo', 'inativo', 'bloqueado'
            FOREIGN KEY(plano_id) REFERENCES planos(id),
            FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            UNIQUE(usuario_id, cpf)
        );

        CREATE TABLE IF NOT EXISTS pagamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            aluno_id TEXT,
            usuario_id INTEGER NOT NULL,
            nome_aluno TEXT,
            mes_referencia TEXT,
            data_pagamento TEXT,
            valor REAL,
            status TEXT DEFAULT 'pendente',
            FOREIGN KEY(aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS despesas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            descricao TEXT NOT NULL,
            valor REAL NOT NULL,
            mes_referencia TEXT NOT NULL,
            data_vencimento TEXT,
            data_pagamento TEXT,
            status TEXT DEFAULT 'pendente'
        );

        CREATE TABLE IF NOT EXISTS configuracoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            chave TEXT NOT NULL,
            valor TEXT NOT NULL,
            UNIQUE(usuario_id, chave)
        );

        CREATE TABLE IF NOT EXISTS professores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            cpf TEXT,
            status TEXT DEFAULT 'ativo',
            UNIQUE(usuario_id, nome)
        );

        CREATE TABLE IF NOT EXISTS turmas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            professor_id INTEGER NOT NULL,
            dias_semana TEXT NOT NULL,
            horario_inicio TEXT NOT NULL,
            horario_fim TEXT NOT NULL,
            tipo TEXT NOT NULL,
            FOREIGN KEY(professor_id) REFERENCES professores(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS turmas_modalidades (
            turma_id INTEGER NOT NULL,
            modalidade_id INTEGER NOT NULL,
            FOREIGN KEY(turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
            FOREIGN KEY(modalidade_id) REFERENCES modalidades(id) ON DELETE CASCADE,
            PRIMARY KEY (turma_id, modalidade_id)
        );

        CREATE TABLE IF NOT EXISTS planos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            valor REAL NOT NULL, -- A coluna modalidades será removida daqui
            UNIQUE(usuario_id, nome),
            FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS modalidades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT UNIQUE NOT NULL
        );

        CREATE TABLE IF NOT EXISTS planos_modalidades (
            plano_id INTEGER NOT NULL,
            modalidade_id INTEGER NOT NULL,
            FOREIGN KEY(plano_id) REFERENCES planos(id) ON DELETE CASCADE,
            FOREIGN KEY(modalidade_id) REFERENCES modalidades(id) ON DELETE CASCADE, PRIMARY KEY (plano_id, modalidade_id)
        );

        CREATE TABLE IF NOT EXISTS graduacoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,            
            modalidade_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            ordem INTEGER DEFAULT 0,
            FOREIGN KEY(modalidade_id) REFERENCES modalidades(id) ON DELETE CASCADE,
            UNIQUE(modalidade_id, nome)
        );

        CREATE TABLE IF NOT EXISTS alunos_graduacoes (
            aluno_id TEXT NOT NULL,
            graduacao_id INTEGER NOT NULL,
            FOREIGN KEY(aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
            FOREIGN KEY(graduacao_id) REFERENCES graduacoes(id) ON DELETE CASCADE,
            PRIMARY KEY (aluno_id, graduacao_id)
        );

        CREATE TABLE IF NOT EXISTS alunos_turmas (
            aluno_id TEXT NOT NULL,
            turma_id INTEGER NOT NULL,
            FOREIGN KEY(aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
            FOREIGN KEY(turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
            PRIMARY KEY (aluno_id, turma_id)
        );

        CREATE TABLE IF NOT EXISTS presencas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            aluno_id TEXT NOT NULL,
            usuario_id INTEGER NOT NULL,
            data_presenca TEXT NOT NULL,
            status TEXT NOT NULL, -- 'presente' ou 'falta'
            FOREIGN KEY(aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
            UNIQUE(aluno_id, data_presenca)
        );

        CREATE TABLE IF NOT EXISTS usuario_permissoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            modulo TEXT NOT NULL,
            FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        );
    ");

    // --- SEEDING: Inserir dados padrão (Modalidades e Graduações) ---
    // Esta lógica será executada apenas uma vez para popular o banco com dados iniciais.
    $db->exec('BEGIN');

    // Modalidades Padrão
    $modalidades_padrao = ['Jiu-Jitsu', 'Jiu-Jitsu Kids', 'Muay Thai', 'Judô', 'Karatê', 'Taekwondo', 'Capoeira'];
    $stmt_mod = $db->prepare("INSERT OR IGNORE INTO modalidades (nome) VALUES (:nome)");
    foreach ($modalidades_padrao as $nome_mod) {
        $stmt_mod->bindValue(':nome', $nome_mod, SQLITE3_TEXT);
        $stmt_mod->execute();
    }

    // Graduações Padrão
    $graduacoes_padrao = [
        'Jiu-Jitsu' => ['Branca', 'Azul', 'Roxa', 'Marrom', 'Preta', 'Coral Vermelha e Preta', 'Coral Vermelha e Branca', 'Vermelha'],
        'Jiu-Jitsu Kids' => ['Branca', 'Cinza', 'Amarela', 'Laranja', 'Verde'],
        'Muay Thai' => ['Branca', 'Branca com Ponta Vermelha', 'Vermelha', 'Vermelha com Ponta Azul Clara', 'Azul Clara', 'Azul Clara com Ponta Azul Escura', 'Azul Escura', 'Azul Escura com Ponta Preta', 'Preta'],
        'Judô' => ['Branca', 'Cinza', 'Azul', 'Amarela', 'Laranja', 'Verde', 'Roxa', 'Marrom', 'Preta'],
        'Karatê' => ['Branca', 'Amarela', 'Laranja', 'Verde', 'Roxa', 'Marrom', 'Preta'],
        'Taekwondo' => ['Branca', 'Branca com Ponta Amarela', 'Amarela', 'Amarela com Ponta Verde', 'Verde', 'Verde com Ponta Azul', 'Azul', 'Azul com Ponta Vermelha', 'Vermelha', 'Vermelha com Ponta Preta', 'Preta'],
        'Capoeira' => ['Crua (Branca)', 'Amarela', 'Laranja', 'Azul', 'Verde', 'Roxa', 'Marrom', 'Vermelha']
    ];

    $stmt_grad = $db->prepare("INSERT OR IGNORE INTO graduacoes (modalidade_id, nome, ordem) VALUES (:mid, :nome, :ordem)");

    foreach ($graduacoes_padrao as $modalidade_nome => $faixas) {
        // Busca o ID da modalidade que acabamos de garantir que existe
        $modalidade_id = $db->querySingle("SELECT id FROM modalidades WHERE nome = '{$modalidade_nome}'");
        
        if ($modalidade_id) {
            $ordem = 1;
            foreach ($faixas as $faixa_nome) {
                $stmt_grad->bindValue(':mid', $modalidade_id, SQLITE3_INTEGER);
                $stmt_grad->bindValue(':nome', $faixa_nome, SQLITE3_TEXT);
                $stmt_grad->bindValue(':ordem', $ordem++, SQLITE3_INTEGER);
                $stmt_grad->execute();
            }
        }
    }

    $db->exec('COMMIT');
    // --- FIM DO SEEDING ---

    return $db;
}

// Para uso direto nos scripts, a variável $db é criada aqui.
$db = getDbConnection();