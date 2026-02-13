<?php
/**
 * Responder Formulario Dinamico - Empresa
 */
require_once __DIR__ . '/../config/config.php';

if (!$auth->requireRole(['empresa'], PUBLIC_URL . '/login.php')) exit;

$page_title = 'Responder Formulario';
$db = getDB();
$empresa_id = $_SESSION['empresa_id'] ?? null;
$error = '';
$mensaje = '';

if (!$empresa_id) {
    set_flash('error', 'No se encontro la empresa asociada');
    redirect('dashboard.php');
}

$form_id = (int)($_GET['id'] ?? 0);
if ($form_id <= 0) {
    set_flash('error', 'Formulario no encontrado.');
    redirect('formularios.php');
}

$stmt = $db->prepare("SELECT * FROM formularios_dinamicos WHERE id = ? AND estado = 'publicado'");
$stmt->execute([$form_id]);
$formulario = $stmt->fetch();
if (!$formulario) {
    set_flash('error', 'Formulario no disponible.');
    redirect('formularios.php');
}

$stmt = $db->prepare("SELECT * FROM formulario_preguntas WHERE formulario_id = ? ORDER BY orden ASC");
$stmt->execute([$form_id]);
$preguntas = $stmt->fetchAll();

foreach ($preguntas as &$p) {
    $p['opciones'] = $p['opciones'] ? json_decode($p['opciones'], true) : null;
}
unset($p);

$stmt = $db->prepare("SELECT * FROM formulario_respuestas WHERE formulario_id = ? AND empresa_id = ?");
$stmt->execute([$form_id, $empresa_id]);
$respuesta_existente = $stmt->fetch();
$respuestas_data = $respuesta_existente ? (json_decode($respuesta_existente['respuestas'], true) ?: []) : [];

