<?php
// Lógica para Despesas Futuras (agora usando a tabela principal de despesas)
// A lógica de processamento de formulários (adicionar, remover, pagar) foi centralizada no index.php.
// Este arquivo agora é responsável apenas pela exibição da lista de despesas.

// A lógica de cadastrar e remover despesas já está no index.php e é reutilizada aqui.
// A consulta de despesas futuras agora busca na tabela 'despesas' com status 'pendente'.
$despesas_futuras = [];
$stmt_despesas_futuras = $db->prepare("SELECT * FROM despesas WHERE usuario_id = :usuario_id AND status = 'pendente' ORDER BY data_vencimento ASC");
$stmt_despesas_futuras->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$res_despesas_futuras = $stmt_despesas_futuras->execute();
while ($row = $res_despesas_futuras->fetchArray(SQLITE3_ASSOC)) {
    $despesas_futuras[] = $row;
}
?>

<div id="despesas-futuras-section" class="content-section">
    <div class="card">
        <div class="card-header">
            <h2>Despesas Futuras (Pendentes)</h2>
            <button class="btn" onclick="openModal('modal-despesa-futura')"><i class="fas fa-plus"></i> Adicionar Despesa</button>
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
                    <?php if (empty($despesas_futuras)): ?>
                        <tr>
                            <td colspan="5">Nenhuma despesa futura cadastrada.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($despesas_futuras as $df): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($df['descricao']); ?></td>
                                <td><?php echo formatarMoeda($df['valor']); ?></td>
                                <td><?php echo formatarData($df['data_vencimento']); ?></td>
                                <td>
                                    <?php if ($df['status'] == 'pendente'): ?>
                                        <span class="status-badge status-pendente">
                                            Pendente
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-pago">
                                            Pago
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($df['status'] == 'pendente'): ?>
                                            <form method="POST" onsubmit="return confirm('Marcar esta despesa como paga?');" style="display:inline;">
                                                <input type="hidden" name="despesa_id" value="<?php echo $df['id']; ?>">
                                                <button type="submit" name="marcar_pago_despesa_futura" class="btn btn-sm" style="background:var(--success);"><i class="fas fa-check"></i> Pagar</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="index.php?p=despesas_futuras" onsubmit="return confirm('Deseja remover esta despesa?');" style="display:inline;">
                                            <input type="hidden" name="despesa_id" value="<?php echo $df['id']; ?>">
                                            <button type="submit" name="remover_despesa" class="btn btn-sm" style="background:var(--danger);"><i class="fas fa-trash"></i></button>
                                        </form>
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

<!-- O modal para adicionar despesa futura continua o mesmo, mas o formulário será tratado pelo index.php -->
<div id="modal-despesa-futura" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Adicionar Despesa Futura</h2>
            <button class="modal-close" onclick="closeModal('modal-despesa-futura')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="index.php?p=despesas_futuras">
                <div class="form-group">
                    <label for="descricao_futura">Descrição</label>
                    <input type="text" name="descricao_futura" id="descricao_futura" required>
                </div>
                <div class="form-group">
                    <label for="valor_despesa_futura">Valor</label>
                    <input type="number" step="0.01" name="valor_despesa_futura" id="valor_despesa_futura" required>
                </div>
                <div class="form-group">
                    <label for="data_vencimento_futura">Data de Vencimento</label>
                    <input type="date" name="data_vencimento_futura" id="data_vencimento_futura" required>
                </div>
                <button type="submit" name="cadastrar_despesa_futura" class="btn">Cadastrar</button>
            </form>
        </div>
    </div>
</div>