<?php
namespace FacturaScripts\Plugins\OptimizadorImagenes\Controller;

use FacturaScripts\Core\Base\Controller;
use FilesystemIterator;
use Imagick;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\AttachedFile;

// Establecemos la codificación por defecto a UTF-8.
ini_set('default_charset', 'UTF-8');

class OptimizarImagenes extends Controller
{
    public $ultimaFecha = 'Nunca';
    public $logsDisponibles = [];
    public $infoMyFiles = [];
    public $infoMaster = [];
    public $libreriaActiva = 'Ninguna';
    public $resolucionSeleccionada = '';
    public $calidadSeleccionada = 85;
    public $logActivo = '';
    public $logContenido = [];
    public $mensajes = [];
    public $totalImages = 0;
    public $dataBase;
    
    // Propiedades de depuración
    public $debugMode = false;
    public $debugMessages = [];

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'admin';
        $pageData['title'] = 'Optimizador de Imágenes';
        $pageData['icon'] = 'fas fa-image';
        $pageData['mensajes'] = $this->mensajes;
        return $pageData;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        
        if (isset($_GET['debug'])) {
            $this->debugMode = true;
            $this->debugMessages[] = "Modo de depuración activado.";
        }
        
        if ($this->debugMode) {
            ob_start();
        }

        $accion = $_POST['action'] ?? '';
        $this->libreriaActiva = $this->getLibreriaActiva();
        
        if (empty($this->dataBase)) {
            $this->dataBase = \FacturaScripts\Core\Tools::getDb();
        }

        if ($accion === 'optimizar') {
            $this->resolucionSeleccionada = $_POST['resolucion'] ?? '0';
            $this->calidadSeleccionada = intval($_POST['calidad'] ?? 65);
            $this->optimizarImagenes();
        } elseif ($accion === 'borrar-log' && !empty($_POST['log'])) {
            $this->borrarLog($_POST['log']);
        } elseif ($accion === 'ver-log-ajax') {
            $this->cargarLogContenidoAjax();
        } elseif ($accion === 'restaurar-imagenes') {
            $this->restaurarImagenes();
        }

        $logsPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Logs';
        if (!is_dir($logsPath)) {
            mkdir($logsPath, 0775, true);
        }

        foreach (glob($logsPath . DIRECTORY_SEPARATOR . '*.log') as $logFile) {
            $this->logsDisponibles[] = [
                'name' => basename($logFile),
                'date' => date('Y-m-d H:i:s', filemtime($logFile)),
                'size' => filesize($logFile),
                'path' => $logFile
            ];
        }

        if (!empty($this->logsDisponibles)) {
            usort($this->logsDisponibles, function($a, $b) {
                return $b['date'] <=> $a['date'];
            });
            $this->ultimaFecha = $this->logsDisponibles[0]['date'];
        }

        $this->infoMyFiles = $this->getImageStats($this->getPath('MyFiles'));
        $this->infoMaster = $this->getImageStats($this->getPath('MyFiles.master'));
        
        $this->totalImages = $this->infoMyFiles['count'];

