<?php
// Función optimizada para obtener facturas pagadas agrupadas
function getGroupedPaidInvoices($supplier = '', $date_from = '', $date_to = '', $status = '') {
    try {
        $conn = getDbConnection();
        
        // Construir la consulta base
        $sql = "SELECT 
                    i.[nombre],
                    i.[docnum_interno_sap],
                    i.[numero_factura_proveedor],
                    i.[fecha_vencimiento],
                    p.[Factura],
                    p.[FechadePago],
                    p.[ValorTotal],
                    p.[ValorPagado],
                    p.[Estado],
                    p.[NumeroPago]
                FROM [invoice_approval_system].[dbo].[invoices] i
                INNER JOIN [invoice_approval_system].[dbo].[Invoice_pagas] p 
                    ON (
                        LTRIM(RTRIM(CAST(i.[docnum_interno_sap] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
                        OR 
                        LTRIM(RTRIM(CAST(i.[numero_factura_proveedor] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
                    )
                WHERE 1=1";
        
        $params = array();
        
        // Agregar filtros
        if (!empty($supplier)) {
            $sql .= " AND i.[nombre] = ?";
            $params[] = $supplier;
        }
        
        if (!empty($date_from)) {
            $sql .= " AND p.[FechadePago] >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $sql .= " AND p.[FechadePago] <= ?";
            $params[] = $date_to;
        }
        
        if (!empty($status)) {
            $sql .= " AND p.[Estado] = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY i.[nombre], p.[FechadePago] DESC";
        
        // Ejecutar la consulta según el tipo de conexión
        if (is_a($conn, 'PDO')) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Para sqlsrv
            $stmt = sqlsrv_prepare($conn, $sql, $params);
            if ($stmt === false) {
                throw new Exception("Error preparando la consulta: " . print_r(sqlsrv_errors(), true));
            }
            
            if (!sqlsrv_execute($stmt)) {
                throw new Exception("Error ejecutando la consulta: " . print_r(sqlsrv_errors(), true));
            }
            
            $results = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $results[] = $row;
            }
        }
        
        // Procesar y agrupar los resultados
        $grouped = array();
        foreach ($results as $row) {
            $supplier_name = $row['nombre'];
            
            if (!isset($grouped[$supplier_name])) {
                $grouped[$supplier_name] = array(
                    'count' => 0,
                    'total_paid' => 0,
                    'completely_paid' => 0,
                    'partially_paid' => 0,
                    'invoices' => array()
                );
            }
            
            // Calcular estado de pago
            $valor_total = floatval($row['ValorTotal'] ?? 0);
            $valor_pagado = floatval($row['ValorPagado'] ?? 0);
            
            $grouped[$supplier_name]['count']++;
            $grouped[$supplier_name]['total_paid'] += $valor_pagado;
            
            if ($valor_pagado >= $valor_total) {
                $grouped[$supplier_name]['completely_paid']++;
            } else {
                $grouped[$supplier_name]['partially_paid']++;
            }
            
            $grouped[$supplier_name]['invoices'][] = $row;
        }
        
        return array('data' => $grouped);
        
    } catch (Exception $e) {
        error_log("Error en getGroupedPaidInvoices: " . $e->getMessage());
        return array('error' => "Error al obtener las facturas: " . $e->getMessage());
    }
}
