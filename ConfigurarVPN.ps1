# Script para configurar una conexión VPN en Windows
# Guarda este archivo como ConfigurarVPN.ps1
# Ejecuta PowerShell como administrador y luego ejecuta este script

# Parámetros de la conexión VPN
$VpnName = "WMSKY"
$ServerAddress = "192.168.2.45"
$TunnelType = "PPTP" # Opciones: PPTP, L2TP, SSTP, IKEv2
$EncryptionLevel = "Required"
$Username = "WMSKY"
$Password = ConvertTo-SecureString "@123*$321" -AsPlainText -Force
$RememberCredential = $true

# Configuración de red después de la conexión VPN
$VpnInterface = "WMSKY"
$IPAddress = "192.168.2.45"
$SubnetMask = "255.255.255.0"
$Gateway = "192.168.2.1"
$DNS = "192.168.2.5"

# Crear la conexión VPN si no existe
try {
    $ExistingVpn = Get-VpnConnection -Name $VpnName -ErrorAction SilentlyContinue
    if ($ExistingVpn -eq $null) {
        Write-Host "Creando nueva conexión VPN: $VpnName"
        Add-VpnConnection -Name $VpnName -ServerAddress $ServerAddress -TunnelType $TunnelType -EncryptionLevel $EncryptionLevel -RememberCredential $RememberCredential -Force
        Write-Host "Conexión VPN creada exitosamente."
    } else {
        Write-Host "La conexión VPN '$VpnName' ya existe. Actualizando configuración..."
        Set-VpnConnection -Name $VpnName -ServerAddress $ServerAddress -TunnelType $TunnelType -EncryptionLevel $EncryptionLevel -RememberCredential $RememberCredential -Force
        Write-Host "Configuración actualizada exitosamente."
    }

    # Configurar credenciales si se proporcionan
    if ($Username -ne "" -and $Password -ne $null) {
        Write-Host "Configurando credenciales para la conexión VPN..."
        Set-VpnConnectionUsernamePassword -ConnectionName $VpnName -Username $Username -Password $Password
        Write-Host "Credenciales configuradas exitosamente."
    }
    
    Write-Host "Configuración de VPN completada. Puedes conectarte usando: rasdial '$VpnName' o desde la interfaz de Windows."
    
    # Instrucciones para conexión manual o por código
    Write-Host "`nPara conectar manualmente:"
    Write-Host "1. Ve a Configuración > Red e Internet > VPN"
    Write-Host "2. Selecciona la conexión '$VpnName' y haz clic en Conectar"
    
    # Opcional: Conectar automáticamente
    $conectarAhora = Read-Host "¿Deseas conectar la VPN ahora? (S/N)"
    if ($conectarAhora -eq "S" -or $conectarAhora -eq "s") {
        Write-Host "Conectando a VPN '$VpnName'..."
        rasdial "$VpnName"
    }
    
    # Configurar rutas de red específicas después de la conexión
    if ((Get-VpnConnection -Name $VpnName).ConnectionStatus -eq "Connected") {
        Write-Host "Configurando rutas de red adicionales..."
        
        # Obtener el índice de interfaz de la VPN
        $vpnIfIndex = (Get-NetAdapter | Where-Object {$_.Name -like "*$VpnName*"}).ifIndex
        
        if ($vpnIfIndex) {
            # Configurar la ruta para todo el tráfico de la red 192.168.2.0/24
            New-NetRoute -DestinationPrefix "192.168.2.0/24" -InterfaceIndex $vpnIfIndex -NextHop $Gateway -RouteMetric 1 -ErrorAction SilentlyContinue
            
            # Configurar DNS para la interfaz VPN
            Set-DnsClientServerAddress -InterfaceIndex $vpnIfIndex -ServerAddresses $DNS
            
            Write-Host "Rutas de red y DNS configurados correctamente."
        } else {
            Write-Host "No se pudo encontrar la interfaz VPN para configurar rutas adicionales." -ForegroundColor Yellow
        }
    }
} catch {
    Write-Host "Error al configurar la VPN: $_" -ForegroundColor Red
}

