<?php
require_once 'database.php';

// Check for the admin user
$admin_email = 'administrador@painel.com';
$stmt_admin = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
$stmt_admin->bindValue(':email', $admin_email, SQLITE3_TEXT);
$result_admin = $stmt_admin->execute();
$admin_user = $result_admin->fetchArray(SQLITE3_ASSOC);

$admin_id = null;
if ($admin_user) {
    $admin_id = $admin_user['id'];
    echo "Admin user ID: " . $admin_id . "\n";
} else {
    echo "Admin user '{$admin_email}' not found.\n";
}

// Check if professor_id = 1 exists for this admin_id
if ($admin_id) {
    $professor_id_to_check = 1;
    $stmt_prof = $db->prepare("SELECT id, nome FROM professores WHERE id = :id AND usuario_id = :uid");
    $stmt_prof->bindValue(':id', $professor_id_to_check, SQLITE3_INTEGER);
    $stmt_prof->bindValue(':uid', $admin_id, SQLITE3_INTEGER);
    $result_prof = $stmt_prof->execute();
    $professor = $result_prof->fetchArray(SQLITE3_ASSOC);

    if ($professor) {
        echo "Professor with ID {$professor_id_to_check} found for admin ID {$admin_id}: " . $professor['nome'] . "\n";
    } else {
        echo "Professor with ID {$professor_id_to_check} NOT found for admin ID {$admin_id}.\n";
    }
} else {
    echo "Cannot check for professor as admin user was not found.\n";
}

$db->close();
?>