<?php
// Helpers for PDF generation: database-backed managers used by the PDF generator
require_once __DIR__ . '/../config/database.php';

class CerradasInvoiceManager {
    private $conn;
    public function __construct() { $this->conn = getDbConnection(); }

    public function searchSuppliers($q = '') {
        $sql = "SELECT DISTINCT nombre FROM invoices WHERE status = 'C'";
        $params = [];
        if ($q) { $sql .= " AND nombre LIKE ?"; $params[] = "%$q%"; }
        $sql .= " ORDER BY nombre ASC LIMIT 10";

        if (is_a($this->conn, 'PDO')) {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = sqlsrv_query($this->conn, $sql, $params);
            $res = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) $res[] = $row[0];
            sqlsrv_free_stmt($stmt);
            return $res;
        }
    }

    public function getCerradasInvoices($supplier = '', $from = '', $to = '', $today = '') {
        $sql = "SELECT *, 
                DATEDIFF(day, fecha_vencimiento, GETDATE()) as dias_antiguedad,
                DATEDIFF(day, created_at, GETDATE()) as dias_finalizada
                FROM invoices WHERE status = 'C' AND ESTADOSAP = 'O'";
        $params = [];

        if ($today === '1') $sql .= " AND CONVERT(date, updated_at) = CONVERT(date, GETDATE())";
        if ($supplier) { $sql .= " AND nombre LIKE ?"; $params[] = "%$supplier%"; }
        if ($from) { $sql .= " AND fecha_vencimiento >= ?"; $params[] = $from; }
        if ($to) { $sql .= " AND fecha_vencimiento <= ?"; $params[] = $to; }
        $sql .= " ORDER BY nombre, fecha_vencimiento DESC";

        if (is_a($this->conn, 'PDO')) {
            $sql = str_replace(['GETDATE()', 'CONVERT(date, updated_at)', 'DATEDIFF(day, fecha_vencimiento, GETDATE())', 'DATEDIFF(day, created_at, GETDATE())'],
                              ['CURDATE()', 'DATE(updated_at)', 'DATEDIFF(CURDATE(), fecha_vencimiento)', 'DATEDIFF(CURDATE(), created_at)'], $sql);
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = sqlsrv_query($this->conn, $sql, $params);
            $res = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $res[] = $row;
            sqlsrv_free_stmt($stmt);
            return $res;
        }
    }
}

class EgresoPagoManager {
    private $conn;
    public function __construct() { $this->conn = getDbConnection(); }

    public function getFacturasEgreso($prov, $ini, $fin) {
        $sql = "WITH FacturasUnicas AS (
            SELECT i.id, i.codigo_sn, i.nombre, i.fecha_contable, i.fecha_vencimiento,
                   i.numero_factura_proveedor, i.docnum_interno_sap, p.Factura, p.[Fecha Factura] FechaFactura,
                   p.[Valor Total] ValorTotal, p.[Valor Pagado] ValorPagado,
                   ROW_NUMBER() OVER (PARTITION BY p.Factura ORDER BY p.[Fecha de Pago] DESC) rn
            FROM invoices i
            INNER JOIN Invoice_pagas p ON LTRIM(RTRIM(CAST(i.docnum_interno_sap AS NVARCHAR))) = LTRIM(RTRIM(CAST(p.Factura AS NVARCHAR)))
            WHERE i.nombre = ? AND p.[Fecha Factura] >= ? AND p.[Fecha Factura] <= ?
            UNION ALL
            SELECT i.id, i.codigo_sn, i.nombre, i.fecha_contable, i.fecha_vencimiento,
                   i.numero_factura_proveedor, i.docnum_interno_sap, p.Factura, p.[Fecha Factura],
                   p.[Valor Total], p.[Valor Pagado],
                   ROW_NUMBER() OVER (PARTITION BY p.Factura ORDER BY p.[Fecha de Pago] DESC)
            FROM invoices i
            INNER JOIN Invoice_pagas p ON LTRIM(RTRIM(CAST(i.numero_factura_proveedor AS NVARCHAR))) = LTRIM(RTRIM(CAST(p.Factura AS NVARCHAR)))
            WHERE i.nombre = ? AND p.[Fecha Factura] >= ? AND p.[Fecha Factura] <= ?
              AND NOT EXISTS (SELECT 1 FROM Invoice_pagas p2 WHERE LTRIM(RTRIM(CAST(p2.Factura AS NVARCHAR))) = LTRIM(RTRIM(CAST(i.docnum_interno_sap AS NVARCHAR))))
        ) SELECT * FROM FacturasUnicas WHERE rn = 1 ORDER BY FechaFactura";

        $params = [$prov, $ini, $fin, $prov, $ini, $fin];
        if (is_a($this->conn, 'PDO')) {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = sqlsrv_query($this->conn, $sql, $params);
            $res = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $res[] = $row;
            sqlsrv_free_stmt($stmt);
            return $res;
        }
    }
}