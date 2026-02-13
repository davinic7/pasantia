<?php
/**
 * Respuestas de Formularios Dinamicos - Ministerio
 */
require_once __DIR__ . '/../config/config.php';

if (!$auth->requireRole(['ministerio', 'admin'], PUBLIC_URL . '/login.php')) exit;

$page_title = 'Respuestas del Formulario';
$db = getDB();

$form_id = (int)($_GET['id'] ?? 0);
if ($form_id <= 0) {
    set_flash('error', 'Formulario no encontrado.');
    redirect('formularios-dinamicos.php');
}

$stmt = $db->prepare("SELECT * FROM formularios_dinamicos WHERE id = ?");
$stmt->execute([$form_id]);
$formulario = $stmt->fetch();
if (!$formulario) {
    set_flash('error', 'Formulario no encontrado.');
    redirect('formularios-dinamicos.php');
}

$stmt = $db->prepare("SELECT * FROM formulario_preguntas WHERE formulario_id = ? ORDER BY orden ASC");
$stmt->execute([$form_id]);
$preguntas = $stmt->fetchAll();

$preguntas_map = [];
foreach ($preguntas as $p) {
    $p['opciones'] = $p['opciones'] ? json_decode($p['opciones'], true) : null;
    $preguntas_map[$p['id']] = $p;
}

$stmt = $db->prepare("
    SELECT r.*, e.nombre as empresa_nombre 
    FROM formulario_respuestas r 
    INNER JOIN empresas e ON r.empresa_id = e.id
    WHERE r.formulario_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$form_id]);
$respuestas = $stmt->fetchAll();

function render_respuesta($pregunta, $valor) {
    if ($valor === null || $valor === '') {
        return '<span class="text-muted">-</span>';
    }

    if ($pregunta['tipo'] === 'checkbox') {
        if (is_array($valor)) {
            return e(implode(', ', $valor));
        }
        return e((string)$valor);
    }

    if ($pregunta['tipo'] === 'tabla' && is_array($valor)) {
        $cols = $pregunta['opciones']['cols'] ?? [];
        $rows = $pregunta['opciones']['rows'] ?? [];
        $html = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th></th>';
        foreach ($cols as $col) {
            $html .= '<th>' . e($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $r_idx => $row) {
            $html .= '<tr><th>' . e($row) . '</th>';
            foreach ($cols as $c_idx => $col) {
                $cell = $valor[$r_idx][$c_idx] ?? '';
                $html .= '<td>' . e($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    return e(is_array($valor) ? json_encode($valor) : (string)$valor);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - Ministerio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= PUBLIC_URL ?>/css/styles.css" rel="stylesheet">
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header"><span class="text-white fw-bold"><i class="bi bi-building me-2"></i>Ministerio</span></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="empresas.php"><i class="bi bi-buildings"></i> Empresas</a>
            <a href="nueva-empresa.php"><i class="bi bi-plus-circle"></i> Nueva Empresa</a>
            <a href="formularios.php"><i class="bi bi-file-earmark-text"></i> Formularios</a>
            <a href="formularios-dinamicos.php" class="active"><i class="bi bi-ui-checks"></i> Formularios dinamicos</a>
            <a href="graficos.php"><i class="bi bi-graph-up"></i> Graficos</a>
            <a href="publicaciones.php"><i class="bi bi-megaphone"></i> Publicaciones</a>
            <hr class="my-3 border-secondary">
            <a href="<?= PUBLIC_URL ?>/" target="_blank"><i class="bi bi-globe"></i> Ver sitio</a>
            <a href="<?= PUBLIC_URL ?>/logout.php"><i class="bi bi-box-arrow-left"></i> Cerrar sesion</a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-0">Respuestas</h1>
                <div class="text-muted"><?= e($formulario['titulo']) ?></div>
            </div>
            <a href="formularios-dinamicos.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Volver</a>
        </div>

        <?php if (empty($respuestas)): ?>
            <div class="alert alert-info">Aun no hay respuestas enviadas.</div>
        <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Estado</th>
                            <th>Fecha envio</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($respuestas as $r): ?>
                        <tr>
                            <td><?= e($r['empresa_nombre']) ?></td>
                            <td>
                                <span class="badge <?= $r['estado'] === 'enviado' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst($r['estado']) ?></span>
                            </td>
                            <td><?= $r['enviado_at'] ? format_datetime($r['enviado_at']) : '-' ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalResp<?= $r['id'] ?>">
                                    <i class="bi bi-eye"></i> Ver
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <?php foreach ($respuestas as $r): ?>
        <?php $resp_data = json_decode($r['respuestas'], true) ?: []; ?>
        <div class="modal fade" id="modalResp<?= $r['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Respuesta - <?= e($r['empresa_nombre']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php foreach ($preguntas as $p): ?>
                            <?php $valor = $resp_data[$p['id']] ?? null; ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold"><?= e($p['etiqueta']) ?></label>
                                <div class="border rounded p-2 bg-light">
                                    <?= render_respuesta($preguntas_map[$p['id']], $valor) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