$bloqueado = $respuesta_existente && $respuesta_existente['estado'] === 'enviado';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($bloqueado) {
        $error = 'Este formulario ya fue enviado y no puede modificarse.';
    } elseif (!verify_csrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Token de seguridad invalido. Recargue la pagina.';
    } else {
        $accion = $_POST['accion'] ?? 'guardar';
        $estado = $accion === 'enviar' ? 'enviado' : 'borrador';
        $respuestas = [];
        $errores_campos = [];

        foreach ($preguntas as $p) {
            $pid = $p['id'];
            $valor = null;

            if ($p['tipo'] === 'checkbox') {
                $valor = $_POST['respuesta'][$pid] ?? [];
                if (!is_array($valor)) {
                    $valor = [];
                }
            } elseif ($p['tipo'] === 'tabla') {
                $valor = $_POST['respuesta_tabla'][$pid] ?? [];
                if (!is_array($valor)) {
                    $valor = [];
                }
            } else {
                $valor = trim($_POST['respuesta'][$pid] ?? '');
            }

            if ($p['requerido']) {
                $vacio = false;
                if ($p['tipo'] === 'checkbox') {
                    $vacio = empty($valor);
                } elseif ($p['tipo'] === 'tabla') {
                    $vacio = true;
                    foreach ($valor as $row) {
                        foreach ((array)$row as $cell) {
                            if (trim((string)$cell) !== '') {
                                $vacio = false;
                                break 2;
                            }
                        }
                    }
                } else {
                    $vacio = ($valor === '');
                }
                if ($vacio) {
                    $errores_campos[] = $p['etiqueta'];
                }
            }

            $respuestas[$pid] = $valor;
        }

        if (!empty($errores_campos)) {
            $error = 'Complete los campos obligatorios: ' . implode(', ', $errores_campos);
        } else {
            try {
                $respuestas_json = json_encode($respuestas, JSON_UNESCAPED_UNICODE);
                if ($respuesta_existente) {
                    $stmt = $db->prepare("
                        UPDATE formulario_respuestas 
                        SET respuestas = ?, estado = ?, ip = ?, enviado_at = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $respuestas_json,
                        $estado,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $estado === 'enviado' ? date('Y-m-d H:i:s') : null,
                        $respuesta_existente['id']
                    ]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO formulario_respuestas 
                        (formulario_id, empresa_id, usuario_id, estado, respuestas, ip, enviado_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $form_id,
                        $empresa_id,
                        $_SESSION['user_id'] ?? null,
                        $estado,
                        $respuestas_json,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $estado === 'enviado' ? date('Y-m-d H:i:s') : null
                    ]);
                }

                log_activity(
                    $estado === 'enviado' ? 'formulario_dinamico_enviado' : 'formulario_dinamico_guardado',
                    'formulario_respuestas',
                    $form_id
                );

                if ($estado === 'enviado') {
                    $nombre_empresa = $_SESSION['empresa_nombre'] ?? 'Empresa';
                    $stmt_min = $db->query("SELECT id FROM usuarios WHERE rol IN ('ministerio', 'admin')");
                    while ($min = $stmt_min->fetch()) {
                        crear_notificacion(
                            $min['id'],
                            'formulario_dinamico_enviado',
                            'Formulario dinamico recibido',
                            "$nombre_empresa envio el formulario: {$formulario['titulo']}",
                            MINISTERIO_URL . '/formulario-respuestas.php?id=' . $form_id
                        );
                    }
                    set_flash('success', 'Formulario enviado correctamente.');
                } else {
                    set_flash('success', 'Borrador guardado correctamente.');
                }

                redirect('formularios.php');
            } catch (Exception $e) {
                $error = 'Error al guardar el formulario. Intente nuevamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - Empresa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= PUBLIC_URL ?>/css/styles.css" rel="stylesheet">
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header"><span class="text-white fw-bold">Parque Industrial</span></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="perfil.php"><i class="bi bi-building"></i> Mi Perfil</a>
            <a href="publicaciones.php"><i class="bi bi-megaphone"></i> Publicaciones</a>
            <a href="formularios.php" class="active"><i class="bi bi-file-earmark-text"></i> Formularios</a>
            <hr class="my-3 border-secondary">
            <a href="<?= PUBLIC_URL ?>/" target="_blank"><i class="bi bi-globe"></i> Ver sitio publico</a>
            <a href="<?= PUBLIC_URL ?>/logout.php"><i class="bi bi-box-arrow-left"></i> Cerrar sesion</a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-0"><?= e($formulario['titulo']) ?></h1>
                <?php if (!empty($formulario['descripcion'])): ?>
                    <div class="text-muted"><?= e($formulario['descripcion']) ?></div>
                <?php endif; ?>
            </div>
            <a href="formularios.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Volver</a>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-success"><?= e($mensaje) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($bloqueado): ?>
            <div class="alert alert-info">Este formulario ya fue enviado. Puede ver sus respuestas.</div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <?php foreach ($preguntas as $p): ?>
                <?php
                $pid = $p['id'];
                $valor = $respuestas_data[$pid] ?? null;
                ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <label class="form-label fw-bold"><?= e($p['etiqueta']) ?> <?= $p['requerido'] ? '<span class="text-danger">*</span>' : '' ?></label>
                        <?php if (!empty($p['ayuda'])): ?>
                            <div class="text-muted small mb-2"><?= e($p['ayuda']) ?></div>
                        <?php endif; ?>

                        <?php if ($p['tipo'] === 'texto'): ?>
                            <input type="text" class="form-control" name="respuesta[<?= $pid ?>]" value="<?= e($valor ?? '') ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                        <?php elseif ($p['tipo'] === 'textarea'): ?>
                            <textarea class="form-control" name="respuesta[<?= $pid ?>]" rows="3" <?= $bloqueado ? 'disabled' : '' ?>><?= e($valor ?? '') ?></textarea>
                        <?php elseif ($p['tipo'] === 'numero'): ?>
                            <input type="number" class="form-control" name="respuesta[<?= $pid ?>]" value="<?= e($valor ?? '') ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                        <?php elseif ($p['tipo'] === 'fecha'): ?>
                            <input type="date" class="form-control" name="respuesta[<?= $pid ?>]" value="<?= e($valor ?? '') ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                        <?php elseif (in_array($p['tipo'], ['select', 'radio', 'checkbox'], true)): ?>
                            <?php $items = $p['opciones']['items'] ?? []; ?>
                            <?php if ($p['tipo'] === 'select'): ?>
                                <select class="form-select" name="respuesta[<?= $pid ?>]" <?= $bloqueado ? 'disabled' : '' ?>>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= e($item) ?>" <?= ($valor ?? '') === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($p['tipo'] === 'radio'): ?>
                                <?php foreach ($items as $item): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="respuesta[<?= $pid ?>]" value="<?= e($item) ?>" <?= ($valor ?? '') === $item ? 'checked' : '' ?> <?= $bloqueado ? 'disabled' : '' ?>>
                                        <label class="form-check-label"><?= e($item) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php $checked = is_array($valor) ? $valor : []; ?>
                                <?php foreach ($items as $item): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="respuesta[<?= $pid ?>][]" value="<?= e($item) ?>" <?= in_array($item, $checked, true) ? 'checked' : '' ?> <?= $bloqueado ? 'disabled' : '' ?>>
                                        <label class="form-check-label"><?= e($item) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php elseif ($p['tipo'] === 'tabla'): ?>
                            <?php
                            $cols = $p['opciones']['cols'] ?? [];
                            $rows = $p['opciones']['rows'] ?? [];
                            ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <?php foreach ($cols as $col): ?>
                                                <th><?= e($col) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r_idx => $row): ?>
                                            <tr>
                                                <th><?= e($row) ?></th>
                                                <?php foreach ($cols as $c_idx => $col): ?>
                                                    <?php $cell_val = $valor[$r_idx][$c_idx] ?? ''; ?>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" name="respuesta_tabla[<?= $pid ?>][<?= $r_idx ?>][<?= $c_idx ?>]" value="<?= e($cell_val) ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$bloqueado): ?>
                <div class="d-flex gap-2">
                    <button type="submit" name="accion" value="guardar" class="btn btn-outline-secondary"><i class="bi bi-save me-2"></i>Guardar borrador</button>
                    <button type="submit" name="accion" value="enviar" class="btn btn-primary"><i class="bi bi-send me-2"></i>Enviar formulario</button>
                </div>
            <?php endif; ?>
        </form>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
