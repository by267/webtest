<?php
header('Content-Type: application/json; charset=utf-8');

// Lee las credenciales directamente desde el entorno seguro de Railway
$host = getenv('MYSQLHOST');
$dbname = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$password = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT');

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

$action = $_GET['action'] ?? '';

// ==========================================
// 1. OBTENER ESTADO (Menú, Insumos, Kardex)
// ==========================================
if ($action === 'estado') {
    // 🔴 CAMBIO: Se quitó el "WHERE i.es_subreceta = FALSE" para que aparezcan en pantalla
    $insumos = $pdo->query("
        SELECT i.id, i.nombre, i.area, i.stock_actual, i.stock_minimo, u.abreviatura AS unidad,
               i.paquetes_por_compra, i.contenido_por_paquete
        FROM insumos i
        INNER JOIN unidades_medida u ON i.unidad_base_id = u.id
        ORDER BY i.area, i.nombre ASC
    ")->fetchAll();

    $productos = $pdo->query("
        SELECT id, CONCAT(nombre, ' - ', tamano_variante) AS descripcion, precio_venta, categoria 
        FROM productos_menu 
        WHERE activo = 1
        ORDER BY categoria, nombre
    ")->fetchAll();

    $kardex = $pdo->query("
        SELECT m.id, i.nombre AS insumo, m.tipo_movimiento, m.cantidad, 
               u.abreviatura, m.observaciones, DATE_FORMAT(m.fecha, '%d/%m %H:%i') as fecha_corta
        FROM movimientos_inventario m
        INNER JOIN insumos i ON m.insumo_id = i.id
        INNER JOIN unidades_medida u ON i.unidad_base_id = u.id
        ORDER BY m.id DESC LIMIT 20
    ")->fetchAll();

    echo json_encode([
        'insumos'   => $insumos,
        'productos' => $productos,
        'kardex'    => $kardex
    ]);
    exit;
}

// ==========================================
// 2. VENDER Y DESCONTAR (Soporta Sub-recetas completas)
// ==========================================
if ($action === 'vender' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $producto_id = (int)($data['producto_id'] ?? 0);
    $cantidad    = (int)($data['cantidad'] ?? 0);

    if ($producto_id <= 0 || $cantidad <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtProd = $pdo->prepare("SELECT precio_venta, nombre, tamano_variante FROM productos_menu WHERE id = ?");
        $stmtProd->execute([$producto_id]);
        $producto = $stmtProd->fetch();
        $total = $producto['precio_venta'] * $cantidad;
        $nombre_ticket = $producto['nombre'] . ' (' . $producto['tamano_variante'] . ')';

        $stmtVenta = $pdo->prepare("INSERT INTO ventas (total) VALUES (?)");
        $stmtVenta->execute([$total]);
        $venta_id = $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare("INSERT INTO detalle_ventas (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
        $stmtDetalle->execute([$venta_id, $producto_id, $cantidad, $producto['precio_venta']]);

        // 🔴 CAMBIO: Se agregó un 3er UNION ALL para registrar la sub-receta en Kardex
        $sqlDesglose = "
            SELECT desglose.insumo_id, 'SALIDA_VENTA', -(desglose.total_descontar), ?, ?
            FROM (
                SELECT ri.insumo_id, (ri.cantidad_requerida * ?) AS total_descontar
                FROM receta_insumos ri
                INNER JOIN insumos ins ON ri.insumo_id = ins.id
                WHERE ri.producto_id = ? AND ins.es_subreceta = FALSE
                
                UNION ALL
                
                SELECT sc.insumo_componente_id, (ri.cantidad_requerida * sc.cantidad_proporcional * ?)
                FROM receta_insumos ri
                INNER JOIN insumos ins ON ri.insumo_id = ins.id
                INNER JOIN subreceta_componentes sc ON ins.id = sc.subreceta_insumo_id
                WHERE ri.producto_id = ? AND ins.es_subreceta = TRUE

                UNION ALL
                
                SELECT ri.insumo_id, (ri.cantidad_requerida * ?) AS total_descontar
                FROM receta_insumos ri
                INNER JOIN insumos ins ON ri.insumo_id = ins.id
                WHERE ri.producto_id = ? AND ins.es_subreceta = TRUE
            ) AS desglose
        ";
        
        $obs = "Venta #$venta_id: $cantidad x $nombre_ticket";
        $stmtKardex = $pdo->prepare("INSERT INTO movimientos_inventario (insumo_id, tipo_movimiento, cantidad, referencia_id, observaciones) $sqlDesglose");
        // Ahora pide 6 parámetros matemáticos en lugar de 4
        $stmtKardex->execute([$venta_id, $obs, $cantidad, $producto_id, $cantidad, $producto_id, $cantidad, $producto_id]);

        // 🔴 CAMBIO: Se agregó el mismo 3er UNION ALL para restar stock de la sub-receta
        $stmtUpdateStock = $pdo->prepare("
            UPDATE insumos i
            INNER JOIN (
                SELECT ri.insumo_id, (ri.cantidad_requerida * ?) AS total_descontar
                FROM receta_insumos ri
                INNER JOIN insumos ins ON ri.insumo_id = ins.id
                WHERE ri.producto_id = ? AND ins.es_subreceta = FALSE
                
                UNION ALL
                
                SELECT sc.insumo_componente_id, (ri.cantidad_requerida * sc.cantidad_proporcional * ?)
                FROM receta_insumos ri
                INNER JOIN insumos ins ON ri.insumo_id = ins.id
                INNER JOIN subreceta_componentes sc ON ins.id = sc.subreceta_insumo_id
                WHERE ri.producto_id = ? AND ins.es_subreceta = TRUE

                UNION ALL
                
                SELECT ri.insumo_id, (ri.cantidad_requerida * ?) AS total_descontar
                FROM receta_insumos ri
                INNER JOIN insumos ins ON ri.insumo_id = ins.id
                WHERE ri.producto_id = ? AND ins.es_subreceta = TRUE
            ) AS desglose ON i.id = desglose.insumo_id
            SET i.stock_actual = i.stock_actual - desglose.total_descontar
        ");
        $stmtUpdateStock->execute([$cantidad, $producto_id, $cantidad, $producto_id, $cantidad, $producto_id]);

        $pdo->commit();
        echo json_encode(['mensaje' => "Venta exitosa. Insumos y Sub-recetas descontados."]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 3. ENTRADA DE ALMACÉN (Por paquetes)
// ==========================================
if ($action === 'entrada' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $insumo_id = (int)($data['insumo_id'] ?? 0);
    $cant_comprada = (float)($data['cantidad'] ?? 0);

    if ($insumo_id <= 0 || $cant_comprada <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtKardex = $pdo->prepare("
            INSERT INTO movimientos_inventario (insumo_id, tipo_movimiento, cantidad, observaciones)
            SELECT id, 'ENTRADA_COMPRA', (? * paquetes_por_compra * contenido_por_paquete), 
                   CONCAT('Abastecimiento: ', ?, ' unidad(es) de compra')
            FROM insumos WHERE id = ?
        ");
        $stmtKardex->execute([$cant_comprada, $cant_comprada, $insumo_id]);

        $stmtUpdate = $pdo->prepare("
            UPDATE insumos 
            SET stock_actual = stock_actual + (? * paquetes_por_compra * contenido_por_paquete) 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$cant_comprada, $insumo_id]);

        $pdo->commit();
        echo json_encode(['mensaje' => 'Mercancía ingresada y convertida a gramos/ml/pz exitosamente.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 4. OBTENER DATOS PARA EL GESTOR DE RECETAS
// ==========================================
if ($action === 'cargar_gestor_recetas') {
    $productos = $pdo->query("
        SELECT id, CONCAT(nombre, ' - ', tamano_variante) AS descripcion, categoria 
        FROM productos_menu 
        ORDER BY categoria, nombre
    ")->fetchAll();

    $insumos = $pdo->query("
        SELECT i.id, i.nombre, i.area, u.abreviatura AS unidad 
        FROM insumos i 
        INNER JOIN unidades_medida u ON i.unidad_base_id = u.id 
        ORDER BY i.area, i.nombre
    ")->fetchAll();

    $recetas = $pdo->query("
        SELECT producto_id, insumo_id, cantidad_requerida 
        FROM receta_insumos
    ")->fetchAll();

    echo json_encode([
        'productos' => $productos, 
        'insumos' => $insumos, 
        'recetas' => $recetas
    ]);
    exit;
}

// ==========================================
// 5. GUARDAR Y SOBRESCRIBIR RECETA
// ==========================================
if ($action === 'guardar_receta' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $producto_id = (int)($data['producto_id'] ?? 0);
    $ingredientes = $data['ingredientes'] ?? [];

    if ($producto_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No has seleccionado un producto válido']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtDelete = $pdo->prepare("DELETE FROM receta_insumos WHERE producto_id = ?");
        $stmtDelete->execute([$producto_id]);

        $stmtInsert = $pdo->prepare("INSERT INTO receta_insumos (producto_id, insumo_id, cantidad_requerida) VALUES (?, ?, ?)");
        
        foreach ($ingredientes as $ing) {
            $insumo_id = (int)$ing['insumo_id'];
            $cantidad = (float)$ing['cantidad'];
            
            if ($insumo_id > 0 && $cantidad > 0) {
                $stmtInsert->execute([$producto_id, $insumo_id, $cantidad]);
            }
        }

        $pdo->commit();
        echo json_encode(['mensaje' => 'Receta actualizada correctamente en la base de datos.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error al guardar: ' . $e->getMessage()]);
    }
    exit;
}