# Script para usar en tu código PHP para conectarse a la VPN
Write-Host "`n---- Script para usar en código PHP ----"
Write-Host @'
<?php
// Función para conectar a la VPN desde PHP
function conectarVPN() {
    $vpnName = "WMSKY";
    $comando = "rasdial \"$vpnName\"";
    
    exec($comando, $output, $returnVar);
    
    if ($returnVar == 0) {
        return true; // Conexión exitosa
    } else {
        return false; // Error en la conexión
    }
}

// Función para desconectar de la VPN
function desconectarVPN() {
    $vpnName = "WMSKY";
    $comando = "rasdial \"$vpnName\" /disconnect";
    
    exec($comando, $output, $returnVar);
    
    if ($returnVar == 0) {
        return true; // Desconexión exitosa
    } else {
        return false; // Error en la desconexión
    }
}

// Ejemplo de uso
if (conectarVPN()) {
    echo "Conectado a la VPN correctamente.\n";
    
    // Aquí va tu código que necesita la conexión VPN
    // Por ejemplo, acceder a recursos en la red 192.168.2.x
    
    // Cuando termines, desconecta la VPN
    desconectarVPN();
} else {
    echo "Error al conectar a la VPN.\n";
}
?>
'@

# Módulo PHP para integrar en tu aplicación existente
Write-Host "`n---- Módulo para integrar en tu aplicación PHP ----"
Write-Host @'
<?php
/**
 * Módulo de conexión VPN para aplicación existente
 * Incluye este archivo en tu proyecto y usa las funciones según sea necesario
 */

class VPNConnector {
    private $vpnName;
    private $connected = false;
    
    public function __construct($vpnName = "WMSKY") {
        $this->vpnName = $vpnName;
    }
    
    /**
     * Conecta a la VPN
     * @return bool éxito de la conexión
     */
    public function conectar() {
        if ($this->isConnected()) {
            return true; // Ya está conectado
        }
        
        $comando = "rasdial \"{$this->vpnName}\"";
        exec($comando, $output, $returnVar);
        
        $this->connected = ($returnVar == 0);
        return $this->connected;
    }
    
    /**
     * Desconecta de la VPN
     * @return bool éxito de la desconexión
     */
    public function desconectar() {
        if (!$this->isConnected()) {
            return true; // Ya está desconectado
        }
        
        $comando = "rasdial \"{$this->vpnName}\" /disconnect";
        exec($comando, $output, $returnVar);
        
        $this->connected = !($returnVar == 0);
        return !$this->connected;
    }
    
    /**
     * Verifica si la VPN está actualmente conectada
     * @return bool estado de conexión
     */
    public function isConnected() {
        $comando = "rasdial";
        exec($comando, $output, $returnVar);
        
        foreach ($output as $line) {
            if (strpos($line, $this->vpnName) !== false) {
                $this->connected = true;
                return true;
            }
        }
        
        $this->connected = false;
        return false;
    }
    
    /**
     * Ejecuta una función con la VPN conectada y luego desconecta
     * @param callable $callback función a ejecutar
     * @return mixed resultado de la función
     */
    public function executeWithVPN($callback) {
        $wasConnected = $this->isConnected();
        $result = null;
        
        if (!$wasConnected) {
            if (!$this->conectar()) {
                return false; // No se pudo conectar
            }
        }
        
        try {
            $result = call_user_func($callback);
        } catch (Exception $e) {
            if (!$wasConnected) {
                $this->desconectar();
            }
            throw $e;
        }
        
        if (!$wasConnected) {
            $this->desconectar();
        }
        
        return $result;
    }
}

// Ejemplo de uso en tu código existente:
/*
require_once 'vpn_connector.php';

$vpn = new VPNConnector();

// Método 1: Conectar/desconectar manualmente
if ($vpn->conectar()) {
    // Tu código que requiere la VPN
    // ...
    
    $vpn->desconectar();
}

// Método 2: Usar la función executeWithVPN
$resultado = $vpn->executeWithVPN(function() {
    // Tu código que requiere la VPN
    // ...
    return $resultadoDeTuCodigo;
});
*/
?>
'@