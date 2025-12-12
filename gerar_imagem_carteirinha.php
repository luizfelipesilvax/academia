<?php

function gerarImagemCarteirinha($idAluno)
{
    // Conexão com o banco de dados
    $db_path = 'academia.db';
    $db = new SQLite3($db_path);

    $stmt = $db->prepare("SELECT * FROM alunos WHERE id = :id");
    $stmt->bindValue(':id', $idAluno, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $aluno = $result->fetchArray(SQLITE3_ASSOC);

    if (!$aluno) {
        return false;
    }

    // --- Configurações da Imagem ---
    $largura = 400;
    $altura = 250;
    $imagem = @imagecreatetruecolor($largura, $altura);
    if (!$imagem) { return false; } // Falha se a extensão GD não estiver ativa

    // --- Cores ---
    $cor_fundo_1 = imagecolorallocate($imagem, 22, 33, 62); // --secondary #16213e
    $cor_fundo_2 = imagecolorallocate($imagem, 15, 52, 96); // --accent #0f3460
    $cor_texto = imagecolorallocate($imagem, 240, 240, 240);
    $cor_label = imagecolorallocate($imagem, 176, 176, 176);
    $cor_sucesso = imagecolorallocate($imagem, 0, 204, 136);
    $cor_sucesso_fundo = imagecolorallocatealpha($imagem, 0, 204, 136, 100); // 0.2 alpha
    $cor_perigo = imagecolorallocate($imagem, 255, 85, 116);
    $cor_perigo_fundo = imagecolorallocatealpha($imagem, 255, 85, 116, 100); // 0.2 alpha
    $cor_highlight = imagecolorallocate($imagem, 233, 69, 96); // --highlight #e94560
    $cor_branca = imagecolorallocate($imagem, 255, 255, 255);

    // --- Fontes (caminho absoluto para robustez) ---
    $fonte_bold = 'C:/Windows/Fonts/arialbd.ttf';
    $fonte_regular = 'C:/Windows/Fonts/arial.ttf';

    // Preencher fundo
    // Simula o linear-gradient(135deg)
    for ($i = 0; $i < ($largura + $altura); $i++) {
        $ratio = $i / ($largura + $altura);
        $r = (int)($cor_fundo_1[0] * (1 - $ratio) + $cor_fundo_2[0] * $ratio);
        $g = (int)($cor_fundo_1[1] * (1 - $ratio) + $cor_fundo_2[1] * $ratio);
        $b = (int)($cor_fundo_1[2] * (1 - $ratio) + $cor_fundo_2[2] * $ratio);
        $cor_gradiente = imagecolorallocate($imagem, $r, $g, $b);
        imageline($imagem, 0, $i, $i, 0, $cor_gradiente);
    }

    // --- Desenhar Conteúdo ---
    // Cabeçalho
    imagettftext($imagem, 11, 0, 25, 40, $cor_highlight, $fonte_bold, "ELITE MARTIAL ARTS");

    // Status
    $status_texto = $aluno['status'] == 'ativo' ? 'ATIVO' : 'INATIVO';
    $cor_status = $aluno['status'] == 'ativo' ? $cor_sucesso : $cor_perigo;
    $cor_fundo_status = $aluno['status'] == 'ativo' ? $cor_sucesso_fundo : $cor_perigo_fundo;
    
    // Desenha o fundo do status (simulando o padding)
    $bbox = imagettfbbox(8, 0, $fonte_bold, $status_texto);
    $text_width = $bbox[2] - $bbox[0];
    $x_status_start = $largura - 25 - $text_width - 15; // 25 de padding, 15 de padding interno
    imagefilledrectangle($imagem, $x_status_start, 28, $largura - 25, 48, $cor_fundo_status);
    
    // Escreve o texto do status
    imagettftext($imagem, 8, 0, $x_status_start + 8, 41, $cor_status, $fonte_bold, $status_texto);

    // Corpo
    $y = 80;
    $x_label = 25;
    $x_valor = 150;
    $modalidades_array = explode(',', $aluno['modalidades']);
    $modalidadesDisplay = '';
    foreach ($modalidades_array as $index => $mod) {
        $modalidadesDisplay .= $mod === 'muay_thai' ? 'Muay Thai' : 'Jiu Jitsu';
        if ($index < count($modalidades_array) - 1) {
            $modalidadesDisplay .= ', ';
        }
    }
    $dados = [
        'Nome' => $aluno['nome_completo'],
        'CPF' => $aluno['cpf'],
        'Plano' => $aluno['plano'] == 'individual' ? 'Individual' : 'Total Pass',
        'Modalidades' => $modalidadesDisplay,
        'Vencimento' => date('d/m/Y', strtotime($aluno['proximo_vencimento']))
    ];

    foreach ($dados as $label => $valor) {
        imagettftext($imagem, 8, 0, $x_label, $y, $cor_label, $fonte_bold, $label . ':');
        imagettftext($imagem, 8, 0, $x_valor, $y, $cor_texto, $fonte_regular, $valor);
        $y += 25;
    }

    // --- Gerar e adicionar QR Code ---
    $link_verificacao = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['PHP_SELF']) . "/verificar_carteirinha.php?data=" . urlencode(json_encode(['id' => $idAluno]));
    $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' . urlencode($link_verificacao);
    
    // Baixar a imagem do QR Code de forma segura
    $qr_code_data = @file_get_contents($qr_code_url);
    if ($qr_code_data) {
        $qr_code_img = @imagecreatefromstring($qr_code_data);
        if ($qr_code_img) {
            // Adiciona um fundo branco com padding para o QR Code, como no CSS
            imagefilledrectangle($imagem, 220, 85, 378, 203, $cor_branca);
            imagecopy($imagem, $qr_code_img, 224, 89, 0, 0, 140, 140);
            imagedestroy($qr_code_img);
        }
    }

    imagettftext($imagem, 7, 0, 25, $altura - 25, $cor_label, $fonte_regular, "Valida mediante pagamento em dia.");

    // --- Salvar Imagem ---
    // Salva a imagem na pasta ./carteirinhas_temp
    $pasta_destino = __DIR__ . '/carteirinhas_temp';
    // Verifica se a pasta de destino existe, se não, a cria.
    if (!is_dir($pasta_destino)) {
        mkdir($pasta_destino, 0777, true);
    }
    $caminho_arquivo = $pasta_destino . '/carteirinha_' . $idAluno . '_' . time() . '.jpg';
    imagejpeg($imagem, $caminho_arquivo, 90); // Salva como JPEG
    imagedestroy($imagem);

    return $caminho_arquivo;
}
?>