        if ($this->debugMode && !empty($this->debugMessages)) {
            echo "<br/><br/>---<br/>";
            echo "<h2>Resumen de Depuración</h2>";
            echo "<pre>";
            foreach ($this->debugMessages as $message) {
                echo htmlspecialchars($message) . "<br/>";
            }
            echo "</pre>";
        }
    }

    private function cargarLogContenidoAjax()
    {
        $logName = $_POST['logName'] ?? '';
        $logsPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR;
        $logPath = $logsPath . basename($logName);
        
        if (file_exists($logPath) && strpos(realpath($logPath), realpath($logsPath)) === 0) {
            $contenidoLog = file_get_contents($logPath);
            echo htmlspecialchars($contenidoLog);
        } else {
            http_response_code(404);
            echo 'No se pudo cargar el log. El archivo no existe o no es válido.';
        }
        
        exit;
    }

    private function optimizarImagenes()
    {
        set_time_limit(0);

        $myFiles = $this->getPath('MyFiles');
        $master = $this->getPath('MyFiles.master');
        $resolucion = $_POST['resolucion'] ?? '0';
        $this->resolucionSeleccionada = $resolucion;

        $maxDimension = ($resolucion !== '0') ? intval($resolucion) : 0;
        $calidad = $this->calidadSeleccionada;

        $this->mensajes[] = ['text' => "Resolución seleccionada: " . ($maxDimension ? $maxDimension . ' px' : 'No reducir') . ". Calidad seleccionada: {$calidad}%", 'type' => 'info'];

        $total = 0;
        $optimizadas = 0;
        $noModificadas = 0; // Nueva variable para contar las imágenes no modificadas
        $ahorrado = 0;
        $log = [];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($myFiles, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile()) continue;
            $relPath = str_replace($myFiles . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $ext = strtolower($file->getExtension());

            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) continue;

            $total++;

            $origen = $file->getPathname();
            $destinoMaster = $master . DIRECTORY_SEPARATOR . $relPath;

            if (!file_exists($destinoMaster)) {
                @mkdir(dirname($destinoMaster), 0775, true);
                copy($origen, $destinoMaster);
            }

            $pesoOriginal = filesize($origen);
            $anchoOriginal = 0;
            $altoOriginal = 0;
            
            $imageInfo = @getimagesize($origen);
            if ($imageInfo !== false) {
                [$anchoOriginal, $altoOriginal] = $imageInfo;
            }

            $anchoFinal = $anchoOriginal;
            $altoFinal = $altoOriginal;
            $pesoFinal = $pesoOriginal;

            $exito = false;
            $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('opti_', true) . '.' . $ext;
            
            if ($this->libreriaActiva === 'Imagick') {
                $exito = $this->optimizarConImagick($origen, $tempPath, $maxDimension, $calidad, $anchoFinal, $altoFinal);
            } elseif ($this->libreriaActiva === 'GD') {
                $exito = $this->optimizarConGD($origen, $tempPath, $maxDimension, $calidad, $anchoFinal, $altoFinal);
            }

            if ($exito) {
                $pesoFinal = filesize($tempPath);
                
                if ($pesoFinal < $pesoOriginal) {
                    rename($tempPath, $origen);
                    
                    $optimizadas++;
                    $ahorroActual = $pesoOriginal - $pesoFinal;
                    $ahorrado += $ahorroActual;
                    $porcentajeAhorro = round(($ahorroActual / $pesoOriginal) * 100, 2);
                    $log[] = "/MyFiles/$relPath - Original: {$anchoOriginal}x{$altoOriginal} - " . $this->formatBytes($pesoOriginal) . " -> Final: {$anchoFinal}x{$altoFinal} - " . $this->formatBytes($pesoFinal) . " - Ahorro: {$porcentajeAhorro}%";

                    $dbPath = 'MyFiles/' . str_replace('\\', '/', $relPath);
                    $attachedFile = new AttachedFile();
                    $where = [new DataBaseWhere('path', $dbPath)];

                    if ($attachedFile->loadFromCode('', $where)) {
                        $attachedFile->size = $pesoFinal;
                        $attachedFile->save();
                    }
                } else {
                    unlink($tempPath);
                    $noModificadas++;
                    $log[] = "/MyFiles/$relPath - Procesada, pero el tamaño optimizado no es menor. Se omite.";
                }
            } else {
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
                $log[] = "/MyFiles/$relPath - Error al optimizar.";
            }
        }

        $log[] = '';
        $log[] = "Total imágenes encontradas: $total";
        $log[] = "Optimización con: {$this->libreriaActiva}";
        $log[] = "Resolución: " . ($maxDimension ? "Máx. " . $maxDimension . " px" : 'No reducir');
        $log[] = "Calidad: {$calidad}%";
        $log[] = "Optimizadas: $optimizadas";
        $log[] = "No modificadas: $noModificadas"; // Añadimos el nuevo contador al log
        $log[] = "Espacio ahorrado: " . $this->formatBytes($ahorrado);

        $nombreLog = 'log-' . date('Y-m-d_H-i-s') . '.log';
        $rutaLog = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR . $nombreLog;
        file_put_contents($rutaLog, implode(PHP_EOL, $log));

        $this->mensajes[] = ['text' => "Optimizando con {$this->libreriaActiva} a tamaño máximo de {$maxDimension}px y calidad del {$calidad}%", 'type' => 'info'];
        $this->mensajes[] = ['text' => "Imágenes procesadas. Log generado: $nombreLog", 'type' => 'success'];
        $this->mensajes[] = ['text' => "Proceso de optimización completado.", 'type' => 'success'];
    }

    private function borrarLog(string $nombre)
    {
        $ruta = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR . basename($nombre);
        if (file_exists($ruta)) {
            unlink($ruta);
            $this->mensajes[] = ['text' => 'Log eliminado correctamente.', 'type' => 'success'];
        } else {
            $this->mensajes[] = ['text' => 'El log no existe.', 'type' => 'danger'];
        }
    }

    private function restaurarImagenes()
    {
        set_time_limit(0);
        $masterPath = $this->getPath('MyFiles.master');
        $myFilesPath = $this->getPath('MyFiles');
        $log = [];

        if (!is_dir($masterPath)) {
            $this->mensajes[] = ['text' => 'El directorio MyFiles.master no existe. No se puede restaurar.', 'type' => 'warning'];
            return;
        }

        $log[] = "Iniciando restauración segura de imágenes.";
        $log[] = "Se reemplazarán las imágenes existentes de MyFiles con las originales de MyFiles.master.";

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($masterPath, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relPath = str_replace($masterPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $destPath = $myFilesPath . DIRECTORY_SEPARATOR . $relPath;
            
            @mkdir(dirname($destPath), 0775, true);

            copy($file->getPathname(), $destPath);
            $log[] = "Restaurada: " . $relPath;
            
            clearstatcache(true, $destPath);
            $nuevoPeso = filesize($destPath);
            
            $dbPath = 'MyFiles/' . str_replace('\\', '/', $relPath);

            $attachedFile = new AttachedFile();
            $where = [ new DataBaseWhere('path', $dbPath) ];

            $mensaje = "Restaurando la imagen: " . basename($relPath) . " (path: " . $dbPath . "). Tamaño final: " . $this->formatBytes($nuevoPeso) . ".";
            if ($this->debugMode) {
                echo $mensaje . "<br/>";
                ob_flush();
                flush();
            }

            if ($attachedFile->loadFromCode('', $where)) {
                if ($attachedFile->size != $nuevoPeso) {
                    if ($this->debugMode) {
                        echo "  - Registro de imagen encontrado (idfile: " . $attachedFile->idfile . "). El tamaño ha cambiado. Guardando cambios...<br/>";
                        ob_flush();
                        flush();
                    }
                    $attachedFile->size = $nuevoPeso;
                    $attachedFile->save();
                    if ($this->debugMode) {
                        echo "  - ¡Guardado con éxito!<br/>";
                        ob_flush();
                        flush();
                    }
                } else {
                    if ($this->debugMode) {
                        echo "  - Registro de imagen encontrado (idfile: " . $attachedFile->idfile . "). El tamaño no ha cambiado. Se omite la actualización.<br/>";
                        ob_flush();
                        flush();
                    }
                }
            } else {
                if ($this->debugMode) {
                    echo "  - Advertencia: No se encontró el registro con la ruta '" . $dbPath . "'. Se omite la actualización.<br/>";
                    ob_flush();
                    flush();
                }
            }
            $this->debugMessages[] = $mensaje;
        }

        $log[] = "Restauración completada. Imágenes restauradas correctamente.";
        $log[] = '';
        $log[] = "Resumen del proceso:";
        
        $rootPath = realpath(__DIR__ . '/../../../');
        $origenLog = str_replace($rootPath, '', $masterPath);
        $destinoLog = str_replace($rootPath, '', $myFilesPath);

        $log[] = "Origen: " . $origenLog;
        $log[] = "Destino: " . $destinoLog;
        
        $nombreLog = 'restauracion-' . date('Y-m-d_H-i-s') . '.log';
        $rutaLog = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR . $nombreLog;
        file_put_contents($rutaLog, implode(PHP_EOL, $log));

        $this->mensajes[] = ['text' => 'Imágenes restauradas correctamente desde MyFiles.master. Log de restauración generado: ' . $nombreLog, 'type' => 'success'];
    }

    private function getImageStats(string $path): array
    {
        $totalSize = 0;
        $totalCount = 0;

        if (!is_dir($path)) return ['count' => 0, 'size' => '0 B'];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile() && preg_match('/\.(jpe?g|png|gif|webp)$/i', $file->getFilename())) {
                $totalCount++;
                $totalSize += $file->getSize();
            }
        }

        return [
            'count' => $totalCount,
            'size' => $this->formatBytes($totalSize),
        ];
    }

    private function getPath(string $folder): string
    {
        return realpath(__DIR__ . '/../../../') . DIRECTORY_SEPARATOR . $folder;
    }

    private function getLibreriaActiva(): string
    {
        if (extension_loaded('imagick')) return 'Imagick';
        if (extension_loaded('gd')) return 'GD';
        return 'Ninguna';
    }

    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
    }

    private function optimizarConGD($pathOriginal, $pathDestino, $maxDimension, $calidad, &$anchoFinal, &$altoFinal): bool
    {
        [$anchoOriginal, $altoOriginal] = @getimagesize($pathOriginal);
        if ($anchoOriginal === null || $altoOriginal === null) {
            return false;
        }

        $image = imagecreatefromstring(file_get_contents($pathOriginal));
        if (!$image) {
            return false;
        }
        
        $anchoRedimensionado = $anchoOriginal;
        $altoRedimensionado = $altoOriginal;

        if ($maxDimension > 0 && ($anchoOriginal > $maxDimension || $altoOriginal > $maxDimension)) {
            if ($anchoOriginal > $altoOriginal) {
                $anchoRedimensionado = $maxDimension;
                $altoRedimensionado = intval($altoOriginal * ($maxDimension / $anchoOriginal));
            } else {
                $altoRedimensionado = $maxDimension;
                $anchoRedimensionado = intval($anchoOriginal * ($maxDimension / $altoOriginal));
            }

            $nueva = imagecreatetruecolor($anchoRedimensionado, $altoRedimensionado);
            imagecopyresampled($nueva, $image, 0, 0, 0, 0, $anchoRedimensionado, $altoRedimensionado, $anchoOriginal, $altoOriginal);
            imagedestroy($image);
            $image = $nueva;
        }

        $extension = strtolower(pathinfo($pathOriginal, PATHINFO_EXTENSION));
        
        switch ($extension) {
            case 'jpeg':
            case 'jpg':
                imagejpeg($image, $pathDestino, $calidad);
                break;
            case 'png':
                $calidadPng = intval((9 * $calidad) / 100);
                imagepng($image, $pathDestino, $calidadPng);
                break;
            case 'webp':
                imagewebp($image, $pathDestino, $calidad);
                break;
            case 'gif':
                imagegif($image, $pathDestino);
                break;
            default:
                imagedestroy($image);
                return false;
        }

        imagedestroy($image);
        
        $anchoFinal = $anchoRedimensionado;
        $altoFinal = $altoRedimensionado;

        return true;
    }

    private function optimizarConImagick($pathOriginal, $pathDestino, $maxDimension, $calidad, &$anchoFinal, &$altoFinal): bool
    {
        try {
            $img = new Imagick($pathOriginal);
            $img->stripImage();

            $anchoOriginal = $img->getImageWidth();
            $altoOriginal = $img->getImageHeight();
            
            $anchoRedimensionado = $anchoOriginal;
            $altoRedimensionado = $altoOriginal;
            
            if ($maxDimension > 0 && ($anchoOriginal > $maxDimension || $altoOriginal > $maxDimension)) {
                 $img->resizeImage($maxDimension, $maxDimension, Imagick::FILTER_LANCZOS, 1, true);

                 $anchoRedimensionado = $img->getImageWidth();
                 $altoRedimensionado = $img->getImageHeight();
            }
            
            $img->setImageCompressionQuality($calidad);
            $img->writeImage($pathDestino);
            
            $anchoFinal = $anchoRedimensionado;
            $altoFinal = $altoRedimensionado;

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}