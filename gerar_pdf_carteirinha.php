<?php
session_start();

// Incluir o autoloader do Composer para usar o mPDF
require_once __DIR__ . '/vendor/autoload.php';

// Garante que apenas usuários logados possam gerar o PDF.
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403); // Forbidden
    echo "Acesso não autorizado.";
    exit;
}

function gerarPdfCarteirinha($aluno_id) {
    // Configuração do banco de dados (igual ao index.php)
    $db_path = 'academia.db';
    $db = new SQLite3($db_path);

    // Buscar dados do aluno e garantir que ele pertence ao usuário logado
    $stmt = $db->prepare("SELECT * FROM alunos WHERE id = :id AND usuario_id = :usuario_id");
    $stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
    $stmt->bindValue(':usuario_id', $_SESSION['usuario_id'], SQLITE3_INTEGER);
    $resultado = $stmt->execute();
    $aluno = $resultado->fetchArray(SQLITE3_ASSOC);

    if (!$aluno) {
        $db->close();
        return null; // Aluno não encontrado
    }

    // Buscar configurações da academia
    $stmt_config = $db->prepare("SELECT valor FROM configuracoes WHERE chave = :chave AND usuario_id = :uid");
    $stmt_config->bindValue(':chave', 'nome_academia', SQLITE3_TEXT);
    $stmt_config->bindValue(':uid', $_SESSION['usuario_id'], SQLITE3_INTEGER);
    $nome_academia = $stmt_config->execute()->fetchArray(SQLITE3_NUM)[0] ?? 'Sua Academia';

    // Funções de formatação (pode ser movido para um arquivo de helpers)
    function formatarDataPdf($data) {
        if (!$data) return 'N/A';
        return date('d/m/Y', strtotime($data));
    }

    // Lógica para formatar as modalidades
    $modalidades_array = explode(',', $aluno['modalidades']);
    $modalidades_formatadas = [];
    foreach ($modalidades_array as $modalidade) {
        $modalidades_formatadas[] = ($modalidade == 'muay_thai' ? 'Muay Thai' : 'Jiu Jitsu');
    }
    $modalidades_display = implode(', ', $modalidades_formatadas);

    // Gerar QR Code como uma imagem em base64
    $qr_code_data = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/verificar_carteirinha.php?id=' . $aluno['id'];
    // Gerar a imagem e já formatar como uma data URI para o HTML
    $qr_code_base64 = (new \chillerlan\QRCode\QRCode)->render($qr_code_data);

    // Definir variáveis de estilo
    $status_text = $aluno['status'] === 'ativo' ? 'ATIVO' : 'INATIVO';
    $status_color = $aluno['status'] === 'ativo' ? '#00cc88' : '#ff5574';
    $status_bg_color = $aluno['status'] === 'ativo' ? 'rgba(0, 204, 136, 0.15)' : 'rgba(255, 85, 116, 0.15)';

    $db->close();

    // Montar o HTML da carteirinha
    $html = "
    <style>
        body { font-family: 'Segoe UI', sans-serif; }
        .carteirinha {
            width: 350px;
            border-radius: 16px;
            padding: 20px;
            background: linear-gradient(135deg, #16213e, #0f3460);
            color: #f0f0f0;
            border: 1px solid #2a3a5c;
        }
        .carteirinha-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .carteirinha-logo {
            font-weight: bold;
            font-size: 16px;
            color: #e94560;
        }
        .carteirinha-status {
            padding: 5px 10px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 11px;
            background-color: {$status_bg_color};
            color: {$status_color};
        }
        .carteirinha-body p { margin: 0 0 8px 0; font-size: 12px; }
        .carteirinha-body .label { color: #b0b0b0; font-weight: 600; min-width: 100px; display: inline-block; }
        .carteirinha-qr { margin: 15px auto; width: 120px; height: 120px; background: white; padding: 5px; border-radius: 8px; }
        .carteirinha-footer { margin-top: 15px; font-size: 10px; color: #b0b0b0; text-align: center; }
    </style>

    <div class='carteirinha'>
        <div class='carteirinha-header'>
            <div class='carteirinha-logo'>{$nome_academia}</div>
            <div class='carteirinha-status'>{$status_text}</div>
        </div>
        <div class='carteirinha-body'>
            <p><span class='label'>Nome:</span> {$aluno['nome_completo']}</p>
            <p><span class='label'>CPF:</span> {$aluno['cpf']}</p>
            <p><span class='label'>Plano:</span> " . ($aluno['plano'] === 'individual' ? 'Individual' : 'Total Pass') . "</p>
            <p><span class='label'>Modalidades:</span> {$modalidades_display}</p>
            <p><span class='label'>Faixa:</span> " . 
                implode(' | ', array_filter([
                    !empty($aluno['faixa_jj']) ? 'JJ: ' . ucfirst(str_replace('_', ' ', preg_replace('/_jj_.*$/', '', $aluno['faixa_jj']))) : '',
                    !empty($aluno['faixa_mt']) ? 'MT: ' . ucfirst(str_replace('_', ' ', preg_replace('/_mt$/', '', $aluno['faixa_mt']))) : ''
                ]))
            . "</p>
            <p><span class='label'>Vencimento:</span> " . formatarDataPdf($aluno['proximo_vencimento']) . "</p>
        </div>
        <div class='carteirinha-qr'>
            <img src='{$qr_code_base64}' width='120' height='120'>
        </div>
        <div class='carteirinha-footer'>
            Válida mediante pagamento em dia
        </div>
    </div>
    ";

    try {
        // Instanciar o mPDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 
            'format' => 'A4', // Usamos um formato padrão, mas o conteúdo definirá o tamanho final
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
        ]);

        // Escrever o HTML no PDF
        $mpdf->WriteHTML($html);

        // Criar um nome de arquivo temporário
        $caminho_temporario = sys_get_temp_dir() . '/carteirinha_' . uniqid() . '.pdf';

        // Salvar o PDF no caminho temporário
        $mpdf->Output($caminho_temporario, \Mpdf\Output\Destination::FILE);

        return $caminho_temporario;

    } catch (\Mpdf\MpdfException $e) {
        // Logar o erro, se necessário
        error_log('mPDF error: ' . $e->getMessage());
        return null;
    }
}

?>